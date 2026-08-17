<?php

namespace Athka\Attendance\Http\Controllers\Api\Employee;

use Athka\Attendance\Models\TrackingPoint;
use Athka\Attendance\Models\TrackingSession;
use Athka\Attendance\Services\TrackingHistoricalReplayService;
use Athka\Attendance\Services\TrackingLiveBatchOrchestrator;
use Athka\Attendance\Services\TrackingOfflineBatchOrchestrator;
use Athka\Attendance\Services\TrackingSessionService;
use Athka\Attendance\Support\TrackingBatchIngestionResult;
use Athka\Employees\Models\Employee;
use Athka\SystemSettings\Services\EmployeeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TrackingController
{
    public function __construct(
        private readonly EmployeeService $employeeService,
        private readonly TrackingSessionService $sessionService,
        private readonly TrackingLiveBatchOrchestrator $liveBatch,
        private readonly TrackingOfflineBatchOrchestrator $offlineBatch,
        private readonly TrackingHistoricalReplayService $replayService,
    ) {
    }

    public function active(Request $request): JsonResponse
    {
        [$companyId, $employee, $error] = $this->resolveEmployee($request);

        if ($error) {
            return $error;
        }

        $session = $this->sessionService->activeForEmployee(
            $companyId,
            (int) $employee->id,
        );

        return response()->json([
            'ok' => true,
            'active' => $session !== null,
            'session' => $session ? $this->sessionPayload($session) : null,
        ]);
    }

    public function start(Request $request): JsonResponse
    {
        [$companyId, $employee, $error] = $this->resolveEmployee($request);

        if ($error) {
            return $error;
        }

        $data = $request->validate([
            'client_session_uuid' => ['nullable', 'string', 'max:100'],
            'device_uuid' => ['nullable', 'string', 'max:100'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy_meters' => ['nullable', 'numeric', 'min:0'],
            'gps_accuracy' => ['nullable', 'numeric', 'min:0'],
        ]);

        $result = $this->sessionService->start(
            companyId: $companyId,
            employee: $employee,
            startedByUserId: $request->user()?->id,
            clientSessionUuid: $data['client_session_uuid'] ?? null,
            startPoint: [
                'lat' => $data['lat'] ?? null,
                'lng' => $data['lng'] ?? null,
                'accuracy_meters' => $data['accuracy_meters'] ?? $data['gps_accuracy'] ?? null,
            ],
            deviceUuid: $data['device_uuid'] ?? null,
        );

        if (! $result->ok || ! $result->session) {
            return response()->json([
                'ok' => false,
                'code' => $result->code,
                'message' => $result->message,
                'context' => $result->context,
            ], $this->statusForCode($result->code));
        }

        return response()->json([
            'ok' => true,
            'code' => $result->code,
            'session' => $this->sessionPayload($result->session),
            'context' => $result->context,
        ]);
    }

    public function points(Request $request): JsonResponse
    {
        [$companyId, $employee, $error] = $this->resolveEmployee($request);

        if ($error) {
            return $error;
        }

        $data = $request->validate([
            'session_public_id' => ['required', 'uuid'],
            'mode' => ['nullable', 'in:live,offline'],
            'replay' => ['nullable', 'boolean'],
            'points' => ['required', 'array', 'min:1', 'max:500'],
            'points.*.client_point_uuid' => ['required', 'string', 'max:100'],
            'points.*.sequence_number' => ['nullable', 'integer', 'min:0'],
            'points.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'points.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'points.*.accuracy_meters' => ['required', 'numeric', 'min:0'],
            'points.*.speed_mps' => ['nullable', 'numeric', 'min:0'],
            'points.*.heading_degrees' => ['nullable', 'numeric'],
            'points.*.altitude_meters' => ['nullable', 'numeric'],
            'points.*.recorded_at' => ['required', 'date'],
            'points.*.is_mocked' => ['nullable', 'boolean'],
            'points.*.provider' => ['nullable', 'string', 'max:50'],
            'points.*.battery_level' => ['nullable', 'integer', 'between:0,100'],
        ]);

        $session = $this->activeSessionFromPublicId(
            companyId: $companyId,
            employee: $employee,
            publicId: $data['session_public_id'],
        );

        if (! $session) {
            return response()->json([
                'ok' => false,
                'code' => 'session_not_found',
                'message' => 'Active tracking session not found.',
            ], 404);
        }

        $mode = $data['mode'] ?? 'live';

        if (
            $mode === 'live'
            && count($data['points']) > TrackingLiveBatchOrchestrator::MAX_BATCH_POINTS
        ) {
            return response()->json([
                'ok' => false,
                'code' => 'batch_too_large',
                'message' => 'Live tracking batches are limited to '
                    . TrackingLiveBatchOrchestrator::MAX_BATCH_POINTS
                    . ' points.',
            ], 422);
        }

        $result = $mode === 'offline'
            ? $this->offlineBatch->ingest(
                session: $session,
                employee: $employee,
                points: $data['points'],
                replay: (bool) ($data['replay'] ?? true),
            )
            : $this->liveBatch->ingest(
                session: $session,
                employee: $employee,
                points: $data['points'],
            );

        return $this->batchResponse($result, $mode, $session->refresh());
    }

    public function stop(Request $request): JsonResponse
    {
        [$companyId, $employee, $error] = $this->resolveEmployee($request);

        if ($error) {
            return $error;
        }

        $data = $request->validate([
            'session_public_id' => ['required', 'uuid'],
            'reason' => ['nullable', 'string', 'max:100'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy_meters' => ['nullable', 'numeric', 'min:0'],
            'gps_accuracy' => ['nullable', 'numeric', 'min:0'],
        ]);

        $session = $this->activeSessionFromPublicId(
            companyId: $companyId,
            employee: $employee,
            publicId: $data['session_public_id'],
        );

        if (! $session) {
            return response()->json([
                'ok' => false,
                'code' => 'session_not_found',
                'message' => 'Active tracking session not found.',
            ], 404);
        }

        if ($this->hasDeferredHistoricalPoints((int) $session->id)) {
            $replay = $this->replayService->rebuild($session);

            if (! $replay->ok) {
                return response()->json([
                    'ok' => false,
                    'code' => 'pending_replay_failed',
                    'message' => $replay->message,
                    'replay_code' => $replay->code,
                ], 409);
            }

            $session = $replay->session;
        }

        $stopped = $this->sessionService->stop(
            session: $session,
            employee: $employee,
            reason: $data['reason'] ?? 'mobile_stop',
            endPoint: [
                'lat' => $data['lat'] ?? null,
                'lng' => $data['lng'] ?? null,
                'accuracy_meters' => $data['accuracy_meters'] ?? $data['gps_accuracy'] ?? null,
            ],
        );

        return response()->json([
            'ok' => true,
            'code' => 'stopped',
            'session' => $this->sessionPayload($stopped),
        ]);
    }

    private function resolveEmployee(Request $request): array
    {
        $user = $request->user();
        $companyId = $this->employeeService->getCompanyId($user);
        $employee = $this->employeeService->resolve($user);

        if (! $employee) {
            return [
                $companyId,
                null,
                response()->json([
                    'ok' => false,
                    'code' => 'employee_not_found',
                    'message' => 'Employee not found.',
                ], 403),
            ];
        }

        return [$companyId, $employee, null];
    }

    private function activeSessionFromPublicId(
        int $companyId,
        Employee $employee,
        string $publicId,
    ): ?TrackingSession {
        return TrackingSession::query()
            ->forCompany($companyId)
            ->forEmployee((int) $employee->id)
            ->active()
            ->where('public_id', $publicId)
            ->first();
    }

    private function hasDeferredHistoricalPoints(int $sessionId): bool
    {
        return TrackingPoint::query()
            ->where('tracking_session_id', $sessionId)
            ->where('is_accepted', true)
            ->get(['id', 'meta'])
            ->contains(function (TrackingPoint $point) {
                $meta = is_array($point->meta) ? $point->meta : [];

                return ($meta['geofence_event']['code'] ?? null)
                    === 'deferred_historical_replay';
            });
    }

    private function batchResponse(
        TrackingBatchIngestionResult $result,
        string $mode,
        TrackingSession $session,
    ): JsonResponse {
        return response()->json([
            'ok' => $result->ok,
            'code' => $result->code,
            'mode' => $mode,
            'summary' => [
                'received' => $result->received,
                'accepted' => $result->accepted,
                'rejected' => $result->rejected,
                'duplicates' => $result->duplicates,
                'persisted' => $result->persisted,
                'deferred' => $result->deferred,
                'replay_requested' => $result->replayRequested,
                'replay_executed' => $result->replayExecuted,
            ],
            'points' => $result->points,
            'replay' => $result->replay
                ? [
                    'ok' => $result->replay->ok,
                    'code' => $result->replay->code,
                    'accepted_points' => $result->replay->acceptedPoints,
                    'rejected_points' => $result->replay->rejectedPoints,
                    'events_created' => $result->replay->eventsCreated,
                    'events_returned' => $result->replay->eventsReturned,
                    'events_open' => $result->replay->eventsOpen,
                    'total_distance_meters' => $result->replay->totalDistanceMeters,
                    'outside_distance_meters' => $result->replay->outsideDistanceMeters,
                ]
                : null,
            'session' => $this->sessionPayload($session),
            'message' => $result->message,
        ], $result->ok ? 200 : 409);
    }

    private function sessionPayload(TrackingSession $session): array
    {
        return [
            'public_id' => $session->public_id,
            'status' => $session->status,
            'geofence_state' => $session->geofence_state,
            'attendance_daily_log_id' => $session->attendance_daily_log_id,
            'attendance_daily_detail_id' => $session->attendance_daily_detail_id,
            'branch_id' => $session->branch_id,
            'started_at' => $session->started_at?->toIso8601String(),
            'ended_at' => $session->ended_at?->toIso8601String(),
            'last_recorded_at' => $session->last_recorded_at?->toIso8601String(),
            'current_location_id' => $session->current_location_id,
            'total_distance_meters' => (float) $session->total_distance_meters,
            'outside_distance_meters' => (float) $session->outside_distance_meters,
            'accepted_points_count' => (int) $session->accepted_points_count,
            'rejected_points_count' => (int) $session->rejected_points_count,
            'device_uuid' => $session->device_uuid,
        ];
    }

    private function statusForCode(string $code): int
    {
        return match ($code) {
            'already_processing' => 409,
            'no_open_attendance' => 409,
            'company_mismatch' => 403,
            default => 422,
        };
    }
}
