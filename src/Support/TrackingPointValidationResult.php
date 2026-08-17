<?php

namespace Athka\Attendance\Support;

final readonly class TrackingPointValidationResult
{
    public function __construct(
        public bool $accepted,
        public ?string $reason = null,
        public array $normalized = [],
        public array $metrics = [],
    ) {
    }

    public static function accepted(array $normalized, array $metrics = []): self
    {
        return new self(
            accepted: true,
            normalized: $normalized,
            metrics: $metrics,
        );
    }

    public static function rejected(string $reason, array $normalized = [], array $metrics = []): self
    {
        return new self(
            accepted: false,
            reason: $reason,
            normalized: $normalized,
            metrics: $metrics,
        );
    }
}
