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
    public $status_emp_filter = 'all'; // all/ACTIVE/SUSPENDED/TERMINATED
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

    protected $queryString = [
        'search' => ['except' => ''],
        'calculation_mode' => ['except' => 'single_day'],
        'date_from' => ['except' => ''],
        'date_to' => ['except' => ''],
        'violation_type_filter' => ['except' => 'all'],
        'status_filter' => ['except' => 'all'],
        'status_emp_filter' => ['except' => 'all'],
        'department_id' => ['except' => 'all'],
        'job_title_id' => ['except' => 'all'],
        'branch_id' => ['except' => 'all'],
    ];

    public function mount()
    {
        $this->calculation_mode = $this->calculation_mode ?: 'single_day';
        $this->date_from = now()->format('Y-m-d');
        $this->date_to = now()->format('Y-m-d');

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
        $this->resetPage();
        $this->loadStats();
    }

    public function clearAllFilters()
    {
        $this->search = '';
        $this->calculation_mode = 'single_day';
        $this->date_from = now()->format('Y-m-d');
        $this->date_to = now()->format('Y-m-d');
        $this->violation_type_filter = 'all';
        $this->status_filter = 'all';
        $this->status_emp_filter = 'all';
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
        if ($this->calculation_mode === 'single_day') {
            $date = $this->date_from ?: now()->format('Y-m-d');
            $this->date_from = $date;
            $this->date_to = $date;
        } else {
            $this->date_from = now()->startOfMonth()->format('Y-m-d');
            $this->date_to = now()->format('Y-m-d');
        }

        $this->refreshData();
    }

    public function updatedDateFrom()
    {
        if ($this->calculation_mode === 'single_day') {
            $this->date_to = $this->date_from;
        }

        $this->refreshData();
    }

    public function updatedDateTo()
    {
        if ($this->calculation_mode === 'single_day') {
            $this->date_to = $this->date_from;
        }

        $this->refreshData();
    }

    public function updatedViolationTypeFilter() { $this->refreshData(); }
    public function updatedStatusFilter() { $this->refreshData(); }
    public function updatedStatusEmpFilter() { $this->refreshData(); }
    public function updatingSearch() { $this->resetPage(); }
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
        $date = Carbon::parse($this->date_from ?: now()->toDateString())
            ->addDay()
            ->toDateString();

        $this->date_from = $date;
        $this->date_to = $date;

        $this->refreshData();
    }

    private function getEffectiveDateRange(): array
    {
        $today = now()->format('Y-m-d');

        if ($this->calculation_mode === 'single_day') {
            $date = $this->date_from ?: $today;
            return [$date, $date];
        }

        $dateFrom = $this->date_from ?: '';
        $dateTo = $this->date_to ?: '';

        if ($dateFrom && $dateTo && Carbon::parse($dateFrom)->gt(Carbon::parse($dateTo))) {
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
        $companyId = auth()->user()->saas_company_id;
        $query = AttendanceDailyPenalty::forCompany($companyId);

        $query = $this->applyDataScoping($query, 'attendance.penalties.view', 'attendance.penalties.view-subordinates');
        $query = $this->applyBranchScopeToPenaltiesQuery($query);
        $query = $this->applyPenaltyDateFilters($query);

        if ($this->status_emp_filter !== 'all') {
            $query->whereHas('employee', fn($q) => $q->where('status', (string) $this->status_emp_filter));
        }

        $base = clone $query;

        $this->stats = [
            'total_calculated' => (clone $base)->sum('calculated_amount'),
            'total_exempted'   => (clone $base)->sum('exemption_amount'),
            'total_net'        => (clone $base)->sum('net_amount'),
            'total_waivers'    => (clone $base)->where('status', 'waived')->count(),
        ];
    }

    public function getPenaltiesProperty()
    {
        $companyId = auth()->user()->saas_company_id;
        $query = AttendanceDailyPenalty::forCompany($companyId)
            ->with(['employee.department', 'employee.jobTitle', 'employee.branch', 'attendanceLog']);

        $query = $this->applyDataScoping($query, 'attendance.penalties.view', 'attendance.penalties.view-subordinates');
        $query = $this->applyBranchScopeToPenaltiesQuery($query);
        $query = $this->applyPenaltyDateFilters($query);

        if ($this->violation_type_filter !== 'all') {
            $query->where('violation_type', $this->violation_type_filter);
        }

        if ($this->status_filter !== 'all') {
            $query->where('status', $this->status_filter);
        }

        if ($this->status_emp_filter !== 'all') {
            $query->whereHas('employee', fn($q) => $q->where('status', (string) $this->status_emp_filter));
        }

        if (!$this->isAll($this->department_id)) {
            $query->whereHas('employee', fn($q) => $q->where('department_id', (int) $this->department_id));
        }

        if (!$this->isAll($this->job_title_id)) {
            $query->whereHas('employee', fn($q) => $q->where('job_title_id', (int) $this->job_title_id));
        }

        if ($this->search) {
            $query->whereHas('employee', function ($q) {
                $q->where('name_ar', 'like', '%' . $this->search . '%')
                    ->orWhere('name_en', 'like', '%' . $this->search . '%')
                    ->orWhere('employee_no', 'like', '%' . $this->search . '%');
            });
        }

        return $query->orderByDesc('attendance_date')->paginate(10);
    }

    public function runCalculation(PenaltyService $service)
    {
        $companyId = auth()->user()->saas_company_id;
        [$dateFrom, $dateTo] = $this->getEffectiveDateRange();

        $allowed = $this->allowedBranchIds();
        if (!empty($allowed) && $this->branch_id === 'all') {
            $this->dispatch('toast', [
                'type' => 'error',
                'message' => tr('Please select a branch before running calculation.')
            ]);
            return;
        }

        if (!$dateFrom || !$dateTo) {
            $this->dispatch('toast', [
                'type' => 'error',
                'message' => tr('Please select a valid date before running calculation.')
            ]);
            return;
        }

        $res = $this->calculation_mode === 'single_day'
            ? $service->calculateForDate($dateFrom, $companyId)
            : $service->calculateForRange($dateFrom, $dateTo, $companyId);

        $this->resetPage();
        $this->loadStats();
        $this->dispatch('$refresh');

        $message = $this->calculation_mode === 'single_day'
            ? tr('Single day calculation completed for') . ' ' . $dateFrom
            : tr('Range calculation completed from') . ' ' . $dateFrom . ' ' . tr('to') . ' ' . $dateTo;

        $this->dispatch('toast', [
            'type' => 'success',
            'message' => $message
                . ' | ' . tr('Processed logs:') . ' ' . ($res['processed'] ?? 0)
                . ' | ' . tr('Penalties created:') . ' ' . ($res['created'] ?? 0),
        ]);
    }

    public function openExemptionModal($id)
    {
        $penalty = $this->findPenaltyOrFail((int) $id);

        /*
        if (Carbon::parse($penalty->attendance_date)->diffInDays(now()) > 7) {
            $this->dispatch('toast', ['type' => 'error', 'message' => tr('Cannot modify penalties older than 7 days.')]);
            return;
        }
        */

        if ($penalty->status === 'confirmed') {
            $this->dispatch('toast', ['type' => 'error', 'message' => tr('Confirmed penalties cannot be modified.')]);
            return;
        }

        $this->selectedPenaltyId = $id;
        $this->exemptionForm['amount'] = $penalty->calculated_amount;
        $this->showExemptionModal = true;
    }

    public function saveExemption()
    {
        $penalty = $this->findPenaltyOrFail((int) $this->selectedPenaltyId);

        $exemptAmount = ($this->exemptionForm['type'] === 'full')
            ? $penalty->calculated_amount
            : min($this->exemptionForm['amount'], $penalty->calculated_amount);

        $updateData = [
            'exemption_type' => $this->exemptionForm['type'],
            'exemption_amount' => $exemptAmount,
            'net_amount' => max(0, $penalty->calculated_amount - $exemptAmount),
            'exemption_status' => 'approved',
            'exemption_reason' => trim(
                ($this->exemptionForm['reason'] ?? '')
                . (filled($this->exemptionForm['details'] ?? '') ? ' - ' . ($this->exemptionForm['details'] ?? '') : '')
            ),
            'exempted_by' => auth()->id(),
            'exempted_at' => now(),
            'status' => ($this->exemptionForm['type'] === 'full') ? 'waived' : 'pending',
        ];

        if ($this->exemptionForm['attachment']) {
            $path = $this->exemptionForm['attachment']->store('attendance/exemptions', 'public');
            $updateData['exemption_attachment'] = $path;
        }

        $penalty->update($updateData);

        $penalty->update([
            'notes' => $penalty->notes . "\n[Audit] Exemption applied by " . auth()->user()->name . " at " . now()
        ]);

        $this->exemptionForm = ['type' => 'full', 'amount' => 0, 'reason' => '', 'details' => '', 'attachment' => null];

        $this->showExemptionModal = false;
        $this->loadStats();
        $this->dispatch('toast', ['type' => 'success', 'message' => tr('Exemption applied.')]);
    }

    public function openConfirmModal($id)
    {
        $this->confirmPenaltyId = $id;
        $this->showConfirmModal = true;
    }

    public function confirmPenalty()
    {
        $penalty = $this->findPenaltyOrFail((int) $this->confirmPenaltyId);
        $penalty->update([
            'status' => 'confirmed',
            'confirmed_by' => auth()->id(),
            'confirmed_at' => now(),
        ]);

        $penalty->update([
            'notes' => $penalty->notes . "\n[Audit] Penalty confirmed for payroll by " . auth()->user()->name . " at " . now()
        ]);

        $this->showConfirmModal = false;
        $this->loadStats();
        $this->dispatch('toast', ['type' => 'success', 'message' => tr('Penalty confirmed for payroll.')]);
    }

    public function deletePenalty($id)
    {
        $penalty = $this->findPenaltyOrFail((int) $id);

        /*
        if (Carbon::parse($penalty->attendance_date)->diffInDays(now()) > 7) {
            $this->dispatch('toast', ['type' => 'error', 'message' => tr('Cannot remove penalties older than 7 days.')]);
            return;
        }
        */

        if ($penalty->status === 'confirmed') {
            $this->dispatch('toast', ['type' => 'error', 'message' => tr('Confirmed penalties cannot be removed.')]);
            return;
        }

        $penalty->delete();
        $this->loadStats();
        $this->dispatch('toast', ['type' => 'info', 'message' => tr('Penalty removed.')]);
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
        if (empty($this->selectedPenalties)) return;

        $companyId = auth()->user()->saas_company_id;

        $q = AttendanceDailyPenalty::forCompany($companyId)
            ->whereIn('id', $this->selectedPenalties)
            ->where('status', 'pending');

        $q = $this->applyBranchScopeToPenaltiesQuery($q);

        $q->update([
            'status' => 'confirmed',
            'confirmed_by' => auth()->id(),
            'confirmed_at' => now(),
        ]);

        $this->selectedPenalties = [];
        $this->selectAll = false;
        $this->loadStats();
        $this->dispatch('toast', ['type' => 'success', 'message' => tr('Selected penalties confirmed.')]);
    }

    public function bulkDelete()
    {
        if (empty($this->selectedPenalties)) return;

        $sevenDaysAgo = now()->subDays(7)->toDateString();

        $companyId = auth()->user()->saas_company_id;

        $q = AttendanceDailyPenalty::forCompany($companyId)
            ->whereIn('id', $this->selectedPenalties)
            ->where('status', '!=', 'confirmed');
            // ->where('attendance_date', '>=', $sevenDaysAgo);

        $q = $this->applyBranchScopeToPenaltiesQuery($q);

        $q->delete();

        $this->selectedPenalties = [];
        $this->selectAll = false;
        $this->loadStats();
        $this->dispatch('toast', ['type' => 'info', 'message' => tr('Selected penalties removed (excluding confirmed or >7 days).')]);
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
        ])->layout('layouts.company-admin');
    }

    public function exportExcel(ExcelExportService $exporter)
    {
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
        $penalties = $this->getPenaltiesQuery()->get();
        $stats = $this->stats;
        [$dateFrom, $dateTo] = $this->getEffectiveDateRange();

        $pdf = Pdf::loadView('attendance::pdf.daily-penalties', [
            'penalties' => $penalties,
            'stats' => $stats,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ]);

        return response()->streamDownload(function () use ($pdf) {
            echo $pdf->stream();
        }, "daily_penalties_" . now()->format('YmdHis') . ".pdf");
    }

    private function getPenaltiesQuery()
    {
        $companyId = auth()->user()->saas_company_id;
        $query = AttendanceDailyPenalty::forCompany($companyId)
            ->with(['employee.department', 'employee.jobTitle', 'employee.branch', 'attendanceLog']);

        $query = $this->applyDataScoping($query, 'attendance.penalties.view', 'attendance.penalties.view-subordinates');
        $query = $this->applyBranchScopeToPenaltiesQuery($query);
        $query = $this->applyPenaltyDateFilters($query);

        if ($this->violation_type_filter !== 'all') $query->where('violation_type', $this->violation_type_filter);
        if ($this->status_filter !== 'all') $query->where('status', $this->status_filter);

        if ($this->department_id !== 'all') {
            $query->whereHas('employee', fn($q) => $q->where('department_id', $this->department_id));
        }

        if ($this->job_title_id !== 'all') {
            $query->whereHas('employee', fn($q) => $q->where('job_title_id', $this->job_title_id));
        }

        if ($this->search) {
            $query->whereHas('employee', function ($q) {
                $q->where('name_ar', 'like', '%' . $this->search . '%')
                    ->orWhere('name_en', 'like', '%' . $this->search . '%')
                    ->orWhere('employee_no', 'like', '%' . $this->search . '%');
            });
        }

        return $query->orderByDesc('attendance_date');
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

        $q = AttendanceDailyPenalty::forCompany($companyId)->with('employee');

        $allowed = $this->allowedBranchIds();
        if (!empty($allowed)) {
            $q->whereHas('employee', fn($qq) => $qq->whereIn('branch_id', $allowed));
        }

        return $q->findOrFail($id);
    }

    private function isAll($value): bool
    {
        return $value === 'all' || blank($value);
    }
}