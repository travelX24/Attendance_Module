<?php

namespace Athka\Attendance\Http\Livewire\Traits;

use Illuminate\Database\Eloquent\Builder;

trait WithDataScoping
{
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

        // Company admin / primary user: full access to all data
        if (
            $user->can('attendance.manage') ||
            $user->can('settings.attendance.manage') ||
            $user->can('attendance.manage-all')
        ) {
            return $query;
        }

        // If user can view all records for this specific permission
        if ($user->can($viewAllPerm)) {
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

        // Default: no matching permission → return empty result
        return $query->whereRaw('1 = 0');
    }
}


