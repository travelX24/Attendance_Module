<?php

namespace Athka\Attendance\Models;

use App\Models\Concerns\AppliesCompanyAndBranchScopeThroughEmployee;
use App\Models\User;
use Athka\Employees\Models\Employee;
use Athka\SystemSettings\Models\AttendancePenaltyPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceDailyPenalty extends Model
{
    use AppliesCompanyAndBranchScopeThroughEmployee;

    protected $fillable = [
        'saas_company_id',
        'employee_id',
        'attendance_daily_log_id',
        'attendance_date',
        'violation_type',
        'violation_minutes',
        'penalty_policy_id',
        'calculated_amount',
        'exemption_amount',
        'net_amount',
        'exemption_type',
        'exemption_status',
        'exemption_reason',
        'exemption_attachment',
        'exempted_by',
        'exempted_at',
        'status',
        'confirmed_by',
        'confirmed_at',
        'notes',
    ];

    protected $casts = [
        'attendance_date' => 'date',
        'calculated_amount' => 'decimal:2',
        'exemption_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'exempted_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    // Relationships
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function attendanceLog(): BelongsTo
    {
        return $this->belongsTo(AttendanceDailyLog::class, 'attendance_daily_log_id');
    }

    public function penaltyPolicy(): BelongsTo
    {
        return $this->belongsTo(AttendancePenaltyPolicy::class, 'penalty_policy_id');
    }

    public function exemptedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'exempted_by');
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    // Scopes
    public function scopeForCompany($query, $companyId)
    {
        return $query->where('saas_company_id', $companyId);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeConfirmed($query)
    {
        return $query->where('status', 'confirmed');
    }
}


