<?php

namespace Athka\Attendance\Models;

use App\Models\Concerns\AppliesCompanyAndBranchScopeThroughEmployee;
use Athka\Employees\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceMissionRequest extends Model
{
    use AppliesCompanyAndBranchScopeThroughEmployee;

    protected $table = 'attendance_mission_requests';

    protected $fillable = [
        'company_id',
        'employee_id',
        'type', // full_day, partial
        'start_date',
        'end_date',
        'from_time',
        'to_time',
        'destination',
        'reason',
        'status', // pending, approved, rejected
        'requested_by',
        'requested_at',
        'approved_by',
        'approved_at',
        'rejected_by',
        'rejected_at',
        'reject_reason',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'requested_by');
    }
}


