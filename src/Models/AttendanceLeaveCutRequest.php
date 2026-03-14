<?php

namespace Athka\Attendance\Models;

use App\Models\Concerns\AppliesCompanyAndBranchScopeThroughEmployee;
use Athka\Employees\Models\Employee;
use Athka\SystemSettings\Models\LeavePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceLeaveCutRequest extends Model
{
    use AppliesCompanyAndBranchScopeThroughEmployee;

    protected $table = 'attendance_leave_cut_requests';

    protected $fillable = [
        'company_id',
        'original_leave_request_id',
        'employee_id',
        'leave_policy_id',
        'policy_year_id',
        'original_start_date',
        'original_end_date',
        'cut_end_date',
        'postponed_start_date',
        'postponed_end_date',
        'reason',
        'status',
        'requested_by',
        'requested_at',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'reject_reason',
        'new_leave_request_id',
    ];

    protected $casts = [
        'original_start_date' => 'date',
        'original_end_date' => 'date',
        'cut_end_date' => 'date',
        'postponed_start_date' => 'date',
        'postponed_end_date' => 'date',
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(LeavePolicy::class, 'leave_policy_id');
    }

    public function originalLeave(): BelongsTo
    {
        return $this->belongsTo(AttendanceLeaveRequest::class, 'original_leave_request_id');
    }
}


