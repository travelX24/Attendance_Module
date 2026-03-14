<?php

namespace Athka\Attendance\Models;

use App\Models\Concerns\AppliesCompanyAndBranchScopeThroughEmployee;
use Athka\Employees\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendancePermissionRequest extends Model
{
    use AppliesCompanyAndBranchScopeThroughEmployee;

    protected $table = 'attendance_permission_requests';

    protected $fillable = [
        'company_id',
        'employee_id',
        'permission_date',
        'from_time',
        'to_time',
        'minutes',
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
    ];

    protected $casts = [
        'permission_date' => 'date',
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'minutes' => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}


