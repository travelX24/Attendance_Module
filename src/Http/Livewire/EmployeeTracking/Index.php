<?php

namespace Athka\Attendance\Http\Livewire\EmployeeTracking;

use Athka\Attendance\Http\Livewire\Traits\WithDataScoping;
use Athka\Attendance\Models\TrackingSession;
use Athka\Attendance\Services\TrackingDashboardService;
use Athka\Employees\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

class Index extends Component
{
    use WithDataScoping;
    use WithPagination;

    public $branch_id = 'all';

    public $employee_id = '';

    public $date_from = '';

    public $date_to = '';

    public $session_status = 'all';

    public ?int $selected_session_id = null;

    public bool $showSessionModal = false;

    public function mount(): void
    {
        $this->requireAttendanceAny([
            'attendance.daily.view',
            'attendance.daily.view-subordinates',
            'attendance.daily.manage',
        ]);

        abort_if($this->companyId() <= 0, 403);

        $this->date_from = now()->toDateString();
        $this->date_to = now()->toDateString();

        $userBranchId = (int) (auth()->user()->branch_id ?? 0);
        $allowed = $this->allowedBranchIds();

        if (! empty($allowed)) {
            $this->branch_id = in_array(
                $userBranchId,
                $allowed,
                true
            )
                ? $userBranchId
                : 'all';
        } else {
            $this->branch_id = $userBranchId ?: 'all';
        }
    }

    public function getBranchesProperty()
    {
        $query = DB::table('branches')
            ->where('saas_company_id', $this->companyId())
            ->where('is_active', true);

        $allowed = $this->allowedBranchIds();

        if (! empty($allowed)) {
            $query->whereIn('id', $allowed);
        }

        return $query
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'code',
            ]);
    }

    public function getEmployeesProperty()
    {
        return $this
            ->accessibleEmployeeQuery()
            ->orderBy('name_ar')
            ->orderBy('name_en')
            ->orderBy('employee_no')
            ->get([
                'id',
                'employee_no',
                'name_ar',
                'name_en',
                'branch_id',
                'status',
            ]);
    }

    public function getSelectedEmployeeProperty(): ?Employee
    {
        $employeeId = (int) $this->employee_id;

        if ($employeeId <= 0) {
            return null;
        }

        return $this
            ->accessibleEmployeeQuery()
            ->whereKey($employeeId)
            ->first();
    }

    public function getSessionsProperty()
    {
        $employee = $this->selectedEmployee;

        if (! $employee) {
            return null;
        }

        return app(TrackingDashboardService::class)
            ->sessionHistory(
                companyId: $this->companyId(),
                employeeId: (int) $employee->id,
                dateFrom: $this->normalizedDate($this->date_from),
                dateTo: $this->normalizedDate($this->date_to),
                status: $this->normalizedSessionStatus(),
                perPage: 15,
            );
    }

    public function getSelectedSessionProperty(): ?TrackingSession
    {
        if (! $this->selected_session_id) {
            return null;
        }

        $employee = $this->selectedEmployee;

        if (! $employee) {
            return null;
        }

        return app(TrackingDashboardService::class)
            ->sessionDetails(
                companyId: $this->companyId(),
                employeeId: (int) $employee->id,
                sessionId: (int) $this->selected_session_id,
            );
    }

    public function getSelectedSessionMapDataProperty(): array
    {
        $session = $this->selectedSession;

        if (! $session) {
            return [];
        }

        $coordinate = static function (
            mixed $lat,
            mixed $lng
        ): ?array {
            if (! is_numeric($lat) || ! is_numeric($lng)) {
                return null;
            }

            return [
                'lat' => (float) $lat,
                'lng' => (float) $lng,
            ];
        };

        $points = $session->points
            ->map(function ($point): array {
                return [
                    'id' => (int) $point->id,
                    'lat' => (float) $point->lat,
                    'lng' => (float) $point->lng,
                    'recorded_at' => $point->recorded_at?->toIso8601String(),
                    'work_state' => (string) $point->work_state,
                    'inside_allowed_geofence' => $point->inside_allowed_geofence,
                    'is_counted_for_distance' => (bool) $point->is_counted_for_distance,
                    'matched_location_id' => $point->matched_location_id
                        ? (int) $point->matched_location_id
                        : null,
                ];
            })
            ->values()
            ->all();

        $geofences = collect(
            $session->relationLoaded('dashboardGeofences')
                ? $session->getRelation('dashboardGeofences')
                : []
        )
            ->map(function ($location): array {
                $boundary = $location->boundary_geojson;

                if (is_string($boundary) && trim($boundary) !== '') {
                    $decoded = json_decode(
                        $boundary,
                        true
                    );

                    if (json_last_error() === JSON_ERROR_NONE) {
                        $boundary = $decoded;
                    }
                }

                if (! is_array($boundary)) {
                    $boundary = null;
                }

                return [
                    'id' => (int) $location->id,
                    'name' => (string) $location->name,
                    'lat' => (float) $location->lat,
                    'lng' => (float) $location->lng,
                    'radius_meters' => (float) $location->radius_meters,
                    'geofence_type' => (string) (
                        $location->geofence_type
                        ?: 'circle'
                    ),
                    'boundary_geojson' => $boundary,
                ];
            })
            ->values()
            ->all();

        $events = $session->geofenceEvents
            ->map(function ($event) use ($coordinate): array {
                return [
                    'id' => (int) $event->id,
                    'is_counted' => (bool) $event->is_counted,
                    'exit' => $coordinate(
                        $event->exit_lat,
                        $event->exit_lng
                    ),
                    'return' => $coordinate(
                        $event->return_lat,
                        $event->return_lng
                    ),
                ];
            })
            ->values()
            ->all();

        return [
            'session_id' => (int) $session->id,
            'status' => (string) $session->status,
            'start' => $coordinate(
                $session->start_lat,
                $session->start_lng
            ),
            'end' => $coordinate(
                $session->end_lat,
                $session->end_lng
            ),
            'last' => $coordinate(
                $session->last_lat,
                $session->last_lng
            ),
            'points' => $points,
            'geofences' => $geofences,
            'events' => $events,
        ];
    }
    public function openSession(int $sessionId): void
    {
        $employee = $this->selectedEmployee;

        abort_unless($employee, 404);

        $session = app(TrackingDashboardService::class)
            ->sessionDetails(
                companyId: $this->companyId(),
                employeeId: (int) $employee->id,
                sessionId: $sessionId,
            );

        abort_unless($session, 404);

        $this->selected_session_id = (int) $session->id;
        $this->showSessionModal = true;
    }

    public function closeSessionModal(): void
    {
        $this->showSessionModal = false;
    }

    private function resetSelectedSession(): void
    {
        $this->selected_session_id = null;
        $this->showSessionModal = false;
    }
    public function updatedBranchId(): void
    {
        $this->resetSelectedSession();
        $this->resetPage('trackingPage');

        $allowed = $this->allowedBranchIds();

        if (
            ! empty($allowed)
            && $this->branch_id !== 'all'
        ) {
            $branchId = (int) $this->branch_id;

            if (! in_array($branchId, $allowed, true)) {
                $this->branch_id = 'all';
            }
        }

        $this->employee_id = '';
    }

    public function updatedEmployeeId(): void
    {
        $this->resetSelectedSession();
        $this->resetPage('trackingPage');

        if (
            filled($this->employee_id)
            && ! $this->selectedEmployee
        ) {
            $this->employee_id = '';
        }
    }

    public function updatedDateFrom(): void
    {
        $this->resetSelectedSession();
        $this->resetPage('trackingPage');

        $from = $this->normalizedDate($this->date_from);
        $to = $this->normalizedDate($this->date_to);

        if ($from && $to && $to < $from) {
            $this->date_to = $from;
        }
    }

    public function updatedDateTo(): void
    {
        $this->resetSelectedSession();
        $this->resetPage('trackingPage');
    }

    public function updatedSessionStatus(): void
    {
        $this->resetSelectedSession();
        $this->resetPage('trackingPage');

        $this->session_status =
            $this->normalizedSessionStatus();
    }

    public function resetFilters(): void
    {
        $this->resetSelectedSession();
        $this->employee_id = '';
        $this->session_status = 'all';

        $this->date_from = now()->toDateString();
        $this->date_to = now()->toDateString();

        $userBranchId = (int) (auth()->user()->branch_id ?? 0);
        $allowed = $this->allowedBranchIds();

        if (! empty($allowed)) {
            $this->branch_id = in_array(
                $userBranchId,
                $allowed,
                true
            )
                ? $userBranchId
                : 'all';
        } else {
            $this->branch_id = $userBranchId ?: 'all';
        }

        $this->resetPage('trackingPage');
    }

    private function accessibleEmployeeQuery(): Builder
    {
        $query = Employee::withoutGlobalScope('active_only')
            ->forCompany($this->companyId());

        /*
         * Same data-scoping contract used by Daily Attendance.
         */
        $query = $this->applyDataScoping(
            $query,
            'attendance.daily.view',
            'attendance.daily.view-subordinates',
            ''
        );

        /*
         * Same allowed-branch contract used by Attendance.
         */
        $allowed = $this->allowedBranchIds();

        if (! empty($allowed)) {
            $query->whereIn('branch_id', $allowed);
        }

        if ($this->branch_id !== 'all') {
            $query->where(
                'branch_id',
                (int) $this->branch_id
            );
        }

        return $query;
    }

    private function allowedBranchIds(): array
    {
        $user = auth()->user();

        if (
            isset($user->access_scope)
            && $user->access_scope === 'all_branches'
        ) {
            return [];
        }

        if (method_exists($user, 'accessibleBranchIds')) {
            $ids = $user->accessibleBranchIds();

            return array_values(
                array_filter(
                    array_map(
                        'intval',
                        is_array($ids)
                            ? $ids
                            : $ids->toArray()
                    )
                )
            );
        }

        $branchId = (int) ($user->branch_id ?? 0);

        return $branchId > 0
            ? [$branchId]
            : [];
    }

    private function companyId(): int
    {
        return (int) (
            auth()->user()->saas_company_id
            ?? 0
        );
    }

    private function normalizedDate(mixed $value): ?string
    {
        if (
            ! is_string($value)
            || trim($value) === ''
        ) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)
                ->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizedSessionStatus(): string
    {
        $allowed = [
            'all',
            TrackingSession::STATUS_ACTIVE,
            TrackingSession::STATUS_COMPLETED,
            TrackingSession::STATUS_CANCELLED,
            TrackingSession::STATUS_EXPIRED,
        ];

        return in_array(
            $this->session_status,
            $allowed,
            true
        )
            ? $this->session_status
            : 'all';
    }

    public function render()
    {
        return view(
            'attendance::livewire.employee-tracking.index',
            [
                'branches' => $this->branches,
                'employees' => $this->employees,
                'selectedEmployee' => $this->selectedEmployee,
                'sessions' => $this->sessions,
                'selectedSession' => $this->selectedSession,
                'selectedSessionMapData' => $this->selectedSessionMapData,
            ]
        )->layout('layouts.company-admin');
    }
}