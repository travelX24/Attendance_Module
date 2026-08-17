<?php

namespace Athka\Attendance\Services;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class TrackingHistoricalReplayPlanner
{
    public function __construct(
        private readonly TrackingDistanceService $distanceService,
    ) {
    }

    /**
     * Build deterministic chronological distance data from accepted points.
     *
     * Each point item must contain:
     * id, lat, lng, recorded_at and should_track.
     */
    public function plan(iterable $points): array
    {
        $items = [];

        foreach ($points as $point) {
            $item = is_array($point) ? $point : (array) $point;

            if (
                ! isset($item['id'])
                || ! is_numeric($item['lat'] ?? null)
                || ! is_numeric($item['lng'] ?? null)
                || empty($item['recorded_at'])
            ) {
                continue;
            }

            $items[] = [
                'id' => (int) $item['id'],
                'lat' => (float) $item['lat'],
                'lng' => (float) $item['lng'],
                'recorded_at' => $this->date($item['recorded_at']),
                'should_track' => (bool) ($item['should_track'] ?? false),
            ];
        }

        usort(
            $items,
            fn (array $a, array $b) =>
                ($a['recorded_at']->getTimestampMs() <=> $b['recorded_at']->getTimestampMs())
                ?: ($a['id'] <=> $b['id'])
        );

        $previous = null;
        $planned = [];
        $totalDistance = 0.0;

        foreach ($items as $item) {
            $distance = null;
            $countDistance = false;

            if ($previous !== null) {
                $distance = $this->distanceService->distanceMeters(
                    $previous['lat'],
                    $previous['lng'],
                    $item['lat'],
                    $item['lng'],
                );

                $countDistance = $item['should_track'];

                if ($countDistance) {
                    $totalDistance += $distance;
                }
            }

            $planned[] = [
                'id' => $item['id'],
                'recorded_at' => $item['recorded_at'],
                'distance_from_previous_meters' => $distance,
                'is_counted_for_distance' => $countDistance,
            ];

            $previous = $item;
        }

        return [
            'points' => $planned,
            'total_distance_meters' => round($totalDistance, 2),
            'first_point_id' => $planned[0]['id'] ?? null,
            'last_point_id' => $planned
                ? $planned[array_key_last($planned)]['id']
                : null,
        ];
    }

    private function date(mixed $value): CarbonImmutable
    {
        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::instance($value);
        }

        return CarbonImmutable::parse((string) $value);
    }
}
