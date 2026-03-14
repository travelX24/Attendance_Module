<?php

namespace Athka\Attendance\Models;

use App\Models\Concerns\AppliesCompanyAndBranchScopeThroughEmployee;
use Athka\Employees\Models\Employee;
use Athka\SystemSettings\Models\LeavePolicy;
use Athka\SystemSettings\Models\LeavePolicyYear;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceLeaveRequest extends Model
{
    use AppliesCompanyAndBranchScopeThroughEmployee;

    protected $table = 'attendance_leave_requests';

    protected $fillable = [
        'company_id',
        'employee_id',
        'leave_policy_id',
        'policy_year_id',
        'start_date',
        'end_date',
        'requested_days',
        'reason',
        'source',
        'status',
        'requested_by',
        'requested_at',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'reject_reason',
        'salary_processed_at',
        'duration_unit', 'half_day_part', 'from_time', 'to_time', 'minutes',
        'attachment_path', 'attachment_name', 'note_ack',
        'is_exception', 'exception_status',
        'replacement_employee_id',
        'replacement_status',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'requested_days' => 'decimal:6',
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'salary_processed_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function policy(): BelongsTo
    {
        return $this->belongsTo(LeavePolicy::class, 'leave_policy_id');
    }

    public function year(): BelongsTo
    {
        return $this->belongsTo(LeavePolicyYear::class, 'policy_year_id');
    }

    public function replacementEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'replacement_employee_id');
    }
}


