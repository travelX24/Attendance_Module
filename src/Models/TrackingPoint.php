<?php

namespace Athka\Attendance\Models;

use Athka\Employees\Models\Employee;
use Athka\SystemSettings\Models\AttendanceGpsLocation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrackingPoint extends Model
{
    public const WORK_STATE_UNKNOWN = 'unknown';
    public const WORK_STATE_WORKING = 'working';
    public const WORK_STATE_BREAK = 'break';
    public const WORK_STATE_PERMISSION = 'permission';
    public const WORK_STATE_MISSION = 'mission';
    public const WORK_STATE_OUTSIDE_WORK_WINDOW = 'outside_work_window';

    protected $table = 'tracking_points';

    protected $guarded = ['id'];

    protected $casts = [
        'lat' => 'decimal:7',
        'lng' => 'decimal:7',
        'accuracy_meters' => 'decimal:2',
        'speed_mps' => 'decimal:3',
        'heading_degrees' => 'decimal:2',
        'altitude_meters' => 'decimal:2',
        'recorded_at' => 'datetime',
        'received_at' => 'datetime',
        'is_mocked' => 'boolean',
        'battery_level' => 'integer',
        'is_accepted' => 'boolean',
        'is_counted_for_distance' => 'boolean',
        'distance_from_previous_meters' => 'decimal:2',
        'inside_allowed_geofence' => 'boolean',
        'distance_to_boundary_meters' => 'decimal:2',
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

    public function matchedLocation(): BelongsTo
    {
        return $this->belongsTo(AttendanceGpsLocation::class, 'matched_location_id');
    }

    public function scopeAccepted($query)
    {
        return $query->where('is_accepted', true);
    }

    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('saas_company_id', $companyId);
    }

    public function scopeForEmployee($query, int $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }
}
