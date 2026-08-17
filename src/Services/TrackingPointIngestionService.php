<?php

namespace Athka\Attendance\Services;

use Athka\Attendance\Models\TrackingPoint;
use Athka\Attendance\Models\TrackingSession;
use Athka\Attendance\Support\TrackingPointIngestionResult;
use Athka\Employees\Models\Employee;
use Athka\SystemSettings\Services\AttendanceLocationGateService;
use Athka\SystemSettings\Support\GeofenceDecision;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class TrackingPointIngestionService
{
    public function __construct(
        private readonly TrackingPointValidationService $validationService,
        private readonly TrackingWorkWindowService $workWindowService,
        private readonly TrackingDistanceService $distanceService,
        private readonly AttendanceLocationGateService $locationGateService,
        private readonly TrackingSessionService $sessionService,
        private readonly TrackingGeofenceEventService $geofenceEventService,
        private readonly TrackingGeofencePointPolicy $geofencePointPolicy,
    ) {
    }

    public function ingest(
        TrackingSession $session,
        Employee $employee,
        array $payload,
        bool $historical = false,
        ?CarbonInterface $receivedAt = null,
    ): TrackingPointIngestionResult {
        try {
            $this->sessionService->assertOwnership($session, $employee);
        } catch (Throwable $e) {
            return TrackingPointIngestionResult::rejected(
                'ownership_mismatch',
                message: $e->getMessage(),
            );
        }

        if ($session->status !== TrackingSession::STATUS_ACTIVE) {
            return TrackingPointIngestionResult::rejected(
                'session_closed',
                message: 'Tracking session is not active.',
            );
        }

        $received = $receivedAt
            ? CarbonImmutable::instance($receivedAt)
            : CarbonImmutable::now();

        $clientPointUuid = $this->cleanString(
            $payload['client_point_uuid'] ?? null
        );

        if ($clientPointUuid) {
            $duplicate = TrackingPoint::query()
                ->where('tracking_session_id', $session->id)
                ->where('client_point_uuid', $clientPointUuid)
                ->first();

            if ($duplicate) {
                $workWindow = $this->workWindowFromStoredPoint($duplicate);
                $decision = $this->decisionFromStoredPoint($duplicate);

                return TrackingPointIngestionResult::success(
                    $duplicate,
                    $workWindow,
                    $decision,
                    duplicate: true,
                );
            }
        }

        $firstPass = $this->validationService->validate(
            $payload,
            $received,
            allowHistorical: $historical,
        );

        if (! $firstPass->accepted) {
            $point = $this->persistRejectedWhenPossible(
                $session,
                $employee,
                $firstPass->normalized,
                $received,
                $firstPass->reason ?? 'invalid_point',
                $payload,
            );

            if ($point) {
                $session->increment('rejected_points_count');
            }

            return TrackingPointIngestionResult::rejected(
                $firstPass->reason ?? 'invalid_point',
                point: $point,
                persisted: $point !== null,
                meta: $firstPass->metrics,
            );
        }

        /** @var CarbonImmutable $recordedAt */
        $recordedAt = $firstPass->normalized['recorded_at'];

        $outOfOrder = $session->last_recorded_at
            && $recordedAt->lessThan(
                CarbonImmutable::instance($session->last_recorded_at)
            );

        $effectiveHistorical = $historical || $outOfOrder;

        $previous = $this->previousAcceptedPoint(
            (int) $session->id,
            $recordedAt,
        );

        $previousPayload = $previous
            ? [
                'client_point_uuid' => $previous->client_point_uuid,
                'sequence_number' => $previous->sequence_number,
                'lat' => (float) $previous->lat,
                'lng' => (float) $previous->lng,
                'accuracy_meters' => (float) $previous->accuracy_meters,
                'speed_mps' => $previous->speed_mps !== null
                    ? (float) $previous->speed_mps
                    : null,
                'heading_degrees' => $previous->heading_degrees !== null
                    ? (float) $previous->heading_degrees
                    : null,
                'recorded_at' => $previous->recorded_at,
                'is_mocked' => (bool) $previous->is_mocked,
                'battery_level' => $previous->battery_level,
            ]
            : null;

        $validated = $this->validationService->validate(
            $payload,
            $received,
            previousAcceptedPoint: $previousPayload,
            allowHistorical: $effectiveHistorical,
        );

        if (! $validated->accepted) {
            $point = $this->persistRejectedWhenPossible(
                $session,
                $employee,
                $validated->normalized,
                $received,
                $validated->reason ?? 'invalid_point',
                $payload,
            );

            if ($point) {
                $session->increment('rejected_points_count');
            }

            return TrackingPointIngestionResult::rejected(
                $validated->reason ?? 'invalid_point',
                point: $point,
                persisted: $point !== null,
                meta: $validated->metrics,
            );
        }

        $workWindow = $this->workWindowService->resolve(
            $employee,
            $recordedAt,
        );

        $allowedLocationIds = $this->locationGateService
            ->allowedLocationsForEmployee(
                (int) $session->saas_company_id,
                $employee,
            )
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->values()
            ->all();

        $gatePayload = [
            'lat' => $validated->normalized['lat'],
            'lng' => $validated->normalized['lng'],
            'is_mocked' => $validated->normalized['is_mocked'],
            'gps_accuracy' => $validated->normalized['accuracy_meters'],
            'location_captured_at' => $recordedAt->toIso8601String(),
        ];

        $geofenceDecision = $effectiveHistorical
            ? $this->locationGateService->evaluateOffline(
                companyId: (int) $session->saas_company_id,
                employee: $employee,
                payload: $gatePayload,
            )
            : $this->locationGateService->evaluateOnline(
                companyId: (int) $session->saas_company_id,
                employee: $employee,
                payload: $gatePayload,
                allowedLocationIds: $allowedLocationIds,
            );

        if (! $this->geofencePointPolicy->accepts($geofenceDecision)) {
            $point = $this->persistRejectedWhenPossible(
                $session,
                $employee,
                $validated->normalized,
                $received,
                $geofenceDecision->code,
                $payload,
                $workWindow,
                $geofenceDecision,
            );

            if ($point) {
                $session->increment('rejected_points_count');
            }

            return TrackingPointIngestionResult::rejected(
                $geofenceDecision->code,
                point: $point,
                persisted: $point !== null,
                meta: [
                    'geofence' => $geofenceDecision->toArray(),
                    'work_state' => $workWindow->state,
                    'should_track' => $workWindow->shouldTrack,
                    'should_count_outside' => $workWindow->shouldCountOutside,
                ],
            );
        }

        $distanceFromPrevious = isset(
            $validated->metrics['distance_meters']
        )
            ? (float) $validated->metrics['distance_meters']
            : null;

        $countDistance = ! $effectiveHistorical
            && $workWindow->shouldTrack
            && $previous !== null
            && $distanceFromPrevious !== null;

        try {
            return DB::transaction(function () use (
                $session,
                $employee,
                $payload,
                $received,
                $validated,
                $recordedAt,
                $effectiveHistorical,
                $outOfOrder,
                $workWindow,
                $geofenceDecision,
                $distanceFromPrevious,
                $countDistance,
            ): TrackingPointIngestionResult {
                $lockedSession = TrackingSession::query()
                    ->whereKey($session->id)
                    ->lockForUpdate()
                    ->first();

                if (! $lockedSession) {
                    return TrackingPointIngestionResult::rejected(
                        'session_not_found'
                    );
                }

                if ($lockedSession->status !== TrackingSession::STATUS_ACTIVE) {
                    return TrackingPointIngestionResult::rejected(
                        'session_closed'
                    );
                }

                $existing = TrackingPoint::query()
                    ->where(
                        'tracking_session_id',
                        $lockedSession->id
                    )
                    ->where(
                        'client_point_uuid',
                        $validated->normalized['client_point_uuid']
                    )
                    ->first();

                if ($existing) {
                    return TrackingPointIngestionResult::success(
                        $existing,
                        $this->workWindowFromStoredPoint($existing),
                        $this->decisionFromStoredPoint($existing),
                        duplicate: true,
                    );
                }

                $point = TrackingPoint::query()->create([
                    'tracking_session_id' => (int) $lockedSession->id,
                    'saas_company_id' => (int) $lockedSession->saas_company_id,
                    'employee_id' => (int) $employee->id,
                    'client_point_uuid' => $validated->normalized['client_point_uuid'],
                    'sequence_number' => $validated->normalized['sequence_number'],
                    'lat' => $validated->normalized['lat'],
                    'lng' => $validated->normalized['lng'],
                    'accuracy_meters' => $validated->normalized['accuracy_meters'],
                    'speed_mps' => $validated->normalized['speed_mps'],
                    'heading_degrees' => $validated->normalized['heading_degrees'],
                    'altitude_meters' => $validated->normalized['altitude_meters'],
                    'recorded_at' => $recordedAt,
                    'received_at' => $received,
                    'is_mocked' => $validated->normalized['is_mocked'],
                    'provider' => $validated->normalized['provider'],
                    'battery_level' => $validated->normalized['battery_level'],
                    'is_accepted' => true,
                    'is_counted_for_distance' => $countDistance,
                    'rejection_reason' => null,
                    'work_state' => $workWindow->state,
                    'distance_from_previous_meters' => $distanceFromPrevious,
                    'matched_location_id' => $geofenceDecision->locationId,
                    'inside_allowed_geofence' => $geofenceDecision->allowed,
                    'distance_to_boundary_meters' => $geofenceDecision->distanceMeters,
                    'meta' => [
                        'work_window' => [
                            'state' => $workWindow->state,
                            'source' => $workWindow->source,
                            'should_track' => $workWindow->shouldTrack,
                            'should_count_outside' => $workWindow->shouldCountOutside,
                            'period_id' => $workWindow->periodId,
                            'window_start' => $workWindow->windowStart?->toIso8601String(),
                            'window_end' => $workWindow->windowEnd?->toIso8601String(),
                            'meta' => $workWindow->meta,
                        ],
                        'geofence' => $geofenceDecision->toArray(),
                        'historical' => $effectiveHistorical,
                        'out_of_order' => $outOfOrder,
                        'geofence_event' => [
                            'processed' => false,
                            'code' => $effectiveHistorical
                                ? 'deferred_historical_replay'
                                : 'pending',
                        ],
                        'raw' => $this->safeRawMeta($payload),
                    ],
                ]);

                $lockedSession->accepted_points_count =
                    (int) $lockedSession->accepted_points_count + 1;

                if ($countDistance) {
                    $lockedSession->total_distance_meters =
                        (float) $lockedSession->total_distance_meters
                        + (float) $distanceFromPrevious;
                }

                $isNewLatest = ! $lockedSession->last_recorded_at
                    || $recordedAt->greaterThanOrEqualTo(
                        CarbonImmutable::instance(
                            $lockedSession->last_recorded_at
                        )
                    );

                if ($isNewLatest) {
                    $lockedSession->last_lat = $validated->normalized['lat'];
                    $lockedSession->last_lng = $validated->normalized['lng'];
                    $lockedSession->last_accuracy_meters =
                        $validated->normalized['accuracy_meters'];
                    $lockedSession->last_recorded_at = $recordedAt;
                    $lockedSession->current_location_id =
                        $geofenceDecision->locationId;
                }

                $lockedSession->save();

                if ($effectiveHistorical) {
                    $eventProcessing = [
                        'processed' => false,
                        'code' => 'deferred_historical_replay',
                        'event_id' => null,
                        'event_created' => false,
                        'event_returned' => false,
                        'state' => $lockedSession->geofence_state,
                    ];
                } else {
                    $eventResult = $this->geofenceEventService
                        ->processAcceptedPoint(
                            session: $lockedSession,
                            point: $point,
                            workWindow: $workWindow,
                            decision: $geofenceDecision,
                        );

                    $eventProcessing = [
                        'processed' => $eventResult->processed,
                        'code' => $eventResult->code,
                        'event_id' => $eventResult->event?->id,
                        'event_created' => $eventResult->eventCreated,
                        'event_returned' => $eventResult->eventReturned,
                        'state' => $eventResult->session->geofence_state,
                    ];
                }

                $pointMeta = is_array($point->meta)
                    ? $point->meta
                    : [];

                $pointMeta['geofence_event'] = $eventProcessing;
                $point->meta = $pointMeta;
                $point->save();

                return TrackingPointIngestionResult::success(
                    $point,
                    $workWindow,
                    $geofenceDecision,
                    meta: [
                        'historical' => $effectiveHistorical,
                        'out_of_order' => $outOfOrder,
                        'distance_counted' => $countDistance,
                        'geofence_event' => $eventProcessing,
                    ],
                );
            });
        } catch (QueryException $e) {
            if ($this->looksLikeDuplicateKey($e)) {
                $existing = TrackingPoint::query()
                    ->where('tracking_session_id', $session->id)
                    ->where(
                        'client_point_uuid',
                        $validated->normalized['client_point_uuid']
                    )
                    ->first();

                if ($existing) {
                    return TrackingPointIngestionResult::success(
                        $existing,
                        $this->workWindowFromStoredPoint($existing),
                        $this->decisionFromStoredPoint($existing),
                        duplicate: true,
                    );
                }
            }

            report($e);

            return TrackingPointIngestionResult::rejected(
                'persistence_failed',
                message: $e->getMessage(),
            );
        } catch (Throwable $e) {
            report($e);

            return TrackingPointIngestionResult::rejected(
                'ingestion_failed',
                message: $e->getMessage(),
            );
        }
    }

    private function previousAcceptedPoint(
        int $sessionId,
        CarbonImmutable $recordedAt,
    ): ?TrackingPoint {
        return TrackingPoint::query()
            ->where('tracking_session_id', $sessionId)
            ->where('is_accepted', true)
            ->where('recorded_at', '<=', $recordedAt)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->first();
    }

    private function persistRejectedWhenPossible(
        TrackingSession $session,
        Employee $employee,
        array $normalized,
        CarbonImmutable $receivedAt,
        string $reason,
        array $rawPayload,
        ?\Athka\Attendance\Support\TrackingWorkWindowResult $workWindow = null,
        ?GeofenceDecision $geofenceDecision = null,
    ): ?TrackingPoint {
        if (
            empty($normalized['client_point_uuid'])
            || ! is_numeric($normalized['lat'] ?? null)
            || ! is_numeric($normalized['lng'] ?? null)
            || ! is_numeric($normalized['accuracy_meters'] ?? null)
            || ! (($normalized['recorded_at'] ?? null) instanceof CarbonInterface)
        ) {
            return null;
        }

        $existing = TrackingPoint::query()
            ->where('tracking_session_id', $session->id)
            ->where(
                'client_point_uuid',
                $normalized['client_point_uuid']
            )
            ->first();

        if ($existing) {
            return $existing;
        }

        return TrackingPoint::query()->create([
            'tracking_session_id' => (int) $session->id,
            'saas_company_id' => (int) $session->saas_company_id,
            'employee_id' => (int) $employee->id,
            'client_point_uuid' => $normalized['client_point_uuid'],
            'sequence_number' => $normalized['sequence_number'] ?? null,
            'lat' => (float) $normalized['lat'],
            'lng' => (float) $normalized['lng'],
            'accuracy_meters' => (float) $normalized['accuracy_meters'],
            'speed_mps' => $normalized['speed_mps'] ?? null,
            'heading_degrees' => $normalized['heading_degrees'] ?? null,
            'altitude_meters' => $normalized['altitude_meters'] ?? null,
            'recorded_at' => $normalized['recorded_at'],
            'received_at' => $receivedAt,
            'is_mocked' => (bool) ($normalized['is_mocked'] ?? false),
            'provider' => $normalized['provider'] ?? null,
            'battery_level' => $normalized['battery_level'] ?? null,
            'is_accepted' => false,
            'is_counted_for_distance' => false,
            'rejection_reason' => $reason,
            'work_state' => $workWindow?->state
                ?? TrackingPoint::WORK_STATE_UNKNOWN,
            'distance_from_previous_meters' => null,
            'matched_location_id' => $geofenceDecision?->locationId,
            'inside_allowed_geofence' => $geofenceDecision?->allowed,
            'distance_to_boundary_meters' => $geofenceDecision?->distanceMeters,
            'meta' => array_filter([
                'rejection_reason' => $reason,
                'work_window' => $workWindow
                    ? [
                        'state' => $workWindow->state,
                        'source' => $workWindow->source,
                        'should_track' => $workWindow->shouldTrack,
                        'should_count_outside' => $workWindow->shouldCountOutside,
                        'period_id' => $workWindow->periodId,
                        'window_start' => $workWindow->windowStart?->toIso8601String(),
                        'window_end' => $workWindow->windowEnd?->toIso8601String(),
                        'meta' => $workWindow->meta,
                    ]
                    : null,
                'geofence' => $geofenceDecision?->toArray(),
                'raw' => $this->safeRawMeta($rawPayload),
            ], fn ($value) => $value !== null),
        ]);
    }

    private function workWindowFromStoredPoint(
        TrackingPoint $point,
    ): \Athka\Attendance\Support\TrackingWorkWindowResult {
        $meta = is_array($point->meta) ? $point->meta : [];
        $stored = $meta['work_window'] ?? [];

        return new \Athka\Attendance\Support\TrackingWorkWindowResult(
            state: (string) ($stored['state'] ?? $point->work_state ?? 'unknown'),
            shouldTrack: (bool) ($stored['should_track'] ?? false),
            shouldCountOutside: (bool) (
                $stored['should_count_outside'] ?? false
            ),
            source: $stored['source'] ?? 'stored_point',
            windowStart: ! empty($stored['window_start'])
                ? CarbonImmutable::parse($stored['window_start'])
                : null,
            windowEnd: ! empty($stored['window_end'])
                ? CarbonImmutable::parse($stored['window_end'])
                : null,
            periodId: isset($stored['period_id'])
                ? (int) $stored['period_id']
                : null,
            meta: is_array($stored['meta'] ?? null)
                ? $stored['meta']
                : [],
        );
    }

    private function decisionFromStoredPoint(
        TrackingPoint $point,
    ): GeofenceDecision {
        $meta = is_array($point->meta) ? $point->meta : [];
        $stored = $meta['geofence'] ?? [];

        return new GeofenceDecision(
            allowed: (bool) (
                $stored['allowed']
                ?? $point->inside_allowed_geofence
                ?? false
            ),
            code: (string) ($stored['code'] ?? 'stored_point'),
            message: (string) ($stored['message'] ?? ''),
            httpStatus: (int) ($stored['http_status'] ?? 200),
            locationId: isset($stored['location_id'])
                ? (int) $stored['location_id']
                : (
                    $point->matched_location_id
                        ? (int) $point->matched_location_id
                        : null
                ),
            locationName: $stored['location_name'] ?? null,
            geofenceType: $stored['geofence_type'] ?? null,
            distanceMeters: isset($stored['distance_meters'])
                ? (float) $stored['distance_meters']
                : (
                    $point->distance_to_boundary_meters !== null
                        ? (float) $point->distance_to_boundary_meters
                        : null
                ),
            gpsAccuracy: isset($stored['gps_accuracy'])
                ? (float) $stored['gps_accuracy']
                : (
                    $point->accuracy_meters !== null
                        ? (float) $point->accuracy_meters
                        : null
                ),
            locationCapturedAt: $stored['location_captured_at']
                ?? $point->recorded_at?->toIso8601String(),
        );
    }

    private function safeRawMeta(array $payload): array
    {
        return array_intersect_key(
            $payload,
            array_flip([
                'client_point_uuid',
                'sequence_number',
                'speed_mps',
                'heading_degrees',
                'altitude_meters',
                'provider',
                'battery_level',
            ])
        );
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function looksLikeDuplicateKey(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        return $sqlState === '23000'
            && in_array($driverCode, [1062, 19], true);
    }
}
