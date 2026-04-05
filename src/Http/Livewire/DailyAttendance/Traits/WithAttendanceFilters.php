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
    public $status = 'all';

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
        $this->generateMissingLogs(true);
        $this->loadStats();
    }

    public function updatedDateFrom($value)
    {
        $this->resetPage();
        if ($this->view_mode === 'daily') {
            $this->date_to = $value;
        }
        $this->generateMissingLogs(true);
        $this->loadStats();
    }

    public function updatedDateTo($value)
    {
        $this->resetPage();
        $this->generateMissingLogs(true);
        $this->loadStats();
    }

    public function updatedAttendanceStatusFilter()
    {
        $this->resetPage();
    }

    public function updatedApprovalStatusFilter()
    {
        $this->resetPage();
    }

    public function updatedWorkScheduleId()
    {
        $this->resetPage();
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
    }

    public function updatedStatus()
    {
        $this->resetPage();
        $this->loadStats();
    }

    public function updatedComplianceFrom()
    {
        $this->resetPage();
    }

    public function updatedComplianceTo()
    {
        $this->resetPage();
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
        $this->status = 'all';

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
            $employeeQuery = Employee::forCompany($companyId)
                ->with('branch')
                ->when($this->status !== 'all', fn($q) => $q->where('status', (string)$this->status));

            // âœ… Data scoping
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

            // Paginate Employees
            $employees = $employeeQuery->paginate(15);

            // Eager Load Attendance Data for current page only
            $startDate = $this->date_from ?: now()->startOfMonth()->toDateString();
            $endDate = $this->date_to ?: now()->endOfMonth()->toDateString();

            foreach ($employees as $employee) {
                // Fetch relevant logs for stats
                $logs = AttendanceDailyLog::forCompany($companyId)
                    ->where('employee_id', $employee->id)
                    ->whereBetween('attendance_date', [$startDate, $endDate]);

                // Apply Log Filters (Status, Schedule, etc.)
                // Note: Applying attendance status filter here filters the STATS, not the employees themselves.
                // If we want to filter employees who HAVE a certain status, we'd need a whereHas on the employeeQuery.
                // For simplified UX, we will show all selected employees but their stats will reflect the period.
                
                if ($this->attendance_status_filter !== 'all') {
                    $logs->where('attendance_status', $this->attendance_status_filter);
                }
                
                if ($this->work_schedule_id !== 'all') {
                    $logs->where('work_schedule_id', $this->work_schedule_id);
                }

                $logsData = $logs->get();

                $employee->summary = (object)[
                    'total_days' => $logsData->count(),
                    'present_days' => $logsData->whereIn('attendance_status', ['present', 'late', 'early_departure', 'auto_checkout'])->count(),
                    'late_days' => $logsData->where('attendance_status', 'late')->count(),
                    'absent_days' => $logsData->where('attendance_status', 'absent')->count(),
                    'early_departure_days' => $logsData->where('attendance_status', 'early_departure')->count(),
                    'on_leave_days' => $logsData->where('attendance_status', 'on_leave')->count(),
                    'auto_checkout_days' => $logsData->where('attendance_status', 'auto_checkout')->count(),
                    'total_scheduled_hours' => $logsData->sum('scheduled_hours'),
                    'total_actual_hours' => $logsData->sum('actual_hours'),
                    'avg_compliance' => $logsData->avg('compliance_percentage') ?? 0,
                    // Use arbitrary log for schedule name if needed, or fetch separately
                    'schedule_name' => $logsData->first()->workSchedule->name ?? '-',
                ];
            }

            return $employees;
        }

        // ==================== DAILY VIEW (Standard Log List) ====================
    $query = AttendanceDailyLog::forCompany($companyId)
            ->with(['employee.branch', 'workSchedule', 'editor', 'approver', 'rejector', 'revoker', 'details', 'scheduleException'])
            ->withCount([
                'auditLogs as edits_count' => fn ($q) => $q->where('action', 'attendance.edited'),
            ]);

        // âœ… Data scoping
        $query = $this->applyDataScoping($query, 'attendance.daily.view', 'attendance.daily.view-subordinates');

        $allowed = $this->allowedBranchIds();
        if (!empty($allowed)) {
            $query->whereHas('employee', fn ($q) => $q->whereIn('branch_id', $allowed));
        }

        if ($this->branch_id !== 'all') {
            $query->whereHas('employee', function ($q) {
                $q->where('branch_id', $this->branch_id);
            });
        }

        if ($this->status !== 'all') {
            $query->whereHas('employee', function ($q) {
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
                $q->where('name_ar', 'like', '%' . $this->search . '%')
                    ->orWhere('name_en', 'like', '%' . $this->search . '%')
                    ->orWhere('employee_no', 'like', '%' . $this->search . '%');
            });
        }

        if ($this->department_id !== 'all') {
            $query->whereHas('employee', function ($q) {
                $q->where('department_id', $this->department_id);
            });
        }

        if ($this->job_title_id !== 'all') {
            $query->whereHas('employee', function ($q) {
                $q->where('job_title_id', $this->job_title_id);
            });
        }

        return $query->orderByDesc('attendance_date')->paginate(20);
    }

    public function getDepartmentsProperty()
    {
        return Department::where('saas_company_id', auth()->user()->saas_company_id)->get();
    }

    public function getWorkSchedulesProperty()
    {
        return WorkSchedule::where('saas_company_id', auth()->user()->saas_company_id)->get();
    }

    public function getJobTitlesProperty()
    {
        return JobTitle::where('saas_company_id', auth()->user()->saas_company_id)->get();
    }

    public function getBranchesProperty()
    {
       $q = Branch::where('saas_company_id', auth()->user()->saas_company_id)
            ->where('is_active', true);

        $allowed = $this->allowedBranchIds();
        if (!empty($allowed)) {
            $q->whereIn('id', $allowed);
        }

        return $q->orderBy('name')->get();
    }

    public function getEmployeesProperty()
    {
        $q = Employee::where('saas_company_id', auth()->user()->saas_company_id);

        // âœ… Data scoping for employee list
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

        // âœ… all_branches => Ø¨Ø¯ÙˆÙ† ØªÙ‚ÙŠÙŠØ¯ (Ù†Ø±Ø¬Ù‘Ø¹ [] Ø­ØªÙ‰ Ù…Ø§ Ù†Ø¹Ù…Ù„ whereIn)
        if ($scope === 'all_branches') {
            return [];
        }

        $ids = [];

        // âœ… Ø§Ù„Ù…ØµØ¯Ø± Ø§Ù„Ø±Ø³Ù…ÙŠ Ø¹Ù†Ø¯Ùƒ
        if (method_exists($user, 'accessibleBranchIds')) {
            $ids = (array) $user->accessibleBranchIds();
        } elseif (method_exists($user, 'allowedBranches')) {
            $ids = $user->allowedBranches()->pluck('branches.id')->all();
        }

        // âœ… fallback: pivot branch_user_access
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

        // âœ… Ù…Ø³ØªØ®Ø¯Ù… Ù…Ø­Ø¯ÙˆØ¯ Ù„ÙƒÙ† Ø¨Ø¯ÙˆÙ† ÙØ±ÙˆØ¹: Ø§Ù…Ù†Ø¹Ù‡ ÙŠØ´ÙˆÙ Ø£ÙŠ Ø´ÙŠØ¡ Ø¨Ø¯Ù„ Ù…Ø§ ÙŠØ´ÙˆÙ Ø§Ù„ÙƒÙ„
        return !empty($ids) ? $ids : [0];
    }
}


