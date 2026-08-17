<?php

namespace Athka\Attendance\Services;

use Athka\Attendance\Support\TrackingPointValidationResult;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

final class TrackingPointValidationService
{
    public function __construct(
        private readonly TrackingDistanceService $distanceService,
    ) {
    }

    public function validate(
        array $point,
        CarbonInterface $receivedAt,
        ?array $previousAcceptedPoint = null,
        bool $allowHistorical = false,
        float $maxAccuracyMeters = 50.0,
        int $maxOnlineAgeSeconds = 300,
        int $maxFutureSkewSeconds = 120,
        float $maxPlausibleSpeedMps = 70.0,
    ): TrackingPointValidationResult {
        $normalized = $this->normalize($point);

        if (! $normalized['client_point_uuid']) {
            return TrackingPointValidationResult::rejected(
                'missing_client_point_uuid',
                $normalized,
            );
        }

        if ($normalized['lat'] === null || $normalized['lng'] === null) {
            return TrackingPointValidationResult::rejected(
                'missing_coordinates',
                $normalized,
            );
        }

        if (
            $normalized['lat'] < -90.0
            || $normalized['lat'] > 90.0
            || $normalized['lng'] < -180.0
            || $normalized['lng'] > 180.0
        ) {
            return TrackingPointValidationResult::rejected(
                'invalid_coordinates',
                $normalized,
            );
        }

        if ($normalized['accuracy_meters'] === null || $normalized['accuracy_meters'] < 0.0) {
            return TrackingPointValidationResult::rejected(
                'invalid_accuracy',
                $normalized,
            );
        }

        if ($normalized['accuracy_meters'] > $maxAccuracyMeters) {
            return TrackingPointValidationResult::rejected(
                'poor_accuracy',
                $normalized,
                [
                    'max_accuracy_meters' => $maxAccuracyMeters,
                ],
            );
        }

        if ($normalized['is_mocked']) {
            return TrackingPointValidationResult::rejected(
                'mocked_location',
                $normalized,
            );
        }

        if ($normalized['recorded_at'] === null) {
            return TrackingPointValidationResult::rejected(
                'invalid_recorded_at',
                $normalized,
            );
        }

        $futureSeconds = ($normalized['recorded_at']->getTimestampMs() - $receivedAt->getTimestampMs()) / 1000;

        if ($futureSeconds > $maxFutureSkewSeconds) {
            return TrackingPointValidationResult::rejected(
                'future_location',
                $normalized,
                [
                    'future_seconds' => round($futureSeconds, 3),
                    'max_future_skew_seconds' => $maxFutureSkewSeconds,
                ],
            );
        }

        $ageSeconds = ($receivedAt->getTimestampMs() - $normalized['recorded_at']->getTimestampMs()) / 1000;

        if (! $allowHistorical && $ageSeconds > $maxOnlineAgeSeconds) {
            return TrackingPointValidationResult::rejected(
                'stale_location',
                $normalized,
                [
                    'age_seconds' => round($ageSeconds, 3),
                    'max_online_age_seconds' => $maxOnlineAgeSeconds,
                ],
            );
        }

        if (
            $normalized['battery_level'] !== null
            && ($normalized['battery_level'] < 0 || $normalized['battery_level'] > 100)
        ) {
            return TrackingPointValidationResult::rejected(
                'invalid_battery_level',
                $normalized,
            );
        }

        if (
            $normalized['heading_degrees'] !== null
            && ($normalized['heading_degrees'] < 0.0 || $normalized['heading_degrees'] > 360.0)
        ) {
            return TrackingPointValidationResult::rejected(
                'invalid_heading',
                $normalized,
            );
        }

        if ($normalized['speed_mps'] !== null && $normalized['speed_mps'] < 0.0) {
            return TrackingPointValidationResult::rejected(
                'invalid_speed',
                $normalized,
            );
        }

        $metrics = [
            'age_seconds' => round(max(0.0, $ageSeconds), 3),
            'historical_mode' => $allowHistorical,
        ];

        if ($previousAcceptedPoint !== null) {
            $previous = $this->normalize($previousAcceptedPoint);

            if (
                $previous['lat'] !== null
                && $previous['lng'] !== null
                && $previous['recorded_at'] !== null
            ) {
                $movement = $this->distanceService->movementMetrics(
                    $previous['lat'],
                    $previous['lng'],
                    $previous['recorded_at'],
                    $normalized['lat'],
                    $normalized['lng'],
                    $normalized['recorded_at'],
                );

                $metrics = array_merge($metrics, $movement);

                if (
                    ! $this->distanceService->isPlausibleMovement(
                        $movement['distance_meters'],
                        $movement['elapsed_seconds'],
                        $maxPlausibleSpeedMps,
                    )
                ) {
                    return TrackingPointValidationResult::rejected(
                        'impossible_jump',
                        $normalized,
                        array_merge($metrics, [
                            'max_plausible_speed_mps' => $maxPlausibleSpeedMps,
                        ]),
                    );
                }
            }
        }

        return TrackingPointValidationResult::accepted(
            $normalized,
            $metrics,
        );
    }

    private function normalize(array $point): array
    {
        return [
            'client_point_uuid' => $this->nullableString($point['client_point_uuid'] ?? null),
            'sequence_number' => $this->nullableInteger($point['sequence_number'] ?? null),
            'lat' => $this->nullableFloat($point['lat'] ?? null),
            'lng' => $this->nullableFloat($point['lng'] ?? null),
            'accuracy_meters' => $this->nullableFloat(
                $point['accuracy_meters']
                    ?? $point['gps_accuracy']
                    ?? null
            ),
            'speed_mps' => $this->nullableFloat($point['speed_mps'] ?? null),
            'heading_degrees' => $this->nullableFloat($point['heading_degrees'] ?? null),
            'altitude_meters' => $this->nullableFloat($point['altitude_meters'] ?? null),
            'recorded_at' => $this->nullableDateTime(
                $point['recorded_at']
                    ?? $point['location_captured_at']
                    ?? null
            ),
            'is_mocked' => filter_var(
                $point['is_mocked'] ?? false,
                FILTER_VALIDATE_BOOL,
                FILTER_NULL_ON_FAILURE
            ) ?? false,
            'provider' => $this->nullableString($point['provider'] ?? null),
            'battery_level' => $this->nullableInteger($point['battery_level'] ?? null),
        ];
    }

    private function nullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function nullableInteger(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function nullableDateTime(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::instance($value)
                ->setTimezone(date_default_timezone_get());
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)
                ->setTimezone(date_default_timezone_get());
        } catch (Throwable) {
            return null;
        }
    }
}
