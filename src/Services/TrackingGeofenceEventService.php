<?php

namespace Athka\Attendance\Services;

use Athka\Attendance\Models\TrackingGeofenceEvent;
use Athka\Attendance\Models\TrackingPoint;
use Athka\Attendance\Models\TrackingSession;
use Athka\Attendance\Support\TrackingGeofenceEventProcessingResult;
use Athka\Attendance\Support\TrackingGeofenceTransition;
use Athka\Attendance\Support\TrackingWorkWindowResult;
use Athka\SystemSettings\Support\GeofenceDecision;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class TrackingGeofenceEventService
{
    public function __construct(
        private readonly TrackingGeofenceStateMachine $stateMachine,
        private readonly TrackingGeofenceEventMetricsService $metrics,
        private readonly TrackingGeofenceNotificationService $notifications,
    ) {
    }

    /**
     * Only the two genuine geofence outcomes may drive the state machine.
     *
     * A valid GPS point with "no_gps_location_assigned" must remain stored,
     * but it must never be interpreted as an employee exit.
     */
    public function supportsDecision(GeofenceDecision $decision): bool
    {
        return in_array(
            $decision->code,
            [
                'inside_allowed_geofence',
                'outside_allowed_geofence',
            ],
            true,
        );
    }

    /**
     * Process one already-persisted, accepted TrackingPoint.
     *
     * This method is designed to run inside the same DB transaction used by
     * point ingestion. The caller must already own/lock the TrackingSession.
     */
    public function processAcceptedPoint(
        TrackingSession $session,
        TrackingPoint $point,
        TrackingWorkWindowResult $workWindow,
        GeofenceDecision $decision,
        bool $dispatchNotifications = true,
    ): TrackingGeofenceEventProcessingResult {
        if (! $point->is_accepted) {
            return new TrackingGeofenceEventProcessingResult(
                processed: false,
                code: 'point_not_accepted',
                session: $session,
            );
        }

        if (! $point->recorded_at) {
            return new TrackingGeofenceEventProcessingResult(
                processed: false,
                code: 'missing_recorded_at',
                session: $session,
            );
        }

        if (! $this->supportsDecision($decision)) {
            return new TrackingGeofenceEventProcessingResult(
                processed: false,
                code: 'geofence_unavailable',
                session: $session,
                meta: [
                    'geofence_code' => $decision->code,
                ],
            );
        }

        $at = CarbonImmutable::instance($point->recorded_at);

        $sessionMeta = is_array($session->meta)
            ? $session->meta
            : [];

        $fsm = is_array($sessionMeta['geofence_fsm'] ?? null)
            ? $sessionMeta['geofence_fsm']
            : [];

        $pendingExitStartedAt = $this->parseDate(
            $fsm['pending_exit_started_at'] ?? null
        );

        $pendingReturnStartedAt = $this->parseDate(
            $fsm['pending_return_started_at'] ?? null
        );

        $pausedFromState = is_string($fsm['paused_from_state'] ?? null)
            ? $fsm['paused_from_state']
            : null;

        $transition = $this->stateMachine->transition(
            currentState: (string) (
                $session->geofence_state
                ?: TrackingSession::STATE_UNKNOWN
            ),
            insideAllowedGeofence: $decision->allowed,
            shouldCountOutside: $workWindow->shouldCountOutside,
            workState: $workWindow->state,
            recordedAt: $at,
            consecutiveOutsidePoints: (int) $session->consecutive_outside_points,
            consecutiveInsidePoints: (int) $session->consecutive_inside_points,
            pendingExitStartedAt: $pendingExitStartedAt,
            pendingReturnStartedAt: $pendingReturnStartedAt,
            pausedFromState: $pausedFromState,
        );

        $openEvent = TrackingGeofenceEvent::query()
            ->where('tracking_session_id', $session->id)
            ->where('status', TrackingGeofenceEvent::STATUS_OPEN)
            ->lockForUpdate()
            ->orderByDesc('id')
            ->first();

        $eventCreated = false;
        $eventReturned = false;

        if ($transition->resumed && $openEvent) {
            $this->applyResumeExclusion(
                event: $openEvent,
                fsm: $fsm,
                resumedAt: $at,
            );
        }

        $this->updatePendingSnapshots(
            fsm: $fsm,
            transition: $transition,
            point: $point,
            decision: $decision,
            workWindow: $workWindow,
        );

        if ($transition->confirmsExit()) {
            [$openEvent, $eventCreated] = $this->confirmExit(
                session: $session,
                point: $point,
                transition: $transition,
                decision: $decision,
                fsm: $fsm,
                existingOpenEvent: $openEvent,
            );
        } elseif ($openEvent) {
            $this->updateOpenEvent(
                event: $openEvent,
                point: $point,
                transition: $transition,
                decision: $decision,
                workWindow: $workWindow,
                fsm: $fsm,
            );
        }

        if ($transition->confirmsReturn() && $openEvent) {
            $this->confirmReturn(
                event: $openEvent,
                transition: $transition,
                fsm: $fsm,
            );

            $eventReturned = true;
        }

        if ($dispatchNotifications && $openEvent) {
            if ($openEvent->exit_notification_sent_at === null) {
                $this->notifications->notifyExit($openEvent);
            }

            if ($eventReturned) {
                $this->notifications->notifyReturn($openEvent);
            }
        }

        $this->updateSessionState(
            session: $session,
            transition: $transition,
            fsm: $fsm,
            at: $at,
        );

        return new TrackingGeofenceEventProcessingResult(
            processed: true,
            code: $transition->action,
            session: $session,
            transition: $transition,
            event: $openEvent?->refresh(),
            eventCreated: $eventCreated,
            eventReturned: $eventReturned,
            meta: [
                'geofence_code' => $decision->code,
            ],
        );
    }

    private function updatePendingSnapshots(
        array &$fsm,
        TrackingGeofenceTransition $transition,
        TrackingPoint $point,
        GeofenceDecision $decision,
        TrackingWorkWindowResult $workWindow,
    ): void {
        if ($transition->action === 'start_exit_pending') {
            $fsm['pending_exit_started_at'] =
                $transition->pendingExitStartedAt?->toIso8601String();

            $fsm['pending_exit'] = [
                'point_id' => (int) $point->id,
                'lat' => (float) $point->lat,
                'lng' => (float) $point->lng,
                'location_id' => $decision->locationId,
                'distance_to_boundary_meters' => $decision->distanceMeters,
                'maximum_distance_to_boundary_meters' => $decision->distanceMeters,
                'outside_route_distance_meters' => 0.0,
            ];
        }

        if (in_array(
            $transition->action,
            ['continue_exit_pending', 'confirm_exit'],
            true,
        )) {
            $snapshot = is_array($fsm['pending_exit'] ?? null)
                ? $fsm['pending_exit']
                : [];

            $snapshot['maximum_distance_to_boundary_meters'] =
                $this->metrics->nextMaximumBoundaryDistance(
                    isset($snapshot['maximum_distance_to_boundary_meters'])
                        ? (float) $snapshot['maximum_distance_to_boundary_meters']
                        : null,
                    $decision->distanceMeters,
                );

            $snapshot['outside_route_distance_meters'] =
                $this->metrics->nextOutsideRouteDistance(
                    (float) (
                        $snapshot['outside_route_distance_meters']
                        ?? 0.0
                    ),
                    $point->distance_from_previous_meters !== null
                        ? (float) $point->distance_from_previous_meters
                        : null,
                    (bool) $point->is_counted_for_distance,
                );

            $fsm['pending_exit'] = $snapshot;
            $fsm['pending_exit_started_at'] =
                $transition->pendingExitStartedAt?->toIso8601String();
        }

        if ($transition->action === 'cancel_exit_pending') {
            unset(
                $fsm['pending_exit'],
                $fsm['pending_exit_started_at'],
            );
        }

        if ($transition->action === 'start_return_pending') {
            $fsm['pending_return_started_at'] =
                $transition->pendingReturnStartedAt?->toIso8601String();

            $fsm['pending_return'] = [
                'point_id' => (int) $point->id,
                'lat' => (float) $point->lat,
                'lng' => (float) $point->lng,
                'location_id' => $decision->locationId,
            ];
        }

        if ($transition->action === 'continue_return_pending') {
            $fsm['pending_return_started_at'] =
                $transition->pendingReturnStartedAt?->toIso8601String();
        }

        if ($transition->action === 'cancel_return_pending') {
            unset(
                $fsm['pending_return'],
                $fsm['pending_return_started_at'],
            );
        }

        if ($transition->excluded) {
            $fsm['paused_from_state'] = $transition->pausedFromState;

            if ($transition->action === 'pause') {
                $fsm['pause'] = [
                    'started_at' => $this->pauseStartAt(
                        $workWindow,
                        CarbonImmutable::instance($point->recorded_at),
                    )->toIso8601String(),
                    'expected_end_at' => $workWindow->windowEnd?->toIso8601String(),
                    'reason' => $transition->exclusionReason,
                    'point_id' => (int) $point->id,
                ];
            }
        }

        if ($transition->resumed) {
            unset(
                $fsm['pause'],
                $fsm['paused_from_state'],
            );
        }
    }

    private function confirmExit(
        TrackingSession $session,
        TrackingPoint $point,
        TrackingGeofenceTransition $transition,
        GeofenceDecision $decision,
        array &$fsm,
        ?TrackingGeofenceEvent $existingOpenEvent,
    ): array {
        if ($existingOpenEvent) {
            return [$existingOpenEvent, false];
        }

        $snapshot = is_array($fsm['pending_exit'] ?? null)
            ? $fsm['pending_exit']
            : [];

        $exitedAt = $transition->pendingExitStartedAt
            ?? CarbonImmutable::instance($point->recorded_at);

        $event = TrackingGeofenceEvent::query()->create([
            'tracking_session_id' => (int) $session->id,
            'saas_company_id' => (int) $session->saas_company_id,
            'employee_id' => (int) $session->employee_id,
            'status' => TrackingGeofenceEvent::STATUS_OPEN,
            'classification' =>
                TrackingGeofenceEvent::CLASSIFICATION_WORK_EXIT,
            'is_counted' => true,
            'exclusion_reason' => null,
            'exit_location_id' => isset($snapshot['location_id'])
                ? (int) $snapshot['location_id']
                : $decision->locationId,
            'return_location_id' => null,
            'exited_at' => $exitedAt,
            'returned_at' => null,
            'exit_lat' => isset($snapshot['lat'])
                ? (float) $snapshot['lat']
                : (float) $point->lat,
            'exit_lng' => isset($snapshot['lng'])
                ? (float) $snapshot['lng']
                : (float) $point->lng,
            'return_lat' => null,
            'return_lng' => null,
            'exit_distance_to_boundary_meters' =>
                isset($snapshot['distance_to_boundary_meters'])
                    ? (float) $snapshot['distance_to_boundary_meters']
                    : $decision->distanceMeters,
            'maximum_distance_to_boundary_meters' =>
                isset($snapshot['maximum_distance_to_boundary_meters'])
                    ? (float) $snapshot['maximum_distance_to_boundary_meters']
                    : $decision->distanceMeters,
            'outside_route_distance_meters' => (float) (
                $snapshot['outside_route_distance_meters']
                ?? 0.0
            ),
            'outside_seconds' => 0,
            'excluded_seconds' => 0,
            'counted_outside_seconds' => 0,
            'exit_confirmation_points' =>
                $transition->consecutiveOutsidePoints,
            'return_confirmation_points' => 0,
            'meta' => [
                'exit_point_id' => $snapshot['point_id'] ?? null,
                'confirmed_exit_point_id' => (int) $point->id,
                'exit_confirmed_at' => CarbonImmutable::instance(
                    $point->recorded_at
                )->toIso8601String(),
                'pause_segments' => [],
            ],
        ]);

        $pendingRoute = (float) (
            $snapshot['outside_route_distance_meters']
            ?? 0.0
        );

        if ($pendingRoute > 0) {
            $session->outside_distance_meters =
                (float) $session->outside_distance_meters
                + $pendingRoute;
        }

        unset(
            $fsm['pending_exit'],
            $fsm['pending_exit_started_at'],
        );

        return [$event, true];
    }

    private function updateOpenEvent(
        TrackingGeofenceEvent $event,
        TrackingPoint $point,
        TrackingGeofenceTransition $transition,
        GeofenceDecision $decision,
        TrackingWorkWindowResult $workWindow,
        array &$fsm,
    ): void {
        $at = CarbonImmutable::instance($point->recorded_at);

        if (
            in_array(
                $transition->action,
                ['continue_outside', 'resume_outside'],
                true,
            )
            && ! $transition->resumed
            && $workWindow->shouldCountOutside
        ) {
            $event->outside_route_distance_meters =
                $this->metrics->nextOutsideRouteDistance(
                    (float) $event->outside_route_distance_meters,
                    $point->distance_from_previous_meters !== null
                        ? (float) $point->distance_from_previous_meters
                        : null,
                    (bool) $point->is_counted_for_distance,
                );

            $event->maximum_distance_to_boundary_meters =
                $this->metrics->nextMaximumBoundaryDistance(
                    $event->maximum_distance_to_boundary_meters !== null
                        ? (float) $event->maximum_distance_to_boundary_meters
                        : null,
                    $decision->distanceMeters,
                );
        }

        if (
            $transition->action === 'continue_outside'
            && (bool) $point->is_counted_for_distance
            && $point->distance_from_previous_meters !== null
        ) {
            $event->session()
                ->whereKey($event->tracking_session_id)
                ->increment(
                    'outside_distance_meters',
                    (float) $point->distance_from_previous_meters,
                );
        }

        if ($event->exited_at) {
            $outsideSeconds = $this->metrics->durationSeconds(
                $event->exited_at,
                $at,
            );

            $event->outside_seconds = $outsideSeconds;
            $event->counted_outside_seconds =
                $this->metrics->countedOutsideSeconds(
                    $outsideSeconds,
                    (int) $event->excluded_seconds,
                );
        }

        if ($transition->excluded && $transition->action === 'pause') {
            $eventMeta = is_array($event->meta)
                ? $event->meta
                : [];

            $eventMeta['last_pause_reason'] =
                $transition->exclusionReason;

            $event->meta = $eventMeta;
        }

        $event->save();
    }

    private function confirmReturn(
        TrackingGeofenceEvent $event,
        TrackingGeofenceTransition $transition,
        array &$fsm,
    ): void {
        $snapshot = is_array($fsm['pending_return'] ?? null)
            ? $fsm['pending_return']
            : [];

        $returnedAt = $transition->pendingReturnStartedAt
            ?? $this->parseDate($fsm['pending_return_started_at'] ?? null)
            ?? CarbonImmutable::now();

        $outsideSeconds = $this->metrics->durationSeconds(
            $event->exited_at,
            $returnedAt,
        );

        $event->status = TrackingGeofenceEvent::STATUS_RETURNED;
        $event->returned_at = $returnedAt;
        $event->return_location_id = isset($snapshot['location_id'])
            ? (int) $snapshot['location_id']
            : null;
        $event->return_lat = isset($snapshot['lat'])
            ? (float) $snapshot['lat']
            : null;
        $event->return_lng = isset($snapshot['lng'])
            ? (float) $snapshot['lng']
            : null;
        $event->outside_seconds = $outsideSeconds;
        $event->counted_outside_seconds =
            $this->metrics->countedOutsideSeconds(
                $outsideSeconds,
                (int) $event->excluded_seconds,
            );
        $event->return_confirmation_points =
            $transition->consecutiveInsidePoints;

        $eventMeta = is_array($event->meta)
            ? $event->meta
            : [];

        $eventMeta['return_point_id'] =
            $snapshot['point_id'] ?? null;

        $event->meta = $eventMeta;
        $event->save();

        unset(
            $fsm['pending_return'],
            $fsm['pending_return_started_at'],
        );
    }

    private function applyResumeExclusion(
        TrackingGeofenceEvent $event,
        array &$fsm,
        CarbonImmutable $resumedAt,
    ): void {
        $pause = is_array($fsm['pause'] ?? null)
            ? $fsm['pause']
            : [];

        $startedAt = $this->parseDate(
            $pause['started_at'] ?? null
        );

        if (! $startedAt) {
            return;
        }

        $expectedEndAt = $this->parseDate(
            $pause['expected_end_at'] ?? null
        );

        $excluded = $this->metrics->boundedExcludedSeconds(
            $startedAt,
            $resumedAt,
            $expectedEndAt,
        );

        if ($excluded <= 0) {
            return;
        }

        $event->excluded_seconds =
            (int) $event->excluded_seconds + $excluded;

        if ($event->exited_at) {
            $outsideSeconds = $this->metrics->durationSeconds(
                $event->exited_at,
                $resumedAt,
            );

            $event->outside_seconds = $outsideSeconds;
            $event->counted_outside_seconds =
                $this->metrics->countedOutsideSeconds(
                    $outsideSeconds,
                    (int) $event->excluded_seconds,
                );
        }

        $eventMeta = is_array($event->meta)
            ? $event->meta
            : [];

        $segments = is_array($eventMeta['pause_segments'] ?? null)
            ? $eventMeta['pause_segments']
            : [];

        $segments[] = [
            'reason' => $pause['reason'] ?? null,
            'started_at' => $startedAt->toIso8601String(),
            'ended_at' => (
                $expectedEndAt
                && $expectedEndAt->greaterThan($startedAt)
                && $expectedEndAt->lessThan($resumedAt)
                    ? $expectedEndAt
                    : $resumedAt
            )->toIso8601String(),
            'excluded_seconds' => $excluded,
        ];

        $eventMeta['pause_segments'] = $segments;
        $event->meta = $eventMeta;
        $event->save();
    }

    private function updateSessionState(
        TrackingSession $session,
        TrackingGeofenceTransition $transition,
        array $fsm,
        CarbonImmutable $at,
    ): void {
        $oldState = (string) (
            $session->geofence_state
            ?: TrackingSession::STATE_UNKNOWN
        );

        $session->geofence_state = $transition->nextState;
        $session->consecutive_outside_points =
            $transition->consecutiveOutsidePoints;
        $session->consecutive_inside_points =
            $transition->consecutiveInsidePoints;

        if ($oldState !== $transition->nextState) {
            $session->state_changed_at = $at;
        }

        $sessionMeta = is_array($session->meta)
            ? $session->meta
            : [];

        $fsm['last_action'] = $transition->action;
        $fsm['last_processed_at'] = $at->toIso8601String();

        if ($transition->pendingExitStartedAt) {
            $fsm['pending_exit_started_at'] =
                $transition->pendingExitStartedAt->toIso8601String();
        }

        if ($transition->pendingReturnStartedAt) {
            $fsm['pending_return_started_at'] =
                $transition->pendingReturnStartedAt->toIso8601String();
        }

        $sessionMeta['geofence_fsm'] = $fsm;
        $session->meta = $sessionMeta;
        $session->save();
    }

    private function pauseStartAt(
        TrackingWorkWindowResult $workWindow,
        CarbonImmutable $pointAt,
    ): CarbonImmutable {
        if (
            $workWindow->windowStart
            && $workWindow->windowStart->lessThanOrEqualTo($pointAt)
        ) {
            return $workWindow->windowStart;
        }

        return $pointAt;
    }

    private function parseDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::instance($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
