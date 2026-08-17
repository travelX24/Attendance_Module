<?php

namespace Athka\Attendance\Services;

use Athka\Attendance\Models\TrackingPoint;
use Athka\Attendance\Models\TrackingSession;
use Athka\Attendance\Support\TrackingBatchIngestionResult;
use Athka\Attendance\Support\TrackingHistoricalReplayResult;
use Athka\Employees\Models\Employee;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class TrackingOfflineBatchOrchestrator
{
    public const MAX_BATCH_POINTS = 500;

    public function __construct(
        private readonly TrackingPointIngestionService $ingestionService,
        private readonly TrackingHistoricalReplayService $replayService,
        private readonly TrackingSessionService $sessionService,
    ) {
    }

    /**
     * Persist an offline/mobile queue idempotently and then rebuild the session
     * once. Individual points never mutate the live FSM during this batch.
     */
    public function ingest(
        TrackingSession $session,
        Employee $employee,
        array $points,
        bool $replay = true,
        ?CarbonInterface $receivedAt = null,
    ): TrackingBatchIngestionResult {
        try {
            $this->sessionService->assertOwnership(
                $session,
                $employee,
            );
        } catch (Throwable $e) {
            return new TrackingBatchIngestionResult(
                ok: false,
                code: 'ownership_mismatch',
                received: count($points),
                message: $e->getMessage(),
            );
        }

        $receivedCount = count($points);

        if ($receivedCount === 0) {
            return new TrackingBatchIngestionResult(
                ok: false,
                code: 'empty_batch',
            );
        }

        if ($receivedCount > self::MAX_BATCH_POINTS) {
            return new TrackingBatchIngestionResult(
                ok: false,
                code: 'batch_too_large',
                received: $receivedCount,
                message: 'Tracking batch exceeds the maximum of '
                    . self::MAX_BATCH_POINTS
                    . ' points.',
            );
        }

        if (
            $session->status
            !== TrackingSession::STATUS_ACTIVE
        ) {
            return new TrackingBatchIngestionResult(
                ok: false,
                code: 'session_closed',
                received: $receivedCount,
                message: 'Tracking session is not active.',
            );
        }

        $lock = Cache::lock(
            "tracking:offline-batch:{$session->saas_company_id}:{$session->id}",
            90,
        );

        if (! $lock->get()) {
            return new TrackingBatchIngestionResult(
                ok: false,
                code: 'already_processing',
                received: $receivedCount,
            );
        }

        try {
            $ordered = $this->orderForDeterminism($points);
            $received = $receivedAt
                ? CarbonImmutable::instance($receivedAt)
                : CarbonImmutable::now();

            $accepted = 0;
            $rejected = 0;
            $duplicates = 0;
            $persisted = 0;
            $deferred = 0;
            $pointResults = [];

            foreach ($ordered as $index => $payload) {
                if (! is_array($payload)) {
                    $rejected++;

                    $pointResults[] = [
                        'index' => $index,
                        'ok' => false,
                        'code' => 'invalid_payload',
                        'point_id' => null,
                        'duplicate' => false,
                        'persisted' => false,
                    ];

                    continue;
                }

                $result = $this->ingestionService->ingest(
                    session: $session,
                    employee: $employee,
                    payload: $payload,
                    historical: true,
                    receivedAt: $received,
                );

                if ($result->ok) {
                    $accepted++;
                } else {
                    $rejected++;
                }

                if ($result->duplicate) {
                    $duplicates++;
                }

                if ($result->persisted) {
                    $persisted++;
                }

                $eventCode = $result->meta['geofence_event']['code']
                    ?? (
                        is_array($result->point?->meta)
                            ? (
                                $result->point
                                    ->meta['geofence_event']['code']
                                ?? null
                            )
                            : null
                    );

                if (
                    $eventCode
                    === 'deferred_historical_replay'
                ) {
                    $deferred++;
                }

                $pointResults[] = [
                    'index' => $index,
                    'client_point_uuid' =>
                        $payload['client_point_uuid']
                        ?? null,
                    'ok' => $result->ok,
                    'code' => $result->code,
                    'point_id' => $result->point?->id,
                    'duplicate' => $result->duplicate,
                    'persisted' => $result->persisted,
                    'rejection_reason' =>
                        $result->point?->rejection_reason,
                    'geofence_event_code' => $eventCode,
                ];
            }

            $pendingDeferred = $this
                ->hasDeferredHistoricalPoints(
                    (int) $session->id
                );

            $shouldReplay = $replay
                && $pendingDeferred;

            $replayResult = null;

            if ($shouldReplay) {
                $replayResult = $this->replayService
                    ->rebuild($session->refresh());
            }

            $ok = $replayResult === null
                || $replayResult->ok;

            return new TrackingBatchIngestionResult(
                ok: $ok,
                code: $replayResult && ! $replayResult->ok
                    ? 'replay_failed'
                    : 'processed',
                received: $receivedCount,
                accepted: $accepted,
                rejected: $rejected,
                duplicates: $duplicates,
                persisted: $persisted,
                deferred: $deferred,
                replayRequested: $replay,
                replayExecuted: $shouldReplay,
                replay: $replayResult,
                points: $pointResults,
                message: $replayResult && ! $replayResult->ok
                    ? $replayResult->message
                    : null,
                meta: [
                    'max_batch_points' =>
                        self::MAX_BATCH_POINTS,
                    'pending_deferred_before_replay' =>
                        $pendingDeferred,
                ],
            );
        } catch (Throwable $e) {
            report($e);

            return new TrackingBatchIngestionResult(
                ok: false,
                code: 'batch_failed',
                received: $receivedCount,
                message: $e->getMessage(),
            );
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * Stable order before persistence improves reproducibility. The replay
     * service still performs its own authoritative recorded_at/id ordering.
     */
    public function orderForDeterminism(
        array $points,
    ): array {
        $indexed = [];

        foreach ($points as $index => $point) {
            $indexed[] = [
                'original_index' => $index,
                'point' => $point,
                'recorded_at' => is_array($point)
                    ? $this->parseRecordedAt(
                        $point['recorded_at']
                            ?? $point['location_captured_at']
                            ?? null
                    )
                    : null,
                'sequence_number' => is_array($point)
                    && is_numeric(
                        $point['sequence_number'] ?? null
                    )
                        ? (int) $point['sequence_number']
                        : null,
            ];
        }

        usort(
            $indexed,
            function (array $a, array $b): int {
                $aAt = $a['recorded_at'];
                $bAt = $b['recorded_at'];

                if ($aAt && $bAt) {
                    $timeCompare =
                        $aAt->getTimestampMs()
                        <=> $bAt->getTimestampMs();

                    if ($timeCompare !== 0) {
                        return $timeCompare;
                    }
                } elseif ($aAt) {
                    return -1;
                } elseif ($bAt) {
                    return 1;
                }

                if (
                    $a['sequence_number'] !== null
                    && $b['sequence_number'] !== null
                ) {
                    $sequenceCompare =
                        $a['sequence_number']
                        <=> $b['sequence_number'];

                    if ($sequenceCompare !== 0) {
                        return $sequenceCompare;
                    }
                }

                return $a['original_index']
                    <=> $b['original_index'];
            }
        );

        return array_values(
            array_map(
                fn (array $item) => $item['point'],
                $indexed,
            )
        );
    }

    private function hasDeferredHistoricalPoints(
        int $sessionId,
    ): bool {
        return TrackingPoint::query()
            ->where(
                'tracking_session_id',
                $sessionId
            )
            ->where('is_accepted', true)
            ->get(['id', 'meta'])
            ->contains(function (TrackingPoint $point) {
                $meta = is_array($point->meta)
                    ? $point->meta
                    : [];

                return (
                    $meta['geofence_event']['code']
                    ?? null
                ) === 'deferred_historical_replay';
            });
    }

    private function parseRecordedAt(
        mixed $value,
    ): ?CarbonImmutable {
        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::instance($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
