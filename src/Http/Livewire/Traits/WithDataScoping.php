<?php

namespace Athka\Attendance\Http\Livewire\Traits;

use Illuminate\Database\Eloquent\Builder;

trait WithDataScoping
{
    protected function canAttendanceAny(array|string $permissions): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }

        $permissions = is_array($permissions) ? $permissions : [$permissions];
        $aliases = [
            'attendance.dashboard.view' => ['attendance.daily.view', 'attendance.daily.manage'],
            'attendance.daily.manual-entry' => ['attendance.daily.manage'],
            'attendance.daily.export' => ['attendance.daily.manage'],
            'attendance.logs.view' => ['attendance.daily.view', 'attendance.daily.manage'],
            'attendance.logs.sync' => ['attendance.daily.manage'],
            'attendance.schedules.assign' => ['attendance.schedules.manage'],
            'attendance.schedules.bulk-assign' => ['attendance.schedules.manage'],
            'shifts.view' => ['attendance.schedules.view', 'attendance.schedules.manage'],
            'shifts.manage' => ['attendance.schedules.manage'],
            'holidays.manage' => ['attendance.schedules.manage'],
            'requests.leaves.view' => ['attendance.leaves.view', 'attendance.leaves.manage'],
            'requests.leaves.create' => ['attendance.leaves.manage'],
            'requests.leaves.approve' => ['attendance.leaves.manage'],
            'attendance.leaves.approve' => ['attendance.leaves.manage'],
            'requests.permissions.view' => ['attendance.leaves.view', 'attendance.leaves.manage'],
            'requests.permissions.manage' => ['attendance.leaves.manage'],
            'requests.overtime.view' => ['attendance.leaves.view', 'attendance.leaves.manage'],
            'requests.overtime.manage' => ['attendance.leaves.manage'],
            'requests.business-trip.manage' => ['attendance.leaves.manage'],
            'attendance.missions.manage' => ['attendance.leaves.manage'],
            'attendance.penalties.waive' => ['attendance.penalties.manage'],
            'attendance.penalties.export' => ['attendance.penalties.manage'],
        ];

        $checkPermissions = [];
        foreach ($permissions as $permission) {
            $checkPermissions[] = $permission;
            foreach ($aliases[$permission] ?? [] as $alias) {
                $checkPermissions[] = $alias;
            }
        }

        foreach (array_unique($checkPermissions) as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    protected function requireAttendanceAny(array|string $permissions): void
    {
        abort_unless($this->canAttendanceAny($permissions), 403);
    }

    /**
     * Apply data scoping to a query based on the user's permissions and role.
     *
     * @param Builder $query The query to scope
     * @param string $viewAllPerm The permission string for viewing all records (e.g., 'attendance.daily.view')
     * @param string $viewSubPerm The permission string for viewing subordinates only (e.g., 'attendance.daily.view-subordinates')
     * @param string $employeeRelation Path to the employee relation (default 'employee')
     * @return Builder
     */
    protected function applyDataScoping(Builder $query, string $viewAllPerm, string $viewSubPerm, string $employeeRelation = 'employee'): Builder
    {
        $user = auth()->user();
        if ($this->canAttendanceAny($viewAllPerm)) {
            return $query;
        }

        // If user can only view subordinates
        if ($user->can($viewSubPerm)) {
            $employeeId = $user->employee_id;

            // If the user is linked to an employee record, filter by that manager_id
            if ($employeeId) {
                if (empty($employeeRelation)) {
                    return $query->where('manager_id', $employeeId);
                }
                return $query->whereHas($employeeRelation, function ($q) use ($employeeId) {
                    $q->where('manager_id', $employeeId);
                });
            }
        }

        // Default: no matching permission; return empty result
        return $query->whereRaw('1 = 0');
    }
}



