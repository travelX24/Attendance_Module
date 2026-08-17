<?php

namespace Athka\Attendance\Services;

use Athka\Attendance\Models\TrackingGeofenceEvent;
use Athka\Attendance\Models\TrackingPoint;
use Athka\Attendance\Models\TrackingSession;
use Athka\Attendance\Support\TrackingHistoricalReplayResult;
use Athka\Attendance\Support\TrackingWorkWindowResult;
use Athka\SystemSettings\Support\GeofenceDecision;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class TrackingHistoricalReplayService
{
    public function __construct(
        private readonly TrackingHistoricalReplayPlanner $planner,
        private readonly TrackingGeofenceEventService $eventService,
        private readonly TrackingGeofenceNotificationService $notifications,
    ) {
    }

    /**
     * Rebuild a session deterministically from all accepted points ordered by
     * recorded_at. This is intentionally explicit and is not automatically
     * invoked by point ingestion yet.
     *
     * Safety rule: once an event notification has been sent, destructive
     * replay is blocked until notification reconciliation is implemented.
     */
    public function rebuild(
        TrackingSession $session,
    ): TrackingHistoricalReplayResult {
        $lock = Cache::lock(
            "tracking:replay:{$session->saas_company_id}:{$session->id}",
            60,
        );

        if (! $lock->get()) {
            return new TrackingHistoricalReplayResult(
                ok: false,
                code: 'already_processing',
                session: $session,
                message: 'A replay for this tracking session is already running.',
            );
        }

        try {
            return DB::transaction(function () use (
                $session,
            ): TrackingHistoricalReplayResult {
                $lockedSession = TrackingSession::query()
                    ->whereKey($session->id)
                    ->lockForUpdate()
                    ->first();

                if (! $lockedSession) {
                    return new TrackingHistoricalReplayResult(
                        ok: false,
                        code: 'session_not_found',
                        session: $session,
                    );
                }

                $hasNotifiedEvents = TrackingGeofenceEvent::query()
                    ->where(
                        'tracking_session_id',
                        $lockedSession->id
                    )
                    ->where(function ($query) {
                        $query
                            ->whereNotNull('exit_notification_sent_at')
                            ->orWhereNotNull('return_notification_sent_at');
                    })
                    ->exists();

                if ($hasNotifiedEvents) {
                    return new TrackingHistoricalReplayResult(
                        ok: false,
                        code: 'notifications_already_sent',
                        session: $lockedSession,
                        message: 'Replay is blocked because geofence notifications were already sent.',
                    );
                }

                $points = TrackingPoint::query()
                    ->where(
                        'tracking_session_id',
                        $lockedSession->id
                    )
                    ->orderBy('recorded_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $accepted = $points
                    ->filter(fn (TrackingPoint $point) =>
                        (bool) $point->is_accepted
                        && $point->recorded_at !== null
                    )
                    ->values();

                $rejectedCount = $points
                    ->filter(fn (TrackingPoint $point) =>
                        ! (bool) $point->is_accepted
                    )
                    ->count();

                $plan = $this->planner->plan(
                    $accepted->map(
                        fn (TrackingPoint $point) => [
                            'id' => (int) $point->id,
                            'lat' => (float) $point->lat,
                            'lng' => (float) $point->lng,
                            'recorded_at' => $point->recorded_at,
                            'should_track' => $this->storedShouldTrack(
                                $point
                            ),
                        ]
                    )->all()
                );

                $planByPointId = collect($plan['points'])
                    ->keyBy('id');

                TrackingGeofenceEvent::query()
                    ->where(
                        'tracking_session_id',
                        $lockedSession->id
                    )
                    ->delete();

                $this->resetSessionForReplay(
                    $lockedSession,
                    acceptedCount: $accepted->count(),
                    rejectedCount: $rejectedCount,
                );

                $eventsCreated = 0;
                $eventsReturned = 0;

                foreach ($accepted as $point) {
                    $planned = $planByPointId->get(
                        (int) $point->id
                    );

                    if ($planned) {
                        $point->distance_from_previous_meters =
                            $planned['distance_from_previous_meters'];

                        $point->is_counted_for_distance =
                            (bool) $planned['is_counted_for_distance'];
                    }

                    $pointMeta = is_array($point->meta)
                        ? $point->meta
                        : [];

                    $pointMeta['geofence_event'] = [
                        'processed' => false,
                        'code' => 'replay_pending',
                    ];

                    $point->meta = $pointMeta;
                    $point->save();

                    $workWindow = $this->workWindowFromStoredPoint(
                        $point
                    );

                    $decision = $this->decisionFromStoredPoint(
                        $point
                    );

                    $result = $this->eventService
                        ->processAcceptedPoint(
                            session: $lockedSession,
                            point: $point,
                            workWindow: $workWindow,
                            decision: $decision,
                            dispatchNotifications: false,
                        );

                    if ($result->eventCreated) {
                        $eventsCreated++;
                    }

                    if ($result->eventReturned) {
                        $eventsReturned++;
                    }

                    $pointMeta = is_array($point->meta)
                        ? $point->meta
                        : [];

                    $pointMeta['geofence_event'] = [
                        'processed' => $result->processed,
                        'code' => $result->code,
                        'event_id' => $result->event?->id,
                        'event_created' => $result->eventCreated,
                        'event_returned' => $result->eventReturned,
                        'state' => $result->session->geofence_state,
                        'replayed' => true,
                    ];

                    $point->meta = $pointMeta;
                    $point->save();
                }

                $this->finalizeSessionAfterReplay(
                    $lockedSession,
                    $accepted,
                    (float) $plan['total_distance_meters'],
                );

                $this->notifications->notifyReplayEvents(
                    $lockedSession,
                );

                $eventsOpen = TrackingGeofenceEvent::query()
                    ->where(
                        'tracking_session_id',
                        $lockedSession->id
                    )
                    ->where(
                        'status',
                        TrackingGeofenceEvent::STATUS_OPEN
                    )
                    ->count();

                $eventsCount = TrackingGeofenceEvent::query()
                    ->where(
                        'tracking_session_id',
                        $lockedSession->id
                    )
                    ->count();

                return new TrackingHistoricalReplayResult(
                    ok: true,
                    code: 'rebuilt',
                    session: $lockedSession->refresh(),
                    acceptedPoints: $accepted->count(),
                    rejectedPoints: $rejectedCount,
                    eventsCreated: $eventsCount,
                    eventsReturned: $eventsReturned,
                    eventsOpen: $eventsOpen,
                    totalDistanceMeters: (float) $lockedSession->total_distance_meters,
                    outsideDistanceMeters: (float) $lockedSession->outside_distance_meters,
                    meta: [
                        'confirmed_exit_events_created' =>
                            $eventsCreated,
                        'first_point_id' =>
                            $plan['first_point_id'],
                        'last_point_id' =>
                            $plan['last_point_id'],
                    ],
                );
            });
        } catch (Throwable $e) {
            report($e);

            return new TrackingHistoricalReplayResult(
                ok: false,
                code: 'replay_failed',
                session: $session,
                message: $e->getMessage(),
            );
        } finally {
            optional($lock)->release();
        }
    }

    private function resetSessionForReplay(
        TrackingSession $session,
        int $acceptedCount,
        int $rejectedCount,
    ): void {
        $meta = is_array($session->meta)
            ? $session->meta
            : [];

        unset($meta['geofence_fsm']);

        $meta['historical_replay'] = [
            'started_at' => now()->toIso8601String(),
        ];

        $session->geofence_state =
            TrackingSession::STATE_UNKNOWN;
        $session->state_changed_at = null;
        $session->current_location_id = null;
        $session->total_distance_meters = 0;
        $session->outside_distance_meters = 0;
        $session->accepted_points_count = $acceptedCount;
        $session->rejected_points_count = $rejectedCount;
        $session->consecutive_outside_points = 0;
        $session->consecutive_inside_points = 0;
        $session->last_lat = null;
        $session->last_lng = null;
        $session->last_accuracy_meters = null;
        $session->last_recorded_at = null;
        $session->meta = $meta;
        $session->save();
    }

    private function finalizeSessionAfterReplay(
        TrackingSession $session,
        $accepted,
        float $totalDistanceMeters,
    ): void {
        $lastPoint = $accepted->last();

        $session->total_distance_meters =
            $totalDistanceMeters;

        if ($lastPoint) {
            $session->last_lat = (float) $lastPoint->lat;
            $session->last_lng = (float) $lastPoint->lng;
            $session->last_accuracy_meters =
                (float) $lastPoint->accuracy_meters;
            $session->last_recorded_at =
                $lastPoint->recorded_at;
            $session->current_location_id =
                $lastPoint->matched_location_id;
        }

        $meta = is_array($session->meta)
            ? $session->meta
            : [];

        $replay = is_array(
            $meta['historical_replay'] ?? null
        )
            ? $meta['historical_replay']
            : [];

        $replay['completed_at'] =
            now()->toIso8601String();

        $replay['points'] =
            (int) $session->accepted_points_count;

        $meta['historical_replay'] = $replay;
        $session->meta = $meta;

        if (
            $session->status
            !== TrackingSession::STATUS_ACTIVE
        ) {
            $session->geofence_state =
                TrackingSession::STATE_STOPPED;
            $session->state_changed_at =
                $session->ended_at ?: now();
        }

        $session->save();
    }

    private function storedShouldTrack(
        TrackingPoint $point,
    ): bool {
        $meta = is_array($point->meta)
            ? $point->meta
            : [];

        return (bool) (
            $meta['work_window']['should_track']
            ?? in_array(
                $point->work_state,
                [
                    TrackingPoint::WORK_STATE_WORKING,
                    TrackingPoint::WORK_STATE_BREAK,
                    TrackingPoint::WORK_STATE_PERMISSION,
                    TrackingPoint::WORK_STATE_MISSION,
                ],
                true,
            )
        );
    }

    private function workWindowFromStoredPoint(
        TrackingPoint $point,
    ): TrackingWorkWindowResult {
        $meta = is_array($point->meta)
            ? $point->meta
            : [];

        $stored = is_array(
            $meta['work_window'] ?? null
        )
            ? $meta['work_window']
            : [];

        return new TrackingWorkWindowResult(
            state: (string) (
                $stored['state']
                ?? $point->work_state
                ?? TrackingPoint::WORK_STATE_UNKNOWN
            ),
            shouldTrack: (bool) (
                $stored['should_track'] ?? false
            ),
            shouldCountOutside: (bool) (
                $stored['should_count_outside'] ?? false
            ),
            source: $stored['source'] ?? 'stored_point',
            windowStart: ! empty($stored['window_start'])
                ? CarbonImmutable::parse(
                    $stored['window_start']
                )
                : null,
            windowEnd: ! empty($stored['window_end'])
                ? CarbonImmutable::parse(
                    $stored['window_end']
                )
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
        $meta = is_array($point->meta)
            ? $point->meta
            : [];

        $stored = is_array(
            $meta['geofence'] ?? null
        )
            ? $meta['geofence']
            : [];

        return new GeofenceDecision(
            allowed: (bool) (
                $stored['allowed']
                ?? $point->inside_allowed_geofence
                ?? false
            ),
            code: (string) (
                $stored['code']
                ?? (
                    $point->inside_allowed_geofence
                        ? 'inside_allowed_geofence'
                        : 'outside_allowed_geofence'
                )
            ),
            message: (string) (
                $stored['message'] ?? ''
            ),
            httpStatus: (int) (
                $stored['http_status'] ?? 200
            ),
            locationId: isset($stored['location_id'])
                ? (int) $stored['location_id']
                : (
                    $point->matched_location_id
                        ? (int) $point->matched_location_id
                        : null
                ),
            locationName:
                $stored['location_name'] ?? null,
            geofenceType:
                $stored['geofence_type'] ?? null,
            distanceMeters:
                isset($stored['distance_meters'])
                    ? (float) $stored['distance_meters']
                    : (
                        $point->distance_to_boundary_meters
                        !== null
                            ? (float) $point
                                ->distance_to_boundary_meters
                            : null
                    ),
            gpsAccuracy:
                isset($stored['gps_accuracy'])
                    ? (float) $stored['gps_accuracy']
                    : (
                        $point->accuracy_meters !== null
                            ? (float) $point->accuracy_meters
                            : null
                    ),
            locationCapturedAt:
                $stored['location_captured_at']
                ?? $point->recorded_at?->toIso8601String(),
        );
    }
}
