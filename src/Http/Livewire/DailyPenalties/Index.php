<?php

namespace Athka\Attendance\Http\Livewire\DailyPenalties;

use Livewire\Component;
use Livewire\WithPagination;
use App\Services\ExcelExportService;
use Athka\Attendance\Models\AttendanceDailyLog;
use Athka\Attendance\Models\AttendanceDailyPenalty;
use Athka\Employees\Models\Employee;
use Athka\SystemSettings\Models\Department;
use Athka\SystemSettings\Models\JobTitle;
use Athka\SystemSettings\Models\AttendancePolicy;
use Athka\SystemSettings\Models\AttendancePenaltyPolicy;
use Illuminate\Support\Facades\DB;
use Livewire\WithFileUploads;
use Illuminate\Support\Facades\Storage;
use Athka\Attendance\Services\PenaltyService;
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;
use Athka\Saas\Models\Branch;
use Athka\Attendance\Http\Livewire\Traits\WithDataScoping;

use Livewire\Attributes\Layout;

#[Layout('layouts.company-admin')]
class Index extends Component
{
    public function placeholder()
    {
        return view('attendance::livewire.leaves.placeholder');
    }

    use WithPagination, WithFileUploads, WithDataScoping;

    // ==================== Filters ====================
    public $search = '';
    public $calculation_mode = 'single_day'; // single_day / range
    public $date_from = '';
    public $date_to = '';
    public $violation_type_filter = 'all'; // all/delay/early_departure/absent/auto_checkout
    public $status_filter = 'all'; // all/pending/confirmed/waived
    public $status_emp_filter = 'ACTIVE'; // all/ACTIVE/SUSPENDED/ENDED/TERMINATED/RESIGNED/RETIRED
    public $department_id = 'all';
    public $job_title_id = 'all';
    public $branch_id = 'all';
    public $selectedPenalties = [];
    public $selectAll = false;

    // ==================== Stats ====================
    public $stats = [
        'total_calculated' => 0,
        'total_exempted' => 0,
        'total_net' => 0,
        'total_waivers' => 0,
    ];

    // ==================== Modals ====================
    public $showExemptionModal = false;
    public $selectedPenaltyId = null;
    public $exemptionForm = [
        'type' => 'full', // full/partial
        'amount' => 0,
        'reason' => '',
        'details' => '',
        'attachment' => null,
    ];

    public $showConfirmModal = false;
    public $confirmPenaltyId = null;
    public $confirmPenaltyPreview = null;

    protected $queryString = [
        'search' => ['except' => ''],
        'calculation_mode' => ['except' => 'single_day'],
        'date_from' => ['except' => ''],
        'date_to' => ['except' => ''],
        'violation_type_filter' => ['except' => 'all'],
        'status_filter' => ['except' => 'all'],
        'status_emp_filter' => ['except' => 'ACTIVE'],
        'department_id' => ['except' => 'all'],
        'job_title_id' => ['except' => 'all'],
        'branch_id' => ['except' => 'all'],
    ];

    private function latestCompletedDate(): string
    {
        return now()->subDay()->toDateString();
    }

    private function resetBulkSelection(): void
    {
        $this->selectedPenalties = [];
        $this->selectAll = false;
    }

    private function resetExemptionForm(): void
    {
        $this->exemptionForm = [
            'type' => 'full',
            'amount' => 0,
            'reason' => '',
            'details' => '',
            'attachment' => null,
        ];
    }

    private function isOutsideEditableWindow($date): bool
    {
        return false;
    }

    private function clampToLatestCompletedDate($date): string
    {
        $latest = Carbon::parse($this->latestCompletedDate());

        try {
            $candidate = Carbon::parse($date ?: $latest->toDateString());
        } catch (\Throwable $exception) {
            return $latest->toDateString();
        }

        return $candidate->gt($latest)
            ? $latest->toDateString()
            : $candidate->toDateString();
    }

    private function initializeDateFiltersFromRequest(): void
    {
        $latestCompletedDate = $this->latestCompletedDate();

        $requestedMode = request()->query('calculation_mode');
        $requestedMode = is_string($requestedMode)
            ? trim($requestedMode)
            : '';

        $this->calculation_mode = in_array(
            $requestedMode,
            ['single_day', 'range'],
            true
        )
            ? $requestedMode
            : ($this->calculation_mode ?: 'single_day');

        $requestedFrom = request()->query('date_from');
        $requestedTo = request()->query('date_to');

        $requestedFrom = is_string($requestedFrom)
            ? trim($requestedFrom)
            : '';
        $requestedTo = is_string($requestedTo)
            ? trim($requestedTo)
            : '';

        if ($this->calculation_mode === 'single_day') {
            $requestedDate = $requestedFrom
                ?: ($requestedTo ?: $latestCompletedDate);

            $date = $this->clampToLatestCompletedDate($requestedDate);

            $this->date_from = $date;
            $this->date_to = $date;

            return;
        }

        $defaultFrom = Carbon::parse($latestCompletedDate)
            ->startOfMonth()
            ->toDateString();

        $dateFrom = $this->clampToLatestCompletedDate(
            $requestedFrom ?: $defaultFrom
        );
        $dateTo = $this->clampToLatestCompletedDate(
            $requestedTo ?: $latestCompletedDate
        );

        if (Carbon::parse($dateFrom)->gt(Carbon::parse($dateTo))) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        $this->date_from = $dateFrom;
        $this->date_to = $dateTo;
    }

    public function mount()
    {
        $this->requireAttendanceAny(['attendance.penalties.view', 'attendance.penalties.view-subordinates', 'attendance.penalties.manage', 'attendance.penalties.waive']);
        $this->initializeDateFiltersFromRequest();

        $userBranchId = (int) (auth()->user()->branch_id ?? 0);
        $allowed = $this->allowedBranchIds();

        if (!empty($allowed)) {
            $this->branch_id = in_array($userBranchId, $allowed, true) ? $userBranchId : 'all';
        } else {
            $this->branch_id = $userBranchId ?: 'all';
        }

        $this->loadStats();
    }

    public function refreshData()
    {
        $this->resetBulkSelection();
        $this->resetPage();
        $this->loadStats();
    }

    public function clearAllFilters()
    {
        $this->search = '';
        $this->calculation_mode = 'single_day';
        $latestCompletedDate = $this->latestCompletedDate();
        $this->date_from = $latestCompletedDate;
        $this->date_to = $latestCompletedDate;
        $this->violation_type_filter = 'all';
        $this->status_filter = 'all';
        $this->status_emp_filter = 'ACTIVE';
        $this->department_id = 'all';
        $this->job_title_id = 'all';

        $userBranchId = (int) (auth()->user()->branch_id ?? 0);
        $allowed = $this->allowedBranchIds();

        if (!empty($allowed)) {
            $this->branch_id = in_array($userBranchId, $allowed, true) ? $userBranchId : 'all';
        } else {
            $this->branch_id = $userBranchId ?: 'all';
        }

        $this->refreshData();
    }

    public function updatedCalculationMode()
    {
        $latestCompletedDate = Carbon::parse($this->latestCompletedDate());

        if ($this->calculation_mode === 'single_day') {
            $date = $this->clampToLatestCompletedDate($this->date_from);
            $this->date_from = $date;
            $this->date_to = $date;
        } else {
            $this->date_from = $latestCompletedDate
                ->copy()
                ->startOfMonth()
                ->toDateString();
            $this->date_to = $latestCompletedDate->toDateString();
        }

        $this->refreshData();
    }

    public function updatedDateFrom()
    {
        $this->date_from = $this->clampToLatestCompletedDate($this->date_from);

        if ($this->calculation_mode === 'single_day') {
            $this->date_to = $this->date_from;
        } elseif (
            filled($this->date_to)
            && Carbon::parse($this->date_from)->gt(Carbon::parse($this->date_to))
        ) {
            $this->date_to = $this->date_from;
        }

        $this->refreshData();
    }

    public function updatedDateTo()
    {
        $this->date_to = $this->clampToLatestCompletedDate($this->date_to);

        if ($this->calculation_mode === 'single_day') {
            $this->date_from = $this->date_to;
        } elseif (
            filled($this->date_from)
            && Carbon::parse($this->date_to)->lt(Carbon::parse($this->date_from))
        ) {
            $this->date_from = $this->date_to;
        }

        $this->refreshData();
    }

    public function updatedViolationTypeFilter() { $this->refreshData(); }
    public function updatedStatusFilter() { $this->refreshData(); }
    public function updatedStatusEmpFilter() { $this->refreshData(); }
    public function updatingSearch()
    {
        $this->resetBulkSelection();
        $this->resetPage();
    }

    public function updatingPage()
    {
        $this->resetBulkSelection();
    }
    public function updatedDepartmentId() { $this->refreshData(); }
    public function updatedJobTitleId() { $this->refreshData(); }

    public function updatedBranchId()
    {
        if (blank($this->branch_id)) {
            $this->branch_id = 'all';
        }

        $allowed = $this->allowedBranchIds();
        if (!empty($allowed) && !$this->isAll($this->branch_id)) {
            $bid = (int) $this->branch_id;
            if (!in_array($bid, $allowed, true)) {
                $this->branch_id = 'all';
            }
        }

        $this->refreshData();
    }

    public function goToPreviousDay()
    {
        $date = Carbon::parse($this->date_from ?: now()->toDateString())
            ->subDay()
            ->toDateString();

        $this->date_from = $date;
        $this->date_to = $date;

        $this->refreshData();
    }

    public function goToNextDay()
    {
        $currentDate = Carbon::parse(
            $this->date_from ?: $this->latestCompletedDate()
        );

        $latestCompletedDate = Carbon::parse($this->latestCompletedDate());

        if ($currentDate->gte($latestCompletedDate)) {
            $this->date_from = $latestCompletedDate->toDateString();
            $this->date_to = $latestCompletedDate->toDateString();
            $this->refreshData();

            return;
        }

        $date = $currentDate
            ->addDay()
            ->min($latestCompletedDate)
            ->toDateString();

        $this->date_from = $date;
        $this->date_to = $date;

        $this->refreshData();
    }

    private function getEffectiveDateRange(): array
    {
        $latestCompletedDate = $this->latestCompletedDate();

        if ($this->calculation_mode === 'single_day') {
            $date = $this->clampToLatestCompletedDate(
                $this->date_from ?: $latestCompletedDate
            );

            return [$date, $date];
        }

        $dateFrom = filled($this->date_from)
            ? $this->clampToLatestCompletedDate($this->date_from)
            : '';
        $dateTo = filled($this->date_to)
            ? $this->clampToLatestCompletedDate($this->date_to)
            : '';

        if (
            $dateFrom
            && $dateTo
            && Carbon::parse($dateFrom)->gt(Carbon::parse($dateTo))
        ) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        return [$dateFrom, $dateTo];
    }

    private function applyPenaltyDateFilters($query)
    {
        [$dateFrom, $dateTo] = $this->getEffectiveDateRange();

        if ($dateFrom) {
            $query->where('attendance_date', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->where('attendance_date', '<=', $dateTo);
        }

        return $query;
    }

    public function loadStats()
    {
        $base = $this->buildPenaltiesQuery(false);

        $this->stats = [
            'total_calculated' => (clone $base)->sum('calculated_amount'),
            'total_exempted'   => (clone $base)->sum('exemption_amount'),
            'total_net'        => (clone $base)->sum('net_amount'),
            'total_waivers'    => (clone $base)->where('status', 'waived')->count(),
        ];
    }
    public function getPenaltiesProperty()
    {
        return $this->buildPenaltiesQuery(true)
            ->orderByDesc('attendance_date')
            ->paginate(10);
    }
    public function runCalculation(PenaltyService $service)
    {
        $this->requireAttendanceAny('attendance.penalties.manage');

        $companyId = (int) auth()->user()->saas_company_id;
        [$dateFrom, $dateTo] = $this->getEffectiveDateRange();

        if (! $dateFrom || ! $dateTo) {
            $this->dispatch('toast', [
                'type' => 'error',
                'message' => tr('Please select a valid date before running calculation.')
            ]);
            return;
        }

        if (Carbon::parse($dateTo)->gte(now()->startOfDay())) {
            $this->dispatch('toast', [
                'type' => 'error',
                'message' => tr('Daily penalties can only be calculated for completed previous days.')
            ]);
            return;
        }

        $employeeIds = $this->getCalculationEmployeeIds($companyId);

        if (empty($employeeIds)) {
            $this->dispatch('toast', [
                'type' => 'error',
                'message' => tr('No employees found for the selected filters.')
            ]);
            return;
        }

        $lockKey = implode(':', [
            'attendance',
            'daily-penalties',
            $companyId,
            $dateFrom,
            $dateTo,
        ]);

        $lock = \Illuminate\Support\Facades\Cache::lock($lockKey, 360);

        if (! $lock->get()) {
            $this->dispatch('toast', [
                'type' => 'warning',
                'message' => tr('A penalty calculation is already running for the selected date.')
            ]);
            return;
        }

        try {
            DB::disableQueryLog();

            if (function_exists('set_time_limit')) {
                @set_time_limit(300);
            }

            $res = $this->calculation_mode === 'single_day'
                ? $service->calculateForDate($dateFrom, $companyId, $employeeIds)
                : $service->calculateForRange($dateFrom, $dateTo, $companyId, $employeeIds);

            $this->refreshData();
            $this->dispatch('$refresh');

            $message = $this->calculation_mode === 'single_day'
                ? tr('Single day calculation completed for') . ' ' . $dateFrom
                : tr('Range calculation completed from') . ' ' . $dateFrom . ' ' . tr('to') . ' ' . $dateTo;

            $this->dispatch('toast', [
                'type' => 'success',
                'message' => $message
                    . ' | ' . tr('Processed logs:') . ' ' . ($res['processed'] ?? 0)
                    . ' | ' . tr('Penalties created:') . ' ' . ($res['created'] ?? 0)
                    . ' | ' . tr('Employees:') . ' ' . count($employeeIds),
            ]);
        } catch (\Throwable $exception) {
            report($exception);

            $this->dispatch('toast', [
                'type' => 'error',
                'message' => tr('Penalty calculation failed. Please review the application log.')
            ]);
        } finally {
            optional($lock)->release();
        }
    }

    private function getCalculationEmployeeIds(int $companyId): array
    {
        $query = Employee::withoutGlobalScope('active_only')->forCompany($companyId);

        $allowed = $this->allowedBranchIds();
        if (!empty($allowed)) {
            $query->whereIn('branch_id', $allowed);
        }

        if (!$this->isAll($this->branch_id)) {
            $query->where('branch_id', (int) $this->branch_id);
        }

        if (!$this->isAll($this->department_id)) {
            $query->where('department_id', (int) $this->department_id);
        }

        if (!$this->isAll($this->job_title_id)) {
            $query->where('job_title_id', (int) $this->job_title_id);
        }

        if ($this->status_emp_filter !== 'all') {
            $query->where('status', (string) $this->status_emp_filter);
        }

        if ($this->search) {
            $search = '%' . $this->search . '%';
            $query->where(function ($q) use ($search) {
                $q->where('name_ar', 'like', $search)
                    ->orWhere('name_en', 'like', $search)
                    ->orWhere('employee_no', 'like', $search);
            });
        }

        return $query->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function openExemptionModal($id)
    {
        $this->requireAttendanceAny('attendance.penalties.waive');
        $penalty = $this->findPenaltyOrFail((int) $id);

        if ($this->isOutsideEditableWindow($penalty->attendance_date)) {
            $this->dispatch('toast', [
                'type' => 'error',
                'message' => tr('Cannot modify penalties older than 7 days.')
            ]);

            return;
        }

        if ($penalty->status === 'confirmed') {
            $this->dispatch('toast', [
                'type' => 'error',
                'message' => tr('Confirmed penalties cannot be modified.')
            ]);

            return;
        }

        $this->resetValidation();
        $this->resetExemptionForm();

        $this->selectedPenaltyId = (int) $penalty->id;
        $this->exemptionForm['amount'] = (float) $penalty->calculated_amount;
        $this->showExemptionModal = true;
    }

    public function saveExemption()
    {
        $this->requireAttendanceAny('attendance.penalties.waive');

        if (! $this->selectedPenaltyId) {
            $this->dispatch('toast', [
                'type' => 'error',
                'message' => tr('The selected penalty is no longer available.')
            ]);

            return;
        }

        $penalty = $this->findPenaltyOrFail((int) $this->selectedPenaltyId);

        if ($this->isOutsideEditableWindow($penalty->attendance_date)) {
            $this->dispatch('toast', [
                'type' => 'error',
                'message' => tr('Cannot modify penalties older than 7 days.')
            ]);

            return;
        }

        if ($penalty->status === 'confirmed') {
            $this->dispatch('toast', [
                'type' => 'error',
                'message' => tr('Confirmed penalties cannot be modified.')
            ]);

            return;
        }

        $maximumAmount = max(0, (float) $penalty->calculated_amount);

        $rules = [
            'exemptionForm.type' => ['required', 'in:full,partial'],
            'exemptionForm.reason' => [
                'required',
                'in:business_mission,emergency_case,technical_issue,late_permission,medical_emergency,other',
            ],
            'exemptionForm.details' => ['nullable', 'string', 'max:1000'],
            'exemptionForm.attachment' => [
                'nullable',
                'file',
                'mimes:png,jpg,jpeg,pdf',
                'max:10240',
            ],
        ];

        if (($this->exemptionForm['type'] ?? null) === 'partial') {
            $rules['exemptionForm.amount'] = [
                'required',
                'numeric',
                'gt:0',
                'max:' . $maximumAmount,
            ];
        } else {
            $rules['exemptionForm.amount'] = [
                'nullable',
                'numeric',
                'min:0',
            ];
        }

        $this->validate($rules);

        $exemptAmount = $this->exemptionForm['type'] === 'full'
            ? $maximumAmount
            : min(
                (float) $this->exemptionForm['amount'],
                $maximumAmount
            );

        $reason = trim(
            (string) $this->exemptionForm['reason']
            . (
                filled($this->exemptionForm['details'] ?? '')
                    ? ' - ' . trim((string) $this->exemptionForm['details'])
                    : ''
            )
        );

        $updateData = [
            'exemption_type' => $this->exemptionForm['type'],
            'exemption_amount' => $exemptAmount,
            'net_amount' => max(0, $maximumAmount - $exemptAmount),
            'exemption_status' => 'approved',
            'exemption_reason' => $reason,
            'exempted_by' => auth()->id(),
            'exempted_at' => now(),
            'status' => $this->exemptionForm['type'] === 'full'
                ? 'waived'
                : 'pending',
            'notes' => trim(
                (string) $penalty->notes
                . "\n[Audit] Exemption applied by "
                . auth()->user()->name
                . ' at '
                . now()
            ),
        ];

        if ($this->exemptionForm['attachment']) {
            $updateData['exemption_attachment'] =
                $this->exemptionForm['attachment']->store(
                    'attendance/exemptions',
                    'public'
                );
        }

        $penalty->update($updateData);

        $this->closeExemptionModal();
        $this->refreshData();

        $this->dispatch('toast', [
            'type' => 'success',
            'message' => tr('Exemption applied.')
        ]);
    }

    public function closeExemptionModal()
    {
        $this->showExemptionModal = false;
        $this->selectedPenaltyId = null;
        $this->resetValidation();
        $this->resetExemptionForm();
    }

    public function openConfirmModal($id)
    {
        $this->requireAttendanceAny('attendance.penalties.manage');
        $penalty = $this->findPenaltyOrFail((int) $id);

        if ($penalty->status !== 'pending') {
            $this->dispatch('toast', [
                'type' => 'error',
                'message' => tr('Only pending penalties can be confirmed.')
            ]);

            return;
        }

        $this->confirmPenaltyId = (int) $penalty->id;
        $this->confirmPenaltyPreview = [
            'net_amount' => (float) $penalty->net_amount,
            'employee_name' => $penalty->employee->name_ar
                ?? $penalty->employee->name_en
                ?? '',
            'attendance_date' => (string) $penalty->attendance_date,
        ];
        $this->showConfirmModal = true;
    }

    public function confirmPenalty()
    {
        $this->requireAttendanceAny('attendance.penalties.manage');

        if (! $this->confirmPenaltyId) {
            $this->closeConfirmModal();

            return;
        }

        $penalty = $this->findPenaltyOrFail((int) $this->confirmPenaltyId);

        if ($penalty->status !== 'pending') {
            $this->closeConfirmModal();
            $this->dispatch('toast', [
                'type' => 'error',
                'message' => tr('Only pending penalties can be confirmed.')
            ]);

            return;
        }

        $penalty->update([
            'status' => 'confirmed',
            'confirmed_by' => auth()->id(),
            'confirmed_at' => now(),
            'notes' => trim(
                (string) $penalty->notes
                . "\n[Audit] Penalty confirmed for payroll by "
                . auth()->user()->name
                . ' at '
                . now()
            ),
        ]);

        $this->closeConfirmModal();
        $this->refreshData();

        $this->dispatch('toast', [
            'type' => 'success',
            'message' => tr('Penalty confirmed for payroll.')
        ]);
    }

    public function closeConfirmModal()
    {
        $this->showConfirmModal = false;
        $this->confirmPenaltyId = null;
        $this->confirmPenaltyPreview = null;
    }

    public function deletePenalty($id)
    {
        $this->requireAttendanceAny('attendance.penalties.manage');
        $penalty = $this->findPenaltyOrFail((int) $id);

        if ($this->isOutsideEditableWindow($penalty->attendance_date)) {
            $this->dispatch('toast', [
                'type' => 'error',
                'message' => tr('Cannot remove penalties older than 7 days.')
            ]);

            return;
        }

        if ($penalty->status === 'confirmed') {
            $this->dispatch('toast', [
                'type' => 'error',
                'message' => tr('Confirmed penalties cannot be removed.')
            ]);

            return;
        }

        $penalty->delete();
        $this->refreshData();

        $this->dispatch('toast', [
            'type' => 'info',
            'message' => tr('Penalty removed.')
        ]);
    }

    public function updatedSelectAll($value)
    {
        if ($value) {
            $this->selectedPenalties = $this->penalties->pluck('id')->map(fn($id) => (string) $id)->toArray();
        } else {
            $this->selectedPenalties = [];
        }
    }

    public function bulkConfirm()
    {
        $this->requireAttendanceAny('attendance.penalties.manage');

        if (empty($this->selectedPenalties)) {
            return;
        }

        $updated = $this->buildPenaltiesQuery(false)
            ->whereIn('id', array_map('intval', $this->selectedPenalties))
            ->where('status', 'pending')
            ->update([
                'status' => 'confirmed',
                'confirmed_by' => auth()->id(),
                'confirmed_at' => now(),
            ]);

        $this->refreshData();

        $this->dispatch('toast', [
            'type' => $updated > 0 ? 'success' : 'warning',
            'message' => $updated > 0
                ? tr('Selected penalties confirmed.')
                : tr('No eligible pending penalties were found in the current view.'),
        ]);
    }

    public function bulkDelete()
    {
        $this->requireAttendanceAny('attendance.penalties.manage');

        if (empty($this->selectedPenalties)) {
            return;
        }

        $query = $this->buildPenaltiesQuery(false)
            ->whereIn('id', array_map('intval', $this->selectedPenalties))
            ->where('status', '!=', 'confirmed');

        $deleted = (clone $query)->count();
        $query->delete();

        $this->refreshData();

        $this->dispatch('toast', [
            'type' => $deleted > 0 ? 'info' : 'warning',
            'message' => $deleted > 0
                ? tr('Selected penalties removed (excluding confirmed or >7 days).')
                : tr('No eligible penalties were found in the current view.'),
        ]);
    }

    public function render()
    {
        $branchesQ = Branch::where('saas_company_id', auth()->user()->saas_company_id)
            ->where('is_active', true);

        $allowed = $this->allowedBranchIds();
        if (!empty($allowed)) {
            $branchesQ->whereIn('id', $allowed);
        }

        return view('attendance::livewire.daily-penalties.index', [
            'penalties' => $this->penalties,
            'departments' => Department::forCompany(auth()->user()->saas_company_id)->get(),
            'jobTitles' => JobTitle::forCompany(auth()->user()->saas_company_id)->get(),
            'branches' => $branchesQ->orderBy('name')->get(),
            'latestCompletedDate' => $this->latestCompletedDate(),
        ])->layout('layouts.company-admin');
    }

    public function exportExcel(ExcelExportService $exporter)
    {
        $this->requireAttendanceAny('attendance.penalties.export');
        $penalties = $this->getPenaltiesQuery()->get();
        $filename = "daily_penalties_" . now()->format('YmdHis');

        $headers = [tr('Employee'), tr('Employee No'), tr('Date'), tr('Violation'), tr('Minutes'), tr('Amount'), tr('Net'), tr('Status')];

        $data = $penalties->map(function ($p) {
            return [
                $p->employee->name_ar ?? $p->employee->name_en,
                $p->employee->employee_no,
                $p->attendance_date->format('Y-m-d'),
                tr(ucfirst($p->violation_type)),
                $p->violation_minutes,
                $p->calculated_amount,
                $p->net_amount,
                tr(ucfirst($p->status)),
            ];
        })->toArray();

        return $exporter->export($filename, $headers, $data);
    }

    public function exportPdf()
    {
        $this->requireAttendanceAny('attendance.penalties.export');
        $penalties = $this->getPenaltiesQuery()->get();
        $stats = $this->stats;
        [$dateFrom, $dateTo] = $this->getEffectiveDateRange();

        $penalties->each(function ($penalty) {
            $employee = $penalty->employee;
            $penalty->pdf_employee_name = $this->pdfReshape($employee?->name_ar ?? $employee?->name_en ?? '-');
            $penalty->pdf_violation = $this->pdfReshape(tr(ucfirst((string) $penalty->violation_type)));
            $penalty->pdf_status = $this->pdfReshape(tr(ucfirst((string) $penalty->status)));
        });

        $pdf = Pdf::loadView('attendance::pdf.daily-penalties', [
            'penalties' => $penalties,
            'stats' => $stats,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'reshaper' => fn ($text) => $this->pdfReshape($text),
        ])->setPaper('a4', 'landscape');

        return response()->streamDownload(function () use ($pdf) {
            echo $pdf->stream();
        }, "daily_penalties_" . now()->format('YmdHis') . ".pdf");
    }

    private function pdfReshape($text)
    {
        if (class_exists('\Athka\Employees\Support\ArabicHelper')) {
            return \Athka\Employees\Support\ArabicHelper::prepareForPdf((string) $text);
        }

        return $text;
    }
    private function getPenaltiesQuery()
    {
        return $this->buildPenaltiesQuery(true)->orderByDesc('attendance_date');
    }

    private function buildPenaltiesQuery(bool $withRelations = false)
    {
        $companyId = auth()->user()->saas_company_id;
        $query = AttendanceDailyPenalty::forCompany($companyId);

        if ($withRelations) {
            $query->with([
                'employee' => fn ($q) => $q->withoutGlobalScope('active_only')->with(['department', 'jobTitle', 'branch']),
                'attendanceLog',
            ]);
        }

        $query = $this->applyDataScoping($query, 'attendance.penalties.view', 'attendance.penalties.view-subordinates');
        $query = $this->applyBranchScopeToPenaltiesQuery($query);
        $query = $this->applyPenaltyDateFilters($query);
        $query = $this->applyPenaltyFilters($query);

        return $query;
    }

    private function applyPenaltyFilters($query)
    {
        if ($this->violation_type_filter !== 'all') {
            $query->where('violation_type', $this->violation_type_filter);
        }

        if ($this->status_filter !== 'all') {
            $query->where('status', $this->status_filter);
        }

        if ($this->status_emp_filter !== 'all') {
            $query->whereHas('employee', fn($q) => $q->withoutGlobalScope('active_only')->where('status', (string) $this->status_emp_filter));
        }

        if (!$this->isAll($this->department_id)) {
            $query->whereHas('employee', fn($q) => $q->withoutGlobalScope('active_only')->where('department_id', (int) $this->department_id));
        }

        if (!$this->isAll($this->job_title_id)) {
            $query->whereHas('employee', fn($q) => $q->withoutGlobalScope('active_only')->where('job_title_id', (int) $this->job_title_id));
        }

        if ($this->search) {
            $search = '%' . $this->search . '%';
            $query->whereHas('employee', function ($q) use ($search) {
                $q->withoutGlobalScope('active_only');
                $q->where('name_ar', 'like', $search)
                    ->orWhere('name_en', 'like', $search)
                    ->orWhere('employee_no', 'like', $search);
            });
        }

        return $query;
    }
    private function allowedBranchIds(): array
    {
        $user = auth()->user();

        if (isset($user->access_scope) && $user->access_scope === 'all_branches') {
            return [];
        }

        if (method_exists($user, 'accessibleBranchIds')) {
            $ids = $user->accessibleBranchIds();
            return array_values(array_filter(array_map('intval', is_array($ids) ? $ids : $ids->toArray())));
        }

        $bid = (int) ($user->branch_id ?? 0);
        return $bid > 0 ? [$bid] : [];
    }

    private function applyBranchScopeToPenaltiesQuery($query)
    {
        $allowed = $this->allowedBranchIds();

        if (empty($allowed) && $this->isAll($this->branch_id)) {
            return $query;
        }

        $selectedBranchId = $this->branch_id;

        $query->whereHas('employee', function ($q) use ($allowed, $selectedBranchId) {
            $q->withoutGlobalScope('active_only');

            if (!empty($allowed)) {
                $q->whereIn('branch_id', $allowed);
            }

            if (!$this->isAll($selectedBranchId)) {
                $q->where('branch_id', (int) $selectedBranchId);
            }
        });

        return $query;
    }

    private function findPenaltyOrFail(int $id): AttendanceDailyPenalty
    {
        $companyId = auth()->user()->saas_company_id;

        $query = AttendanceDailyPenalty::forCompany($companyId)->with([
            'employee' => fn ($employeeQuery) => $employeeQuery
                ->withoutGlobalScope('active_only'),
        ]);

        $query = $this->applyDataScoping(
            $query,
            'attendance.penalties.view',
            'attendance.penalties.view-subordinates'
        );

        $query = $this->applyBranchScopeToPenaltiesQuery($query);

        return $query->findOrFail($id);
    }

    private function isAll($value): bool
    {
        return $value === 'all' || blank($value);
    }
}


