<?php

namespace Athka\Attendance\Models;

use App\Models\Concerns\AppliesCompanyAndBranchScopeThroughEmployee;
use Athka\Employees\Models\Employee;
use Athka\SystemSettings\Models\WorkSchedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeWorkSchedule extends Model
{
    use AppliesCompanyAndBranchScopeThroughEmployee;

    protected $table = 'employee_work_schedules';

    protected $fillable = [
        'employee_id',
        'work_schedule_id',
        'start_date',
        'end_date',
        'is_active',
        'assignment_type',
        'notes',
        'saas_company_id',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function workSchedule(): BelongsTo
    {
        return $this->belongsTo(WorkSchedule::class);
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(EmployeeWorkScheduleException::class, 'employee_work_schedule_id');
    }
}


