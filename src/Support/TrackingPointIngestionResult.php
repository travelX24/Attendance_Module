<?php

namespace Athka\Attendance\Support;

use Athka\Attendance\Models\TrackingPoint;
use Athka\SystemSettings\Support\GeofenceDecision;

final readonly class TrackingPointIngestionResult
{
    public function __construct(
        public bool $ok,
        public string $code,
        public ?TrackingPoint $point = null,
        public ?TrackingWorkWindowResult $workWindow = null,
        public ?GeofenceDecision $geofenceDecision = null,
        public bool $duplicate = false,
        public bool $persisted = false,
        public ?string $message = null,
        public array $meta = [],
    ) {
    }

    public static function success(
        TrackingPoint $point,
        TrackingWorkWindowResult $workWindow,
        GeofenceDecision $geofenceDecision,
        bool $duplicate = false,
        array $meta = [],
    ): self {
        return new self(
            ok: true,
            code: $duplicate ? 'duplicate' : 'accepted',
            point: $point,
            workWindow: $workWindow,
            geofenceDecision: $geofenceDecision,
            duplicate: $duplicate,
            persisted: true,
            meta: $meta,
        );
    }

    public static function rejected(
        string $code,
        ?TrackingPoint $point = null,
        bool $persisted = false,
        ?string $message = null,
        array $meta = [],
    ): self {
        return new self(
            ok: false,
            code: $code,
            point: $point,
            persisted: $persisted,
            message: $message,
            meta: $meta,
        );
    }
}
