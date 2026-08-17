<?php

namespace Athka\Attendance\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class TrackingGeofenceEventMetricsService
{
    public function durationSeconds(
        CarbonInterface $from,
        CarbonInterface $to,
    ): int {
        $fromAt = CarbonImmutable::instance($from);
        $toAt = CarbonImmutable::instance($to);

        return max(
            0,
            $toAt->getTimestamp() - $fromAt->getTimestamp(),
        );
    }

    public function countedOutsideSeconds(
        int $outsideSeconds,
        int $excludedSeconds,
    ): int {
        return max(0, $outsideSeconds - $excludedSeconds);
    }

    public function nextMaximumBoundaryDistance(
        ?float $currentMaximum,
        ?float $candidate,
    ): ?float {
        if ($candidate === null) {
            return $currentMaximum;
        }

        if ($currentMaximum === null) {
            return max(0.0, $candidate);
        }

        return max($currentMaximum, $candidate);
    }

    public function nextOutsideRouteDistance(
        float $currentMeters,
        ?float $segmentMeters,
        bool $countSegment,
    ): float {
        if (! $countSegment || $segmentMeters === null) {
            return max(0.0, $currentMeters);
        }

        return max(0.0, $currentMeters) + max(0.0, $segmentMeters);
    }

    public function boundedExcludedSeconds(
        CarbonInterface $startedAt,
        CarbonInterface $resumedAt,
        ?CarbonInterface $expectedEndAt = null,
    ): int {
        $start = CarbonImmutable::instance($startedAt);
        $resume = CarbonImmutable::instance($resumedAt);

        $end = $resume;

        if ($expectedEndAt !== null) {
            $expectedEnd = CarbonImmutable::instance($expectedEndAt);

            if ($expectedEnd->greaterThan($start) && $expectedEnd->lessThan($resume)) {
                $end = $expectedEnd;
            }
        }

        return $this->durationSeconds($start, $end);
    }
}
