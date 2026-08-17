<?php

namespace Athka\Attendance\Support;

final readonly class TrackingBatchIngestionResult
{
    public function __construct(
        public bool $ok,
        public string $code,
        public int $received = 0,
        public int $accepted = 0,
        public int $rejected = 0,
        public int $duplicates = 0,
        public int $persisted = 0,
        public int $deferred = 0,
        public bool $replayRequested = false,
        public bool $replayExecuted = false,
        public ?TrackingHistoricalReplayResult $replay = null,
        public array $points = [],
        public ?string $message = null,
        public array $meta = [],
    ) {
    }
}
