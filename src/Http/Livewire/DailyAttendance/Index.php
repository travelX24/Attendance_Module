<?php

namespace Athka\Attendance\Http\Livewire\DailyAttendance;

use Livewire\Component;
use Livewire\WithPagination;
use Livewire\WithFileUploads;
use Athka\Attendance\Models\AttendanceDailyLog;
use Athka\Employees\Models\Employee;
use Athka\Attendance\Models\AttendanceAuditLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

use Athka\Attendance\Http\Livewire\DailyAttendance\Traits\WithAttendanceFilters;
use Athka\Attendance\Http\Livewire\DailyAttendance\Traits\WithAttendanceActions;
use Athka\Attendance\Http\Livewire\DailyAttendance\Traits\WithAttendanceEdits;
use Athka\Attendance\Http\Livewire\DailyAttendance\Traits\WithManualAttendance;
use Athka\Attendance\Http\Livewire\DailyAttendance\Traits\WithAttendanceExports;
use Athka\Attendance\Http\Livewire\Traits\WithDataScoping;

class Index extends Component
{
    use WithPagination, 
        WithFileUploads,
        WithAttendanceFilters, 
        WithAttendanceActions, 
        WithAttendanceEdits, 
        WithManualAttendance, 
        WithAttendanceExports,
        WithDataScoping;

    protected $paginationTheme = 'tailwind';

    // View Mode: 'daily' (default) or 'summary' (employee grouped)
    public $view_mode = 'daily'; 

    // ==================== Global Properties ====================
    public $stats = [
        'total' => 0,
        'present' => 0,
        'late' => 0,
        'absent' => 0,
        'on_leave' => 0,
        'compliance_avg' => 0,
        'pending_approval' => 0,
        'present_percentage' => 0,
        'absent_percentage' => 0,
        'late_percentage' => 0,
        'on_leave_percentage' => 0,
        'early_departure_percentage' => 0,
        'auto_checkout_percentage' => 0,
    ];

    public $warnings = [
        'no_attendance_3days' => 0,
    ];

    public $warningNoAttendanceEmployeeIds = [];

    protected $listeners = ['refreshAttendance' => 'refreshData'];

    public function mount()
    {
        $this->date_from = now()->toDateString();
        $this->date_to = now()->toDateString();

        $userBranchId = (int) (auth()->user()->branch_id ?? 0);
        $allowed = $this->allowedBranchIds(); 

        if (!empty($allowed)) {
            $this->branch_id = in_array($userBranchId, $allowed, true) ? $userBranchId : 'all';
        } else {
            $this->branch_id = $userBranchId ?: 'all';
        }

        $this->generateMissingLogs(true);

        $this->loadStats();
        $this->loadWarnings();
    }

    public function refreshData()
    {
        $this->resetPage();
        $this->loadStats();
        $this->loadWarnings();
        $this->resetModalFlags();
    }

    public function resetModalFlags()
    {
        $this->showEditModal = false;
        $this->showApprovalModal = false;
        $this->showRejectModal = false;
        $this->showUnapproveModal = false;
        $this->showCreateModal = false;
        $this->showBulkApprovalModal = false;
        $this->showApprovedEditConfirmModal = false;
        $this->reset(['approvedEditConfirmText', 'approvedEditConfirmUnderstood']);
    }

    public function loadStats()
    {
        $companyId = auth()->user()->saas_company_id;
        $query = AttendanceDailyLog::forCompany($companyId);

        // âœ… Data scoping (Admin vs Subordinates)
        $query = $this->applyDataScoping($query, 'attendance.daily.view', 'attendance.daily.view-subordinates');

        // âœ… Allowed branches scope
        $allowed = $this->allowedBranchIds();
        if (!empty($allowed)) {
            $query->whereHas('employee', fn ($q) => $q->whereIn('branch_id', $allowed));
        }

        // âœ… Selected branch filter (if user picked a specific branch)
        if ($this->branch_id !== 'all') {
            $query->whereHas('employee', fn ($q) => $q->where('branch_id', (int) $this->branch_id));
        }

        if ($this->date_from) $query->where('attendance_date', '>=', $this->date_from);
        if ($this->date_to) $query->where('attendance_date', '<=', $this->date_to);

        $data = $query->select('attendance_status', DB::raw('count(*) as count'), DB::raw('avg(compliance_percentage) as avg_comp'))
                    ->groupBy('attendance_status')
                    ->get();


        $this->stats['total'] = $data->sum('count');
        
        // Ø§Ù„Ø­Ø§Ø¶Ø±ÙˆÙ† Ù‡Ù… ÙƒÙ„ Ù…Ù† Ø³Ø¬Ù„ Ø¯Ø®ÙˆÙ„ (Ø­Ø§Ø¶Ø±ØŒ Ù…ØªØ£Ø®Ø±ØŒ Ù…ØºØ§Ø¯Ø±Ø© Ù…Ø¨ÙƒØ±Ø©ØŒ Ø®Ø±ÙˆØ¬ ØªÙ„Ù‚Ø§Ø¦ÙŠ)
        $this->stats['present'] = $data->whereIn('attendance_status', ['present', 'late', 'early_departure', 'auto_checkout'])->sum('count');
        
        $this->stats['late'] = $data->where('attendance_status', 'late')->first()->count ?? 0;
        $this->stats['absent'] = $data->where('attendance_status', 'absent')->first()->count ?? 0;
        $this->stats['on_leave'] = $data->where('attendance_status', 'on_leave')->first()->count ?? 0;
        $this->stats['early_departure'] = $data->where('attendance_status', 'early_departure')->first()->count ?? 0;
        $this->stats['auto_checkout'] = $data->where('attendance_status', 'auto_checkout')->first()->count ?? 0;
        
        $this->stats['compliance_avg'] = round($data->avg('avg_comp') ?? 0, 1);
        
        $pendingQ = AttendanceDailyLog::forCompany($companyId)
            ->where('approval_status', 'pending')
            ->whereBetween('attendance_date', [$this->date_from ?: '1900-01-01', $this->date_to ?: '2100-01-01']);

        // âœ… Data scoping
        $pendingQ = $this->applyDataScoping($pendingQ, 'attendance.daily.view', 'attendance.daily.view-subordinates');

        if (!empty($allowed)) {
            $pendingQ->whereHas('employee', fn ($q) => $q->whereIn('branch_id', $allowed));
        }

        if ($this->branch_id !== 'all') {
            $pendingQ->whereHas('employee', fn ($q) => $q->where('branch_id', (int) $this->branch_id));
        }

        $this->stats['pending_approval'] = $pendingQ->count();

        $totalCount = (int) $this->stats['total'];
        $this->stats['present_percentage']  = $totalCount > 0 ? round(($this->stats['present'] / $totalCount) * 100, 1) : 0;
        $this->stats['absent_percentage']   = $totalCount > 0 ? round(($this->stats['absent'] / $totalCount) * 100, 1) : 0;
        $this->stats['late_percentage']     = $totalCount > 0 ? round(($this->stats['late'] / $totalCount) * 100, 1) : 0;
        $this->stats['on_leave_percentage'] = $totalCount > 0 ? round(($this->stats['on_leave'] / $totalCount) * 100, 1) : 0;
        $this->stats['early_departure_percentage'] = $totalCount > 0 ? round(($this->stats['early_departure'] / $totalCount) * 100, 1) : 0;
        $this->stats['auto_checkout_percentage']   = $totalCount > 0 ? round(($this->stats['auto_checkout'] / $totalCount) * 100, 1) : 0;
    }

    public function loadWarnings()
    {
        $companyId = auth()->user()->saas_company_id;

        $threeDaysAgo = now()->subDays(3)->toDateString();

        $empQ = Employee::forCompany($companyId)
            ->where('status', 'active');

        // âœ… Data scoping
        $empQ = $this->applyDataScoping($empQ, 'attendance.daily.view', 'attendance.daily.view-subordinates', '');

        // âœ… Allowed branches scope
        $allowed = $this->allowedBranchIds();
        if (!empty($allowed)) {
            $empQ->whereIn('branch_id', $allowed);
        }

        // âœ… Selected branch filter
        if ($this->branch_id !== 'all') {
            $empQ->where('branch_id', (int) $this->branch_id);
        }

        $this->warningNoAttendanceEmployeeIds = $empQ
            ->whereNotExists(function($q) use ($threeDaysAgo, $companyId) {
                $q->select(DB::raw(1))
                ->from('attendance_daily_logs')
                ->whereColumn('attendance_daily_logs.employee_id', 'employees.id')
                // âœ… Ù…Ù‡Ù…: Ø§Ù…Ù†Ø¹ Ø£ÙŠ ØªØ¯Ø§Ø®Ù„ multi-tenant
                ->where('attendance_daily_logs.saas_company_id', $companyId)
                ->where('attendance_date', '>=', $threeDaysAgo);
            })
            ->pluck('id')
            ->toArray();

        $this->warnings['no_attendance_3days'] = count($this->warningNoAttendanceEmployeeIds);
    }
    // ==================== Helper Methods ====================
    public function auditLog(
        string $action,
        ?int $employeeId = null,
        ?string $entityType = null,
        ?int $entityId = null,
        $before = null,
        $after = null,
        array $meta = []
    ) {
        AttendanceAuditLog::create([
            'saas_company_id' => auth()->user()->saas_company_id,
            'actor_user_id' => auth()->id(),
            'action' => $action,
            'employee_id' => $employeeId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before_json' => $before,
            'after_json' => $after,
            'meta_json' => $meta,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    public function timeToHm($value)
    {
        if (!$value) return '-';
        return Carbon::parse($value)->format('H:i');
    }

    public function render()
    {
     return view('attendance::livewire.daily-attendance.index', [
            'attendanceLogs' => $this->attendanceLogs,
            'departments' => $this->departments,
            'branches' => $this->branches,
            'workSchedules' => $this->workSchedules,
            'jobTitles' => $this->jobTitles,
            'employees' => $this->employees,
            'modalTrigger' => $this->modalTrigger,
        ])->layout('layouts.company-admin');
    }
}


