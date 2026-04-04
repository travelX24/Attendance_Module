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
        }
    }

    /**
     * Handle the AttendanceLeaveRequest "updated" event.
     * Check if status changed from approved to something else.
     */
    public function updated(AttendanceLeaveRequest $leave): void
    {
        if ($leave->isDirty('status')) {
            $this->syncAttendanceLogs($leave);
        }
    }

    /**
     * Re-sync all attendance logs within the leave date range.
     */
    protected function syncAttendanceLogs(AttendanceLeaveRequest $leave): void
    {
        $start = Carbon::parse($leave->start_date);
        $end = Carbon::parse($leave->end_date);
        
        // Find existing logs for this employee in this range
        $logs = AttendanceDailyLog::where('employee_id', $leave->employee_id)
            ->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])
            ->get();

        foreach ($logs as $log) {
            // syncWithSchedule() in the model will check for the leave and set status to on_leave
            $log->save(); 
        }
    }
}
