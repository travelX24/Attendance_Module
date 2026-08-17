<?php

namespace Athka\Attendance\Services;

use Athka\Attendance\Models\TrackingSession;
use Athka\Attendance\Support\TrackingSessionStartResult;
use Athka\Employees\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final class TrackingSessionService
{
    public function activeForEmployee(
        int $companyId,
        int $employeeId,
    ): ?TrackingSession {
        return TrackingSession::query()
            ->forCompany($companyId)
            ->forEmployee($employeeId)
            ->active()
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Resolve the currently open attendance detail for the employee.
     *
     * We intentionally do not restrict this query to today's date. An open
     * detail from the previous calendar day can still be the active attendance
     * anchor for an overnight shift.
     */
    public function openAttendanceContext(
        int $companyId,
        int $employeeId,
    ): ?array {
        $row = DB::table('attendance_daily_details as detail')
            ->join(
                'attendance_daily_logs as log',
                'log.id',
                '=',
                'detail.daily_log_id'
            )
            ->where('log.saas_company_id', $companyId)
            ->where('log.employee_id', $employeeId)
            ->whereDate(
                'log.attendance_date',
                '>=',
                now()->subDay()->toDateString()
            )
            ->whereNull('detail.check_out_time')
            ->orderByDesc('log.attendance_date')
            ->orderByDesc('detail.id')
            ->first([
                'detail.id as detail_id',
                'detail.daily_log_id',
                'detail.work_schedule_period_id',
                'detail.check_in_time',
                'detail.attendance_status as detail_status',
                'detail.meta_data as detail_meta_data',
                'log.attendance_date',
                'log.attendance_status as log_status',
            ]);

        if (! $row) {
            return null;
        }

        return [
            'detail_id' => (int) $row->detail_id,
            'daily_log_id' => (int) $row->daily_log_id,
            'work_schedule_period_id' => $row->work_schedule_period_id !== null
                ? (int) $row->work_schedule_period_id
                : null,
            'check_in_time' => (string) $row->check_in_time,
            'attendance_date' => CarbonImmutable::parse(
                (string) $row->attendance_date
            )->toDateString(),
            'detail_status' => $row->detail_status !== null
                ? (string) $row->detail_status
                : null,
            'log_status' => $row->log_status !== null
                ? (string) $row->log_status
                : null,
            'detail_meta' => $this->decodeJson($row->detail_meta_data),
        ];
    }

    public function start(
        int $companyId,
        Employee $employee,
        ?int $startedByUserId = null,
        ?string $clientSessionUuid = null,
        array $startPoint = [],
        ?string $deviceUuid = null,
    ): TrackingSessionStartResult {
        if ((int) $employee->saas_company_id !== $companyId) {
            return TrackingSessionStartResult::failure(
                'company_mismatch',
                'Employee does not belong to the requested company.',
            );
        }

        $lock = Cache::lock(
            "tracking:start:{$companyId}:{$employee->id}",
            15
        );

        if (! $lock->get()) {
            return TrackingSessionStartResult::failure(
                'already_processing',
                'A tracking-session start request is already being processed.',
            );
        }

        try {
            return DB::transaction(function () use (
                $companyId,
                $employee,
                $startedByUserId,
                $clientSessionUuid,
                $startPoint,
                $deviceUuid,
            ): TrackingSessionStartResult {
                $existing = TrackingSession::query()
                    ->forCompany($companyId)
                    ->forEmployee((int) $employee->id)
                    ->active()
                    ->lockForUpdate()
                    ->orderByDesc('id')
                    ->first();

                if ($existing) {
                    return TrackingSessionStartResult::success(
                        $existing,
                        code: 'already_active',
                    );
                }

                $attendance = $this->openAttendanceContext(
                    $companyId,
                    (int) $employee->id,
                );

                if ($attendance === null) {
                    return TrackingSessionStartResult::failure(
                        'no_open_attendance',
                        'Tracking requires an open attendance check-in session.',
                    );
                }

                $startLat = $this->numericOrNull($startPoint['lat'] ?? null);
                $startLng = $this->numericOrNull($startPoint['lng'] ?? null);
                $startAccuracy = $this->numericOrNull(
                    $startPoint['accuracy_meters']
                        ?? $startPoint['gps_accuracy']
                        ?? null
                );

                $session = TrackingSession::query()->create([
                    'saas_company_id' => $companyId,
                    'employee_id' => (int) $employee->id,
                    'attendance_daily_log_id' => $attendance['daily_log_id'],
                    'attendance_daily_detail_id' => $attendance['detail_id'],
                    'branch_id' => $employee->branch_id
                        ? (int) $employee->branch_id
                        : null,
                    'started_by_user_id' => $startedByUserId,
                    'client_session_uuid' => $this->cleanString(
                        $clientSessionUuid
                    ),
                    'status' => TrackingSession::STATUS_ACTIVE,
                    'geofence_state' => TrackingSession::STATE_UNKNOWN,
                    'state_changed_at' => now(),
                    'started_at' => now(),
                    'start_lat' => $startLat,
                    'start_lng' => $startLng,
                    'start_accuracy_meters' => $startAccuracy,
                    'last_lat' => $startLat,
                    'last_lng' => $startLng,
                    'last_accuracy_meters' => $startAccuracy,
                    'last_recorded_at' => null,
                    'device_uuid' => $this->cleanString($deviceUuid),
                    'meta' => [
                        'attendance_anchor' => $attendance,
                    ],
                ]);

                return TrackingSessionStartResult::success(
                    $session,
                    context: [
                        'attendance' => $attendance,
                    ],
                );
            });
        } catch (Throwable $e) {
            report($e);

            return TrackingSessionStartResult::failure(
                'start_failed',
                $e->getMessage(),
            );
        } finally {
            optional($lock)->release();
        }
    }

    public function stop(
        TrackingSession $session,
        Employee $employee,
        string $reason = 'manual',
        array $endPoint = [],
    ): TrackingSession {
        $this->assertOwnership($session, $employee);

        if ($session->status !== TrackingSession::STATUS_ACTIVE) {
            return $session;
        }

        $session->status = TrackingSession::STATUS_COMPLETED;
        $session->geofence_state = TrackingSession::STATE_STOPPED;
        $session->state_changed_at = now();
        $session->ended_at = now();
        $session->close_reason = $this->cleanString($reason) ?: 'manual';

        $session->end_lat = $this->numericOrNull(
            $endPoint['lat'] ?? $session->last_lat
        );
        $session->end_lng = $this->numericOrNull(
            $endPoint['lng'] ?? $session->last_lng
        );
        $session->end_accuracy_meters = $this->numericOrNull(
            $endPoint['accuracy_meters']
                ?? $endPoint['gps_accuracy']
                ?? $session->last_accuracy_meters
        );

        $session->save();

        return $session->refresh();
    }

    public function assertOwnership(
        TrackingSession $session,
        Employee $employee,
    ): void {
        if (
            (int) $session->saas_company_id !== (int) $employee->saas_company_id
            || (int) $session->employee_id !== (int) $employee->id
        ) {
            throw new \DomainException(
                'Tracking session does not belong to this employee.'
            );
        }
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function numericOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
