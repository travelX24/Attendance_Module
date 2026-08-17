<?php

namespace Athka\Attendance\Services;

use Athka\Attendance\Models\TrackingSession;
use Athka\Attendance\Support\TrackingGeofenceTransition;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class TrackingGeofenceStateMachine
{
    public function __construct(
        private readonly int $outsidePointsToConfirm = 3,
        private readonly int $returnPointsToConfirm = 2,
        private readonly int $outsideSecondsToConfirm = 120,
    ) {
    }

    public function transition(
        string $currentState,
        bool $insideAllowedGeofence,
        bool $shouldCountOutside,
        string $workState,
        CarbonInterface $recordedAt,
        int $consecutiveOutsidePoints = 0,
        int $consecutiveInsidePoints = 0,
        ?CarbonInterface $pendingExitStartedAt = null,
        ?CarbonInterface $pendingReturnStartedAt = null,
        ?string $pausedFromState = null,
    ): TrackingGeofenceTransition {
        $at = CarbonImmutable::instance($recordedAt);
        $pendingExit = $pendingExitStartedAt
            ? CarbonImmutable::instance($pendingExitStartedAt)
            : null;
        $pendingReturn = $pendingReturnStartedAt
            ? CarbonImmutable::instance($pendingReturnStartedAt)
            : null;

        if (! $shouldCountOutside) {
            return $this->pause(
                currentState: $currentState,
                workState: $workState,
                at: $at,
                pausedFromState: $pausedFromState,
            );
        }

        $resumed = $this->isPausedState($currentState);

        if ($resumed) {
            $currentState = $this->operationalStateAfterPause(
                $pausedFromState,
            );

            $consecutiveOutsidePoints = 0;
            $consecutiveInsidePoints = 0;
            $pendingExit = null;
            $pendingReturn = null;
        }

        if ($insideAllowedGeofence) {
            return $this->handleInside(
                currentState: $currentState,
                at: $at,
                consecutiveOutsidePoints: $consecutiveOutsidePoints,
                consecutiveInsidePoints: $consecutiveInsidePoints,
                pendingExitStartedAt: $pendingExit,
                pendingReturnStartedAt: $pendingReturn,
                resumed: $resumed,
            );
        }

        return $this->handleOutside(
            currentState: $currentState,
            at: $at,
            consecutiveOutsidePoints: $consecutiveOutsidePoints,
            consecutiveInsidePoints: $consecutiveInsidePoints,
            pendingExitStartedAt: $pendingExit,
            pendingReturnStartedAt: $pendingReturn,
            resumed: $resumed,
        );
    }

    private function handleInside(
        string $currentState,
        CarbonImmutable $at,
        int $consecutiveOutsidePoints,
        int $consecutiveInsidePoints,
        ?CarbonImmutable $pendingExitStartedAt,
        ?CarbonImmutable $pendingReturnStartedAt,
        bool $resumed,
    ): TrackingGeofenceTransition {
        if (
            $currentState === TrackingSession::STATE_UNKNOWN
            || $currentState === TrackingSession::STATE_INSIDE
        ) {
            return new TrackingGeofenceTransition(
                previousState: $currentState,
                nextState: TrackingSession::STATE_INSIDE,
                action: $resumed ? 'resume_inside' : 'stay_inside',
                resumed: $resumed,
            );
        }

        if ($currentState === TrackingSession::STATE_EXIT_PENDING) {
            return new TrackingGeofenceTransition(
                previousState: $currentState,
                nextState: TrackingSession::STATE_INSIDE,
                action: 'cancel_exit_pending',
                resumed: $resumed,
            );
        }

        if ($currentState === TrackingSession::STATE_OUTSIDE) {
            return new TrackingGeofenceTransition(
                previousState: $currentState,
                nextState: TrackingSession::STATE_RETURN_PENDING,
                action: 'start_return_pending',
                consecutiveInsidePoints: 1,
                pendingReturnStartedAt: $at,
                resumed: $resumed,
            );
        }

        if ($currentState === TrackingSession::STATE_RETURN_PENDING) {
            $insideCount = $consecutiveInsidePoints + 1;
            $returnStartedAt = $pendingReturnStartedAt ?? $at;

            if ($insideCount >= $this->returnPointsToConfirm) {
                return new TrackingGeofenceTransition(
                    previousState: $currentState,
                    nextState: TrackingSession::STATE_INSIDE,
                    action: 'confirm_return',
                    consecutiveInsidePoints: $insideCount,
                    pendingReturnStartedAt: $returnStartedAt,
                    resumed: $resumed,
                    meta: [
                        'confirmed_return_at' => $returnStartedAt->toIso8601String(),
                    ],
                );
            }

            return new TrackingGeofenceTransition(
                previousState: $currentState,
                nextState: TrackingSession::STATE_RETURN_PENDING,
                action: 'continue_return_pending',
                consecutiveInsidePoints: $insideCount,
                pendingReturnStartedAt: $returnStartedAt,
                resumed: $resumed,
            );
        }

        return new TrackingGeofenceTransition(
            previousState: $currentState,
            nextState: TrackingSession::STATE_INSIDE,
            action: $resumed ? 'resume_inside' : 'reset_inside',
            resumed: $resumed,
        );
    }

    private function handleOutside(
        string $currentState,
        CarbonImmutable $at,
        int $consecutiveOutsidePoints,
        int $consecutiveInsidePoints,
        ?CarbonImmutable $pendingExitStartedAt,
        ?CarbonImmutable $pendingReturnStartedAt,
        bool $resumed,
    ): TrackingGeofenceTransition {
        if (
            $currentState === TrackingSession::STATE_UNKNOWN
            || $currentState === TrackingSession::STATE_INSIDE
        ) {
            return new TrackingGeofenceTransition(
                previousState: $currentState,
                nextState: TrackingSession::STATE_EXIT_PENDING,
                action: 'start_exit_pending',
                consecutiveOutsidePoints: 1,
                pendingExitStartedAt: $at,
                resumed: $resumed,
            );
        }

        if ($currentState === TrackingSession::STATE_EXIT_PENDING) {
            $outsideCount = $consecutiveOutsidePoints + 1;
            $exitStartedAt = $pendingExitStartedAt ?? $at;
            $elapsedSeconds = max(
                0,
                $at->getTimestamp() - $exitStartedAt->getTimestamp()
            );

            if (
                $outsideCount >= $this->outsidePointsToConfirm
                || $elapsedSeconds >= $this->outsideSecondsToConfirm
            ) {
                return new TrackingGeofenceTransition(
                    previousState: $currentState,
                    nextState: TrackingSession::STATE_OUTSIDE,
                    action: 'confirm_exit',
                    consecutiveOutsidePoints: $outsideCount,
                    pendingExitStartedAt: $exitStartedAt,
                    resumed: $resumed,
                    meta: [
                        'exit_elapsed_seconds' => $elapsedSeconds,
                        'confirmed_exit_at' => $exitStartedAt->toIso8601String(),
                    ],
                );
            }

            return new TrackingGeofenceTransition(
                previousState: $currentState,
                nextState: TrackingSession::STATE_EXIT_PENDING,
                action: 'continue_exit_pending',
                consecutiveOutsidePoints: $outsideCount,
                pendingExitStartedAt: $exitStartedAt,
                resumed: $resumed,
                meta: [
                    'exit_elapsed_seconds' => $elapsedSeconds,
                ],
            );
        }

        if ($currentState === TrackingSession::STATE_OUTSIDE) {
            return new TrackingGeofenceTransition(
                previousState: $currentState,
                nextState: TrackingSession::STATE_OUTSIDE,
                action: $resumed ? 'resume_outside' : 'continue_outside',
                consecutiveOutsidePoints: max(
                    1,
                    $consecutiveOutsidePoints + 1
                ),
                resumed: $resumed,
            );
        }

        if ($currentState === TrackingSession::STATE_RETURN_PENDING) {
            return new TrackingGeofenceTransition(
                previousState: $currentState,
                nextState: TrackingSession::STATE_OUTSIDE,
                action: 'cancel_return_pending',
                consecutiveOutsidePoints: 1,
                resumed: $resumed,
            );
        }

        return new TrackingGeofenceTransition(
            previousState: $currentState,
            nextState: TrackingSession::STATE_EXIT_PENDING,
            action: 'start_exit_pending',
            consecutiveOutsidePoints: 1,
            pendingExitStartedAt: $at,
            resumed: $resumed,
        );
    }

    private function pause(
        string $currentState,
        string $workState,
        CarbonImmutable $at,
        ?string $pausedFromState,
    ): TrackingGeofenceTransition {
        $pauseState = match ($workState) {
            TrackingWorkWindowService::STATE_BREAK =>
                TrackingSession::STATE_BREAK_PAUSED,
            TrackingWorkWindowService::STATE_PERMISSION =>
                TrackingSession::STATE_PERMISSION_PAUSED,
            TrackingWorkWindowService::STATE_MISSION =>
                TrackingSession::STATE_MISSION,
            default =>
                TrackingSession::STATE_STOPPED,
        };

        $operationalState = $this->isPausedState($currentState)
            ? ($pausedFromState ?? TrackingSession::STATE_INSIDE)
            : $currentState;

        return new TrackingGeofenceTransition(
            previousState: $currentState,
            nextState: $pauseState,
            action: $currentState === $pauseState
                ? 'stay_paused'
                : 'pause',
            excluded: true,
            exclusionReason: $workState,
            pausedFromState: $operationalState,
            meta: [
                'paused_at' => $at->toIso8601String(),
            ],
        );
    }

    private function operationalStateAfterPause(
        ?string $pausedFromState,
    ): string {
        return match ($pausedFromState) {
            TrackingSession::STATE_OUTSIDE,
            TrackingSession::STATE_RETURN_PENDING =>
                TrackingSession::STATE_OUTSIDE,

            // An unconfirmed exit before a break/permission must not be
            // backdated across the excluded interval. If the employee is
            // still outside after the pause, confirmation starts again.
            TrackingSession::STATE_EXIT_PENDING,
            TrackingSession::STATE_UNKNOWN,
            TrackingSession::STATE_INSIDE,
            null =>
                TrackingSession::STATE_INSIDE,

            default =>
                TrackingSession::STATE_INSIDE,
        };
    }

    private function isPausedState(string $state): bool
    {
        return in_array(
            $state,
            [
                TrackingSession::STATE_BREAK_PAUSED,
                TrackingSession::STATE_PERMISSION_PAUSED,
                TrackingSession::STATE_MISSION,
                TrackingSession::STATE_STOPPED,
            ],
            true,
        );
    }
}
