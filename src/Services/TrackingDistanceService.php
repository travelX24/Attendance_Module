<?php

namespace Athka\Attendance\Services;

use Carbon\CarbonInterface;

final class TrackingDistanceService
{
    private const EARTH_RADIUS_METERS = 6371008.8;

    public function distanceMeters(
        float $lat1,
        float $lng1,
        float $lat2,
        float $lng2,
    ): float {
        $lat1Rad = deg2rad($lat1);
        $lat2Rad = deg2rad($lat2);
        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLng = deg2rad($lng2 - $lng1);

        $a = sin($deltaLat / 2) ** 2
            + cos($lat1Rad) * cos($lat2Rad) * sin($deltaLng / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(max(0.0, 1 - $a)));

        return round(self::EARTH_RADIUS_METERS * $c, 2);
    }

    public function elapsedSeconds(
        CarbonInterface $from,
        CarbonInterface $to,
    ): float {
        return max(0.0, ($to->getTimestampMs() - $from->getTimestampMs()) / 1000);
    }

    public function impliedSpeedMps(
        float $distanceMeters,
        CarbonInterface $from,
        CarbonInterface $to,
    ): ?float {
        $seconds = $this->elapsedSeconds($from, $to);

        if ($seconds <= 0.0) {
            return null;
        }

        return round($distanceMeters / $seconds, 3);
    }

    public function movementMetrics(
        float $fromLat,
        float $fromLng,
        CarbonInterface $fromRecordedAt,
        float $toLat,
        float $toLng,
        CarbonInterface $toRecordedAt,
    ): array {
        $distance = $this->distanceMeters(
            $fromLat,
            $fromLng,
            $toLat,
            $toLng,
        );

        $elapsed = $this->elapsedSeconds(
            $fromRecordedAt,
            $toRecordedAt,
        );

        return [
            'distance_meters' => $distance,
            'elapsed_seconds' => round($elapsed, 3),
            'implied_speed_mps' => $elapsed > 0.0
                ? round($distance / $elapsed, 3)
                : null,
        ];
    }

    public function isPlausibleMovement(
        float $distanceMeters,
        float $elapsedSeconds,
        float $maxSpeedMps = 70.0,
        float $minimumDistanceForSpeedCheckMeters = 200.0,
    ): bool {
        if ($distanceMeters < $minimumDistanceForSpeedCheckMeters) {
            return true;
        }

        if ($elapsedSeconds <= 0.0) {
            return false;
        }

        return ($distanceMeters / $elapsedSeconds) <= $maxSpeedMps;
    }
}
