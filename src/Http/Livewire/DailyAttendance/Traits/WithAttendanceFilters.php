<?php

namespace Athka\Attendance\Http\Livewire\DailyAttendance\Traits;

use Athka\Attendance\Models\AttendanceDailyLog;
use Athka\Employees\Models\Employee;
use Athka\SystemSettings\Models\Department;
use Athka\SystemSettings\Models\JobTitle;
use Athka\SystemSettings\Models\WorkSchedule;
use Carbon\Carbon;
use Athka\Saas\Models\Branch;
use Athka\Attendance\Models\EmployeeWorkSchedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
trait WithAttendanceFilters
{
    public $modalTrigger = 0;
    // ==================== Filters ====================
    public $search = '';
    public $date_from = '';
    public $date_to = '';
    public $attendance_status_filter = 'all'; // all/present/late/absent/on_leave
    public $approval_status_filter = 'all'; // all/pending/approved/rejected
    public $work_schedule_id = 'all';
    public $compliance_from = '';
    public $compliance_to = '';
    public $department_id = 'all';
    public $branch_id = 'all';
    public $job_title_id = 'all';
    public $status = 'ACTIVE';

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function switchView($mode)
    {
        $this->view_mode = $mode;
        $this->updatedViewMode($mode);
    }

    public function updatedViewMode($value)
    {
        $this->resetPage();
        if ($value === 'daily') {
            $this->date_to = $this->date_from;
        } else {
            // Default range for summary: start of month to today
            $this->date_from = now()->startOfMonth()->toDateString();
            $this->date_to = now()->toDateString();
        }
        if ($this->view_mode === 'daily') {
            $this->generateMissingLogs(true);
        }
        $this->loadStats();
    }

    public function updatedDateFrom($value)
    {
        $this->resetPage();
        if ($this->view_mode === 'daily') {
            $this->date_to = $value;
        }
        if ($this->view_mode === 'daily') {
            $this->generateMissingLogs(true);
        }
        $this->loadStats();
    }

    public function updatedDateTo($value)
    {
        $this->resetPage();
        if ($this->view_mode === 'daily') {
            $this->generateMissingLogs(true);
        }
        $this->loadStats();
    }

    public function updatedAttendanceStatusFilter()
    {
        $this->resetPage();
    }

    public function updatedApprovalStatusFilter()
    {
        $this->resetPage();
        if (method_exists($this, 'loadStats')) $this->loadStats();
    }

    public function updatedWorkScheduleId()
    {
        $this->resetPage();
        if (method_exists($this, 'loadStats')) $this->loadStats();
    }

    public function updatedBranchId()
    {
        $this->resetPage();

        $allowed = $this->allowedBranchIds();
        if (!empty($allowed) && $this->branch_id !== 'all') {
            $bid = (int) $this->branch_id;
            if (!in_array($bid, $allowed, true)) {
                $this->branch_id = 'all';
            }
        }

        if (method_exists($this, 'loadStats')) $this->loadStats();
        if (method_exists($this, 'loadWarnings')) $this->loadWarnings();
    }

    public function updatedDepartmentId()
    {
        $this->resetPage();

        if (method_exists($this, 'loadStats')) $this->loadStats();
        if (method_exists($this, 'loadWarnings')) $this->loadWarnings();
    }


    public function updatedJobTitleId()
    {
        $this->resetPage();
        if (method_exists($this, 'loadStats')) $this->loadStats();
    }

    public function updatedStatus()
    {
        $this->resetPage();
        $this->loadStats();
    }

    public function updatedComplianceFrom()
    {
        $this->resetPage();
        if (method_exists($this, 'loadStats')) $this->loadStats();
    }

    public function updatedComplianceTo()
    {
        $this->resetPage();
        if (method_exists($this, 'loadStats')) $this->loadStats();
    }

    public function clearAllFilters()
    {
        $this->search = '';
        $this->attendance_status_filter = 'all';
        $this->approval_status_filter = 'all';
        $this->work_schedule_id = 'all';
        $this->compliance_from = '';
        $this->compliance_to = '';
        $this->department_id = 'all';
        $this->job_title_id = 'all';
        $this->status = 'ACTIVE';

        $userBranchId = (int) (auth()->user()->branch_id ?? 0);
        $allowed = $this->allowedBranchIds();

        if (!empty($allowed)) {
            $this->branch_id = in_array($userBranchId, $allowed, true) ? $userBranchId : 'all';
        } else {
            $this->branch_id = $userBranchId ?: 'all';
        }

        // Reset dates based on view mode
        if ($this->view_mode === 'daily') {
            $this->date_from = now()->toDateString();
            $this->date_to = now()->toDateString();
        } else {
            $this->date_from = now()->startOfMonth()->toDateString();
            $this->date_to = now()->toDateString();
        }

        $this->resetPage();
        if (method_exists($this, 'loadStats')) $this->loadStats();
        if (method_exists($this, 'loadWarnings')) $this->loadWarnings();
    }

    public function getAttendanceLogsProperty()
    {
        $companyId = auth()->user()->saas_company_id;

        // ==================== SUMMARY VIEW (Grouped by Employee) ====================
        if ($this->view_mode === 'summary') {
            $employeeQuery = Employee::withoutGlobalScope('active_only')
                ->forCompany($companyId)
                ->with('branch')
                ->when($this->status !== 'all', fn($q) => $q->where('status', (string)$this->status));

            // Data scoping.
            $employeeQuery = $this->applyDataScoping($employeeQuery, 'attendance.daily.view', 'attendance.daily.view-subordinates', '');

            $allowed = $this->allowedBranchIds();
            if (!empty($allowed)) {
                $employeeQuery->whereIn('branch_id', $allowed);
            }

            if ($this->branch_id !== 'all') {
                $employeeQuery->where('branch_id', $this->branch_id);
            }
            // Apply Employee Filters
            if ($this->search) {
                $employeeQuery->where(function ($q) {
                    $q->where('name_ar', 'like', '%' . $this->search . '%')
                      ->orWhere('name_en', 'like', '%' . $this->search . '%')
                      ->orWhere('employee_no', 'like', '%' . $this->search . '%');
                });
            }

            if ($this->department_id !== 'all') {
                $employeeQuery->where('department_id', $this->department_id);
            }

            if ($this->job_title_id !== 'all') {
                $employeeQuery->where('job_title_id', $this->job_title_id);
            }

            // If filtering by attendance-log criteria, show only employees with matching logs.
            if ($this->attendance_status_filter !== 'all' || $this->work_schedule_id !== 'all' || $this->approval_status_filter !== 'all') {
                $employeeQuery->whereIn('id', function($q) {
                    $q->select('employee_id')
                      ->from('attendance_daily_logs')
                      ->where('saas_company_id', auth()->user()->saas_company_id)
                      ->whereBetween('attendance_date', [$this->date_from, $this->date_to]);

                    if ($this->attendance_status_filter !== 'all') $q->where('attendance_status', $this->attendance_status_filter);
                    if ($this->work_schedule_id !== 'all') $q->where('work_schedule_id', $this->work_schedule_id);
                    if ($this->approval_status_filter !== 'all') $q->where('approval_status', $this->approval_status_filter);
                });
            }

            // Paginate Employees
            $employees = $employeeQuery->paginate(15);

            // Aggregate attendance data for the current page in one query.
            $startDate = $this->date_from ?: now()->startOfMonth()->toDateString();
            $endDate = $this->date_to ?: now()->endOfMonth()->toDateString();

            $employeeIds = $employees->getCollection()->pluck('id')->filter()->values()->all();
            $summaryByEmployee = collect();
            $partialLeaveDaysByEmployee = collect();
            $scheduleNames = collect();

            if (!empty($employeeIds)) {
                $logs = AttendanceDailyLog::forCompany($companyId)
                    ->whereIn('employee_id', $employeeIds)
                    ->whereBetween('attendance_date', [$startDate, $endDate]);

                if ($this->attendance_status_filter !== 'all') {
                    $logs->where('attendance_status', $this->attendance_status_filter);
                }

                if ($this->work_schedule_id !== 'all') {
                    $logs->where('work_schedule_id', $this->work_schedule_id);
                }

                $summaryByEmployee = $logs
                    ->select(
                        'employee_id',
                        DB::raw('COUNT(*) as total_days'),
                        DB::raw("SUM(CASE WHEN attendance_status = 'present' THEN 1 ELSE 0 END) as present_days"),
                        DB::raw("SUM(CASE WHEN attendance_status = 'late' THEN 1 ELSE 0 END) as late_days"),
                        DB::raw("SUM(CASE WHEN attendance_status = 'absent' THEN 1 ELSE 0 END) as absent_days"),
                        DB::raw("SUM(CASE WHEN attendance_status = 'early_departure' THEN 1 ELSE 0 END) as early_departure_days"),
                        DB::raw("SUM(CASE WHEN attendance_status = 'on_leave' THEN 1 ELSE 0 END) as on_leave_days"),
                        DB::raw("SUM(CASE WHEN attendance_status = 'auto_checkout' THEN 1 ELSE 0 END) as auto_checkout_days"),
                        DB::raw('COALESCE(SUM(scheduled_hours), 0) as total_scheduled_hours'),
                        DB::raw('COALESCE(SUM(actual_hours), 0) as total_actual_hours'),
                        DB::raw('COALESCE(AVG(CASE WHEN scheduled_hours > 0 THEN compliance_percentage END), 0) as avg_compliance'),
                        DB::raw('MAX(work_schedule_id) as schedule_id')
                    )
                    ->groupBy('employee_id')
                    ->get()
                    ->keyBy('employee_id');

                if (Schema::hasTable('attendance_leave_requests')) {
                    $leaveColumns = Schema::getColumnListing('attendance_leave_requests');

                    if (in_array('employee_id', $leaveColumns, true)
                        && in_array('requested_days', $leaveColumns, true)
                        && in_array('duration_unit', $leaveColumns, true)
                        && in_array('start_date', $leaveColumns, true)
                    ) {
                        $partialLeaveQuery = DB::table('attendance_leave_requests')
                            ->whereIn('employee_id', $employeeIds)
                            ->where('status', 'approved')
                            ->where('duration_unit', 'half_day')
                            ->whereBetween('start_date', [$startDate, $endDate])
                            ->where(function ($q) use ($leaveColumns) {
                                if (in_array('work_schedule_period_id', $leaveColumns, true)) {
                                    $q->whereNotNull('work_schedule_period_id');
                                }

                                if (in_array('half_day_part', $leaveColumns, true)) {
                                    $method = in_array('work_schedule_period_id', $leaveColumns, true) ? 'orWhere' : 'where';
                                    $q->{$method}('half_day_part', 'work_period');
                                }
                            });

                        if (in_array('company_id', $leaveColumns, true)) {
                            $partialLeaveQuery->where('company_id', $companyId);
                        } elseif (in_array('saas_company_id', $leaveColumns, true)) {
                            $partialLeaveQuery->where('saas_company_id', $companyId);
                        }

                        $partialLeaveDaysByEmployee = $partialLeaveQuery
                            ->select('employee_id', DB::raw('SUM(requested_days) as partial_leave_days'))
                            ->groupBy('employee_id')
                            ->pluck('partial_leave_days', 'employee_id');
                    }
                }

                $scheduleIds = $summaryByEmployee
                    ->pluck('schedule_id')
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();

                if (!empty($scheduleIds)) {
                    $scheduleNames = WorkSchedule::where('saas_company_id', $companyId)
                        ->whereIn('id', $scheduleIds)
                        ->pluck('name', 'id');
                }
            }

            foreach ($employees as $employee) {
                $summary = $summaryByEmployee->get($employee->id);
                $scheduleId = $summary->schedule_id ?? null;
                $partialLeaveDays = (float) ($partialLeaveDaysByEmployee->get($employee->id) ?? 0);

                $employee->summary = (object)[
                    'total_days' => (int) ($summary->total_days ?? 0),
                    'present_days' => (int) ($summary->present_days ?? 0),
                    'late_days' => (int) ($summary->late_days ?? 0),
                    'absent_days' => (int) ($summary->absent_days ?? 0),
                    'early_departure_days' => (int) ($summary->early_departure_days ?? 0),
                    'on_leave_days' => (float) ($summary->on_leave_days ?? 0) + $partialLeaveDays,
                    'auto_checkout_days' => (int) ($summary->auto_checkout_days ?? 0),
                    'total_scheduled_hours' => (float) ($summary->total_scheduled_hours ?? 0),
                    'total_actual_hours' => (float) ($summary->total_actual_hours ?? 0),
                    'avg_compliance' => (float) ($summary->avg_compliance ?? 0),
                    'schedule_name' => $scheduleId ? ($scheduleNames[$scheduleId] ?? '-') : '-',
                ];
            }

            return $employees;
        }

        // ==================== DAILY VIEW (Standard Log List) ====================
    $query = AttendanceDailyLog::forCompany($companyId)
            ->with([
                'employee' => fn ($q) => $q->withoutGlobalScope('active_only')->with('branch'),
                'workSchedule',
                'details',
                'scheduleException',
            ])
            ->withCount([
                'auditLogs as edits_count' => fn ($q) => $q->where('action', 'attendance.edited'),
            ]);

        // Data scoping.
        $query = $this->applyDataScoping($query, 'attendance.daily.view', 'attendance.daily.view-subordinates');

        $allowed = $this->allowedBranchIds();
        if (!empty($allowed)) {
            $query->whereHas('employee', fn ($q) => $q->withoutGlobalScope('active_only')->whereIn('branch_id', $allowed));
        }

        if ($this->branch_id !== 'all') {
            $query->whereHas('employee', function ($q) {
                $q->withoutGlobalScope('active_only');
                $q->where('branch_id', $this->branch_id);
            });
        }

        if ($this->status !== 'all') {
            $query->whereHas('employee', function ($q) {
                $q->withoutGlobalScope('active_only');
                $q->where('status', (string)$this->status);
            });
        }

        if ($this->date_from) {
            $query->where('attendance_date', '>=', $this->date_from);
        }

        if ($this->date_to) {
            $query->where('attendance_date', '<=', $this->date_to);
        }

        if ($this->attendance_status_filter !== 'all') {
            $query->where('attendance_status', $this->attendance_status_filter);
        }

        if ($this->approval_status_filter !== 'all') {
            $query->where('approval_status', $this->approval_status_filter);
        }

        if ($this->work_schedule_id !== 'all') {
            $query->where('work_schedule_id', $this->work_schedule_id);
        }

        if ($this->compliance_from !== '') {
            $query->where('compliance_percentage', '>=', $this->compliance_from);
        }

        if ($this->compliance_to !== '') {
            $query->where('compliance_percentage', '<=', $this->compliance_to);
        }

        if ($this->search) {
            $query->whereHas('employee', function ($q) {
                $q->withoutGlobalScope('active_only');
                $q->where('name_ar', 'like', '%' . $this->search . '%')
                  ->orWhere('name_en', 'like', '%' . $this->search . '%')
                  ->orWhere('employee_no', 'like', '%' . $this->search . '%');
            });
        }

        if ($this->department_id !== 'all') {
            $query->whereHas('employee', function ($q) {
                $q->withoutGlobalScope('active_only');
                $q->where('department_id', $this->department_id);
            });
        }

        if ($this->job_title_id !== 'all') {
            $query->whereHas('employee', function ($q) {
                $q->withoutGlobalScope('active_only');
                $q->where('job_title_id', $this->job_title_id);
            });
        }

        return $query->orderByDesc('attendance_date')->paginate(20);
    }

    public function getDepartmentsProperty()
    {
        return Department::where('saas_company_id', auth()->user()->saas_company_id)
            ->select('id', 'name')
            ->orderBy('name')
            ->get();
    }

    public function getWorkSchedulesProperty()
    {
        return WorkSchedule::where('saas_company_id', auth()->user()->saas_company_id)
            ->select('id', 'name')
            ->orderBy('name')
            ->get();
    }

    public function getJobTitlesProperty()
    {
        return JobTitle::where('saas_company_id', auth()->user()->saas_company_id)
            ->select('id', 'name')
            ->orderBy('name')
            ->get();
    }

    public function getBranchesProperty()
    {
       $q = Branch::where('saas_company_id', auth()->user()->saas_company_id)
            ->where('is_active', true);

        $allowed = $this->allowedBranchIds();
        if (!empty($allowed)) {
            $q->whereIn('id', $allowed);
        }

        return $q->select('id', 'name')->orderBy('name')->get();
    }

    public function getEmployeesProperty()
    {
        $q = Employee::withoutGlobalScope('active_only')
            ->where('saas_company_id', auth()->user()->saas_company_id)
            ->when($this->status !== 'all', fn ($query) => $query->where('status', (string) $this->status));

        // Data scoping for employee list.
        $q = $this->applyDataScoping($q, 'attendance.daily.view', 'attendance.daily.view-subordinates', '');

        $allowed = $this->allowedBranchIds();
        if (!empty($allowed)) {
            $q->whereIn('branch_id', $allowed);
        }

        if ($this->branch_id !== 'all') {
            $q->where('branch_id', $this->branch_id);
        }

        return $q->get();
    }

        // ==================== Branch Access (Allowed Branches) ====================
    protected bool $allowedBranchIdsResolved = false;
    protected array $allowedBranchIdsCache = [];

    protected function allowedBranchIds(): array
    {
        if ($this->allowedBranchIdsResolved) {
            return $this->allowedBranchIdsCache;
        }

        $companyId = (int) (auth()->user()->saas_company_id ?? 0);
        $this->allowedBranchIdsCache = $this->resolveCurrentUserAllowedBranchIds($companyId);
        $this->allowedBranchIdsResolved = true;

        return $this->allowedBranchIdsCache;
    }

    protected function resolveCurrentUserAllowedBranchIds(int $companyId): array
    {
        $user = auth()->user();
        if (!$user) return [0];

        $scope = $user->access_scope ?? 'all_branches';

        // all_branches means no branch restriction.
        if ($scope === 'all_branches') {
            return [];
        }

        $ids = [];

        // Prefer the official user branch access source when available.
        if (method_exists($user, 'accessibleBranchIds')) {
            $ids = (array) $user->accessibleBranchIds();
        } elseif (method_exists($user, 'allowedBranches')) {
            $ids = $user->allowedBranches()->pluck('branches.id')->all();
        }

        // Fallback to branch_user_access pivot.
        if (empty($ids) && Schema::hasTable('branch_user_access')) {
            $cols = Schema::getColumnListing('branch_user_access');

            if (in_array('user_id', $cols, true) && in_array('branch_id', $cols, true)) {
                $q = DB::table('branch_user_access')->where('user_id', (int) $user->id);

                if (in_array('saas_company_id', $cols, true)) {
                    $q->where('saas_company_id', $companyId);
                } elseif (in_array('company_id', $cols, true)) {
                    $q->where('company_id', $companyId);
                }

                $ids = $q->pluck('branch_id')->all();
            }
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), fn ($v) => $v > 0)));

        // If the user is branch-restricted but has no branches, return an impossible branch id.
        return !empty($ids) ? $ids : [0];
    }
}


