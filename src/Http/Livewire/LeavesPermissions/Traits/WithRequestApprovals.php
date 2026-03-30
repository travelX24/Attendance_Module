<?php

namespace Athka\Attendance\Http\Livewire\LeavesPermissions\Traits;

use Athka\Attendance\Models\AttendanceLeaveRequest;
use Athka\Attendance\Models\AttendancePermissionRequest;
use Athka\Attendance\Models\AttendanceLeaveCutRequest;
use Athka\Attendance\Models\AttendanceMissionRequest;
use Athka\SystemSettings\Models\LeavePolicy;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Athka\Employees\Models\Employee;
use Illuminate\Support\Facades\Schema;

trait WithRequestApprovals
{
    public bool $rejectOpen = false;
    public string $rejectType = 'leave';
    public int $rejectId = 0;
    public string $rejectReason = '';

    /**
     * âœ… Robust detection for company column.
     * Prefers existing detectCompanyColumn() if exists on component.
     */
    protected function lpDetectCompanyColumn(string $table): ?string
    {
        if (method_exists($this, 'detectCompanyColumn')) {
            try {
                $col = $this->detectCompanyColumn($table);
                if (!empty($col)) return $col;
            } catch (\Throwable $e) {
                // ignore and fallback
            }
        }

        foreach (['saas_company_id', 'company_id'] as $c) {
            if (Schema::hasColumn($table, $c)) return $c;
        }

        return null;
    }



    public function approveLeave(int $id): void
    {
        $this->ensureCanManage();

        $reqTable = (new AttendanceLeaveRequest())->getTable();
        $reqCoCol = $this->lpDetectCompanyColumn($reqTable);

        $q = AttendanceLeaveRequest::query()
            ->when($reqCoCol, fn ($qq) => $qq->where($reqCoCol, $this->companyId));

        $this->lpApplyAllowedBranchesOnRequest($q, $reqTable, 'employee_id');

        $row = $q->findOrFail($id);
        if ($row->status !== 'pending') return;

        // âœ… NEW: Integrate with ApprovalInbox sequence
        try {
            $controller = app(\Athka\SystemSettings\Http\Controllers\Api\Employee\ApprovalInboxController::class);
            $resp = $controller->approve(new \Illuminate\Http\Request(), 'leaves', $id);
            $content = json_decode($resp->getContent(), true);
            
            if (!($content['ok'] ?? false)) {
                $err = $content['message'] ?? $content['error'] ?? 'Approval failed';
                $this->dispatch('toast', ['type' => 'error', 'message' => $err]);
                return;
            }
        } catch (\Throwable $e) {
            $this->dispatch('toast', ['type' => 'error', 'message' => $e->getMessage()]);
            return;
        }

        // Reload row to check if it's fully approved now
        $row->refresh();

        if ($row->status === 'approved') {
            $this->syncAttendanceLogForLeave($row);

            $this->logAction('leave', (int) $row->id, 'approved', [
                'requested_days' => $row->requested_days
            ], (int) $row->employee_id);

            // âœ… Ø¥Ø¹Ø§Ø¯Ø© Ø­Ø³Ø§Ø¨ Ø§Ù„Ø±ØµÙŠØ¯
            if (!empty($row->leave_policy_id) && !empty($row->policy_year_id)) {
                $this->recalculateBalance(
                    $this->companyId,
                    (int) $row->employee_id,
                    (int) $row->leave_policy_id,
                    (int) $row->policy_year_id
                );
            }
        }

        $this->dispatch('toast', ['type' => 'success', 'message' => tr('Saved successfully')]);
        $this->resetPage('leavePage');
    }

    public function approvePermission(int $id): void
    {
        $this->ensureCanManage();

        $permTable = (new AttendancePermissionRequest())->getTable();
        $permCoCol = $this->lpDetectCompanyColumn($permTable);

        $q = AttendancePermissionRequest::query()
            ->when($permCoCol, fn ($qq) => $qq->where($permCoCol, $this->companyId));

        $this->lpApplyAllowedBranchesOnRequest($q, $permTable, 'employee_id');

        $row = $q->findOrFail($id);
        if ($row->status !== 'pending') return;

        // âœ… NEW: Integrate with ApprovalInbox sequence
        try {
            $controller = app(\Athka\SystemSettings\Http\Controllers\Api\Employee\ApprovalInboxController::class);
            $resp = $controller->approve(new \Illuminate\Http\Request(), 'permissions', $id);
            $content = json_decode($resp->getContent(), true);
            
            if (!($content['ok'] ?? false)) {
                $err = $content['message'] ?? $content['error'] ?? 'Approval failed';
                $this->dispatch('toast', ['type' => 'error', 'message' => $err]);
                return;
            }
        } catch (\Throwable $e) {
            $this->dispatch('toast', ['type' => 'error', 'message' => $e->getMessage()]);
            return;
        }

        $row->refresh();

        if ($row->status === 'approved') {
            // âœ… recalculates monthly excess minutes correctly after final approval
            if (method_exists($this, 'recalculatePermissionMonthExcess')) {
                $this->recalculatePermissionMonthExcess(
                    (int) $row->employee_id,
                    Carbon::parse($row->permission_date)->startOfDay()
                );
            }

            $this->logAction('permission', (int) $row->id, 'approved', [
                'minutes' => $row->minutes
            ], (int) $row->employee_id);
        }

        $this->dispatch('toast', ['type' => 'success', 'message' => tr('Saved successfully')]);
        $this->resetPage('permPage');
    }

    public function cancelPermission(int $id): void
    {
        $this->ensureCanManage();

        $permTable = (new AttendancePermissionRequest())->getTable();
        $permCoCol = $this->lpDetectCompanyColumn($permTable);

        $q = AttendancePermissionRequest::query()
            ->when($permCoCol, fn ($qq) => $qq->where($permCoCol, $this->companyId));

        $this->lpApplyAllowedBranchesOnRequest($q, $permTable, 'employee_id');

        $row = $q->findOrFail($id);

        if (!in_array($row->status, ['approved', 'pending'], true)) return;

        $wasApproved = $row->status === 'approved';
        $permDate = Carbon::parse($row->permission_date)->startOfDay();

        $row->update(['status' => 'cancelled']);

        // âœ… if it was approved, recompute the month distribution
        if ($wasApproved && method_exists($this, 'recalculatePermissionMonthExcess')) {
            $this->recalculatePermissionMonthExcess((int) $row->employee_id, $permDate);
        }

        $this->logAction('permission', (int) $row->id, 'cancelled', [
            'previous_status' => $wasApproved ? 'approved' : 'pending'
        ], (int) $row->employee_id);

        $this->dispatch('toast', ['type' => 'success', 'message' => tr('Saved successfully')]);
        $this->resetPage('historyPermPage');
    }

    public function cancelLeave(int $id): void
    {
        $this->ensureCanManage();

        $reqTable = (new AttendanceLeaveRequest())->getTable();
        $reqCoCol = $this->lpDetectCompanyColumn($reqTable);

        $q = AttendanceLeaveRequest::query()
            ->when($reqCoCol, fn ($qq) => $qq->where($reqCoCol, $this->companyId));

        $this->lpApplyAllowedBranchesOnRequest($q, $reqTable, 'employee_id');

        $row = $q->findOrFail($id);

        if ($row->salary_processed_at || !in_array($row->status, ['approved', 'pending'], true)) return;

        $wasApproved = $row->status === 'approved';
        $row->update(['status' => 'cancelled']);

        if ($wasApproved) {
            $this->removeAttendanceLogSync($row);
        }

        $this->logAction('leave', (int) $row->id, 'cancelled', [
            'previous_status' => $wasApproved ? 'approved' : 'pending'
        ], (int) $row->employee_id);

        // âœ… Ù„Ø§ Ù†Ø¹ÙŠØ¯ Ø­Ø³Ø§Ø¨ Ø§Ù„Ø±ØµÙŠØ¯ Ø¥Ù„Ø§ Ù„Ùˆ ÙƒØ§Ù†Øª Ù„Ù‡Ø§ policy + policy_year
        if ($wasApproved && !empty($row->leave_policy_id) && !empty($row->policy_year_id)) {
            $this->recalculateBalance(
                $this->companyId,
                (int) $row->employee_id,
                (int) $row->leave_policy_id,
                (int) $row->policy_year_id
            );
        }

        $this->dispatch('toast', ['type' => 'success', 'message' => tr('Saved successfully')]);
        $this->resetPage('historyLeavePage');
    }

    public function openReject(string $type, int $id): void
    {
        $this->rejectType = in_array($type, ['leave', 'permission', 'cut_leave', 'replacement'], true) ? $type : 'leave';
        
        // Only allow non-managers if it's a replacement rejection for themselves
        if ($this->rejectType !== 'replacement') {
             $this->ensureCanManage();
        }

        $this->rejectId = $id;
        $this->rejectReason = '';
        $this->rejectOpen = true;
    }

    public function closeReject(): void
    {
        $this->rejectOpen = false;
    }

    public function confirmReject(): void
    {
        $this->validate(['rejectReason' => ['required', 'string', 'min:2', 'max:2000']]);

        $model = match($this->rejectType) {
            'leave', 'replacement' => AttendanceLeaveRequest::class,
            'permission' => AttendancePermissionRequest::class,
            'cut_leave' => AttendanceLeaveCutRequest::class,
            'mission' => AttendanceMissionRequest::class,
            default => AttendanceLeaveRequest::class
        };

        $row = $model::findOrFail($this->rejectId);

        if ($this->rejectType === 'replacement') {
            $empId = auth()->user()->employee_id;
            if ($row->replacement_employee_id == $empId && $row->replacement_status === 'pending') {
                $row->update([
                    'replacement_status' => 'rejected',
                    'reject_reason' => $this->rejectReason // Store reason here for visibility
                ]);
                $this->logAction('leave', (int) $row->id, 'replacement_rejected', [
                    'reason' => $this->rejectReason
                ], (int) $row->employee_id);
            }
        } elseif ($row->status === 'pending') {
            $this->ensureCanManage();
            // âœ… NEW: Integrate with ApprovalInbox sequence
            try {
                $controller = app(\Athka\SystemSettings\Http\Controllers\Api\Employee\ApprovalInboxController::class);
                $type = $this->rejectType === 'cut_leave' ? 'leaves' : $this->rejectType . 's';
                
                $rejectReq = new \Illuminate\Http\Request();
                $rejectReq->merge(['comment' => $this->rejectReason]);
                
                $resp = $controller->reject($rejectReq, $type, $this->rejectId);
                $content = json_decode($resp->getContent(), true);
                
                if (!($content['ok'] ?? false)) {
                    $err = $content['message'] ?? $content['error'] ?? 'Rejection failed';
                    $this->dispatch('toast', ['type' => 'error', 'message' => $err]);
                    return;
                }
            } catch (\Throwable $e) {
                $this->dispatch('toast', ['type' => 'error', 'message' => $e->getMessage()]);
                return;
            }

            $this->logAction($this->rejectType, (int) $row->id, 'rejected', [
                'reason' => $this->rejectReason
            ], (int) $row->employee_id);
        }

        $page = match($this->rejectType) {
            'leave' => 'leavePage',
            'permission' => 'permPage',
            'cut_leave' => 'cutPage',
            'mission' => 'missionPage',
            default => 'leavePage'
        };
        $this->resetPage($page);

        $this->dispatch('toast', ['type' => 'success', 'message' => tr('Saved successfully')]);
        $this->closeReject();
    }

    public function approveMission(int $id): void
    {
        $this->ensureCanManage();

        $table = (new \Athka\Attendance\Models\AttendanceMissionRequest())->getTable();
        $coCol = $this->lpDetectCompanyColumn($table);

        $q = \Athka\Attendance\Models\AttendanceMissionRequest::query()
            ->when($coCol, fn ($qq) => $qq->where($coCol, $this->companyId));

        $this->lpApplyAllowedBranchesOnRequest($q, $table, 'employee_id');

        $row = $q->findOrFail($id);
        if ($row->status !== 'pending') return;

        // âœ… NEW: Integrate with ApprovalInbox sequence
        try {
            $controller = app(\Athka\SystemSettings\Http\Controllers\Api\Employee\ApprovalInboxController::class);
            $resp = $controller->approve(new \Illuminate\Http\Request(), 'missions', $id);
            $content = json_decode($resp->getContent(), true);
            
            if (!($content['ok'] ?? false)) {
                $err = $content['message'] ?? $content['error'] ?? 'Approval failed';
                $this->dispatch('toast', ['type' => 'error', 'message' => $err]);
                return;
            }
        } catch (\Throwable $e) {
            $this->dispatch('toast', ['type' => 'error', 'message' => $e->getMessage()]);
            return;
        }

        $row->refresh();

        if ($row->status === 'approved') {
            $this->logAction('mission', (int) $row->id, 'approved', [], (int) $row->employee_id);
        }

        $this->dispatch('toast', ['type' => 'success', 'message' => tr('Saved successfully')]);
        $this->resetPage('missionPage');
    }

    public function approveCutLeave(int $id): void
    {
        $this->ensureCanManage();

        $cutTable = (new AttendanceLeaveCutRequest())->getTable();
        $cutCoCol = $this->lpDetectCompanyColumn($cutTable);

        $cutQ = AttendanceLeaveCutRequest::query()
            ->when($cutCoCol, fn ($qq) => $qq->where($cutCoCol, $this->companyId));

        $this->lpApplyAllowedBranchesOnRequest($cutQ, $cutTable, 'employee_id');

        $cut = $cutQ->findOrFail($id);
        if ($cut->status !== 'pending') return;

        // âœ… NEW: Integrate with ApprovalInbox sequence
        try {
            $controller = app(\Athka\SystemSettings\Http\Controllers\Api\Employee\ApprovalInboxController::class);
            $resp = $controller->approve(new \Illuminate\Http\Request(), 'leaves', $id);
            $content = json_decode($resp->getContent(), true);
            
            if (!($content['ok'] ?? false)) {
                $err = $content['message'] ?? $content['error'] ?? 'Approval failed';
                $this->dispatch('toast', ['type' => 'error', 'message' => $err]);
                return;
            }
        } catch (\Throwable $e) {
            $this->dispatch('toast', ['type' => 'error', 'message' => $e->getMessage()]);
            return;
        }

        $cut->refresh();

        if ($cut->status === 'approved') {
            // original leave must also be within allowed branches
            $reqTable = (new AttendanceLeaveRequest())->getTable();
            $reqCoCol = $this->lpDetectCompanyColumn($reqTable);

            $origQ = AttendanceLeaveRequest::query()
                ->when($reqCoCol, fn ($qq) => $qq->where($reqCoCol, $this->companyId));

            $this->lpApplyAllowedBranchesOnRequest($origQ, $reqTable, 'employee_id');

            $original = $origQ->findOrFail((int) $cut->original_leave_request_id);

            $policy = LeavePolicy::query()
                ->where('company_id', $this->companyId) 
                ->findOrFail($original->leave_policy_id);

            $origStart = Carbon::parse($original->start_date)->startOfDay();
            $origEnd   = Carbon::parse($original->end_date)->startOfDay();
            $cutEnd    = Carbon::parse($cut->cut_end_date)->startOfDay();

            if ($cutEnd->lt($origStart) || $cutEnd->gte($origEnd)) {
                $this->dispatch('toast', ['type' => 'error', 'message' => tr('Invalid date range')]);
                return;
            }

            DB::transaction(function () use ($cut, $original, $policy, $origStart, $origEnd, $cutEnd) {
                $newRequestedDays = $this->computeRequestedDays($policy, $origStart, $cutEnd);

                $original->update([
                    'end_date' => $cutEnd->toDateString(),
                    'requested_days' => $newRequestedDays
                ]);

                $this->removeAttendanceLogSyncInRange(
                    (int) $original->employee_id,
                    $cutEnd->copy()->addDay(),
                    $origEnd
                );

                $newLeaveId = null;

                if ($cut->postponed_start_date && ! $cut->new_leave_request_id) {
                    $pStart = Carbon::parse($cut->postponed_start_date)->startOfDay();
                    $pEnd   = Carbon::parse($cut->postponed_end_date)->startOfDay();

                    if ($pStart->lte($pEnd)) {
                        $new = AttendanceLeaveRequest::create([
                            'company_id' => $this->companyId,
                            'employee_id' => $original->employee_id,
                            'leave_policy_id' => $original->leave_policy_id,
                            'policy_year_id' => $original->policy_year_id,
                            'start_date' => $pStart->toDateString(),
                            'end_date' => $pEnd->toDateString(),
                            'requested_days' => $this->computeRequestedDays($policy, $pStart, $pEnd),
                            'reason' => $cut->reason,
                            'source' => 'hr_cut',
                            'status' => 'pending',
                            'requested_by' => auth()->id(),
                            'requested_at' => now(),
                        ]);
                        $newLeaveId = $new->id;
                    }
                }

                $cut->update([
                    'new_leave_request_id' => $newLeaveId ?: $cut->new_leave_request_id,
                ]);

                $this->logAction('leave_cut', (int) $cut->id, 'approved', [
                    'new_requested_days' => $newRequestedDays
                ], (int) $original->employee_id);

                $this->recalculateBalance(
                    $this->companyId,
                    (int) $original->employee_id,
                    (int) $original->leave_policy_id,
                    (int) $original->policy_year_id
                );
            });
        }

        $this->dispatch('toast', ['type' => 'success', 'message' => tr('Saved successfully')]);
        $this->resetPage('cutPage');
    }

    protected function lpApplyAllowedBranchesOnRequest($q, string $requestTable, string $employeeIdColumn = 'employee_id'): void
    {
        // Ù„Ùˆ Ù…Ø§ Ø¹Ù†Ø¯Ùƒ Ù†Ø¸Ø§Ù… ØµÙ„Ø§Ø­ÙŠØ§Øª ÙØ±ÙˆØ¹ØŒ Ù„Ø§ ØªÙ‚ÙŠÙ‘Ø¯
        if (!method_exists($this, 'lpAllowedBranchIdsSafe')) return;

        // Ù…Ù‡Ù…: Ù„Ùˆ Ø±Ø¬Ù‘Ø¹Øª null => Ù…Ø¹Ù†Ø§Ù‡Ø§ ÙˆØµÙˆÙ„ ÙƒØ§Ù…Ù„ (Ø¨Ø¯ÙˆÙ† ØªÙ‚ÙŠÙŠØ¯)
        $allowed = $this->lpAllowedBranchIdsSafe();
        if ($allowed === null) return;

        $allowed = array_values(array_filter(array_map('intval', (array) $allowed)));

        // âœ… Ù„Ùˆ ÙØ§Ø¶ÙŠ: Ø£Ø­ÙŠØ§Ù†Ø§Ù‹ all_branches ÙŠØ±Ø¬Ø¹ [] Ø¨Ø§Ù„ØºÙ„Ø· => Ù„Ø§ ØªÙ‚ÙŠÙ‘Ø¯
        if (empty($allowed)) {
            $scope = auth()->user()?->access_scope ?? 'all_branches';
            if ($scope === 'all_branches') return;

            // ØºÙŠØ± ÙƒØ°Ø§: Ù…Ø§ ÙŠØ´ÙˆÙ Ø´ÙŠØ¡ (Ø£Ù…Ø§Ù†)
            $q->whereRaw('1=0');
            return;
        }

        $empTable  = (new Employee())->getTable();
        $branchCol = $this->employeeBranchColumn
            ?? (method_exists($this, 'detectEmployeeBranchColumn') ? $this->detectEmployeeBranchColumn() : null)
            ?? 'branch_id';

        if (!$branchCol || !Schema::hasColumn($empTable, $branchCol)) {
            // Ù„Ùˆ Ù…Ø§ Ù†Ù‚Ø¯Ø± Ù†Ø­Ø¯Ø¯ Ø¹Ù…ÙˆØ¯ Ø§Ù„ÙØ±Ø¹ Ø¨Ø«Ù‚Ø©ØŒ Ù…Ø§ Ù†Ø·Ø¨Ù‘Ù‚ ØªÙ‚ÙŠÙŠØ¯ Ø­ØªÙ‰ Ù„Ø§ Ù†ÙƒØ³Ø± Ø§Ù„Ù†Ø¸Ø§Ù…
            return;
        }

        $q->whereExists(function ($sub) use ($empTable, $branchCol, $allowed, $requestTable, $employeeIdColumn) {
            $sub->select(DB::raw(1))
                ->from($empTable . ' as lp_emp')
                ->whereColumn('lp_emp.id', $requestTable . '.' . $employeeIdColumn)
                ->whereIn('lp_emp.' . $branchCol, $allowed);
        });
    }
}

