<?php

namespace Athka\Attendance\Models;

use App\Models\Concerns\AppliesCompanyAndBranchScopeThroughEmployee;
use Athka\Employees\Models\Employee;
use Athka\SystemSettings\Models\LeavePolicy;
use Athka\SystemSettings\Models\LeavePolicyYear;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceLeaveBalance extends Model
{
    use AppliesCompanyAndBranchScopeThroughEmployee;

    protected $table = 'attendance_leave_balances';

    protected $fillable = [
        'company_id',
        'employee_id',
        'leave_policy_id',
        'policy_year_id',
        'entitled_days',
        'taken_days',
        'remaining_days',
        'last_recalculated_at',
    ];

    protected $casts = [
        'entitled_days' => 'decimal:2',
        'taken_days' => 'decimal:2',
        'remaining_days' => 'decimal:2',
        'last_recalculated_at' => 'datetime',
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
}


