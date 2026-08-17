<?php

namespace Athka\Attendance\Support;

use Athka\Attendance\Models\TrackingSession;

final readonly class TrackingSessionStartResult
{
    public function __construct(
        public bool $ok,
        public string $code,
        public ?TrackingSession $session = null,
        public ?string $message = null,
        public array $context = [],
    ) {
    }

    public static function success(
        TrackingSession $session,
        string $code = 'started',
        array $context = [],
    ): self {
        return new self(
            ok: true,
            code: $code,
            session: $session,
            context: $context,
        );
    }

    public static function failure(
        string $code,
        ?string $message = null,
        array $context = [],
    ): self {
        return new self(
            ok: false,
            code: $code,
            message: $message,
            context: $context,
        );
    }
}
