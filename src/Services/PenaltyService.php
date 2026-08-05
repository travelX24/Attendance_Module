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
    /**
     * Run penalty calculation for one specific day.
     *
     * @return array{processed:int,created:int}
     */
    public function calculateForDate($date, $companyId, array $employeeIds = []): array
    {
        return $this->calculateForRange($date, $date, $companyId, $employeeIds);
    }

    /**
     * Run penalty calculation for a specific date range.
     *
     * @return array{processed:int,created:int}
     */
    public function calculateForRange($dateFrom, $dateTo, $companyId, array $employeeIds = []): array
    {
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
            ->with('employee')
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

        return ['processed' => $processed, 'created' => $createdOrUpdated];
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

        $status = $this->resolvePenaltyStatus($log);

        $targetViolationType = match ($status) {
            'late' => 'delay',
            'early_departure' => 'early_departure',
            'absent' => 'absent',
            'auto_checkout' => 'auto_checkout',
            default => null,
        };

        if ($targetViolationType === 'delay') {
            $lateMinutes = $this->getLateMinutes($log);
            $lateAbsencePolicy = UnexcusedAbsencePolicy::query()
                ->where('saas_company_id', $log->saas_company_id)
                ->where('policy_id', $policyId)
                ->where('absence_reason_type', 'late_early')
                ->where('is_active', true)
                ->where(function ($q) {
                    $q->where('is_enabled', true)->orWhereNull('is_enabled');
                })
                ->where('late_minutes', '>', 0)
                ->where('late_minutes', '<=', $lateMinutes)
                ->first();

            if ($lateAbsencePolicy) {
                $targetViolationType = 'absent';
            }
        }

        // Delete any existing pending penalties for this employee on this date that do not match the target violation type
        AttendanceDailyPenalty::where('saas_company_id', $log->saas_company_id)
            ->where('employee_id', $log->employee_id)
            ->where('attendance_date', $log->attendance_date)
            ->where('status', '!=', 'confirmed')
            ->when($targetViolationType, fn ($q) => $q->where('violation_type', '!=', $targetViolationType))
            ->delete();

        if (! $targetViolationType) {
            return false;
        }

        if ($targetViolationType === 'auto_checkout') {
            $grace = \Athka\SystemSettings\Models\AttendanceGraceSetting::where('saas_company_id', $log->saas_company_id)->first();
            if (! ($grace && (bool) $grace->auto_checkout_penalty_enabled)) {
                return false;
            }
        }

        return $this->processViolation($log, $policyId, $targetViolationType, $group);
    }

    /**
     * @return bool true if penalty saved/updated
     */
    private function processViolation(AttendanceDailyLog $log, int $policyId, string $violationType, ?object $group = null): bool
    {
        $existing = AttendanceDailyPenalty::where([
            'saas_company_id' => $log->saas_company_id,
            'employee_id' => $log->employee_id,
            'attendance_date' => $log->attendance_date,
            'violation_type' => $violationType,
        ])->first();

        if ($existing && $existing->status === 'confirmed') {
            return false;
        }

        $hasPermission = \Athka\Attendance\Models\AttendancePermissionRequest::where('employee_id', $log->employee_id)
            ->where('permission_date', $log->attendance_date)
            ->where('status', 'approved')
            ->exists();

        if ($hasPermission) {
            if ($existing && $existing->status !== 'confirmed') {
                $existing->delete();
            }
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

        $threshold = max(0, (int) ($penaltyPolicy->threshold_minutes ?? 0));
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

            return false;
        }

        $units = 1;

        if (in_array($violationType, ['delay', 'early_departure'], true) && $interval > 0) {
            $units = (int) ceil($billableMinutes / $interval);
        }

        $amount = 0.0;
        $type = strtolower((string) $penaltyPolicy->deduction_type);

        if (in_array($type, ['fixed', 'fixed_amount'], true)) {
            $amount = ((float) $penaltyPolicy->deduction_value) * $units;
        } elseif (in_array($type, ['percentage', 'percent'], true)) {
            $dailyRate = ((float) ($log->employee->basic_salary ?? 0)) / 30;
            $amount = ($dailyRate * (((float) $penaltyPolicy->deduction_value) / 100)) * $units;
        }

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
                'calculated_amount' => $amount,
                'net_amount' => $amount,
                'status' => 'pending',
                'notes' => ($existing ? $existing->notes : '')
                    . "\n[System] Calculated/Recalculated at " . now()
                    . sprintf(
                        ' | grace daily=%d, monthly_before=%d, monthly_applied=%d, billable=%d',
                        $threshold,
                        $monthlyGraceUsedBefore,
                        $monthlyGraceApplied,
                        $billableMinutes
                    ),
            ]
        );

        return true;
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
            ->with('employee')
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
            return $earlyMinutes > 0 ? 'early_departure' : null;
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

            $convertedToAbsence = UnexcusedAbsencePolicy::query()
                ->where('saas_company_id', $log->saas_company_id)
                ->where('policy_id', $policyId)
                ->where('absence_reason_type', 'late_early')
                ->where('is_active', true)
                ->where(function ($query) {
                    $query->where('is_enabled', true)->orWhereNull('is_enabled');
                })
                ->where('late_minutes', '>', 0)
                ->where('late_minutes', '<=', $lateMinutes)
                ->exists();

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
                'net_amount' => $amount,
                'status' => 'pending',
                'notes' => ($existing ? $existing->notes : '') . "\n[System] Calculated/Recalculated absence penalty at " . now(),
            ]
        );

        return true;
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
        if ($detailMinutes > 0) {
            return $detailMinutes;
        }

        $s = $this->parseTimeOnDate($log->attendance_date, $log->scheduled_check_in);
        $a = $this->parseTimeOnDate($log->attendance_date, $log->check_in_time);

        if (! $s || ! $a) {
            return 0;
        }

        return max(0, $s->diffInMinutes($a, false));
    }

    private function getEarlyDepartureMinutes(AttendanceDailyLog $log): int
    {
        $detailMinutes = $this->sumDetailEarlyDepartureMinutes($log);
        if ($detailMinutes > 0) {
            return $detailMinutes;
        }

        $s = $this->parseTimeOnDate($log->attendance_date, $log->scheduled_check_out);
        $a = $this->parseTimeOnDate($log->attendance_date, $log->check_out_time);

        if (! $s || ! $a) {
            return 0;
        }

        return max(0, $a->diffInMinutes($s, false));
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
