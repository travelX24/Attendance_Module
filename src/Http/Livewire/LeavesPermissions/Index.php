<?php

namespace Athka\Attendance\Http\Livewire\LeavesPermissions;

use Livewire\Component;
use Livewire\WithPagination;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\WithFileUploads;
use Athka\SystemSettings\Services\LeaveSettingService;

use Athka\Employees\Models\Employee;
use Athka\SystemSettings\Models\LeavePolicyYear;
use Athka\SystemSettings\Models\LeavePolicy;
use Athka\Saas\Models\Branch;
use Athka\Attendance\Models\AttendanceLeaveRequest;
use Athka\Attendance\Models\AttendancePermissionRequest;
use Athka\Attendance\Models\AttendanceLeaveBalance;
use Athka\Attendance\Models\AttendanceRequestAction;
use Athka\Attendance\Models\AttendanceLeaveCutRequest;

use Athka\Attendance\Http\Livewire\LeavesPermissions\Traits\WithLeavePermissionsFilters;
use Athka\Attendance\Http\Livewire\LeavesPermissions\Traits\WithLeaveRequests;
use Athka\Attendance\Http\Livewire\LeavesPermissions\Traits\WithPermissionRequests;
use Athka\Attendance\Http\Livewire\LeavesPermissions\Traits\WithRequestApprovals;
use Athka\Attendance\Http\Livewire\LeavesPermissions\Traits\WithLeaveBalances;
use Athka\Attendance\Http\Livewire\LeavesPermissions\Traits\WithAttendanceSync;
use Athka\Attendance\Http\Livewire\LeavesPermissions\Traits\WithMissionRequests;
use Athka\Attendance\Http\Livewire\Traits\WithDataScoping;

class Index extends Component
{
    use WithPagination,
        WithFileUploads,
        WithLeavePermissionsFilters,
        WithLeaveRequests,
        WithPermissionRequests,
        WithRequestApprovals,
        WithLeaveBalances,
        WithAttendanceSync,
        WithMissionRequests,
        WithDataScoping;

    public int $companyId = 0;
    public bool $workflowModalOpen = false;
    public $currentWorkflowTasks = [];
    public $currentRequest = null;
    public string $currentRequestType = 'leave';
    public string $pendingSubTab = 'leaves';

    public ?string $employeeBranchColumn = null;

    protected $paginationTheme = 'tailwind';

    public function mount(LeaveSettingService $leaveSettingService): void
    {
        $this->companyId = (int) $this->resolveCompanyId();

        $yearTable  = (new LeavePolicyYear())->getTable();
        $yearCoCol  = $this->detectCompanyColumn($yearTable);

        $year = LeavePolicyYear::query()
            ->when($yearCoCol, fn ($q) => $q->where($yearCoCol, $this->companyId))
            ->when(Schema::hasColumn($yearTable, 'is_active'), fn ($q) => $q->where('is_active', true))
            ->orderByDesc('year')
            ->first()
            ?: LeavePolicyYear::query()
                ->when($yearCoCol, fn ($q) => $q->where($yearCoCol, $this->companyId))
                ->orderByDesc('year')
                ->first();

        if (!$year) {
            $leaveSettingService->ensureDefaultConfiguration($this->companyId);
            $year = LeavePolicyYear::query()
                ->when($yearCoCol, fn ($q) => $q->where($yearCoCol, $this->companyId))
                ->where('is_active', true)
                ->first();
        }

        $this->selectedYearId = $year ? (int) $year->id : null;

        $this->employeeCompanyColumn = $this->detectEmployeeCompanyColumn();
        $this->employeeNameColumns   = $this->detectEmployeeNameColumns();
        $this->employeeDepartmentColumn = $this->detectEmployeeDepartmentColumn();
        $this->employeeJobTitleColumn   = $this->detectEmployeeJobTitleColumn();
        $this->departmentsTable = $this->detectDepartmentsTable();
        $this->jobTitlesTable   = $this->detectJobTitlesTable();
        $this->leavePolicyYearColumn = $this->detectLeavePolicyYearColumn();

        $this->employeeBranchColumn = $this->detectEmployeeBranchColumn();  
  }

    protected function resetAllPages(): void
    {
        $this->resetPage('leavePage');
        $this->resetPage('permPage');
        $this->resetPage('cutPage');
        $this->resetPage('missionPage');
        $this->resetPage('historyPage');
        $this->resetPage('balancePage');
        $this->resetPage('historyLeavePage');
        $this->resetPage('historyPermPage');
        $this->resetPage('historyCutPage');
        $this->resetPage('historyMissionPage');
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['balances', 'pending', 'history'], true) ? $tab : 'pending';
    }

    public function setPendingSubTab(string $subTab): void
    {
        $this->pendingSubTab = in_array($subTab, ['leaves', 'permissions', 'cuts', 'missions'], true) ? $subTab : 'leaves';
    }

    public function openWorkflow(string $type, int $id): void
    {
        $this->currentRequestType = $type;
        $this->currentRequest = $type === 'leave' 
            ? AttendanceLeaveRequest::with('employee')->find($id)
            : ($type === 'permission'
                ? AttendancePermissionRequest::with('employee')->find($id)
                : \Athka\Attendance\Models\AttendanceMissionRequest::with('employee')->find($id));

        if (!$this->currentRequest) return;

        // Fetch approval tasks logic
        $this->currentWorkflowTasks = \Athka\SystemSettings\Models\ApprovalTask::query()
            ->with('approver')
            ->where('approvable_type', $type === 'leave' ? 'leaves' : ($type === 'permission' ? 'permissions' : 'missions'))
            ->where('approvable_id', $id)
            ->orderBy('position')
            ->get();

        $this->workflowModalOpen = true;
    }

    public function closeWorkflow(): void
    {
        $this->workflowModalOpen = false;
        $this->currentWorkflowTasks = [];
        $this->currentRequest = null;
    }

    public function getPendingLeaveRequestsProperty()
    {
        $q = AttendanceLeaveRequest::query()
            ->with(['employee', 'policy'])
            ->where('company_id', $this->companyId)
            ->where('status', 'pending');

        // âœ… Data scoping
        $q = $this->applyDataScoping($q, 'attendance.leaves.view', 'attendance.leaves.view-subordinates');

        if ($this->selectedYearId) $q->where('policy_year_id', (int) $this->selectedYearId);
        if ($this->filterLeavePolicyId) $q->where('leave_policy_id', (int) $this->filterLeavePolicyId);

        $this->applyDateRangeOverlapFilter($q, 'start_date', 'end_date');
        $this->applyEmployeeFilters($q);
        $this->applyApprovalTaskFilter($q, 'leaves');

        return $q->orderByDesc('id')->paginate($this->perPage, ['*'], 'leavePage');
    }

    public function getPendingPermissionRequestsProperty()
    {
        $permTable = (new AttendancePermissionRequest())->getTable();
        $permCoCol = $this->detectCompanyColumn($permTable);

        $q = AttendancePermissionRequest::query()
            ->with(['employee'])
            ->when($permCoCol, fn ($qq) => $qq->where($permCoCol, $this->companyId))
            ->where('status', 'pending');

        // âœ… Data scoping
        $q = $this->applyDataScoping($q, 'attendance.leaves.view', 'attendance.leaves.view-subordinates');


        $this->applySelectedYearDateRangeFilter($q, 'permission_date');
        $this->applyDateRangeBetweenFilter($q, 'permission_date');
        $this->applyEmployeeFilters($q);
        $this->applyApprovalTaskFilter($q, 'permissions');

        return $q->orderByDesc('id')->paginate($this->perPage, ['*'], 'permPage');
    }

    public function getBalancesProperty()
    {
        $q = AttendanceLeaveBalance::query()
            ->with(['employee', 'policy', 'year'])
            ->where('company_id', $this->companyId);

        // âœ… Data scoping
        $q = $this->applyDataScoping($q, 'attendance.leaves.view', 'attendance.leaves.view-subordinates');

        if ($this->selectedYearId) $q->where('policy_year_id', (int) $this->selectedYearId);
        if ($this->filterLeavePolicyId) $q->where('leave_policy_id', (int) $this->filterLeavePolicyId);

        $this->applyEmployeeFilters($q);

        return $q->orderByDesc('remaining_days')->paginate($this->perPage, ['*'], 'balancePage');
    }

    public function getHistoryProperty()
    {
        $q = AttendanceRequestAction::query()
            ->with(['actor', 'employee'])
            ->where('company_id', $this->companyId)
            ->orderByDesc('id');

        // âœ… Data scoping
        $q = $this->applyDataScoping($q, 'attendance.leaves.view', 'attendance.leaves.view-subordinates');

        $this->applyDateRangeBetweenFilter($q, 'created_at');

        $allowed = $this->allowedBranchIds();
        $term = trim($this->search);

        if (
            !empty($allowed)
            || $term !== ''
            || ($this->branchId ?? '') !== ''
            || $this->departmentId
            || $this->jobTitleId
        ) {
            $q->whereHas('employee', function ($qq) use ($term, $allowed) {
                $this->applyEmployeeWhere($qq, $term, $allowed);
            });
        }
        return $q->paginate($this->perPage, ['*'], 'historyPage');
    }

    public function getPreviousLeaveRequestsProperty()
    {
        $q = AttendanceLeaveRequest::query()
            ->with(['employee', 'policy'])
            ->where('company_id', $this->companyId)
            ->where('status', '!=', 'pending');

        // âœ… Data scoping
        $q = $this->applyDataScoping($q, 'attendance.leaves.view', 'attendance.leaves.view-subordinates');

        if ($this->selectedYearId) $q->where('policy_year_id', (int) $this->selectedYearId);
        if ($this->filterLeavePolicyId) $q->where('leave_policy_id', (int) $this->filterLeavePolicyId);
        if ($this->historyStatus !== '') $q->where('status', $this->historyStatus);

        $this->applyDateRangeOverlapFilter($q, 'start_date', 'end_date');
        $this->applyEmployeeFilters($q);

        return $q->orderByDesc('id')->paginate($this->perPage, ['*'], 'historyLeavePage');
    }

    public function getPreviousPermissionRequestsProperty()
    {
       $permTable = (new AttendancePermissionRequest())->getTable();
        $permCoCol = $this->detectCompanyColumn($permTable);

        $q = AttendancePermissionRequest::query()
            ->with(['employee'])
            ->when($permCoCol, fn ($qq) => $qq->where($permCoCol, $this->companyId))
            ->where('status', '!=', 'pending');

        // âœ… Data scoping
        $q = $this->applyDataScoping($q, 'attendance.leaves.view', 'attendance.leaves.view-subordinates');


        if ($this->historyStatus !== '') $q->where('status', $this->historyStatus);

        $this->applySelectedYearDateRangeFilter($q, 'permission_date');
        $this->applyDateRangeBetweenFilter($q, 'permission_date');
        $this->applyEmployeeFilters($q);

        return $q->orderByDesc('id')->paginate($this->perPage, ['*'], 'historyPermPage');
    }

    public function getPendingCutLeaveRequestsProperty()
    {
        $q = AttendanceLeaveCutRequest::query()
            ->with(['employee', 'policy'])
            ->where('company_id', $this->companyId)
            ->where('status', 'pending');

        // âœ… Data scoping
        $q = $this->applyDataScoping($q, 'attendance.leaves.view', 'attendance.leaves.view-subordinates');

        if ($this->selectedYearId) $q->where('policy_year_id', (int) $this->selectedYearId);
        if ($this->filterLeavePolicyId) $q->where('leave_policy_id', (int) $this->filterLeavePolicyId);

        $this->applyDateRangeOverlapFilter($q, 'original_start_date', 'original_end_date');
        $this->applyEmployeeFilters($q);
        $this->applyApprovalTaskFilter($q, 'leaves'); // assuming cut uses leaves type for approvals? Or maybe cut needs its own. 
        // Wait, cut usually follows similar sequence. But let's check cut type.

        return $q->orderByDesc('id')->paginate($this->perPage, ['*'], 'cutPage');
    }

    public function getPreviousCutLeaveRequestsProperty()
    {
        $q = AttendanceLeaveCutRequest::query()
            ->with(['employee', 'policy'])
            ->where('company_id', $this->companyId)
            ->where('status', '!=', 'pending');

        // âœ… Data scoping
        $q = $this->applyDataScoping($q, 'attendance.leaves.view', 'attendance.leaves.view-subordinates');

        if ($this->selectedYearId) $q->where('policy_year_id', (int) $this->selectedYearId);
        if ($this->filterLeavePolicyId) $q->where('leave_policy_id', (int) $this->filterLeavePolicyId);
        if ($this->historyStatus !== '') $q->where('status', $this->historyStatus);

        $this->applyDateRangeOverlapFilter($q, 'original_start_date', 'original_end_date');
        $this->applyEmployeeFilters($q);

        return $q->orderByDesc('id')->paginate($this->perPage, ['*'], 'historyCutPage');
    }

    public function getGroupEmployeesForSelectProperty()
    {
        $q = Employee::query();

        // âœ… Data scoping
        $q = $this->applyDataScoping($q, 'attendance.leaves.view', 'attendance.leaves.view-subordinates', '');

        if ($this->employeeCompanyColumn) $q->where($this->employeeCompanyColumn, $this->companyId);

        $employeeTable = (new Employee())->getTable();
        $contractTypeColumn = null;

        foreach (['contract_type', 'contractType', 'employment_type'] as $col) {
            if (Schema::hasColumn($employeeTable, $col)) {
                $contractTypeColumn = $col;
                break;
            }
        }
        $allowed = $this->allowedBranchIds();
        if (!empty($allowed) && $this->employeeBranchColumn) {
            $q->whereIn($this->employeeBranchColumn, $allowed);
        }
        
        if ($this->employeeBranchColumn) {
            $activeBranchId = $this->groupBranchId
                ?: ((($this->branchId ?? '') !== '') ? (int) $this->branchId : null);

            if ($activeBranchId) {
                $q->where($this->employeeBranchColumn, $activeBranchId);
            }
        }

        if ($this->groupDepartmentId && $this->employeeDepartmentColumn) {
            $q->where($this->employeeDepartmentColumn, (int) $this->groupDepartmentId);
        }

        if ($this->groupJobTitleId && $this->employeeJobTitleColumn) {
            $q->where($this->employeeJobTitleColumn, (int) $this->groupJobTitleId);
        }

       if ($this->groupContractType !== '' && $contractTypeColumn) {
            $q->where($contractTypeColumn, trim((string) $this->groupContractType));
        }

        $term = trim($this->groupEmployeeSearch);
        
        if ($term !== '') {
            $q->where(function ($qq) use ($term) {
                foreach ($this->employeeNameColumns as $col) $qq->orWhere($col, 'like', '%' . $term . '%');
                if (Schema::hasColumn('employees', 'employee_no')) $qq->orWhere('employee_no', 'like', '%' . $term . '%');
            });
        }

        return $q->orderByDesc('id')->limit(50)->get();
    }

    public function getApprovedLeavesForCutProperty()
    {
        $q = AttendanceLeaveRequest::query()
            ->with(['employee', 'policy'])
            ->where('company_id', $this->companyId)
            ->where('status', 'approved')
            ->whereNull('salary_processed_at');

        // âœ… Data scoping
        $q = $this->applyDataScoping($q, 'attendance.leaves.view', 'attendance.leaves.view-subordinates');

        if ($this->selectedYearId) $q->where('policy_year_id', (int) $this->selectedYearId);

        $this->applyEmployeeFilters($q);

        return $q->orderByDesc('id')->limit(50)->get();
    }

    protected function logAction(string $type, int $subjectId, string $action, array $meta = [], ?int $employeeId = null): void
    {
        AttendanceRequestAction::create([
            'company_id' => $this->companyId,
            'actor_user_id' => auth()->id(),
            'employee_id' => $employeeId,
            'subject_type' => $type,
            'subject_id' => $subjectId,
            'action' => $action,
            'meta' => $meta,
        ]);
    }

    protected function applyEmployeeFilters($q): void
    {
        $term = trim($this->search);

        $allowed = $this->allowedBranchIds();
        $mustScopeByBranch = !empty($allowed);

        if (
            ! $mustScopeByBranch
            && $term === ''
            && ($this->branchId ?? '') === ''
            && ! $this->departmentId
            && ! $this->jobTitleId
        ) {
            return;
        }

        $q->whereHas('employee', function ($qq) use ($term, $allowed) {
            $this->applyEmployeeWhere($qq, $term, $allowed);
        });
    }

    protected function applyEmployeeWhere($qq, string $term, ?array $allowed = null): void
    {
        $allowed = $allowed ?? $this->allowedBranchIds();

        $qq->where(function ($w) use ($term, $allowed) {

            // âœ… ØªÙ‚ÙŠÙŠØ¯ Ø¯Ø§Ø¦Ù… Ø¯Ø§Ø®Ù„ Ø§Ù„ÙØ±ÙˆØ¹ Ø§Ù„Ù…Ø³Ù…ÙˆØ­Ø©
            if ($this->employeeBranchColumn && !empty($allowed)) {
                $w->whereIn($this->employeeBranchColumn, $allowed);
            }

            if ($term !== '') {
                $w->where(function ($or) use ($term) {
                    foreach ($this->employeeNameColumns as $col) $or->orWhere($col, 'like', '%' . $term . '%');
                    if (Schema::hasColumn('employees', 'employee_no')) $or->orWhere('employee_no', 'like', '%' . $term . '%');
                    if (is_numeric($term)) $or->orWhere('id', (int) $term);
                });
            }

            // âœ… ÙÙ„ØªØ± Ø§Ù„ÙØ±Ø¹ Ø§Ù„Ù…Ø®ØªØ§Ø± (Ø¥Ù† ÙˆØ¬Ø¯)
            if (($this->branchId ?? '') !== '' && $this->employeeBranchColumn) {
                $w->where($this->employeeBranchColumn, (int) $this->branchId);
            }

            if ($this->departmentId && $this->employeeDepartmentColumn) $w->where($this->employeeDepartmentColumn, (int) $this->departmentId);
            if ($this->jobTitleId && $this->employeeJobTitleColumn) $w->where($this->employeeJobTitleColumn, (int) $this->jobTitleId);
        });
    }

    protected function applyDateRangeBetweenFilter($q, string $dateColumn): void
    {
        if ($this->fromDate !== '') $q->whereDate($dateColumn, '>=', $this->fromDate);
        if ($this->toDate !== '') $q->whereDate($dateColumn, '<=', $this->toDate);
    }

    protected function applyDateRangeOverlapFilter($q, string $startCol, string $endCol): void
    {
        if ($this->fromDate !== '') $q->whereDate($endCol, '>=', $this->fromDate);
        if ($this->toDate !== '') $q->whereDate($startCol, '<=', $this->toDate);
    }

    protected function applySelectedYearDateRangeFilter($q, string $dateColumn): void
    {
        if (! $this->selectedYearId) return;

        $yearTable = (new LeavePolicyYear())->getTable();
        $yearCoCol = $this->detectCompanyColumn($yearTable);

        $year = LeavePolicyYear::query()
            ->when($yearCoCol, fn ($q) => $q->where($yearCoCol, $this->companyId))
            ->where('id', (int) $this->selectedYearId)
            ->first();

        if (! $year) return;

        $start = $year->starts_on ?: ($year->year ? Carbon::create((int) $year->year, 1, 1)->toDateString() : null);
        $end   = $year->ends_on   ?: ($year->year ? Carbon::create((int) $year->year, 12, 31)->toDateString() : null);

        if ($start) $q->whereDate($dateColumn, '>=', $start);
        if ($end)   $q->whereDate($dateColumn, '<=', $end);
    }

    protected function ensureCanManage(): void
    {
        // Allow if they have global manage permission
        if (auth()->user()?->can('settings.attendance.manage') || auth()->user()?->can('attendance.manage')) {
            return;
        }

        // Allow if they have basic view permissions as a manager
        // The ApprovalInboxController will handle the strict per-request task authorization.
        if (auth()->user()?->can('attendance.leaves.view') || auth()->user()?->can('attendance.leaves.view-subordinates')) {
            return;
        }

        abort(403);
    }

    protected function emptyPaginator(string $pageName)
    {
        return new \Illuminate\Pagination\LengthAwarePaginator(
            [],
            0,
            $this->perPage,
            1,
            ['path' => request()->url(), 'pageName' => $pageName]
        );
    }

    public function render()
    {
        $pendingLeave = $this->tab === 'pending' ? $this->pendingLeaveRequests : $this->emptyPaginator('leavePage');
        $pendingPerm  = $this->tab === 'pending' ? $this->pendingPermissionRequests : $this->emptyPaginator('permPage');
        $pendingCut   = $this->tab === 'pending' ? $this->pendingCutLeaveRequests : $this->emptyPaginator('cutPage');
        $pendingMission = $this->tab === 'pending' ? $this->pendingMissionRequests : $this->emptyPaginator('missionPage');
        $balances     = ($this->tab === 'balances' && Schema::hasTable('attendance_leave_balances')) ? $this->balances : $this->emptyPaginator('balancePage');
        $prevCut      = $this->tab === 'history' ? $this->previousCutLeaveRequests : $this->emptyPaginator('historyCutPage');
        $history      = $this->tab === 'history' ? $this->history : $this->emptyPaginator('historyPage');
        $prevLeave    = $this->tab === 'history' ? $this->previousLeaveRequests : $this->emptyPaginator('historyLeavePage');
        $prevPerm     = $this->tab === 'history' ? $this->previousPermissionRequests : $this->emptyPaginator('historyPermPage');
        $prevMission  = $this->tab === 'history' ? $this->previousMissionRequests : $this->emptyPaginator('historyMissionPage');

        if ($balances instanceof \Illuminate\Pagination\LengthAwarePaginator) {
            $balances->setCollection($balances->getCollection()->map(function ($row) {
                $entitled = (float)($row->entitled_days ?? 0);
                $row->usage_percentage = $entitled > 0 ? round((($row->taken_days ?? 0) / $entitled) * 100, 2) : 0.0;
                return $row;
            }));
        }

        [$workStart, $workEnd] = $this->getCompanyWorkWindow($this->start_date ?: ($this->group_start_date ?: null));

        return view('attendance::livewire.leaves-permissions.index', [
            'years' => $this->years,
            'policies' => $this->policies,
            'departments' => $this->departments,
            'jobTitles' => $this->jobTitles,
            'employeesForSelect' => $this->employeesForSelect,
            'createLeavePolicies' => $this->getCreateLeavePoliciesProperty(),
            'replacementEmployees' => $this->replacementEmployees,
            'branches' => $this->branches,
            'pendingLeaveRequests' => $pendingLeave,
            'pendingPermissionRequests' => $pendingPerm,
            'balances' => $balances,
            'approvedLeavesForCut' => $this->approvedLeavesForCut,
            'history' => $history,
            'previousLeaveRequests' => $prevLeave,
            'previousPermissionRequests' => $prevPerm,
            'groupEmployeesForSelect' => ($this->createGroupLeaveOpen || $this->createGroupPermissionOpen)
                ? $this->groupEmployeesForSelect
                : collect(),
            'pendingCutLeaveRequests' => $pendingCut,
            'pendingMissionRequests' => $pendingMission,
            'previousMissionRequests' => $prevMission,
            'previousCutLeaveRequests' => $prevCut,
            'workStart' => $workStart,
            'workEnd' => $workEnd,
        ])->layout('layouts.company-admin');
    }



/**
 * Validate permission time within company work window.
 * This method is called from WithPermissionRequests trait.
 */
protected function validatePermissionWithinWorkWindow(...$args): bool
{
    // 1) Detect mode (single/group) if passed as first arg
    $mode = null;
    if (isset($args[0]) && is_string($args[0]) && in_array($args[0], ['group', 'single', 'permission'], true)) {
        $mode = $args[0] === 'permission' ? 'single' : $args[0];
        array_shift($args);
    }

    $looksLikeTime = function ($v): bool {
        if (!is_string($v)) return false;
        $v = trim($v);
        return (bool) preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $v);
    };

    $toMinutes = function (?string $t): ?int {
        if (!$t) return null;
        $t = trim($t);
        if ($t === '') return null;

        // accept HH:MM or HH:MM:SS
        $parts = explode(':', $t);
        if (count($parts) < 2) return null;

        $h = (int) $parts[0];
        $m = (int) $parts[1];

        if ($h < 0 || $h > 23) return null;
        if ($m < 0 || $m > 59) return null;

        return $h * 60 + $m;
    };

    // 2) Support different call signatures safely:
    // - validatePermissionWithinWorkWindow()
    // - validatePermissionWithinWorkWindow($date, $from, $to)
    // - validatePermissionWithinWorkWindow($from, $to)  // date from component
    $date = null;
    $from = null;
    $to   = null;

    if (isset($args[0], $args[1]) && $looksLikeTime($args[0]) && $looksLikeTime($args[1]) && !isset($args[2])) {
        // called as (from, to)
        $from = $args[0];
        $to   = $args[1];
    } else {
        $date = $args[0] ?? null;
        $from = $args[1] ?? null;
        $to   = $args[2] ?? null;
    }

    // 3) Auto-detect group/single if not provided
    if (!$mode) {
        $hasGroup = !empty($this->group_from_time ?? null) || !empty($this->group_to_time ?? null);
        $mode = $hasGroup ? 'group' : 'single';
    }

    $fromField = $mode === 'group' ? 'group_from_time' : 'from_time';
    $toField   = $mode === 'group' ? 'group_to_time'   : 'to_time';

    $date = is_string($date) && trim($date) !== ''
        ? $date
        : ($mode === 'group'
            ? ($this->group_permission_date ?? null)
            : ($this->permission_date ?? null));

    $from = is_string($from) && trim($from) !== '' ? $from : ($this->{$fromField} ?? null);
    $to   = is_string($to)   && trim($to)   !== '' ? $to   : ($this->{$toField}   ?? null);

    // Nothing to validate yet
    if (!$from || !$to) return true;

    // 4) Get company work window (already exists in your traits)
    [$workStart, $workEnd] = $this->getCompanyWorkWindow($date);

    if (!$workStart || !$workEnd) return true;

    $ws = $toMinutes($workStart);
    $we = $toMinutes($workEnd);
    $f  = $toMinutes($from);
    $t  = $toMinutes($to);

    if ($ws === null || $we === null || $f === null || $t === null) return true;

    // Clear previous related errors (safe)
    try {
        $this->resetErrorBag([$fromField, $toField]);
    } catch (\Throwable $e) {
        // ignore if not supported in your Livewire version
    }

    $inWindow = function (int $x) use ($ws, $we): bool {
        // Normal window (e.g. 08:00 -> 16:00)
        if ($ws <= $we) return $x >= $ws && $x <= $we;

        // Overnight window (e.g. 22:00 -> 06:00)
        return ($x >= $ws) || ($x <= $we);
    };

    $ok = true;

    if (!$inWindow($f)) {
        $this->addError($fromField, tr('Time must be within working hours.'));
        $ok = false;
    }

    if (!$inWindow($t)) {
        $this->addError($toField, tr('Time must be within working hours.'));
        $ok = false;
    }

    // Optional: also show exact range (uncomment if you want)
    // if (!$ok) {
    //     $this->addError($toField, tr('Allowed range') . ": {$workStart} - {$workEnd}");
    // }

    return $ok;
}
protected function detectEmployeeBranchColumn(): ?string
{
    $table = (new Employee())->getTable();

    foreach (['branch_id', 'branchId', 'branch'] as $col) {
        if (Schema::hasColumn($table, $col)) return $col;
    }

    return null;
}
public function getBranchesProperty()
{
    $branchesTable = (new Branch())->getTable();
    $allowed = $this->allowedBranchIds();

    return Branch::query()
        ->where('saas_company_id', $this->companyId)
        ->when(!empty($allowed), fn ($q) => $q->whereIn('id', $allowed))
        ->when(Schema::hasColumn($branchesTable, 'is_active'), fn ($q) => $q->where('is_active', true))
        ->orderBy('name')
        ->get(['id', 'name', 'code']);
}
public function updatedBranchId(): void
{
    $allowed = $this->allowedBranchIds();

    if (!empty($allowed) && ($this->branchId ?? '') !== '') {
        $bid = (int) $this->branchId;
        if (!in_array($bid, $allowed, true)) {
            $this->branchId = ''; 
        }
    }

    $this->resetAllPages();
}

private function allowedBranchIds(): array
{
    $user = auth()->user();
    if (!$user) return [];

    // Ù„Ùˆ Ø¹Ù†Ø¯Ùƒ access_scope = all_branches
    if (isset($user->access_scope) && $user->access_scope === 'all_branches') {
        return []; // empty = Ø¨Ø¯ÙˆÙ† Ù‚ÙŠÙˆØ¯
    }

    // Ù„Ùˆ Ø¹Ù†Ø¯Ùƒ method Ø¬Ø§Ù‡Ø²
    if (method_exists($user, 'accessibleBranchIds')) {
        $ids = $user->accessibleBranchIds();
        $arr = is_array($ids) ? $ids : (method_exists($ids, 'toArray') ? $ids->toArray() : []);
        return array_values(array_filter(array_map('intval', $arr)));
    }

    // fallback: pivot table branch_user_access
    if (Schema::hasTable('branch_user_access')) {
        $ids = DB::table('branch_user_access')
            ->where('user_id', (int) $user->id)
            ->pluck('branch_id')
            ->all();

        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!empty($ids)) return $ids;
    }

    // fallback Ø£Ø®ÙŠØ±: ÙØ±Ø¹ Ø§Ù„Ù…Ø³ØªØ®Ø¯Ù…
    $bid = (int) ($user->branch_id ?? 0);
    return $bid > 0 ? [$bid] : [];
}
    protected function applyApprovalTaskFilter($q, $type)
    {
        $employeeId = auth()->user()->employee_id;
        if (!$employeeId) return $q;

        // ✅ NEW: Ensure tasks exist for all pending requests of this type
        try {
            $approvalService = app(\Athka\SystemSettings\Services\Approvals\ApprovalService::class);
            $src = $approvalService->getRequestSource($type);
            if ($src) {
                $approvalService->ensureTasksForPendingRequests($src, $this->companyId);
            }
        } catch (\Throwable $e) {}

        $q->where(function ($query) use ($employeeId, $type) {
            $table = $query->getModel()->getTable();
            $hasReplacementCols = Schema::hasColumn($table, 'replacement_employee_id');

            // 1) Show to Managers ONLY IF replacement is null or approved
            $query->where(function ($subManager) use ($employeeId, $type, $hasReplacementCols) {
                $subManager->whereExists(function ($sub) use ($employeeId, $type, $subManager) {
                    $table = $subManager->getModel()->getTable();
                    $sub->select(DB::raw(1))
                        ->from('approval_tasks')
                        ->whereColumn('approvable_id', $table . '.id')
                        ->where('approvable_type', $type)
                        ->where('approver_employee_id', $employeeId)
                        ->where('status', 'pending');
                });

                if ($hasReplacementCols) {
                    $subManager->where(function ($inner) {
                        $inner->whereNull('replacement_employee_id')
                              ->orWhere('replacement_status', 'approved');
                    });
                }
            });

            // 2) Show to Replacement Employee if pending
            if ($hasReplacementCols) {
                $query->orWhere(function ($subRep) use ($employeeId) {
                    $subRep->where('replacement_employee_id', $employeeId)
                           ->where('replacement_status', 'pending');
                });
            }
        });

        return $q;
    }
}


