<?php

namespace Athka\Attendance\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeShiftRotation extends Model
{
    protected $table = 'employee_shift_rotations';

    protected $fillable = [
        'saas_company_id',
        'employee_id',
        'work_schedule_id_a',
        'work_schedule_id_b',
        'start_date',
        'end_date',
        'rotation_days',
        'is_active',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
        'rotation_days' => 'integer',
    ];
}


