<?php

namespace Athka\Attendance\Services;

use Athka\Attendance\Models\AttendanceDailyLog;
use Athka\Attendance\Models\AttendanceDailyPenalty;
use Athka\Employees\Models\Employee;
use Athka\SystemSettings\Models\AttendancePolicy;
use Athka\SystemSettings\Models\AttendancePenaltyPolicy;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PenaltyService
{
    /**
     * Run penalty calculation for one specific day.
     *
     * @return array{processed:int,created:int}
     */
    public function calculateForDate($date, $companyId): array
    {
        return $this->calculateForRange($date, $date, $companyId);
    }

    /**
     * Run penalty calculation for a specific date range.
     *
     * @return array{processed:int,created:int}
     */
    public function calculateForRange($dateFrom, $dateTo, $companyId): array
    {
        $this->prepareAbsentLogs($dateFrom, $dateTo, $companyId);

        $logs = AttendanceDailyLog::forCompany($companyId)
            ->whereBetween('attendance_date', [$dateFrom, $dateTo])
            ->whereIn('attendance_status', ['late', 'early_departure', 'absent', 'auto_checkout'])
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
    private function prepareAbsentLogs($dateFrom, $dateTo, $companyId): void
    {
        $start = Carbon::parse($dateFrom);
        $end = Carbon::parse($dateTo);
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $dateStr = $cursor->toDateString();

            $activeEmployees = Employee::forCompany($companyId)
                ->where('status', 'active')
                ->get();

            foreach ($activeEmployees as $employee) {
                $exists = AttendanceDailyLog::where([
                    'saas_company_id' => $companyId,
                    'employee_id' => $employee->id,
                    'attendance_date' => $dateStr,
                ])->exists();

                if ($exists) {
                    continue;
                }

                $hasSchedule = \Athka\Attendance\Models\EmployeeWorkSchedule::where('employee_id', $employee->id)
                    ->where('is_active', true)
                    ->where('saas_company_id', $companyId)
                    ->where('start_date', '<=', $dateStr)
                    ->where(fn ($q) => $q->whereNull('end_date')->orWhere('end_date', '>=', $dateStr))
                    ->first();

                if (! $hasSchedule) {
                    continue;
                }

                AttendanceDailyLog::create([
                    'saas_company_id' => $companyId,
                    'employee_id' => $employee->id,
                    'attendance_date' => $dateStr,
                    'attendance_status' => 'absent',
                    'approval_status' => 'pending',
                    'work_schedule_id' => $hasSchedule->work_schedule_id,
                ]);
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
            ->select('employee_groups.applied_policy_id')
            ->first();

        $policyId = $group
            ? (int) $group->applied_policy_id
            : (int) AttendancePolicy::where('saas_company_id', $log->saas_company_id)->where('is_default', true)->value('id');

        if (! $policyId) {
            return false;
        }

        $created = false;

        if ($log->attendance_status === 'late') {
            $created = $this->processViolation($log, $policyId, 'delay') || $created;
        }

        if ($log->attendance_status === 'early_departure') {
            $created = $this->processViolation($log, $policyId, 'early_departure') || $created;
        }

        if ($log->attendance_status === 'absent') {
            $created = $this->processViolation($log, $policyId, 'absent') || $created;
        }

        if ($log->attendance_status === 'auto_checkout') {
            $created = $this->processViolation($log, $policyId, 'auto_checkout') || $created;
        }

        return $created;
    }

    /**
     * @return bool true if penalty saved/updated
     */
    private function processViolation(AttendanceDailyLog $log, int $policyId, string $violationType): bool
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

        if (Carbon::parse($log->attendance_date)->diffInDays(now()) > 7) {
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
            'absent' => 'absence',
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
            ->whereBetween('attendance_date', [$startOfMonth, $endDate])
            ->count() + 1;

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

        if (! $penaltyPolicy) {
            $penaltyPolicy = AttendancePenaltyPolicy::query()
                ->where('saas_company_id', $log->saas_company_id)
                ->where('policy_id', $policyId)
                ->where('violation_type', $policyType)
                ->where(function ($q) {
                    $q->where('is_enabled', true)->orWhere('is_active', true);
                })
                ->where('minutes_from', 0)
                ->where('minutes_to', 0)
                ->where('recurrence_from', '<=', $recurrenceCount)
                ->where(function ($q) use ($recurrenceCount) {
                    $q->whereNull('recurrence_to')->orWhere('recurrence_to', '>=', $recurrenceCount);
                })
                ->first();
        }

        if (! $penaltyPolicy) {
            return false;
        }

        $action = strtolower((string) $penaltyPolicy->penalty_action);
        if (! in_array($action, ['deduction', 'deduct'], true)) {
            return false;
        }

        $threshold = (int) ($penaltyPolicy->threshold_minutes ?? 0);
        $interval = (int) ($penaltyPolicy->interval_minutes ?? 0);

        $billableMinutes = max(0, $minutes - $threshold);
        $units = 1;

        if (in_array($violationType, ['delay', 'early_departure'], true) && $interval > 0) {
            $units = (int) ceil($billableMinutes / $interval);
            $units = max(1, $units);
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
                'notes' => ($existing ? $existing->notes : '') . "\n[System] Calculated/Recalculated at " . now(),
            ]
        );

        return true;
    }

    private function getLateMinutes(AttendanceDailyLog $log): int
    {
        $s = $this->parseTimeOnDate($log->attendance_date, $log->scheduled_check_in);
        $a = $this->parseTimeOnDate($log->attendance_date, $log->check_in_time);

        if (! $s || ! $a) {
            return 0;
        }

        return max(0, $s->diffInMinutes($a, false));
    }

    private function getEarlyDepartureMinutes(AttendanceDailyLog $log): int
    {
        $s = $this->parseTimeOnDate($log->attendance_date, $log->scheduled_check_out);
        $a = $this->parseTimeOnDate($log->attendance_date, $log->check_out_time);

        if (! $s || ! $a) {
            return 0;
        }

        return max(0, $a->diffInMinutes($s, false));
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