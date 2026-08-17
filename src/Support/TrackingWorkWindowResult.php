<?php

namespace Athka\Attendance\Support;

use Carbon\CarbonImmutable;

final readonly class TrackingWorkWindowResult
{
    public function __construct(
        public string $state,
        public bool $shouldTrack,
        public bool $shouldCountOutside,
        public ?string $source = null,
        public ?CarbonImmutable $windowStart = null,
        public ?CarbonImmutable $windowEnd = null,
        public ?int $periodId = null,
        public array $meta = [],
    ) {
    }

    public function isWorking(): bool
    {
        return $this->state === 'working';
    }

    public function isExcluded(): bool
    {
        return ! $this->shouldCountOutside;
    }
}
