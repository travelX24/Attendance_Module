<?php

namespace Athka\Attendance\Services;

use Athka\Attendance\Models\AttendanceDailyLog;
use Athka\Attendance\Models\AttendanceDailyPenalty;
use Athka\Employees\Models\Employee;
use Athka\SystemSettings\Models\AttendancePolicy;
use Athka\SystemSettings\Models\AttendancePenaltyPolicy;
use Athka\SystemSettings\Models\AttendanceGraceSetting;
use Athka\SystemSettings\Models\UnexcusedAbsencePolicy;
use Athka\SystemSettings\Services\WorkScheduleService;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PenaltyService
{
    private array $skipReasons = [];
    /**
     * Run penalty calculation for one specific day.
     *
     * @return array{processed:int,created:int,skipped?:array<string,int>}
     */
    public function calculateForDate($date, $companyId, array $employeeIds = []): array
    {
        return $this->calculateForRange($date, $date, $companyId, $employeeIds);
    }

    /**
     * Run penalty calculation for a specific date range.
     *
     * @return array{processed:int,created:int,skipped?:array<string,int>}
     */
    public function calculateForRange($dateFrom, $dateTo, $companyId, array $employeeIds = []): array
    {
        $this->skipReasons = [];

        DB::disableQueryLog();

        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        $dateFrom = Carbon::parse($dateFrom)->toDateString();
        $dateTo = Carbon::parse($dateTo)->toDateString();

        if (Carbon::parse($dateFrom)->gt(Carbon::parse($dateTo))) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        $this->prepareAbsentLogs($dateFrom, $dateTo, $companyId, $employeeIds);

        $logs = AttendanceDailyLog::forCompany($companyId)
            ->with(['employee' => fn ($q) => $q->withoutGlobalScope('active_only')])
            ->whereBetween('attendance_date', [$dateFrom, $dateTo])
            ->whereIn('attendance_status', ['present', 'late', 'early_departure', 'absent', 'auto_checkout'])
            ->when(!empty($employeeIds), fn ($q) => $q->whereIn('employee_id', $employeeIds))
            ->get();

        $processed = 0;
        $createdOrUpdated = 0;

        foreach ($logs as $log) {
            $processed++;

            if ($this->calculatePenaltyForLog($log)) {
                $createdOrUpdated++;
            }
        }

        return [
            'processed' => $processed,
            'created' => $createdOrUpdated,
            'skipped' => $this->skipReasons,
        ];
    }

    /**
     * Nightly preparation: Identify employees who didn't check in and aren't on leave.
     */
    private function prepareAbsentLogs($dateFrom, $dateTo, $companyId, array $employeeIds = []): void
    {
        $start = Carbon::parse($dateFrom);
        $end = Carbon::parse($dateTo);
        $cursor = $start->copy();
        $scheduleService = app(WorkScheduleService::class);

        $activeEmployees = Employee::forCompany($companyId)
            ->when(!empty($employeeIds), fn ($q) => $q->whereIn('id', $employeeIds))
            ->get();

        if ($activeEmployees->isEmpty()) {
            return;
        }

        $activeEmployeeIds = $activeEmployees
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        while ($cursor->lte($end)) {
            $dateStr = $cursor->toDateString();

            $existingEmployeeIds = AttendanceDailyLog::forCompany($companyId)
                ->where('attendance_date', $dateStr)
                ->whereIn('employee_id', $activeEmployeeIds)
                ->pluck('employee_id')
                ->map(fn ($id) => (int) $id)
                ->flip();

            foreach ($activeEmployees as $employee) {
                if ($existingEmployeeIds->has((int) $employee->id)) {
                    continue;
                }

                $schedule = $scheduleService->getEffectiveSchedule(
                    $companyId,
                    $employee,
                    $dateStr
                );

                if (! $schedule) {
                    continue;
                }

                AttendanceDailyLog::create([
                    'saas_company_id' => $companyId,
                    'employee_id' => $employee->id,
                    'attendance_date' => $dateStr,
                    'attendance_status' => 'absent',
                    'approval_status' => 'pending',
                    'work_schedule_id' => $schedule->id,
                ]);

                $existingEmployeeIds->put((int) $employee->id, true);
            }

            $cursor->addDay();
        }
    }

    /**
     * @return bool true if any penalty was created/updated
     */
    public function calculatePenaltyForLog(AttendanceDailyLog $log): bool
    {
        $employee = $log->employee;
        if (! $employee) {
            return false;
        }

        $calendarSkipReason = $this->calendarPenaltySkipReason($log);
        if ($calendarSkipReason !== null) {
            $this->deleteUnconfirmedPenaltiesForLog($log);
            $this->markSkipped($calendarSkipReason);

            return false;
        }

        $group = DB::table('employee_group_members')
            ->join('employee_groups', 'employee_group_members.group_id', '=', 'employee_groups.id')
            ->where('employee_group_members.employee_id', $employee->id)
            ->select(
                'employee_groups.applied_policy_id',
                'employee_groups.grace_source',
                'employee_groups.grace_setting_id'
            )
            ->first();

        $policyId = $group
            ? (int) $group->applied_policy_id
            : (int) AttendancePolicy::where('saas_company_id', $log->saas_company_id)->where('is_default', true)->value('id');

        if (! $policyId) {
            return false;
        }

        $lateMinutes = $this->getLateMinutes($log);
        $earlyMinutes = $this->getEarlyDepartureMinutes($log);

        if ((string) $log->attendance_status === 'absent') {
            $targetViolationTypes = ['absent'];
        } elseif ((string) $log->attendance_status === 'auto_checkout') {
            $grace = $this->resolveCompanyGraceSetting(
                (int) $log->saas_company_id
            );

            $targetViolationTypes = ($grace && (bool) $grace->auto_checkout_penalty_enabled)
                ? ['auto_checkout']
                : [];
        } elseif ($this->shouldConvertLateEarlyToAbsence($log, $policyId, $lateMinutes, $earlyMinutes)) {
            $targetViolationTypes = ['absent'];
        } else {
            $targetViolationTypes = [];

            if ($lateMinutes > 0) {
                $targetViolationTypes[] = 'delay';
            }

            if ($earlyMinutes > 0) {
                $targetViolationTypes[] = 'early_departure';
            }
        }

        $targetViolationTypes = array_values(array_unique($targetViolationTypes));

        $this->deletePenaltiesOutsideResolvedTarget($log, $targetViolationTypes);

        if (empty($targetViolationTypes)) {
            $this->markSkipped('no_billable_violation');
            return false;
        }

        $created = false;

        foreach ($targetViolationTypes as $violationType) {
            $created = $this->processViolation($log, $policyId, $violationType, $group) || $created;
        }

        return $created;
    }

    /**
     * @return bool true if penalty saved/updated
     */
    private function shouldConvertLateEarlyToAbsence(
        AttendanceDailyLog $log,
        int $policyId,
        int $lateMinutes,
        int $earlyMinutes
    ): bool {
        if ($lateMinutes <= 0 && $earlyMinutes <= 0) {
            return false;
        }

        // 1. Check UnexcusedAbsencePolicy for company-configured absence threshold (late_minutes / early_leave_minutes)
        $absencePolicies = UnexcusedAbsencePolicy::query()
            ->where('saas_company_id', $log->saas_company_id)
            ->where(function ($q) use ($policyId) {
                $q->where('policy_id', $policyId)->orWhereNull('policy_id');
            })
            ->where('is_active', true)
            ->where(function ($query) {
                $query->where('is_enabled', true)->orWhereNull('is_enabled');
            })
            ->get();

        foreach ($absencePolicies as $policy) {
            $thresholdLate = (int) ($policy->late_minutes ?? 0);
            $thresholdEarly = (int) ($policy->early_leave_minutes ?? 0);

            if ($thresholdEarly <= 0 && $thresholdLate > 0) {
                $thresholdEarly = $thresholdLate;
            }

            if ($lateMinutes > 0 && $thresholdLate > 0 && $lateMinutes >= $thresholdLate) {
                return true;
            }

            if ($earlyMinutes > 0 && $thresholdEarly > 0 && $earlyMinutes >= $thresholdEarly) {
                return true;
            }
        }

        // 2. Check if lateness/early departure exceeds the maximum defined minutes_to tier in AttendancePenaltyPolicy
        if ($lateMinutes > 0) {
            $maxLatePolicyMinutes = (int) AttendancePenaltyPolicy::query()
                ->where('saas_company_id', $log->saas_company_id)
                ->where(function ($q) use ($policyId) {
                    $q->where('policy_id', $policyId)->orWhereNull('policy_id');
                })
                ->where('violation_type', 'late_arrival')
                ->where('is_active', true)
                ->where(function ($q) {
                    $q->where('is_enabled', true)->orWhereNull('is_enabled');
                })
                ->where('minutes_to', '>', 0)
                ->where('minutes_to', '<', 1440)
                ->max('minutes_to');

            if ($maxLatePolicyMinutes > 0 && $lateMinutes > $maxLatePolicyMinutes) {
                return true;
            }
        }

        if ($earlyMinutes > 0) {
            $maxEarlyPolicyMinutes = (int) AttendancePenaltyPolicy::query()
                ->where('saas_company_id', $log->saas_company_id)
                ->where(function ($q) use ($policyId) {
                    $q->where('policy_id', $policyId)->orWhereNull('policy_id');
                })
                ->where('violation_type', 'early_departure')
                ->where('is_active', true)
                ->where(function ($q) {
                    $q->where('is_enabled', true)->orWhereNull('is_enabled');
                })
                ->where('minutes_to', '>', 0)
                ->where('minutes_to', '<', 1440)
                ->max('minutes_to');

            if ($maxEarlyPolicyMinutes > 0 && $earlyMinutes > $maxEarlyPolicyMinutes) {
                return true;
            }
        }

        return false;
    }
    private function processViolation(
        AttendanceDailyLog $log,
        int $policyId,
        string $violationType,
        ?object $group = null
    ): bool {
        return DB::transaction(function () use ($log, $policyId, $violationType, $group) {
            $existing = AttendanceDailyPenalty::where([
                'saas_company_id' => $log->saas_company_id,
                'employee_id'     => $log->employee_id,
                'attendance_date' => $log->attendance_date,
                'violation_type'  => $violationType,
            ])->lockForUpdate()->first();

        if (
            in_array($violationType, ['delay', 'early_departure', 'auto_checkout'], true)
            && $this->hasAbsencePenaltyForLog($log)
        ) {
            if ($existing && $existing->status !== 'confirmed') {
                $existing->delete();
            }

            $this->markSkipped('covered_by_absence');

            return false;
        }

        if ($existing && $existing->status === 'confirmed') {
            return false;
        }

        $hasPermission = $this->hasApprovedPermissionForViolation($log, $violationType);

        if ($hasPermission) {
            if ($existing && $existing->status !== 'confirmed') {
                $existing->delete();
            }
            $this->markSkipped('approved_permission');

            return false;
        }

        $policyType = match ($violationType) {
            'delay' => 'late_arrival',
            'early_departure' => 'early_departure',
            'auto_checkout' => 'auto_checkout',
            'absent' => 'unexcused_absence',
            default => null,
        };

        if (! $policyType) {
            return false;
        }

        $minutes = 0;
        if ($violationType === 'delay') {
            $minutes = $this->getLateMinutes($log);
        } elseif ($violationType === 'early_departure') {
            $minutes = $this->getEarlyDepartureMinutes($log);
        }

        if ($minutes === 0 && ! in_array($violationType, ['auto_checkout', 'absent'], true)) {
            return false;
        }

        $startOfMonth = Carbon::parse($log->attendance_date)->startOfMonth()->toDateString();
        $endDate = Carbon::parse($log->attendance_date)->toDateString();

        $recurrenceCount = AttendanceDailyPenalty::where('saas_company_id', $log->saas_company_id)
            ->where('employee_id', $log->employee_id)
            ->where('violation_type', $violationType)
            ->where('attendance_date', '>=', $startOfMonth)
            ->where('attendance_date', '<', $endDate)
            ->count() + 1;

        if ($violationType === 'absent') {
            return $this->processAbsenceViolation($log, $recurrenceCount, $existing);
        }

        $penaltyPolicy = $this->findPenaltyPolicy(
            $log,
            $policyId,
            $violationType,
            $minutes,
            $recurrenceCount
        );

        if (! $penaltyPolicy) {
            return false;
        }

        $action = strtolower((string) $penaltyPolicy->penalty_action);
        if (! in_array($action, ['deduction', 'deduct'], true)) {
            return false;
        }

        $units = 1;
        $threshold = max(0, (int) ($penaltyPolicy->threshold_minutes ?? 0));
        $monthlyGraceUsedBefore = 0;
        $monthlyGraceApplied = 0;
        $billableMinutes = 0;

        if ($violationType !== 'auto_checkout') {
            $interval = max(0, (int) ($penaltyPolicy->interval_minutes ?? 0));
            $dailyExcessMinutes = max(0, $minutes - $threshold);
            $monthlyGraceMinutes = $this->resolveMonthlyGraceMinutes($log, $group);
            $monthlyGraceUsedBefore = $this->calculateMonthlyGraceUsedBefore(
                $log,
                $policyId,
                $monthlyGraceMinutes,
                $group
            );
            $monthlyGraceRemaining = max(0, $monthlyGraceMinutes - $monthlyGraceUsedBefore);
            $monthlyGraceApplied = min($dailyExcessMinutes, $monthlyGraceRemaining);
            $billableMinutes = max(0, $dailyExcessMinutes - $monthlyGraceApplied);

            if ($billableMinutes === 0) {
                if ($existing && $existing->status !== 'confirmed') {
                    $existing->delete();
                }
                $this->markSkipped('covered_by_grace');

                return false;
            }

            if (in_array($violationType, ['delay', 'early_departure'], true) && $interval > 0) {
                $units = (int) ceil($billableMinutes / $interval);
            }
        }

        $amount = 0.0;
        $type = strtolower((string) $penaltyPolicy->deduction_type);

        if (in_array($type, ['fixed', 'fixed_amount'], true)) {
            $amount = ((float) $penaltyPolicy->deduction_value) * $units;
        } elseif (in_array($type, ['percentage', 'percent'], true)) {
            $basicSalary = (float) ($log->employee->basic_salary ?? 0);
            $dailyRate = $basicSalary / 30;
            $scheduledHours = (float) ($log->scheduled_hours ?? 0);
            if ($scheduledHours <= 0) {
                $scheduledHours = 8.0;
            }
            $minuteRate = ($dailyRate / $scheduledHours) / 60;
            $amount = ($minuteRate * (((float) $penaltyPolicy->deduction_value) / 100)) * $units;
        }

        $exceptionalMultiplier = $this->exceptionalDayMultiplierForViolation($log, $violationType);
        $amount *= $exceptionalMultiplier;

        if ($amount <= 0) {
            if ($existing && $existing->status !== 'confirmed') {
                $existing->delete();
            }
            $this->markSkipped('exceptional_day');

            return false;
        }

        $recalculatedState = $this->resolveRecalculatedPenaltyState($existing, (float) $amount);

        AttendanceDailyPenalty::updateOrCreate(
            [
                'saas_company_id' => $log->saas_company_id,
                'employee_id' => $log->employee_id,
                'attendance_date' => $log->attendance_date,
                'violation_type' => $violationType,
            ],
            [
                'attendance_daily_log_id' => $log->id,
                'violation_minutes' => $minutes,
                'penalty_policy_id' => $penaltyPolicy->id,
                'calculated_amount' => round(max(0.00, $amount), 2),
                'exemption_amount' => round(max(0.00, $recalculatedState['exemption_amount']), 2),
                'net_amount' => round(max(0.00, $recalculatedState['net_amount']), 2),
                'status' => $recalculatedState['status'],
                'notes' => ($existing ? $existing->notes : '')
                    . "\n[System] Calculated/Recalculated at " . now()
                    . sprintf(
                        ' | grace daily=%d, monthly_before=%d, monthly_applied=%d, billable=%d, exceptional_multiplier=%.2f',
                        $threshold,
                        $monthlyGraceUsedBefore,
                        $monthlyGraceApplied,
                        $billableMinutes,
                        $exceptionalMultiplier
                    ),
            ]
        );

            return true;
        });
    }

    /**
     * Resolve the penalty policy that supplies the daily grace threshold and
     * the interval/deduction configuration for one attendance violation.
     */
    private function findPenaltyPolicy(
        AttendanceDailyLog $log,
        int $policyId,
        string $violationType,
        int $minutes,
        int $recurrenceCount
    ): ?AttendancePenaltyPolicy {
        $policyType = match ($violationType) {
            'delay' => 'late_arrival',
            'early_departure' => 'early_departure',
            'auto_checkout' => 'auto_checkout',
            default => $violationType,
        };

        $penaltyPolicy = AttendancePenaltyPolicy::findApplicablePenalty(
            $policyId,
            $policyType,
            $minutes,
            $recurrenceCount
        );

        if (! $penaltyPolicy && $policyType !== $violationType) {
            $penaltyPolicy = AttendancePenaltyPolicy::findApplicablePenalty(
                $policyId,
                $violationType,
                $minutes,
                $recurrenceCount
            );
        }

        if ($penaltyPolicy) {
            return $penaltyPolicy;
        }

        return AttendancePenaltyPolicy::query()
            ->where('saas_company_id', $log->saas_company_id)
            ->where('policy_id', $policyId)
            ->where('violation_type', $policyType)
            ->where('is_active', true)
            ->where(function ($query) {
                $query->where('is_enabled', true)->orWhereNull('is_enabled');
            })
            ->where('minutes_from', 0)
            ->where('minutes_to', 0)
            ->where('recurrence_from', '<=', $recurrenceCount)
            ->where(function ($query) use ($recurrenceCount) {
                $query->whereNull('recurrence_to')
                    ->orWhere('recurrence_to', '>=', $recurrenceCount);
            })
            ->first();
    }

    /**
     * The Basic Settings screen stores the shared monthly allowance for late
     * arrival and early departure in late_grace_minutes. A group may override
     * it through its own attendance grace record.
     */
    private function resolveMonthlyGraceMinutes(AttendanceDailyLog $log, ?object $group = null): int
    {
        $graceSetting = null;

        if (
            $group
            && ($group->grace_source ?? 'use_global') === 'custom'
            && ! empty($group->grace_setting_id)
        ) {
            $graceSetting = AttendanceGraceSetting::query()
                ->where('saas_company_id', $log->saas_company_id)
                ->whereKey((int) $group->grace_setting_id)
                ->first();
        }

        if (! $graceSetting) {
            $graceSetting = AttendanceGraceSetting::query()
                ->where('saas_company_id', $log->saas_company_id)
                ->orderBy('id')
                ->first();
        }

        if (! $graceSetting) {
            $graceSetting = AttendanceGraceSetting::globalDefault()->first();
        }

        return max(0, (int) ($graceSetting->late_grace_minutes ?? 0));
    }

    /**
     * Rebuild the amount of shared monthly grace already consumed before this
     * attendance date. Only the portion above each day's own threshold consumes
     * the monthly allowance. The computation is chronological and idempotent,
     * so recalculation does not require a separate balance table.
     */
    private function calculateMonthlyGraceUsedBefore(
        AttendanceDailyLog $currentLog,
        int $policyId,
        int $monthlyGraceMinutes,
        ?object $group = null
    ): int {
        if ($monthlyGraceMinutes <= 0) {
            return 0;
        }

        $currentDate = Carbon::parse($currentLog->attendance_date);
        $monthStart = $currentDate->copy()->startOfMonth()->toDateString();
        $dateBeforeCurrent = $currentDate->copy()->subDay()->toDateString();

        if ($dateBeforeCurrent < $monthStart) {
            return 0;
        }

        $priorLogs = AttendanceDailyLog::forCompany($currentLog->saas_company_id)
            ->with(['employee' => fn ($q) => $q->withoutGlobalScope('active_only')])
            ->where('employee_id', $currentLog->employee_id)
            ->whereBetween('attendance_date', [$monthStart, $dateBeforeCurrent])
            ->orderBy('attendance_date')
            ->orderBy('id')
            ->get();

        if ($priorLogs->isEmpty()) {
            return 0;
        }

        $approvedPermissionDates = \Athka\Attendance\Models\AttendancePermissionRequest::query()
            ->where('employee_id', $currentLog->employee_id)
            ->where('status', 'approved')
            ->whereBetween('permission_date', [$monthStart, $dateBeforeCurrent])
            ->pluck('permission_date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->flip();

        $used = 0;

        foreach ($priorLogs as $priorLog) {
            if ($used >= $monthlyGraceMinutes) {
                break;
            }

            $priorDate = Carbon::parse($priorLog->attendance_date)->toDateString();

            if ($approvedPermissionDates->has($priorDate)) {
                continue;
            }

            $violationType = $this->resolveMonthlyGraceViolationType($priorLog, $policyId);

            if (! $violationType) {
                continue;
            }

            $minutes = $violationType === 'delay'
                ? $this->getLateMinutes($priorLog)
                : $this->getEarlyDepartureMinutes($priorLog);

            if ($minutes <= 0) {
                continue;
            }

            $recurrenceCount = AttendanceDailyPenalty::query()
                ->where('saas_company_id', $priorLog->saas_company_id)
                ->where('employee_id', $priorLog->employee_id)
                ->where('violation_type', $violationType)
                ->where('attendance_date', '>=', $monthStart)
                ->where('attendance_date', '<', $priorDate)
                ->count() + 1;

            $priorPolicy = $this->findPenaltyPolicy(
                $priorLog,
                $policyId,
                $violationType,
                $minutes,
                $recurrenceCount
            );

            if (! $priorPolicy) {
                continue;
            }

            $action = strtolower((string) $priorPolicy->penalty_action);

            if (! in_array($action, ['deduction', 'deduct'], true)) {
                continue;
            }

            $dailyThreshold = max(
                0,
                (int) ($priorPolicy->threshold_minutes ?? 0)
            );
            $consumableMinutes = max(0, $minutes - $dailyThreshold);

            if ($consumableMinutes === 0) {
                continue;
            }

            $used += min(
                $monthlyGraceMinutes - $used,
                $consumableMinutes
            );
        }

        return min($monthlyGraceMinutes, $used);
    }

    /**
     * Decide which monthly-grace category a previous log consumes. Late cases
     * converted to absence are intentionally excluded from monthly grace.
     */
    private function resolveMonthlyGraceViolationType(
        AttendanceDailyLog $log,
        int $policyId
    ): ?string {
        $earlyMinutes = $this->getEarlyDepartureMinutes($log);
        $lateMinutes = $this->getLateMinutes($log);

        if (
            $log->attendance_status === 'early_departure'
            || (
                ! in_array($log->attendance_status, ['absent', 'auto_checkout'], true)
                && $earlyMinutes > 0
            )
        ) {
            if ($earlyMinutes <= 0) {
                return null;
            }

            $convertedToAbsence = $this->shouldConvertLateEarlyToAbsence($log, $policyId, 0, $earlyMinutes);

            return $convertedToAbsence ? null : 'early_departure';
        }

        if (
            $log->attendance_status === 'late'
            || (
                ! in_array($log->attendance_status, ['absent', 'auto_checkout'], true)
                && $lateMinutes > 0
            )
        ) {
            if ($lateMinutes <= 0) {
                return null;
            }

            $convertedToAbsence = $this->shouldConvertLateEarlyToAbsence($log, $policyId, $lateMinutes, 0);

            return $convertedToAbsence ? null : 'delay';
        }

        return null;
    }

    private function processAbsenceViolation(AttendanceDailyLog $log, int $recurrenceCount, ?AttendanceDailyPenalty $existing): bool
    {
        $group = DB::table('employee_group_members')
            ->join('employee_groups', 'employee_group_members.group_id', '=', 'employee_groups.id')
            ->where('employee_group_members.employee_id', $log->employee_id)
            ->select('employee_groups.applied_policy_id')
            ->first();

        $policyId = $group
            ? (int) $group->applied_policy_id
            : (int) AttendancePolicy::where('saas_company_id', $log->saas_company_id)->where('is_default', true)->value('id');

        if (! $policyId) {
            return false;
        }

        $absencePolicy = null;
        $lateMinutes = $this->getLateMinutes($log);
        $earlyMinutes = $this->getEarlyDepartureMinutes($log);

        if ($this->shouldConvertLateEarlyToAbsence($log, $policyId, $lateMinutes, $earlyMinutes)) {
            $absencePolicy = UnexcusedAbsencePolicy::query()
                ->where('saas_company_id', $log->saas_company_id)
                ->where('policy_id', $policyId)
                ->where('absence_reason_type', 'late_early')
                ->where('is_active', true)
                ->where(function ($q) {
                    $q->where('is_enabled', true)->orWhereNull('is_enabled');
                })
                ->orderByDesc('id')
                ->first();
        }

        if (! $absencePolicy) {
            $absencePolicy = UnexcusedAbsencePolicy::query()
                ->where('saas_company_id', $log->saas_company_id)
                ->where('policy_id', $policyId)
                ->where('absence_reason_type', 'no_notice')
                ->where('is_active', true)
                ->where(function ($q) {
                    $q->where('is_enabled', true)->orWhereNull('is_enabled');
                })
                ->where('day_from', '<=', $recurrenceCount)
                ->where(function ($q) use ($recurrenceCount) {
                    $q->whereNull('day_to')->orWhere('day_to', '>=', $recurrenceCount);
                })
                ->orderByDesc('day_from')
                ->first();
        }

        if (! $absencePolicy) {
            return false;
        }

        $action = strtolower((string) $absencePolicy->penalty_action);
        if (! in_array($action, ['deduction', 'deduct'], true)) {
            return false;
        }

        $amount = $this->calculateDeductionAmount(
            $log,
            (string) $absencePolicy->deduction_type,
            (float) $absencePolicy->deduction_value
        );

        $exceptionalMultiplier = $this->exceptionalDayMultiplierForViolation($log, 'absent');
        $amount *= $exceptionalMultiplier;

        if ($amount <= 0) {
            if ($existing && $existing->status !== 'confirmed') {
                $existing->delete();
            }
            $this->markSkipped('exceptional_day');

            return false;
        }

        $recalculatedState = $this->resolveRecalculatedPenaltyState($existing, (float) $amount);

        AttendanceDailyPenalty::updateOrCreate(
            [
                'saas_company_id' => $log->saas_company_id,
                'employee_id' => $log->employee_id,
                'attendance_date' => $log->attendance_date,
                'violation_type' => 'absent',
            ],
            [
                'attendance_daily_log_id' => $log->id,
                'violation_minutes' => 0,
                'penalty_policy_id' => null,
                'calculated_amount' => $amount,
                'exemption_amount' => $recalculatedState['exemption_amount'],
                'net_amount' => $recalculatedState['net_amount'],
                'status' => $recalculatedState['status'],
                'notes' => ($existing ? $existing->notes : '') . "\n[System] Calculated/Recalculated absence penalty at " . now()
                    . sprintf(' | exceptional_multiplier=%.2f', $exceptionalMultiplier),
            ]
        );

        return true;
    }

    private function resolveRecalculatedPenaltyState(?AttendanceDailyPenalty $existing, float $calculatedAmount): array
    {
        $calculatedAmount = (float) sprintf('%.2f', max(0.00, $calculatedAmount));

        if (
            ! $existing
            || (string) $existing->exemption_status !== 'approved'
            || (float) $existing->exemption_amount <= 0
        ) {
            return [
                'exemption_amount' => 0.00,
                'net_amount' => $calculatedAmount,
                'status' => 'pending',
            ];
        }

        $exemptionType = strtolower((string) $existing->exemption_type);
        $existingExemptionAmount = round(max(0.00, (float) $existing->exemption_amount), 2);
        $exemptionAmount = $exemptionType === 'full'
            || (string) $existing->status === 'waived'
                ? $calculatedAmount
                : min($existingExemptionAmount, $calculatedAmount);
        $exemptionAmount = round($exemptionAmount, 2);
        $netAmount = round(max(0.00, $calculatedAmount - $exemptionAmount), 2);

        return [
            'exemption_amount' => $exemptionAmount,
            'net_amount' => $netAmount,
            'status' => $netAmount <= 0 ? 'waived' : 'pending',
        ];
    }

    private function hasApprovedPermissionForViolation(AttendanceDailyLog $log, string $violationType): bool
    {
        if ($violationType === 'absent' && ! $this->hasAnyActualAttendance($log)) {
            return false;
        }

        if ($violationType === 'delay' && $this->getLateMinutes($log) > 0) {
            return false;
        }

        if ($violationType === 'early_departure' && $this->getEarlyDepartureMinutes($log) > 0) {
            return false;
        }

        return \Athka\Attendance\Models\AttendancePermissionRequest::where('company_id', $log->saas_company_id)
            ->where('employee_id', $log->employee_id)
            ->where('permission_date', $log->attendance_date)
            ->where('status', 'approved')
            ->exists();
    }

    private function hasAnyActualAttendance(AttendanceDailyLog $log): bool
    {
        return ! empty($log->check_in_time) || ! empty($log->check_out_time);
    }

    private function calculateDeductionAmount(AttendanceDailyLog $log, string $type, float $value, int $units = 1): float
    {
        $type = strtolower($type);

        if (in_array($type, ['fixed', 'fixed_amount'], true)) {
            return $value * $units;
        }

        if (in_array($type, ['percentage', 'percent'], true)) {
            $dailyRate = ((float) ($log->employee->basic_salary ?? 0)) / 30;
            return ($dailyRate * ($value / 100)) * $units;
        }

        return 0.0;
    }

    private function getLateMinutes(AttendanceDailyLog $log): int
    {
        $detailMinutes = $this->sumDetailLateMinutes($log);
        $totalLateMinutes = 0;

        if ($detailMinutes > 0) {
            $totalLateMinutes = $detailMinutes;
        } else {
            $s = $this->parseTimeOnDate($log->attendance_date, $log->scheduled_check_in);
            $a = $this->parseTimeOnDate($log->attendance_date, $log->check_in_time);

            if ($s && $a) {
                $totalLateMinutes = max(0, $s->diffInMinutes($a, false));
            }
        }

        if ($totalLateMinutes <= 0) {
            return 0;
        }

        $coveredPermissionMinutes = $this->getApprovedPermissionCoveredMinutes($log, 'late');

        return max(0, $totalLateMinutes - $coveredPermissionMinutes);
    }

    private function getEarlyDepartureMinutes(AttendanceDailyLog $log): int
    {
        $detailMinutes = $this->sumDetailEarlyDepartureMinutes($log);
        $totalEarlyMinutes = 0;

        if ($detailMinutes > 0) {
            $totalEarlyMinutes = $detailMinutes;
        } else {
            $s = $this->parseTimeOnDate($log->attendance_date, $log->scheduled_check_out);
            $a = $this->parseTimeOnDate($log->attendance_date, $log->check_out_time);

            if ($s && $a) {
                $totalEarlyMinutes = max(0, $a->diffInMinutes($s, false));
            }
        }

        if ($totalEarlyMinutes <= 0) {
            return 0;
        }

        $coveredPermissionMinutes = $this->getApprovedPermissionCoveredMinutes($log, 'early');

        return max(0, $totalEarlyMinutes - $coveredPermissionMinutes);
    }

    private function getApprovedPermissionCoveredMinutes(AttendanceDailyLog $log, string $type): int
    {
        $permissions = \Athka\Attendance\Models\AttendancePermissionRequest::where('company_id', $log->saas_company_id)
            ->where('employee_id', $log->employee_id)
            ->where('permission_date', $log->attendance_date)
            ->where('status', 'approved')
            ->get();

        if ($permissions->isEmpty()) {
            return 0;
        }

        $coveredMinutes = 0;

        foreach ($permissions as $perm) {
            $permMinutes = (int) ($perm->minutes ?? 0);

            if ($type === 'late') {
                $schIn = $this->parseTimeOnDate($log->attendance_date, $log->scheduled_check_in);
                $actIn = $this->parseTimeOnDate($log->attendance_date, $log->check_in_time);
                $pFrom = $this->parseTimeOnDate($log->attendance_date, $perm->from_time);
                $pTo   = $this->parseTimeOnDate($log->attendance_date, $perm->to_time);

                if ($schIn && $actIn && $pFrom && $pTo) {
                    $overlapStart = $schIn->gt($pFrom) ? $schIn : $pFrom;
                    $overlapEnd   = $actIn->lt($pTo)   ? $actIn : $pTo;

                    if ($overlapEnd->gt($overlapStart)) {
                        $coveredMinutes += (int) $overlapStart->diffInMinutes($overlapEnd);
                        continue;
                    }
                }
            } elseif ($type === 'early') {
                $actOut = $this->parseTimeOnDate($log->attendance_date, $log->check_out_time);
                $schOut = $this->parseTimeOnDate($log->attendance_date, $log->scheduled_check_out);
                $pFrom  = $this->parseTimeOnDate($log->attendance_date, $perm->from_time);
                $pTo    = $this->parseTimeOnDate($log->attendance_date, $perm->to_time);

                if ($schOut && $actOut && $pFrom && $pTo) {
                    $overlapStart = $actOut->gt($pFrom) ? $actOut : $pFrom;
                    $overlapEnd   = $schOut->lt($pTo)   ? $schOut : $pTo;

                    if ($overlapEnd->gt($overlapStart)) {
                        $coveredMinutes += (int) $overlapStart->diffInMinutes($overlapEnd);
                        continue;
                    }
                }
            }

            $coveredMinutes += $permMinutes;
        }

        return $coveredMinutes;
    }

    private function resolvePenaltyStatus(AttendanceDailyLog $log): string
    {
        if (in_array($log->attendance_status, ['absent', 'auto_checkout'], true)) {
            return (string) $log->attendance_status;
        }

        // The attendance status is descriptive. Penalty eligibility must start
        // from the raw deviation, then apply daily and monthly grace inside the
        // penalty calculation. Otherwise a fixed status-level grace can hide a
        // violation after the employee has already consumed the monthly pool.
        if ($this->getEarlyDepartureMinutes($log) > 0) {
            return 'early_departure';
        }

        if ($this->getLateMinutes($log) > 0) {
            return 'late';
        }

        return (string) $log->attendance_status;
    }

    private function sumDetailLateMinutes(AttendanceDailyLog $log): int
    {
        return $this->sumDetailViolationMinutes($log, 'late');
    }

    private function sumDetailEarlyDepartureMinutes(AttendanceDailyLog $log): int
    {
        return $this->sumDetailViolationMinutes($log, 'early_departure');
    }

    private function sumDetailViolationMinutes(AttendanceDailyLog $log, string $type): int
    {
        $details = DB::table('attendance_daily_details')
            ->where('daily_log_id', $log->id)
            ->get();

        if ($details->isEmpty()) {
            return 0;
        }

        $periodIds = $details->pluck('work_schedule_period_id')->filter()->unique()->values();
        if ($periodIds->isEmpty()) {
            return 0;
        }

        $periods = DB::table('work_schedule_periods')
            ->whereIn('id', $periodIds)
            ->get()
            ->keyBy('id');

        $total = 0;

        foreach ($details as $detail) {
            if (! $detail->work_schedule_period_id || ! isset($periods[$detail->work_schedule_period_id])) {
                continue;
            }

            $period = $periods[$detail->work_schedule_period_id];

            if ($type === 'late') {
                $scheduled = $this->parseTimeOnDate($log->attendance_date, $period->start_time);
                $actual = $this->parseTimeOnDate($log->attendance_date, $detail->check_in_time);
                if ($scheduled && $actual) {
                    $total += max(0, $scheduled->diffInMinutes($actual, false));
                }
            }

            if ($type === 'early_departure') {
                $scheduledStart = $this->parseTimeOnDate($log->attendance_date, $period->start_time);
                $scheduledEnd = $this->parseTimeOnDate($log->attendance_date, $period->end_time);
                $actual = $this->parseTimeOnDate($log->attendance_date, $detail->check_out_time);

                if ($scheduledStart && $scheduledEnd && $actual) {
                    if ((bool) $period->is_night_shift || $scheduledEnd->lt($scheduledStart)) {
                        $scheduledEnd->addDay();
                    }

                    $total += max(0, $actual->diffInMinutes($scheduledEnd, false));
                }
            }
        }

        return $total;
    }

    private function calendarPenaltySkipReason(AttendanceDailyLog $log): ?string
    {
        $employee = $log->employee;
        if (! $employee) {
            return null;
        }

        $dateStr = $log->attendance_date instanceof \DateTimeInterface
            ? Carbon::instance($log->attendance_date)->toDateString()
            : Carbon::parse($log->attendance_date)->toDateString();

        $scheduleService = app(WorkScheduleService::class);
        $exceptionalDay = $scheduleService->getExceptionalDay((int) $log->saas_company_id, $dateStr, $employee);

        if ($exceptionalDay) {
            $isHoliday = isset($exceptionalDay->is_holiday)
                ? (bool) $exceptionalDay->is_holiday
                : ((float) ($exceptionalDay->absence_multiplier ?? 1) <= 0 && (float) ($exceptionalDay->late_multiplier ?? 1) <= 0);

            if ($isHoliday) {
                return (bool) ($exceptionalDay->is_official_holiday ?? false)
                    ? 'official_holiday'
                    : 'exceptional_day';
            }
        }

        $holidays = $scheduleService->getHolidays((int) $log->saas_company_id, $dateStr, $dateStr);
        if ($holidays->isNotEmpty()) {
            return 'official_holiday';
        }

        $schedule = $scheduleService->getEffectiveSchedule((int) $log->saas_company_id, $employee, $dateStr);
        if (! $schedule) {
            return 'no_effective_schedule';
        }

        $metrics = $scheduleService->getMetricsForDate($dateStr, $schedule, $holidays, $employee);

        if (($metrics['status'] ?? null) !== 'holiday' && ! (bool) ($metrics['is_holiday'] ?? false)) {
            return null;
        }

        return $holidays->isNotEmpty() ? 'official_holiday' : 'exceptional_day';
    }

    private function deleteUnconfirmedPenaltiesForLog(AttendanceDailyLog $log): void
    {
        AttendanceDailyPenalty::where('saas_company_id', $log->saas_company_id)
            ->where('employee_id', $log->employee_id)
            ->where('attendance_date', $log->attendance_date)
            ->where('status', '!=', 'confirmed')
            ->delete();
    }

    private function deletePenaltiesOutsideResolvedTarget(AttendanceDailyLog $log, array $targetViolationTypes): void
    {
        $query = AttendanceDailyPenalty::where('saas_company_id', $log->saas_company_id)
            ->where('employee_id', $log->employee_id)
            ->where('attendance_date', $log->attendance_date);

        if (empty($targetViolationTypes)) {
            $query->where('status', '!=', 'confirmed')->delete();

            return;
        }

        $query->whereNotIn('violation_type', $targetViolationTypes);

        if (in_array('absent', $targetViolationTypes, true)) {
            $query->whereIn('violation_type', ['delay', 'early_departure', 'auto_checkout'])->delete();

            return;
        }

        $query->where('status', '!=', 'confirmed')->delete();
    }

    private function hasAbsencePenaltyForLog(AttendanceDailyLog $log): bool
    {
        return AttendanceDailyPenalty::where('saas_company_id', $log->saas_company_id)
            ->where('employee_id', $log->employee_id)
            ->where('attendance_date', $log->attendance_date)
            ->where('violation_type', 'absent')
            ->exists();
    }

    private function exceptionalDayMultiplierForViolation(AttendanceDailyLog $log, string $violationType): float
    {
        $employee = $log->employee;
        if (! $employee) {
            return 1.0;
        }

        $dateStr = $log->attendance_date instanceof \DateTimeInterface
            ? Carbon::instance($log->attendance_date)->toDateString()
            : Carbon::parse($log->attendance_date)->toDateString();

        $exceptionalDay = app(WorkScheduleService::class)
            ->getExceptionalDay((int) $log->saas_company_id, $dateStr, $employee);

        if (! $exceptionalDay) {
            return 1.0;
        }

        $multiplier = match ($violationType) {
            'absent' => $exceptionalDay->absence_multiplier ?? 1,
            'delay', 'early_departure', 'auto_checkout' => $exceptionalDay->late_multiplier ?? 1,
            default => 1,
        };

        return max(0.0, (float) $multiplier);
    }

    private function markSkipped(string $reason): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            return;
        }

        $this->skipReasons[$reason] = ($this->skipReasons[$reason] ?? 0) + 1;
    }

    private function parseTimeOnDate($date, $time): ?Carbon
    {
        if (blank($date) || blank($time)) {
            return null;
        }

        $d = $date instanceof \DateTimeInterface ? Carbon::instance($date) : Carbon::parse($date);

        if ($time instanceof \DateTimeInterface) {
            $hm = Carbon::instance($time)->format('H:i');
        } else {
            $t = (string) $time;
            $hm = strlen($t) >= 5 ? substr($t, 0, 5) : $t;
        }

        return Carbon::parse($d->format('Y-m-d') . ' ' . $hm);
    }
}
