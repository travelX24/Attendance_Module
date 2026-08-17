<?php

namespace Athka\Attendance\Support;

use Carbon\CarbonImmutable;

final readonly class TrackingGeofenceTransition
{
    public function __construct(
        public string $previousState,
        public string $nextState,
        public string $action,
        public int $consecutiveOutsidePoints = 0,
        public int $consecutiveInsidePoints = 0,
        public ?CarbonImmutable $pendingExitStartedAt = null,
        public ?CarbonImmutable $pendingReturnStartedAt = null,
        public bool $excluded = false,
        public ?string $exclusionReason = null,
        public ?string $pausedFromState = null,
        public bool $resumed = false,
        public array $meta = [],
    ) {
    }

    public function stateChanged(): bool
    {
        return $this->previousState !== $this->nextState;
    }

    public function confirmsExit(): bool
    {
        return $this->action === 'confirm_exit';
    }

    public function confirmsReturn(): bool
    {
        return $this->action === 'confirm_return';
    }
}
