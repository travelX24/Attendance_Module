<?php

namespace Athka\Attendance\Http\Livewire\LeavesPermissions\Traits;

use Athka\Employees\Models\Employee;
use Athka\SystemSettings\Models\LeavePolicy;
use Athka\SystemSettings\Models\LeavePolicyYear;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Athka\Saas\Models\Branch;
trait WithLeavePermissionsFilters
{
    public string $tab = 'pending';
    public int $perPage = 10;

    public string $search = '';
    public ?int $selectedYearId = null;
    public ?int $departmentId = null;
    public ?int $jobTitleId = null;
    public ?int $filterLeavePolicyId = null;
    public string $fromDate = '';
    public string $toDate = '';
    public string $historyStatus = '';

    public string $branchId = '';

    public string $employeeSearch = '';
    public string $groupEmployeeSearch = '';

    public ?string $employeeCompanyColumn = null;
    public array $employeeNameColumns = [];
    public ?string $employeeDepartmentColumn = null;
    public ?string $employeeJobTitleColumn = null;
    public ?string $departmentsTable = null;
    public ?string $jobTitlesTable = null;
    public ?string $leavePolicyYearColumn = null;



    protected ?string $leavePoliciesCompanyColumnForFilters = null;
    protected ?string $leavePolicyYearsCompanyColumnForFilters = null;

    public function updatedSearch(): void { $this->resetAllPages(); }
    public function updatedSelectedYearId(): void { $this->resetAllPages(); }
    public function updatedDepartmentId(): void { $this->resetAllPages(); }
    public function updatedJobTitleId(): void { $this->resetAllPages(); }
    public function updatedFilterLeavePolicyId(): void { $this->resetAllPages(); }
    public function updatedFromDate(): void { $this->resetAllPages(); }
    public function updatedToDate(): void { $this->resetAllPages(); }
    public function updatedHistoryStatus(): void { $this->resetAllPages(); }
    public function updatedBranchId(): void
    {
        $allowed = $this->lpAllowedBranchIds();

        if (!empty($allowed) && $this->branchId !== '') {
            $bid = (int) $this->branchId;
            if (!in_array($bid, $allowed, true)) {
                $this->branchId = ''; // Ø±Ø¬Ù‘Ø¹Ù‡ All (Ù„ÙƒÙ† Ø§Ù„Ø§Ø³ØªØ¹Ù„Ø§Ù…Ø§Øª Ø³ØªØ¨Ù‚Ù‰ Ù…Ø­ØµÙˆØ±Ø© Ø¨Ø§Ù„Ù…Ø³Ù…ÙˆØ­)
            }
        }

        $this->resetAllPages();
    }

    public function clearAllFilters(): void
    {
        $this->search = '';
        $this->departmentId = null;
        $this->jobTitleId = null;
        $this->filterLeavePolicyId = null;
        $this->fromDate = '';
        $this->toDate = '';
        $this->historyStatus = '';
        $this->branchId = '';
        $this->resetAllPages();
    }

    public function getCompanyIdProperty(): int
    {
        return $this->resolveCompanyId();
    }

    public function getYearsProperty()
    {
        $table = (new LeavePolicyYear())->getTable();

        $this->leavePolicyYearsCompanyColumnForFilters ??= $this->detectCompanyColumn($table);
        $companyCol = $this->leavePolicyYearsCompanyColumnForFilters;

        $q = LeavePolicyYear::query();
        if ($companyCol) {
            $q->where($companyCol, $this->companyId);
        }

        $base = LeavePolicyYear::query();
        if ($companyCol) {
            $base->where($companyCol, $this->companyId);
        }

        $active = null;
        if (Schema::hasColumn($table, 'is_active')) {
            $active = (clone $base)->where('is_active', true)->orderByDesc('year')->first();
        }

        $year = $active ?: (clone $base)->orderByDesc('year')->first();

        return $year ? collect([$year]) : collect();
    }

    public function getPoliciesProperty()
    {
        $table = (new LeavePolicy())->getTable();

        $this->leavePoliciesCompanyColumnForFilters ??= $this->detectCompanyColumn($table);
        $companyCol = $this->leavePoliciesCompanyColumnForFilters;

        $q = LeavePolicy::query();
        if ($companyCol) {
            $q->where($companyCol, $this->companyId);
        }

        if (Schema::hasColumn($table, 'is_active')) {
            $q->where('is_active', true);
        }
        if ($this->selectedYearId && $this->leavePolicyYearColumn) {
            if ($this->leavePolicyYearColumn === 'year') {
                $yearRow = LeavePolicyYear::query()->find((int) $this->selectedYearId);
                if ($yearRow?->year) $q->where('year', (int) $yearRow->year);
            } else {
                $q->where($this->leavePolicyYearColumn, (int) $this->selectedYearId);
            }
        }

        return $q->orderBy(Schema::hasColumn($table, 'name') ? 'name' : 'id')->get();
    }

    public function getDepartmentsProperty()
    {
        if (!$this->departmentsTable) return collect();

        $table = $this->departmentsTable;
        $labelCol = $this->detectBestLookupLabelColumn($table);
        $companyCol = $this->detectLookupCompanyColumn($table);

        $q = DB::table($table)->select(['id', DB::raw("$labelCol as label")]);

        if ($companyCol) $q->where($companyCol, $this->companyId);
        if (Schema::hasColumn($table, 'is_active')) $q->where('is_active', 1);

        return $q->orderByRaw("$labelCol asc")->get();
    }

    public function getJobTitlesProperty()
    {
        if (!$this->jobTitlesTable) return collect();

        $table = $this->jobTitlesTable;
        $labelCol = $this->detectBestLookupLabelColumn($table);
        $companyCol = $this->detectLookupCompanyColumn($table);

        $q = DB::table($table)->select(['id', DB::raw("$labelCol as label")]);
        if ($companyCol) $q->where($companyCol, $this->companyId);

        return $q->orderBy('id')->get();
    }

    public function getEmployeesForSelectProperty()
    {
        $empTable = (new Employee())->getTable();

        $q = Employee::query();
        if ($this->employeeCompanyColumn) {
            $q->where($this->employeeCompanyColumn, $this->companyId);
        }

        $allowed = $this->lpAllowedBranchIds();

        // âœ… Ø¹Ù…ÙˆØ¯ Ø§Ù„ÙØ±Ø¹ (Ø¢Ù…Ù†)
        $branchCol = $this->employeeBranchColumn;
        if (!$branchCol) {
            $branchCol = Schema::hasColumn($empTable, 'branch_id') ? 'branch_id' : null;
        }

        // âœ… ØªÙ‚ÙŠÙŠØ¯ Ø¯Ø§Ø¦Ù… Ø¨Ø§Ù„Ù…Ø³Ù…ÙˆØ­ Ø­ØªÙ‰ Ù„Ùˆ All
        if ($branchCol && !empty($allowed)) {
            $q->whereIn($branchCol, $allowed);
        }

        // âœ… ÙÙ„ØªØ± Ø§Ù„ÙØ±Ø¹ Ø§Ù„Ù…Ø®ØªØ§Ø± (Ø¥Ù† ÙˆØ¬Ø¯)
        if ($branchCol && $this->branchId !== '') {
            $q->where($branchCol, (int) $this->branchId);
        }

        $term = trim($this->employeeSearch);
        if ($term !== '') {
            $q->where(function ($qq) use ($term, $empTable) {
                foreach ($this->employeeNameColumns as $col) {
                    $qq->orWhere($col, 'like', '%' . $term . '%');
                }

                if (Schema::hasColumn($empTable, 'employee_no')) {
                    $qq->orWhere('employee_no', 'like', '%' . $term . '%');
                }
            });
        }

        return $q->orderByDesc('id')->limit(25)->get();
    }

    protected function resolveCompanyId(): int
    {
        $user = auth()->user();

        if ($id = (int) ($user->saas_company_id ?? $user->company_id ?? $user->company?->id ?? 0)) {
            return $id;
        }

        foreach (['company_id', 'current_company_id', 'saas_company_id'] as $key) {
            if ($val = session($key)) {
                return is_numeric($val) ? (int) $val : (int) ($val->id ?? 0);
            }
        }

        return 0;
    }

    /**
     * âœ… Detect company column for a given table.
     * Prefer saas_company_id if exists, else company_id.
     */
    protected function detectCompanyColumn(string $table): ?string
    {
        foreach (['saas_company_id', 'company_id'] as $c) {
            if (Schema::hasColumn($table, $c)) return $c;
        }
        return null;
    }

    protected function detectEmployeeCompanyColumn(): ?string
    {
        $table = (new Employee())->getTable();
        foreach (['saas_company_id', 'company_id'] as $c) {
            if (Schema::hasColumn($table, $c)) return $c;
        }
        return null;
    }

    protected function detectEmployeeDepartmentColumn(): ?string
    {
        $table = (new Employee())->getTable();
        foreach (['department_id', 'dept_id'] as $c) {
            if (Schema::hasColumn($table, $c)) return $c;
        }
        return null;
    }

    protected function detectEmployeeJobTitleColumn(): ?string
    {
        $table = (new Employee())->getTable();
        foreach (['job_title_id', 'title_id'] as $c) {
            if (Schema::hasColumn($table, $c)) return $c;
        }
        return null;
    }

    protected function detectEmployeeNameColumns(): array
    {
        $table = (new Employee())->getTable();
        $cols = [];

        foreach (['name_ar', 'name_en', 'name', 'full_name'] as $c) {
            if (Schema::hasColumn($table, $c)) $cols[] = $c;
        }

        return $cols ?: ['id'];
    }

    protected function detectLeavePolicyYearColumn(): ?string
    {
        $table = (new LeavePolicy())->getTable();
       foreach (['policy_year_id', 'leave_policy_year_id', 'leave_policy_years_id', 'year_id', 'year'] as $c) {
                if (Schema::hasColumn($table, $c)) return $c;
            }

        return null;
    }

    protected function detectDepartmentsTable(): ?string
    {
        foreach (['departments', 'hr_departments'] as $t) {
            if (Schema::hasTable($t)) return $t;
        }
        return null;
    }

    protected function detectJobTitlesTable(): ?string
    {
        foreach (['job_titles', 'hr_job_titles'] as $t) {
            if (Schema::hasTable($t)) return $t;
        }
        return null;
    }

    protected function detectLookupCompanyColumn(string $table): ?string
    {
        foreach (['saas_company_id', 'company_id'] as $c) {
            if (Schema::hasColumn($table, $c)) return $c;
        }
        return null;
    }

    protected function detectBestLookupLabelColumn(string $table): string
    {
        $locale = app()->getLocale();

        $candidates = $locale === 'ar'
            ? ['name_ar', 'title_ar', 'name']
            : ['name_en', 'title_en', 'name'];

        foreach ($candidates as $c) {
            if (Schema::hasColumn($table, $c)) return $c;
        }

        return 'id';
    }

    public function getGroupEmployeesForSelectProperty()
    {
        $empTable = (new Employee())->getTable();

        $q = Employee::query();
        if ($this->employeeCompanyColumn) {
            $q->where($this->employeeCompanyColumn, $this->companyId);
        }
        $allowed = $this->lpAllowedBranchIds();

        $branchCol = $this->employeeBranchColumn;
        if (!$branchCol) {
            $branchCol = Schema::hasColumn($empTable, 'branch_id') ? 'branch_id' : null;
        }

        // âœ… ØªÙ‚ÙŠÙŠØ¯ Ø¯Ø§Ø¦Ù… Ø¨Ø§Ù„Ù…Ø³Ù…ÙˆØ­
        if ($branchCol && !empty($allowed)) {
            $q->whereIn($branchCol, $allowed);
        }

        // âœ… ÙÙ„ØªØ± Ø§Ù„ÙØ±Ø¹ Ø§Ù„Ù…Ø®ØªØ§Ø±
        if ($branchCol && $this->branchId !== '') {
            $q->where($branchCol, (int) $this->branchId);
        }

        // Department filter (group modal)
        if ($this->groupDepartmentId && $this->employeeDepartmentColumn && Schema::hasColumn($empTable, $this->employeeDepartmentColumn)) {
            $q->where($this->employeeDepartmentColumn, (int) $this->groupDepartmentId);
        }

        // Job title filter (group modal)
        if ($this->groupJobTitleId && $this->employeeJobTitleColumn && Schema::hasColumn($empTable, $this->employeeJobTitleColumn)) {
            $q->where($this->employeeJobTitleColumn, (int) $this->groupJobTitleId);
        }

        // Search (group modal)
        $term = trim((string) $this->groupEmployeeSearch);
        if ($term !== '') {
            $q->where(function ($qq) use ($term, $empTable) {
                foreach ($this->employeeNameColumns as $col) {
                    $qq->orWhere($col, 'like', '%' . $term . '%');
                }

                if (Schema::hasColumn($empTable, 'employee_no')) {
                    $qq->orWhere('employee_no', 'like', '%' . $term . '%');
                }
            });
        }

        return $q->orderByDesc('id')->limit(200)->get();
    }
    public function getBranchesProperty()
    {
        $table = (new Branch())->getTable();
        $allowed = $this->lpAllowedBranchIds();

        return Branch::query()
            ->where('saas_company_id', $this->companyId)
            ->when(!empty($allowed), fn ($q) => $q->whereIn('id', $allowed))
            ->when(Schema::hasColumn($table, 'is_active'), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get(['id', 'name', 'code']);
    }

    protected function lpAllowedBranchIds(): array
{
    $user = auth()->user();
    if (!$user) return [];

    // ØµÙ„Ø§Ø­ÙŠØ© ÙƒÙ„ Ø§Ù„ÙØ±ÙˆØ¹
    if (isset($user->access_scope) && $user->access_scope === 'all_branches') {
        return []; // empty = Ø¨Ø¯ÙˆÙ† Ù‚ÙŠÙˆØ¯
    }

    // Ù„Ùˆ Ø¹Ù†Ø¯Ùƒ method Ø¬Ø§Ù‡Ø²
    if (method_exists($user, 'accessibleBranchIds')) {
        $ids = $user->accessibleBranchIds();
        $arr = is_array($ids) ? $ids : (method_exists($ids, 'toArray') ? $ids->toArray() : []);
        return array_values(array_filter(array_map('intval', $arr)));
    }

    // pivot
    if (Schema::hasTable('branch_user_access')) {
        $ids = DB::table('branch_user_access')
            ->where('user_id', (int) $user->id)
            ->pluck('branch_id')
            ->all();

        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!empty($ids)) return $ids;
    }

    // fallback: ÙØ±Ø¹ Ø§Ù„Ù…Ø³ØªØ®Ø¯Ù…
    $bid = (int) ($user->branch_id ?? 0);
    return $bid > 0 ? [$bid] : [];
}

}


