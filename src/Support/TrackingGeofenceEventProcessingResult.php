<?php

namespace Athka\Attendance\Support;

use Athka\Attendance\Models\TrackingGeofenceEvent;
use Athka\Attendance\Models\TrackingSession;

final readonly class TrackingGeofenceEventProcessingResult
{
    public function __construct(
        public bool $processed,
        public string $code,
        public TrackingSession $session,
        public ?TrackingGeofenceTransition $transition = null,
        public ?TrackingGeofenceEvent $event = null,
        public bool $eventCreated = false,
        public bool $eventReturned = false,
        public array $meta = [],
    ) {
    }
}
