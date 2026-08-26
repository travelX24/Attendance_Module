<?php

namespace Athka\Attendance\Models;

use App\Models\User;
use Athka\Employees\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendancePenaltyExemptionHistory extends Model
{
    protected $table = 'attendance_penalty_exemption_histories';

    protected $fillable = [
        'saas_company_id',
        'attendance_daily_penalty_id',
        'employee_id',
        'attendance_date',
        'violation_type',
        'action',
        'status',
        'exemption_type',
        'exemption_amount',
        'net_amount',
        'reason',
        'attachment_path',
        'applied_by',
        'applied_at',
        'cancelled_by',
        'cancelled_at',
    ];

    protected $casts = [
        'attendance_date' => 'date',
        'exemption_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'applied_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function penalty(): BelongsTo
    {
        return $this->belongsTo(AttendanceDailyPenalty::class, 'attendance_daily_penalty_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function appliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function scopeForCompany($query, $companyId)
    {
        return $query->where('saas_company_id', $companyId);
    }
}
