<?php

namespace Athka\Attendance\Services;

use App\Models\User;
use Throwable;

final class TrackingNotificationRecipientPolicy
{
    public function allowsCompanyAdmin(
        User $user,
        ?int $employeeBranchId,
    ): bool {
        return $this->allowsBranch(
            accessScope: (string) ($user->access_scope ?? ''),
            userBranchId: $user->branch_id !== null
                ? (int) $user->branch_id
                : null,
            allowedBranchIds: $this->allowedBranchIds($user),
            employeeBranchId: $employeeBranchId,
        ) && $this->hasAttendanceVisibility($user);
    }

    public function allowsBranch(
        string $accessScope,
        ?int $userBranchId,
        array $allowedBranchIds,
        ?int $employeeBranchId,
    ): bool {
        $scope = strtolower(trim($accessScope));

        if ($employeeBranchId === null || $employeeBranchId <= 0) {
            return in_array(
                $scope,
                ['all_branches', 'all'],
                true,
            );
        }

        if (in_array($scope, ['all_branches', 'all'], true)) {
            return true;
        }

        if (in_array($scope, ['my_branch', 'branch'], true)) {
            return $userBranchId !== null
                && $userBranchId > 0
                && $userBranchId === $employeeBranchId;
        }

        if ($scope === 'selected_branches') {
            return in_array(
                $employeeBranchId,
                array_values(array_unique(array_map(
                    'intval',
                    $allowedBranchIds,
                ))),
                true,
            );
        }

        return false;
    }

    public function hasAttendanceVisibility(User $user): bool
    {
        foreach ([
            'attendance.dashboard.view',
            'attendance.daily.view',
            'attendance.daily.view-subordinates',
        ] as $permission) {
            try {
                if ($user->can($permission)) {
                    return true;
                }
            } catch (Throwable) {
            }
        }

        return false;
    }

    public function allowedBranchIds(User $user): array
    {
        if (! method_exists($user, 'allowedBranches')) {
            return [];
        }

        try {
            return $user->allowedBranches
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->unique()
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }
}
