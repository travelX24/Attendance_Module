<?php

namespace Athka\Attendance\Services;

use Athka\Attendance\Models\TrackingSession;
use Athka\SystemSettings\Models\AttendanceGpsLocation;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class TrackingDashboardService
{
    public function sessionHistory(
        int $companyId,
        int $employeeId,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        string $status = 'all',
        int $perPage = 15,
    ): LengthAwarePaginator {
        $query = TrackingSession::query()
            ->forCompany($companyId)
            ->forEmployee($employeeId)
            ->withCount([
                'points',
                'geofenceEvents',
            ]);

        if (filled($dateFrom)) {
            $from = CarbonImmutable::parse($dateFrom)->startOfDay();

            $query->where(function (Builder $query) use ($from): void {
                $query
                    ->whereNull('ended_at')
                    ->orWhere('ended_at', '>=', $from);
            });
        }

        if (filled($dateTo)) {
            $to = CarbonImmutable::parse($dateTo)->endOfDay();
            $query->where('started_at', '<=', $to);
        }

        $allowedStatuses = [
            TrackingSession::STATUS_ACTIVE,
            TrackingSession::STATUS_COMPLETED,
            TrackingSession::STATUS_CANCELLED,
            TrackingSession::STATUS_EXPIRED,
        ];

        if (in_array($status, $allowedStatuses, true)) {
            $query->where('status', $status);
        }

        return $query
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->paginate(
                max(5, min($perPage, 100)),
                ['*'],
                'trackingPage'
            );
    }

    public function sessionDetails(
        int $companyId,
        int $employeeId,
        int $sessionId,
    ): ?TrackingSession {
        $session = TrackingSession::query()
            ->forCompany($companyId)
            ->forEmployee($employeeId)
            ->whereKey($sessionId)
            ->with([
                'points' => function ($query): void {
                    $query
                        ->accepted()
                        ->orderBy('recorded_at')
                        ->orderBy('sequence_number')
                        ->orderBy('id');
                },
                'geofenceEvents' => function ($query): void {
                    $query
                        ->orderBy('exited_at')
                        ->orderBy('id');
                },
            ])
            ->withCount([
                'points',
                'geofenceEvents',
            ])
            ->first();

        if (! $session) {
            return null;
        }

        /*
         * Historical geofences are resolved only from location IDs
         * that were actually persisted by the tracking lifecycle:
         * - accepted points matched_location_id
         * - session current_location_id
         * - geofence event exit/return location IDs
         *
         * We intentionally do not substitute arbitrary current branch
         * locations when no historical location ID exists.
         */
        $locationIds = $session->points
            ->pluck('matched_location_id')
            ->filter()
            ->map(fn ($id): int => (int) $id);

        if ($session->current_location_id) {
            $locationIds->push((int) $session->current_location_id);
        }

        foreach ($session->geofenceEvents as $event) {
            if ($event->exit_location_id) {
                $locationIds->push((int) $event->exit_location_id);
            }

            if ($event->return_location_id) {
                $locationIds->push((int) $event->return_location_id);
            }
        }

        $locationIds = $locationIds
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        $locations = $locationIds->isEmpty()
            ? collect()
            : AttendanceGpsLocation::query()
                ->where('saas_company_id', $companyId)
                ->whereIn('id', $locationIds->all())
                ->orderBy('id')
                ->get();

        $session->setRelation(
            'dashboardGeofences',
            $locations
        );

        return $session;
    }
}