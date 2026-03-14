<?php

namespace Athka\Attendance\Http\Livewire\LeavesPermissions\Traits;

use Athka\Attendance\Models\AttendanceLeaveRequest;
use Athka\Attendance\Models\AttendanceDailyLog;
use Athka\Attendance\Models\AttendanceDailyPenalty;
use Carbon\Carbon;

trait WithAttendanceSync
{
    protected function syncAttendanceLogForLeave(AttendanceLeaveRequest $leave): void
    {
        $start = Carbon::parse($leave->start_date)->startOfDay();
        $end = Carbon::parse($leave->end_date)->startOfDay();
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $dateStr = $cursor->toDateString();
            AttendanceDailyLog::updateOrCreate(
                ['saas_company_id' => $leave->company_id, 'employee_id' => $leave->employee_id, 'attendance_date' => $dateStr],
                ['attendance_status' => 'on_leave', 'approval_status' => 'approved']
            );
            AttendanceDailyPenalty::where('saas_company_id', $leave->company_id)->where('employee_id', $leave->employee_id)->where('attendance_date', $dateStr)->where('violation_type', 'absent')->delete();
            $cursor->addDay();
        }
    }

    protected function removeAttendanceLogSync(AttendanceLeaveRequest $leave): void
    {
        $start = Carbon::parse($leave->start_date)->startOfDay();
        $end = Carbon::parse($leave->end_date)->startOfDay();
        $this->removeAttendanceLogSyncInRange((int)$leave->employee_id, $start, $end);
    }

    protected function removeAttendanceLogSyncInRange(int $employeeId, Carbon $start, Carbon $end): void
    {
        $logs = AttendanceDailyLog::where('saas_company_id', $this->companyId)
            ->where('employee_id', $employeeId)
            ->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])
            ->where('attendance_status', 'on_leave')
            ->get();

        foreach ($logs as $log) {
            if ($log->check_in_time) {
                $log->calculateStatus();
                $log->save();
            } else {
                $log->update(['attendance_status' => 'absent']);
            }
        }
    }
}


