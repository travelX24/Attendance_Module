<?php

namespace Athka\Attendance\Models;

use Athka\Employees\Models\Employee;
use Athka\SystemSettings\Models\AttendanceGpsLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrackingGeofenceEvent extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_RETURNED = 'returned';
    public const STATUS_CANCELLED = 'cancelled';

    public const CLASSIFICATION_WORK_EXIT = 'work_exit';
    public const CLASSIFICATION_BREAK = 'break';
    public const CLASSIFICATION_PERMISSION = 'permission';
    public const CLASSIFICATION_MISSION = 'mission';
    public const CLASSIFICATION_INFORMATIONAL = 'informational';

    protected $table = 'tracking_geofence_events';

    protected $guarded = ['id'];

    protected $casts = [
        'is_counted' => 'boolean',
        'exited_at' => 'datetime',
        'returned_at' => 'datetime',
        'exit_lat' => 'decimal:7',
        'exit_lng' => 'decimal:7',
        'return_lat' => 'decimal:7',
        'return_lng' => 'decimal:7',
        'exit_distance_to_boundary_meters' => 'decimal:2',
        'maximum_distance_to_boundary_meters' => 'decimal:2',
        'outside_route_distance_meters' => 'decimal:2',
        'exit_notification_sent_at' => 'datetime',
        'return_notification_sent_at' => 'datetime',
        'meta' => 'array',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(TrackingSession::class, 'tracking_session_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function exitLocation(): BelongsTo
    {
        return $this->belongsTo(AttendanceGpsLocation::class, 'exit_location_id');
    }

    public function returnLocation(): BelongsTo
    {
        return $this->belongsTo(AttendanceGpsLocation::class, 'return_location_id');
    }

    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('saas_company_id', $companyId);
    }

    public function scopeForEmployee($query, int $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }

    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }
}
