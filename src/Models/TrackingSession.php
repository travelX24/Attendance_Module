<?php

namespace Athka\Attendance\Models;

use App\Models\User;
use Athka\Employees\Models\Employee;
use Athka\Saas\Models\Branch;
use Athka\SystemSettings\Models\AttendanceGpsLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class TrackingSession extends Model
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_EXPIRED = 'expired';

    public const STATE_UNKNOWN = 'unknown';
    public const STATE_INSIDE = 'inside';
    public const STATE_EXIT_PENDING = 'exit_pending';
    public const STATE_OUTSIDE = 'outside';
    public const STATE_RETURN_PENDING = 'return_pending';
    public const STATE_BREAK_PAUSED = 'break_paused';
    public const STATE_PERMISSION_PAUSED = 'permission_paused';
    public const STATE_MISSION = 'mission';
    public const STATE_STOPPED = 'stopped';

    protected $table = 'tracking_sessions';

    protected $guarded = ['id'];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'state_changed_at' => 'datetime',
        'last_recorded_at' => 'datetime',
        'start_lat' => 'decimal:7',
        'start_lng' => 'decimal:7',
        'start_accuracy_meters' => 'decimal:2',
        'last_lat' => 'decimal:7',
        'last_lng' => 'decimal:7',
        'last_accuracy_meters' => 'decimal:2',
        'end_lat' => 'decimal:7',
        'end_lng' => 'decimal:7',
        'end_accuracy_meters' => 'decimal:2',
        'total_distance_meters' => 'decimal:2',
        'outside_distance_meters' => 'decimal:2',
        'meta' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (TrackingSession $session): void {
            if (! $session->public_id) {
                $session->public_id = (string) Str::uuid();
            }
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function dailyLog(): BelongsTo
    {
        return $this->belongsTo(AttendanceDailyLog::class, 'attendance_daily_log_id');
    }

    public function dailyDetail(): BelongsTo
    {
        return $this->belongsTo(AttendanceDailyDetail::class, 'attendance_daily_detail_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }

    public function currentLocation(): BelongsTo
    {
        return $this->belongsTo(AttendanceGpsLocation::class, 'current_location_id');
    }

    public function points(): HasMany
    {
        return $this->hasMany(TrackingPoint::class, 'tracking_session_id');
    }

    public function geofenceEvents(): HasMany
    {
        return $this->hasMany(TrackingGeofenceEvent::class, 'tracking_session_id');
    }

    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('saas_company_id', $companyId);
    }

    public function scopeForEmployee($query, int $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
