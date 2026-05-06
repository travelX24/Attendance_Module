<?php

namespace Athka\Attendance\Models;

use Illuminate\Database\Eloquent\Model;
use Athka\Employees\Models\Employee;
use Athka\SystemSettings\Models\WorkSchedule;
use Athka\Attendance\Models\AttendanceDailyDetail;
use Athka\Attendance\Models\AttendanceAuditLog;
use Athka\SystemSettings\Models\AttendanceGraceSetting;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AttendanceDailyLog extends Model
{
    protected $table = 'attendance_daily_logs';

    protected $guarded = ['id'];

    protected $casts = [
        'attendance_date' => 'date',
        'meta_data' => 'array',
        'check_attempts' => 'array',
        'is_edited' => 'boolean',
    ];

    // ==================== Relationships ====================

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function workSchedule()
    {
        return $this->belongsTo(WorkSchedule::class, 'work_schedule_id');
    }

    public function details()
    {
        return $this->hasMany(AttendanceDailyDetail::class, 'daily_log_id');
    }

    public function auditLogs()
    {
        return $this->hasMany(AttendanceAuditLog::class, 'entity_id')
            ->where('entity_type', 'attendance_daily_log');
    }

    public function editor()
    {
        return $this->belongsTo(\App\Models\User::class, 'last_edited_by');
    }

    public function approver()
    {
        return $this->belongsTo(\App\Models\User::class, 'approved_by');
    }

    public function rejector()
    {
        return $this->belongsTo(\App\Models\User::class, 'rejected_by');
    }

    public function revoker()
    {
        return $this->belongsTo(\App\Models\User::class, 'revoked_by');
    }

    public function scheduleException()
    {
        return $this->belongsTo(\Athka\Attendance\Models\EmployeeWorkScheduleException::class, 'work_schedule_exception_id');
    }

    // ==================== Scopes ====================

    public function scopeForCompany($query, $companyId)
    {
        return $query->where('saas_company_id', $companyId);
    }

    public function scopeForEmployee($query, $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }

    public function scopeForDate($query, $date)
    {
        return $query->where('attendance_date', $date);
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

    /**
     * Resolve the correct schedule and daily metrics for the log's date.
     */
    public function syncWithSchedule(): void
    {
        $service = app(\Athka\SystemSettings\Services\WorkScheduleService::class);
        $dateStr = $this->attendance_date->toDateString();

        $ws = $service->getEffectiveSchedule($this->saas_company_id, $this->employee, $dateStr);
        $holidays = $service->getHolidays($this->saas_company_id, $dateStr, $dateStr);
        
        $metrics = $service->getMetricsForDate($dateStr, $ws, $holidays, $this->employee);

        $this->work_schedule_id = $ws->id ?? null;
        $this->scheduled_hours = $metrics['hours'] ?? 0;
        $this->scheduled_check_in = $metrics['check_in'] ?? null;
        $this->scheduled_check_out = $metrics['check_out'] ?? null;
        
        if ($metrics['status'] === 'holiday') {
            $this->attendance_status = 'holiday';
        }
    }

    /**
     * Centralized helper to parse localized Carbon strings safely.
     */
    private function parseLocalizedCarbon($value)
    {
        if (!$value) return null;
        if ($value instanceof \DateTimeInterface) return Carbon::instance($value);
        
        $clean = str_replace(['ص', 'م'], ['AM', 'PM'], (string)$value);
        try {
            return Carbon::parse($clean);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function calculateCompliance(): void
    {
        if ((float)$this->scheduled_hours <= 0) {
            $this->compliance_percentage = 100;
            return;
        }

        $compliancePoints = 0;
        $totalScheduledMinutes = (float)$this->scheduled_hours * 60;
        $lateMinutes = 0;
        $earlyMinutes = 0;
        
        $dateCarbon = $this->parseLocalizedCarbon($this->attendance_date);
        $dateStr = $dateCarbon ? $dateCarbon->toDateString() : now()->toDateString();

        if ($this->scheduled_check_in && $this->check_in_time) {
            $sIn = $this->parseLocalizedCarbon($dateStr . " " . $this->formatTimeHm($this->scheduled_check_in));
            $aIn = $this->parseLocalizedCarbon($dateStr . " " . $this->formatTimeHm($this->check_in_time));
            if ($aIn && $sIn && $aIn->gt($sIn)) {
                $lateMinutes = $aIn->diffInMinutes($sIn);
            }
        }

        if ($this->scheduled_check_out && $this->check_out_time) {
            $sOut = $this->parseLocalizedCarbon($dateStr . " " . $this->formatTimeHm($this->scheduled_check_out));
            $aOut = $this->parseLocalizedCarbon($dateStr . " " . $this->formatTimeHm($this->check_out_time));
            if ($aOut && $sOut && $aOut->lt($sOut)) {
                $earlyMinutes = $sOut->diffInMinutes($aOut);
            }
        }

        $workedMinutes = $totalScheduledMinutes - $lateMinutes - $earlyMinutes;
        $this->compliance_percentage = $totalScheduledMinutes > 0 ? round(($workedMinutes / $totalScheduledMinutes) * 100, 2) : 0;
        if ($this->compliance_percentage < 0) $this->compliance_percentage = 0;
    }

    public function calculateActualHours(): void
    {
        if ($this->details()->exists()) {
            $totalMinutes = 0;
            foreach ($this->details as $detail) {
                if ($detail->check_in_time && $detail->check_out_time) {
                    $in = $this->parseLocalizedCarbon($detail->check_in_time);
                    $out = $this->parseLocalizedCarbon($detail->check_out_time);
                    if ($in && $out) {
                        $totalMinutes += $out->diffInMinutes($in);
                    }
                }
            }
            $this->actual_hours = round($totalMinutes / 60, 2);
            return;
        }

        $checkIn = $this->parseLocalizedCarbon($this->check_in_time);
        $checkOut = $this->parseLocalizedCarbon($this->check_out_time);
        $this->actual_hours = ($checkIn && $checkOut) ? round($checkOut->diffInMinutes($checkIn) / 60, 2) : 0;
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

        $grace = AttendanceGraceSetting::where('saas_company_id', $this->saas_company_id)->first()
                 ?? AttendanceGraceSetting::getGlobalDefault();

        // --- Start: Auto-Checkout Logic ---
        $openDetail = $this->details()->whereNull('check_out_time')->orderBy('check_in_time', 'desc')->first();
        
        if ($openDetail) {
            $service = app(\Athka\SystemSettings\Services\WorkScheduleService::class);
            $dateStr = $this->attendance_date->toDateString();
            $ws = $service->getEffectiveSchedule($this->saas_company_id, $this->employee, $dateStr);
            $holidays = $service->getHolidays($this->saas_company_id, $dateStr, $dateStr);
            $metrics = $service->getMetricsForDate($dateStr, $ws, $holidays, $this->employee);

            $p = null;
            foreach ($metrics['periods'] as $periodItem) {
                if (isset($periodItem['id']) && $periodItem['id'] == $openDetail->work_schedule_period_id) {
                    $p = (object)$periodItem;
                    break;
                }
            }

            if (!$p && isset($metrics['periods'][$openDetail->work_schedule_period_id - 1])) {
                $p = (object)$metrics['periods'][$openDetail->work_schedule_period_id - 1];
            }

            if ($p && isset($p->end_time)) {
                $end = Carbon::parse($dateStr . ' ' . $p->end_time);
                if ($p->is_night_shift ?? false) $end->addDay();

                $limit = $end->copy()->addHours((int)($grace->auto_checkout_after_minutes ?? 2));

                if (now()->greaterThan($limit)) {
                    $openDetail->update([
                        'check_out_time' => $limit->format('H:i:s'),
                        'attendance_status' => 'auto_checkout'
                    ]);
                    $this->check_out_time = $limit;
                    $this->attendance_status = 'auto_checkout';
                    return; 
                }
            }
        }
        // --- End: Auto-Checkout Logic ---

        $lateGrace = $grace->late_grace_minutes ?? 15;
        $earlyGrace = $grace->early_leave_grace_minutes ?? 15;

        $newStatus = 'present';
        if ($this->scheduled_check_in) {
            $dateStr = $this->attendance_date->toDateString();
            $sIn = $this->parseLocalizedCarbon($dateStr . " " . $this->formatTimeHm($this->scheduled_check_in));
            $aIn = $this->parseLocalizedCarbon($dateStr . " " . $this->formatTimeHm($effectiveCheckIn));
            
            if ($sIn && $aIn && $aIn->gt($sIn->copy()->addMinutes($lateGrace))) {
                $newStatus = 'late';
            }
        }

        if ($this->check_out_time && $this->scheduled_check_out) {
            $dateStr = $this->attendance_date->toDateString();
            $sOut = $this->parseLocalizedCarbon($dateStr . " " . $this->formatTimeHm($this->scheduled_check_out));
            $aOut = $this->parseLocalizedCarbon($dateStr . " " . $this->formatTimeHm($this->check_out_time));

            if ($sOut && $aOut && $aOut->lt($sOut->copy()->subMinutes($earlyGrace))) {
                $newStatus = 'early_departure';
            }
        }

        $this->attendance_status = $newStatus;
    }

    public function formatTimeHm($value): ?string
    {
        if (!$value) return null;
        if (is_string($value)) {
            return substr($value, 0, 5);
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i');
        }
        return null;
    }

    // ==================== Attributes ====================

    public function getCheckInHmAttribute()
    {
        return $this->formatTimeHm($this->check_in_time);
    }

    public function getCheckOutHmAttribute()
    {
        return $this->formatTimeHm($this->check_out_time);
    }

    public function getScheduledCheckInHmAttribute()
    {
        return $this->formatTimeHm($this->scheduled_check_in);
    }

    public function getScheduledCheckOutHmAttribute()
    {
        return $this->formatTimeHm($this->scheduled_check_out);
    }
}
