<?php

namespace Athka\Attendance\Models;

use App\Models\Concerns\AppliesCompanyAndBranchScopeThroughEmployee;
use App\Models\User;
use Athka\Employees\Models\Employee;
use Athka\SystemSettings\Models\AttendanceGraceSetting;
use Athka\SystemSettings\Models\WorkSchedule;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AttendanceDailyLog extends Model
{
    use AppliesCompanyAndBranchScopeThroughEmployee;

    protected $fillable = [
        'saas_company_id',
        'employee_id',
        'attendance_date',
        'work_schedule_id',
        'scheduled_hours',
        'check_in_time',
        'check_out_time',
        'actual_hours',
        'scheduled_check_in',
        'scheduled_check_out',
        'attendance_status',
        'approval_status',
        'compliance_percentage',
        'is_edited',
        'edited_by',
        'edited_at',
        'edit_reason',
        'edit_notes',
        'approved_by',
        'approved_at',
        'approval_notes',

        'rejected_by',
        'rejected_at',
        'rejection_notes',

        'revoked_by',
        'revoked_at',
        'revoke_reason',

        'source',
        'check_attempts',
        'meta_data',
    ];

    protected $casts = [
        'attendance_date' => 'date',
        'check_in_time' => 'datetime',
        'check_out_time' => 'datetime',
        'scheduled_check_in' => 'datetime',
        'scheduled_check_out' => 'datetime',
        'scheduled_hours' => 'decimal:2',
        'actual_hours' => 'decimal:2',
        'compliance_percentage' => 'decimal:2',
        'is_edited' => 'boolean',
        'edited_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'revoked_at' => 'datetime',
        'check_attempts' => 'array',
        'meta_data' => 'array',
    ];

    // Relationships
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function workSchedule(): BelongsTo
    {
        return $this->belongsTo(WorkSchedule::class);
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AttendanceAuditLog::class, 'entity_id', 'id')
            ->where('entity_type', 'attendance_daily_log');
    }

    public function details(): HasMany
    {
        return $this->hasMany(AttendanceDailyDetail::class, 'daily_log_id');
    }

    // Scopes
    public function scopeForCompany($query, $companyId)
    {
        return $query->where('saas_company_id', $companyId);
    }

    public function scopeForDate($query, $date)
    {
        return $query->where('attendance_date', $date);
    }

    public function scopeForEmployee($query, $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }

    public function scopePending($query)
    {
        return $query->where('approval_status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('approval_status', 'approved');
    }

    // Maintain data integrity on saving
    protected static function booted()
    {
        static::saving(function ($log) {
            $log->syncWithSchedule();
            $log->calculateStatus();
            $log->calculateActualHours();
            $log->calculateCompliance();

            if ($log->employee && $log->employee->contract_type === 'freelancer') {
                 if ($log->check_in_time && $log->attendance_status !== 'absent' && $log->approval_status !== 'approved' && $log->approval_status !== 'rejected') {
                     $log->approval_status = 'pending';
                 }
            }
        });
    }

    public function syncWithSchedule(): void
    {
        $companyId = $this->saas_company_id;
        $date = $this->attendance_date;
        if (!$date || !$companyId) {
            return;
        }

        $service = app(\Athka\SystemSettings\Services\WorkScheduleService::class);
        $dateStr = \Carbon\Carbon::parse($date)->toDateString();

        // âœ… Check for approved leaves
        $leave = \Athka\Attendance\Models\AttendanceLeaveRequest::withoutGlobalScopes()
            ->where('employee_id', $this->employee_id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $dateStr)
            ->whereDate('end_date', '>=', $dateStr)
            ->first();

        if ($leave) {
            $this->attendance_status = 'on_leave';
            $this->scheduled_hours = 0;
            $this->scheduled_check_in = null;
            $this->scheduled_check_out = null;
            return;
        }

        $schedule = $service->getEffectiveSchedule((int)$companyId, $this->employee, $dateStr);
        $holidays = $service->getHolidays((int)$companyId, $dateStr, $dateStr);
        $metrics = $service->getMetricsForDate($dateStr, $schedule, $holidays);

        $this->work_schedule_id = $schedule ? $schedule->id : null;
        $this->scheduled_hours = $metrics['hours'] ?? 0;
        $this->scheduled_check_in = $metrics['check_in'];
        $this->scheduled_check_out = $metrics['check_out'];

        if ($metrics['status'] === 'holiday' || $metrics['status'] === 'off') {
            if (!$this->check_in_time && $this->attendance_status !== 'on_leave') {
                $this->attendance_status = 'day_off';
            }
        }

        if ($this->getEffectiveTrackingMode() === 'automatic' && $metrics['status'] === 'workday') {
            if (!$this->check_in_time && $this->scheduled_check_in) {
                $this->check_in_time = $this->scheduled_check_in;
            }
            if (!$this->check_out_time && $this->scheduled_check_out) {
                $this->check_out_time = $this->scheduled_check_out;
            }
            if ($this->source !== 'manual') {
                $this->source = 'automatic';
            }
        }
    }

    public function getEffectiveTrackingMode(): string
    {
        $group = \Athka\SystemSettings\Models\EmployeeGroup::whereHas('employees', function ($q) {
            $q->where('employees.id', $this->employee_id);
        })->where('is_active', true)->first();

        if ($group && $group->appliedPolicy) {
            return $group->appliedPolicy->tracking_mode ?? 'check_in_out';
        }

        $defaultPolicy = \Athka\SystemSettings\Models\AttendancePolicy::where('saas_company_id', $this->saas_company_id)
            ->where('is_default', true)
            ->first();

        return $defaultPolicy->tracking_mode ?? 'check_in_out';
    }

    // Helpers
    private function formatTimeHm($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->format('H:i');
        }
        $str = (string) $value;
        return strlen($str) >= 5 ? substr($str, 0, 5) : $str;
    }

    public function calculateCompliance(): void
    {
        if (!$this->scheduled_hours || (float)$this->scheduled_hours <= 0) {
            $this->compliance_percentage = 100;
            return;
        }

        if (!$this->check_in_time) {
            $this->compliance_percentage = 0;
            return;
        }

        $totalScheduledMinutes = (float)$this->scheduled_hours * 60;
        $lateMinutes = 0;
        $earlyMinutes = 0;
        $dateStr = Carbon::parse($this->attendance_date)->toDateString();

        if ($this->scheduled_check_in && $this->check_in_time) {
            $sIn = Carbon::parse($dateStr . " " . $this->formatTimeHm($this->scheduled_check_in));
            $aIn = Carbon::parse($dateStr . " " . $this->formatTimeHm($this->check_in_time));
            if ($aIn->gt($sIn)) {
                $lateMinutes = $aIn->diffInMinutes($sIn);
            }
        }

        if ($this->scheduled_check_out && $this->check_out_time) {
            $sOut = Carbon::parse($dateStr . " " . $this->formatTimeHm($this->scheduled_check_out));
            $aOut = Carbon::parse($dateStr . " " . $this->formatTimeHm($this->check_out_time));
            if ($aOut->lt($sOut)) {
                $earlyMinutes = $sOut->diffInMinutes($aOut);
            }
        }

        $missedMinutes = $lateMinutes + $earlyMinutes;
        $effectiveMinutes = max(0, $totalScheduledMinutes - $missedMinutes);
        $this->compliance_percentage = round(($effectiveMinutes / $totalScheduledMinutes) * 100, 2);
    }

    public function calculateActualHours(): void
    {
        $details = $this->details;
        if ($details && $details->isNotEmpty()) {
            $totalMinutes = 0;
            foreach ($details as $detail) {
                if ($detail->check_in_time && $detail->check_out_time) {
                    try {
                        $in = Carbon::parse($detail->check_in_time);
                        $out = Carbon::parse($detail->check_out_time);
                        $totalMinutes += $out->diffInMinutes($in);
                    } catch (\Exception $e) {}
                }
            }
            $this->actual_hours = round($totalMinutes / 60, 2);
            return;
        }

        if (!$this->check_in_time || !$this->check_out_time) {
            $this->actual_hours = 0;
            return;
        }

        $checkIn = Carbon::parse($this->check_in_time);
        $checkOut = Carbon::parse($this->check_out_time);
        $this->actual_hours = round($checkOut->diffInMinutes($checkIn) / 60, 2);
    }

    public function calculateStatus(): void
    {
        $effectiveCheckIn = $this->check_in_time;
        if (!$effectiveCheckIn) {
            $firstDetail = $this->details()->orderBy('check_in_time', 'asc')->first();
            $effectiveCheckIn = $firstDetail?->check_in_time;
            if ($effectiveCheckIn) $this->check_in_time = $effectiveCheckIn;
        }

        if (!$effectiveCheckIn) {
            if ($this->attendance_status === 'on_leave') return;
            $this->attendance_status = ((float)$this->scheduled_hours > 0) ? 'absent' : 'day_off';
            return;
        }

        $newStatus = 'present';
        $grace = AttendanceGraceSetting::where('saas_company_id', $this->saas_company_id)->first()
                 ?? AttendanceGraceSetting::getGlobalDefault();

        $lateGrace = $grace->late_grace_minutes ?? 15;
        $earlyGrace = $grace->early_leave_grace_minutes ?? 15;
        $dateStr = Carbon::parse($this->attendance_date)->toDateString();

        // --- Start: Auto-Checkout Logic ---
        $effectiveCheckOut = $this->check_out_time;
        if (!$effectiveCheckOut) {
            $lastDetail = $this->details()->whereNotNull('check_out_time')->orderBy('check_out_time', 'desc')->first();
            $effectiveCheckOut = $lastDetail?->check_out_time;
        }

        if (!$effectiveCheckOut && $this->check_in_time && $this->scheduled_check_out) {
            $autoCheckoutMins = (int)($grace->auto_checkout_after_minutes ?? 0);
            if ($autoCheckoutMins > 0) {
                 $scheduledOut = Carbon::parse($dateStr . " " . $this->formatTimeHm($this->scheduled_check_out));
                 $limit = $scheduledOut->copy()->addMinutes($autoCheckoutMins);
                 
                 if (now()->greaterThan($limit)) {
                     // Perform auto-checkout: set time to scheduled out and update status
                     $this->check_out_time = $this->scheduled_check_out;
                     $this->attendance_status = 'auto_checkout';
                     
                     // Close open sessions in secondary details table
                     $this->details()->whereNull('check_out_time')->update([
                         'check_out_time' => $this->formatTimeHm($this->scheduled_check_out)
                     ]);
                     
                     return; // Skip remaining status calculations
                 }
            }
        }
        // --- End: Auto-Checkout Logic ---

        if ($this->scheduled_check_in && $effectiveCheckIn) {
            $scheduledIn = Carbon::parse($dateStr . " " . $this->formatTimeHm($this->scheduled_check_in));
            $actualIn = Carbon::parse($dateStr . " " . $this->formatTimeHm($effectiveCheckIn));
            if ($actualIn->greaterThan($scheduledIn->copy()->addMinutes($lateGrace))) {
                $newStatus = 'late';
            }
        }

        $effectiveCheckOut = $this->check_out_time;
        if (!$effectiveCheckOut) {
            $lastDetail = $this->details()->whereNotNull('check_out_time')->orderBy('check_out_time', 'desc')->first();
            $effectiveCheckOut = $lastDetail?->check_out_time;
        }

        if ($this->scheduled_check_out && $effectiveCheckOut) {
            $scheduledOut = Carbon::parse($dateStr . " " . $this->formatTimeHm($this->scheduled_check_out));
            $actualOut = Carbon::parse($dateStr . " " . $this->formatTimeHm($effectiveCheckOut));
            if ($actualOut->lessThan($scheduledOut->copy()->subMinutes($earlyGrace))) {
                if ($newStatus !== 'late') $newStatus = 'early_departure';
            }
        }
        $this->attendance_status = $newStatus;
    }
}
