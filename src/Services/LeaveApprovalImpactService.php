<?php

namespace Athka\Attendance\Services;

use Athka\Attendance\Models\AttendanceDailyLog;
use Athka\Attendance\Models\AttendanceDailyPenalty;
use Athka\Attendance\Models\AttendanceLeaveBalance;
use Athka\Attendance\Models\AttendanceLeaveRequest;
use Athka\Employees\Models\Employee;
use Athka\SystemSettings\Models\LeavePolicy;
use Carbon\Carbon;

class LeaveApprovalImpactService
{
    public function apply(AttendanceLeaveRequest $leave): void
    {
        if ($leave->status === 'approved') {
            $this->syncAttendanceLogs($leave);
        } else {
            $this->restoreAttendanceLogs($leave);
        }

        $this->recalculateBalance($leave);
    }

    public function syncApprovedLeavesForRange(int $companyId, Carbon|string $start, Carbon|string $end, array $employeeIds = []): void
    {
        $startDate = $start instanceof Carbon ? $start->toDateString() : Carbon::parse($start)->toDateString();
        $endDate = $end instanceof Carbon ? $end->toDateString() : Carbon::parse($end)->toDateString();

        $query = AttendanceLeaveRequest::query()
            ->where('company_id', $companyId)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $endDate)
            ->whereDate('end_date', '>=', $startDate);

        if (!empty($employeeIds)) {
            $query->whereIn('employee_id', $employeeIds);
        }

        $query->get()->each(fn (AttendanceLeaveRequest $leave) => $this->apply($leave));
    }

    protected function syncAttendanceLogs(AttendanceLeaveRequest $leave): void
    {
        foreach ($this->leaveDates($leave) as $dateStr) {
            AttendanceDailyLog::updateOrCreate(
                [
                    'saas_company_id' => $leave->company_id,
                    'employee_id' => $leave->employee_id,
                    'attendance_date' => $dateStr,
                ],
                [
                    'attendance_status' => 'on_leave',
                    'approval_status' => 'approved',
                ]
            );

            AttendanceDailyPenalty::where('saas_company_id', $leave->company_id)
                ->where('employee_id', $leave->employee_id)
                ->where('attendance_date', $dateStr)
                ->where('violation_type', 'absent')
                ->delete();
        }
    }

    protected function restoreAttendanceLogs(AttendanceLeaveRequest $leave): void
    {
        foreach ($this->leaveDates($leave) as $dateStr) {
            $log = AttendanceDailyLog::where('saas_company_id', $leave->company_id)
                ->where('employee_id', $leave->employee_id)
                ->where('attendance_date', $dateStr)
                ->where('attendance_status', 'on_leave')
                ->first();

            if (!$log) {
                continue;
            }

            if ($log->check_in_time) {
                $log->calculateStatus();
                $log->save();
            } else {
                $log->update([
                    'attendance_status' => 'absent',
                    'approval_status' => 'pending',
                ]);
            }
        }
    }

    public function recalculateBalance(AttendanceLeaveRequest $leave): void
    {
        if (empty($leave->leave_policy_id) || empty($leave->policy_year_id)) {
            return;
        }

        $policy = LeavePolicy::query()
            ->where('company_id', $leave->company_id)
            ->find($leave->leave_policy_id);

        if (!$policy) {
            return;
        }

        $employee = Employee::withoutGlobalScope('active_only')->find($leave->employee_id);
        $entitled = $this->entitledDays($policy, $employee);

        $taken = (float) AttendanceLeaveRequest::query()
            ->where('company_id', $leave->company_id)
            ->where('employee_id', $leave->employee_id)
            ->where('leave_policy_id', $leave->leave_policy_id)
            ->where('policy_year_id', $leave->policy_year_id)
            ->where('status', 'approved')
            ->sum('requested_days');

        AttendanceLeaveBalance::updateOrCreate(
            [
                'company_id' => $leave->company_id,
                'employee_id' => $leave->employee_id,
                'leave_policy_id' => $leave->leave_policy_id,
                'policy_year_id' => $leave->policy_year_id,
            ],
            [
                'entitled_days' => $entitled,
                'taken_days' => $taken,
                'remaining_days' => max($entitled - $taken, 0),
                'last_recalculated_at' => now(),
            ]
        );
    }

    protected function entitledDays(LeavePolicy $policy, ?Employee $employee): float
    {
        $entitled = (float) ($policy->days_per_year ?? 0);

        if (!$employee) {
            return $entitled;
        }

        $excluded = (array) ($policy->excluded_contract_types ?? []);
        if (in_array($employee->contract_type, $excluded, true)) {
            return 0.0;
        }

        if (data_get($policy->settings, 'meta.system_key') === 'annual_default') {
            if ($employee->is_transferred_employee) {
                return (float) (($employee->opening_leave_balance ?? 0) + ($employee->leave_balance_adjustments ?? 0));
            }

            return (float) (($employee->annual_leave_days ?? $policy->days_per_year ?? 0) + ($employee->leave_balance_adjustments ?? 0));
        }

        return $entitled;
    }

    protected function leaveDates(AttendanceLeaveRequest $leave): array
    {
        $dates = [];
        $cursor = Carbon::parse($leave->start_date)->startOfDay();
        $end = Carbon::parse($leave->end_date)->startOfDay();

        while ($cursor->lte($end)) {
            $dates[] = $cursor->toDateString();
            $cursor->addDay();
        }

        return $dates;
    }
}
