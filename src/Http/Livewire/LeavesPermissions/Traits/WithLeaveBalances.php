<?php

namespace Athka\Attendance\Http\Livewire\LeavesPermissions\Traits;

use Athka\Attendance\Models\AttendanceLeaveBalance;
use Athka\Attendance\Models\AttendanceLeaveRequest;
use Athka\SystemSettings\Models\LeavePolicy;

trait WithLeaveBalances
{
    public bool $balanceAuditOpen = false;
    public $selectedBalance = null;
    public $balanceHistory = [];

    public function recalcBalanceRow(int $id): void
    {
        $this->ensureCanManage();
        $row = AttendanceLeaveBalance::query()->where('company_id', $this->companyId)->findOrFail($id);
        $this->recalculateBalance($this->companyId, (int)$row->employee_id, (int)$row->leave_policy_id, (int)$row->policy_year_id);
        session()->flash('success', tr('Saved successfully'));
        $this->resetPage('balancePage');
    }

    protected function recalculateBalance(int $companyId, int $employeeId, int $policyId, int $yearId): void
    {
        $policy = LeavePolicy::query()->where('company_id', $companyId)->find($policyId);
        $employee = \Athka\Employees\Models\Employee::find($employeeId);
        
        $entitled = $policy ? (float)($policy->days_per_year ?? 0) : 0.0;
        
        // Check exclusions
        if ($policy && $employee) {
            $excluded = (array) ($policy->excluded_contract_types ?? []);
            if (in_array($employee->contract_type, $excluded)) {
                $entitled = 0.0;
            }
        }
        $taken = (float) AttendanceLeaveRequest::query()->where('company_id', $companyId)->where('employee_id', $employeeId)->where('leave_policy_id', $policyId)->where('policy_year_id', $yearId)->where('status', 'approved')->sum('requested_days');
        
        AttendanceLeaveBalance::updateOrCreate(
            ['company_id' => $companyId, 'employee_id' => $employeeId, 'leave_policy_id' => $policyId, 'policy_year_id' => $yearId],
            ['entitled_days' => $entitled, 'taken_days' => $taken, 'remaining_days' => max($entitled - $taken, 0), 'last_recalculated_at' => now()]
        );
    }

    public function openBalanceAudit(int $balanceId): void
    {
        $this->ensureCanManage();
        $this->selectedBalance = AttendanceLeaveBalance::with(['employee', 'policy', 'year'])->findOrFail($balanceId);
        $this->balanceHistory = AttendanceLeaveRequest::where('employee_id', $this->selectedBalance->employee_id)
            ->where('leave_policy_id', $this->selectedBalance->leave_policy_id)
            ->where('policy_year_id', $this->selectedBalance->policy_year_id)
            ->where('status', 'approved')
            ->orderByDesc('start_date')
            ->get();
        $this->balanceAuditOpen = true;
    }

    public function closeBalanceAudit(): void
    {
        $this->balanceAuditOpen = false;
        $this->selectedBalance = null;
        $this->balanceHistory = [];
    }
}


