<?php

namespace Athka\Attendance\Models;

use App\Models\Concerns\AppliesCompanyAndBranchScopeThroughEmployee;
use Athka\Employees\Models\Employee;
use Athka\SystemSettings\Models\WorkSchedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeWorkScheduleException extends Model
{
    use AppliesCompanyAndBranchScopeThroughEmployee;

    protected $table = 'employee_work_schedule_exceptions';

    protected $fillable = [
        'saas_company_id',
        'employee_id',
        'employee_work_schedule_id',
        'work_schedule_id',
        'exception_date',
        'exception_type',
        'start_time',
        'end_time',
        'breaks_json',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'exception_date' => 'date:Y-m-d',
        'breaks_json' => 'array',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(EmployeeWorkSchedule::class, 'employee_work_schedule_id');
    }

    public function workSchedule(): BelongsTo
    {
        return $this->belongsTo(WorkSchedule::class);
    }
}


