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

    public $tempMetrics = null;
    public $preFetchedHolidays = null;
    public $preFetchedRequests = null;
    public $preFetchedSchedule = null;

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

        $ws = $this->preFetchedSchedule ?? $service->getEffectiveSchedule($this->saas_company_id, $this->employee, $dateStr);
        
        if ($this->preFetchedHolidays !== null) {
            $holidays = $this->preFetchedHolidays->filter(function($h) use ($dateStr) {
                return $dateStr >= Carbon::parse($h->start_date)->toDateString() && $dateStr <= Carbon::parse($h->end_date)->toDateString();
            });
        } else {
            $holidays = $service->getHolidays($this->saas_company_id, $dateStr, $dateStr);
        }
        
        if ($this->preFetchedRequests !== null) {
            $requests = [
                'leaves' => ($this->preFetchedRequests['leaves'] ?? collect())->filter(function($l) use ($dateStr) {
                    $d = Carbon::parse($dateStr);
                    return $d->between(Carbon::parse($l->start_date)->startOfDay(), Carbon::parse($l->end_date)->startOfDay());
                }),
                'missions' => ($this->preFetchedRequests['missions'] ?? collect())->filter(function($m) use ($dateStr) {
                    $d = Carbon::parse($dateStr);
                    return $d->between(Carbon::parse($m->start_date)->startOfDay(), Carbon::parse($m->end_date)->startOfDay());
                }),
                'permissions' => ($this->preFetchedRequests['permissions'] ?? collect())->filter(fn($p) => (string)$p->permission_date === $dateStr),
            ];
        } else {
            $requests = $this->employee ? $service->getEmployeeRequests($this->employee_id, $dateStr, $dateStr) : [];
        }
        
        $metrics = $service->getMetricsForDate($dateStr, $ws, $holidays, $this->employee, $requests);
        $this->tempMetrics = $metrics;

        $this->work_schedule_id = $ws->id ?? null;
        $this->scheduled_hours = $metrics['hours'] ?? 0;
        $this->scheduled_check_in = $metrics['check_in'] ?? null;
        $this->scheduled_check_out = $metrics['check_out'] ?? null;
        
        if (($metrics['status'] ?? null) === 'holiday') {
            $this->attendance_status = 'holiday';
        } elseif (($metrics['status'] ?? null) === 'on_leave') {
            $this->attendance_status = 'on_leave';
        } elseif (($metrics['status'] ?? null) === 'mission') {
            $this->attendance_status = 'mission';
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
        $details = $this->exists ? ($this->relationLoaded('details') ? $this->details : $this->details()->get()) : collect();
        if ($details->isNotEmpty()) {
            $totalMinutes = 0;
            foreach ($details as $detail) {
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
        $details = $this->exists ? ($this->relationLoaded('details') ? $this->details : $this->details()->get()) : collect();
        $effectiveCheckIn = $this->check_in_time;
        if (!$effectiveCheckIn) {
            $firstDetail = $details->sortBy('check_in_time')->first();
            $effectiveCheckIn = $firstDetail?->check_in_time;
            if ($effectiveCheckIn) $this->check_in_time = $effectiveCheckIn;
        }

        if (!$effectiveCheckIn) {
            if ($this->attendance_status === 'on_leave') return;
            $this->attendance_status = ((float)$this->scheduled_hours > 0) ? 'absent' : 'day_off';
            return;
        }

        // Cache grace settings statically to avoid repeated DB queries per request
        static $graceSettingsCache = [];
        $cid = $this->saas_company_id;
        if (!isset($graceSettingsCache[$cid])) {
            $graceSettingsCache[$cid] = AttendanceGraceSetting::where('saas_company_id', $cid)->first()
                     ?? AttendanceGraceSetting::getGlobalDefault();
        }
        $grace = $graceSettingsCache[$cid];

        // Retrieve the day metrics (cached during syncWithSchedule if possible, or loaded on-demand)
        $metrics = $this->tempMetrics ?? null;
        $logDateStr = $this->attendance_date->toDateString();
        if (!$metrics) {
            $scheduleService = app(\Athka\SystemSettings\Services\WorkScheduleService::class);
            $effectiveWs = $scheduleService->getEffectiveSchedule($this->saas_company_id, $this->employee, $logDateStr);
            $dayHolidays = $scheduleService->getHolidays($this->saas_company_id, $logDateStr, $logDateStr);
            $metrics = $scheduleService->getMetricsForDate($logDateStr, $effectiveWs, $dayHolidays, $this->employee);
            $this->tempMetrics = $metrics;
        }

        // --- Start: Aggressive Auto-Checkout Logic ---
        $foundDetail = $details->whereNull('check_out_time')->sortByDesc('check_in_time')->first();
        
        // If no open detail, we check if the last one is an auto_checkout that might need correction
        if (!$foundDetail) {
            $lastD = $details->sortByDesc('check_in_time')->first();
            if ($lastD && $lastD->attendance_status === 'auto_checkout') {
                $foundDetail = $lastD;
            }
        }
        
        if ($foundDetail) {
            $matchedP = null;
            $periods = $metrics['periods'] ?? [];
            foreach ($periods as $pItem) {
                if (isset($pItem['id']) && $pItem['id'] == $foundDetail->work_schedule_period_id) {
                    $matchedP = (object)$pItem;
                    break;
                }
            }

            // Fallback to index-based if ID doesn't match
            if (!$matchedP && isset($periods[$foundDetail->work_schedule_period_id - 1])) {
                $matchedP = (object)$periods[$foundDetail->work_schedule_period_id - 1];
            }

            // Case 2: Multi-period or Single period with auto-checkout policy
            if ($matchedP && isset($matchedP->end_time)) {
                $baseEnd = Carbon::parse($logDateStr . ' ' . $matchedP->end_time);
                if ($matchedP->is_night_shift ?? false) $baseEnd->addDay();

                $hoursToAdd = (int)($grace->auto_checkout_after_minutes ?? 2);
                $checkoutLimit = $baseEnd->copy()->addHours($hoursToAdd);

                // --- NEW: Cap auto-checkout at the start of the next period ---
                $nextPeriodStart = null;
                $foundCurrent = false;
                foreach ($periods as $pItem) {
                    if ($foundCurrent) {
                        $nextPeriodStart = Carbon::parse($logDateStr . ' ' . $pItem['start_time']);
                        if ($pItem['is_night_shift'] ?? false) $nextPeriodStart->addDay();
                        break;
                    }
                    if (($pItem['id'] ?? null) == $foundDetail->work_schedule_period_id) {
                        $foundCurrent = true;
                    }
                }

                $effectiveLimit = $checkoutLimit;
                if ($nextPeriodStart && $nextPeriodStart->lt($checkoutLimit)) {
                    $effectiveLimit = $nextPeriodStart;
                }

                $formattedLimit = $effectiveLimit->format('H:i:s');

                // If now is past the limit, we apply auto-checkout
                if (now()->gt($effectiveLimit)) {
                    $foundDetail->check_out_time = $formattedLimit;
                    $foundDetail->save();

                    $this->attendance_status = 'auto_checkout';

                    $meta = $this->meta_data ?? [];
                    $meta['auto_checkout'] = [
                        'final_limit' => $formattedLimit,
                        'calculated_at' => now()->toDateTimeString()
                    ];
                    $this->meta_data = $meta;
                    
                    return; 
                }
            }
        }
        // --- End: Aggressive Auto-Checkout Logic ---

        $lateGrace = $grace->late_grace_minutes ?? 15;
        $earlyGrace = $grace->early_leave_grace_minutes ?? 15;

        $newStatus = 'present';
        
        $periods = $metrics['periods'] ?? [];
        
        $isLate = false;
        $isEarlyDeparture = false;
        
        if (count($periods) > 0) {
            foreach ($details as $detail) {
                if ($detail->work_schedule_period_id) {
                    $matchedP = null;
                    foreach ($periods as $pItem) {
                        if (isset($pItem['id']) && $pItem['id'] == $detail->work_schedule_period_id) {
                            $matchedP = (object)$pItem;
                            break;
                        }
                    }
                    if (!$matchedP && isset($periods[$detail->work_schedule_period_id - 1])) {
                        $matchedP = (object)$periods[$detail->work_schedule_period_id - 1];
                    }
                    
                    if ($matchedP) {
                        // Check if late for this period
                        if ($detail->check_in_time && isset($matchedP->start_time)) {
                            $sIn = $this->parseLocalizedCarbon($logDateStr . " " . $this->formatTimeHm($matchedP->start_time));
                            $aIn = $this->parseLocalizedCarbon($logDateStr . " " . $this->formatTimeHm($detail->check_in_time));
                            if ($sIn && $aIn && $aIn->gt($sIn->copy()->addMinutes($lateGrace))) {
                                $isLate = true;
                            }
                        }
                        
                        // Check if early checkout for this period
                        if ($detail->check_out_time && isset($matchedP->end_time)) {
                            $sOut = $this->parseLocalizedCarbon($logDateStr . " " . $this->formatTimeHm($matchedP->end_time));
                            $aOut = $this->parseLocalizedCarbon($logDateStr . " " . $this->formatTimeHm($detail->check_out_time));
                            if ($sOut && $aOut && $aOut->lt($sOut->copy()->subMinutes($earlyGrace))) {
                                $isEarlyDeparture = true;
                            }
                        }
                    }
                }
            }
        } else {
            if ($this->scheduled_check_in && $effectiveCheckIn) {
                $sIn = $this->parseLocalizedCarbon($logDateStr . " " . $this->formatTimeHm($this->scheduled_check_in));
                $aIn = $this->parseLocalizedCarbon($logDateStr . " " . $this->formatTimeHm($effectiveCheckIn));
                if ($sIn && $aIn && $aIn->gt($sIn->copy()->addMinutes($lateGrace))) {
                    $isLate = true;
                }
            }
            if ($this->check_out_time && $this->scheduled_check_out) {
                $sOut = $this->parseLocalizedCarbon($logDateStr . " " . $this->formatTimeHm($this->scheduled_check_out));
                $aOut = $this->parseLocalizedCarbon($logDateStr . " " . $this->formatTimeHm($this->check_out_time));
                if ($sOut && $aOut && $aOut->lt($sOut->copy()->subMinutes($earlyGrace))) {
                    $isEarlyDeparture = true;
                }
            }
        }
        
        if ($isEarlyDeparture) {
            $newStatus = 'early_departure';
        } elseif ($isLate) {
            $newStatus = 'late';
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
