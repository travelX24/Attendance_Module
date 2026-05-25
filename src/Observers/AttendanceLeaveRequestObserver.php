<?php

namespace Athka\Attendance\Observers;

use Athka\Attendance\Models\AttendanceLeaveRequest;
use Athka\Attendance\Models\AttendanceDailyLog;
use Carbon\Carbon;

class AttendanceLeaveRequestObserver
{
    /**
     * Handle the AttendanceLeaveRequest "saved" event.
     * Triggered for both create and update.
     */
    public function saved(AttendanceLeaveRequest $leave): void
    {
        if ($leave->status === 'approved') {
            $this->syncAttendanceLogs($leave);
            $this->recalculateBalance($leave);
        }
    }

    public function updated(AttendanceLeaveRequest $leave): void
    {
        if ($leave->isDirty('status')) {
            $this->syncAttendanceLogs($leave);
            $this->recalculateBalance($leave);
        }
    }

    protected function syncAttendanceLogs(AttendanceLeaveRequest $leave): void
    {
        $start = Carbon::parse($leave->start_date)->startOfDay();
        $end = Carbon::parse($leave->end_date)->startOfDay();
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $dateStr = $cursor->toDateString();
            
            if ($leave->status === 'approved') {
                // Ensure the log exists and has the correct status
                AttendanceDailyLog::updateOrCreate(
                    [
                        'saas_company_id' => $leave->company_id, 
                        'employee_id'     => $leave->employee_id, 
                        'attendance_date' => $dateStr
                    ],
                    [
                        'attendance_status' => 'on_leave', 
                        'approval_status'   => 'approved'
                    ]
                );
            } else {
                // If cancelled/rejected, refresh existing logs to pick up their normal status
                $log = AttendanceDailyLog::where('employee_id', $leave->employee_id)
                    ->where('attendance_date', $dateStr)
                    ->first();
                if ($log) {
                    $log->save(); // This will trigger syncWithSchedule and fix the status
                }
            }
            $cursor->addDay();
        }
    }

    protected function recalculateBalance(AttendanceLeaveRequest $leave): void
    {
        if (empty($leave->leave_policy_id) || empty($leave->policy_year_id)) return;

        $companyId = $leave->company_id;
        $employeeId = $leave->employee_id;
        $policyId = $leave->leave_policy_id;
        $yearId = $leave->policy_year_id;

        $policy = \Athka\SystemSettings\Models\LeavePolicy::find($policyId);
        $employee = \Athka\Employees\Models\Employee::find($employeeId);
        
        $entitled = $policy ? (float)($policy->days_per_year ?? 0) : 0.0;
        
        if ($policy && $employee) {
            $excluded = (array) ($policy->excluded_contract_types ?? []);
            if (in_array($employee->contract_type, $excluded)) {
                $entitled = 0.0;
            }
        }

        $taken = (float) AttendanceLeaveRequest::query()
            ->where('company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->where('leave_policy_id', $policyId)
            ->where('policy_year_id', $yearId)
            ->where('status', 'approved')
            ->sum('requested_days');
        
        \Athka\Attendance\Models\AttendanceLeaveBalance::updateOrCreate(
            [
                'company_id' => $companyId, 
                'employee_id' => $employeeId, 
                'leave_policy_id' => $policyId, 
                'policy_year_id' => $yearId
            ],
            [
                'entitled_days' => $entitled, 
                'taken_days' => $taken, 
                'remaining_days' => max($entitled - $taken, 0), 
                'last_recalculated_at' => now()
            ]
        );
    }
}
