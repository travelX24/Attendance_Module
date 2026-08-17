<?php

namespace Athka\Attendance\Support;

use Athka\Attendance\Models\TrackingSession;

final readonly class TrackingHistoricalReplayResult
{
    public function __construct(
        public bool $ok,
        public string $code,
        public TrackingSession $session,
        public int $acceptedPoints = 0,
        public int $rejectedPoints = 0,
        public int $eventsCreated = 0,
        public int $eventsReturned = 0,
        public int $eventsOpen = 0,
        public float $totalDistanceMeters = 0.0,
        public float $outsideDistanceMeters = 0.0,
        public array $meta = [],
        public ?string $message = null,
    ) {
    }
}
