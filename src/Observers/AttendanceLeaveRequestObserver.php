<?php

namespace Athka\Attendance\Observers;

use Athka\Attendance\Models\AttendanceLeaveRequest;
use Athka\Attendance\Services\LeaveApprovalImpactService;

class AttendanceLeaveRequestObserver
{
    /**
     * Handle the AttendanceLeaveRequest "saved" event.
     * Triggered for both create and update.
     */
    public function saved(AttendanceLeaveRequest $leave): void
    {
        if ($leave->wasRecentlyCreated && $leave->status === 'approved') {
            app(LeaveApprovalImpactService::class)->apply($leave);
        }
    }

    public function updated(AttendanceLeaveRequest $leave): void
    {
        if ($leave->wasChanged([
            'status',
            'start_date',
            'end_date',
            'requested_days',
            'leave_policy_id',
            'policy_year_id',
        ])) {
            app(LeaveApprovalImpactService::class)->apply($leave);
        }
    }
}
