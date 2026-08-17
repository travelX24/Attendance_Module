<?php

namespace Athka\Attendance\Services;

use Athka\SystemSettings\Support\GeofenceDecision;

final class TrackingGeofencePointPolicy
{
    /**
     * A tracking point is usable only when the GPS gate can classify it
     * relative to the employee's configured allowed geofences.
     *
     * "outside_allowed_geofence" is intentionally accepted: it is the
     * business signal needed to detect a real work-area exit.
     *
     * Other gate denials (for example no_gps_location_assigned) are evidence
     * failures and must be retained as rejected points, never as live route
     * points and never as geofence state-machine input.
     */
    public function accepts(GeofenceDecision $decision): bool
    {
        return $decision->allowed
            || $decision->code === 'outside_allowed_geofence';
    }
}
