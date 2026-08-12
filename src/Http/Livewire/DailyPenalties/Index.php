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
use Athka\SystemSettings\Services\WorkScheduleService;
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
    public $status_emp_filter = 'all'; // all/ACTIVE/SUSPENDED/ENDED/TERMINATED/RESIGNED/RETIRED
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
    public $showExemptionHistoryModal = false;
    public $exemptionHistoryPenaltyPreview = null;
    public $exemptionHistory = [];

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

    private function clearFieldErrorsByPrefix(string $field): void
    {
        $bag = $this->getErrorBag();

        foreach (array_keys($bag->toArray()) as $key) {
            if ($key === $field || str_starts_with($key, $field . '.')) {
                $bag->forget($key);
            }
        }

        $this->setErrorBag($bag);
    }

    public function clearUploadFieldError(string $field): void
    {
        $this->clearFieldErrorsByPrefix($field);
    }

    public function setUploadFieldError(string $field, string $message): void
    {
        $this->clearFieldErrorsByPrefix($field);

        if (trim($message) !== '') {
            $this->addError($field, $message);
        }
    }

    public function setUploadFieldErrors(string $field, array $messages): void
    {
        $this->clearFieldErrorsByPrefix($field);

        foreach ($messages as $message) {
            if (is_string($message) && trim($message) !== '') {
                $this->addError($field, $message);
            }
        }
    }

    private function isOutsideEditableWindow($date): bool
    {
        return Carbon::parse($date)
            ->startOfDay()
            ->lt(now()->subDays(7)->startOfDay());
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

        $this->date_from = $dateFrom;
        $this->date_to = $dateTo;
    }

    public function mount()
    {
        $this->requireAttendanceAny(['attendance.penalties.view', 'attendance.penalties.view-subordinates', 'attendance.penalties.manage', 'attendance.penalties.waive']);
        $this->initializeDateFiltersFromRequest();



        if ($this->hasInvalidDateRange()) {
            $this->addDateRangeError();
        }
        $userBranchId = (int) (auth()->user()->branch_id ?? 0);
        $allowed = $this->allowedBranchIds();

        $this->branch_id = !empty($allowed) && in_array($userBranchId, $allowed, true)
            ? $userBranchId
            : 'all';

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
        $this->status_emp_filter = 'all';
        $this->department_id = 'all';
        $this->job_title_id = 'all';

        $userBranchId = (int) (auth()->user()->branch_id ?? 0);
        $allowed = $this->allowedBranchIds();

        $this->branch_id = !empty($allowed) && in_array($userBranchId, $allowed, true)
            ? $userBranchId
            : 'all';

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
        $this->resetValidation('date_to');

        if ($this->calculation_mode === 'single_day') {
            $this->date_to = $this->date_from;
        } elseif ($this->hasInvalidDateRange()) {
            $this->addDateRangeError();
        }

        $this->refreshData();
    }

    public function updatedDateTo()
    {
        $this->date_to = $this->clampToLatestCompletedDate($this->date_to);
        $this->resetValidation('date_to');

        if ($this->calculation_mode === 'single_day') {
            $this->date_from = $this->date_to;
        } elseif ($this->hasInvalidDateRange()) {
            $this->addDateRangeError();
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

    private function hasInvalidDateRange(
        ?string $dateFrom = null,
        ?string $dateTo = null
    ): bool {
        if ($this->calculation_mode === 'single_day') {
            return false;
        }

        $dateFrom = $dateFrom ?? $this->date_from;
        $dateTo = $dateTo ?? $this->date_to;

        return filled($dateFrom)
            && filled($dateTo)
            && Carbon::parse($dateFrom)->gt(Carbon::parse($dateTo));
    }

    private function addDateRangeError(): void
    {
        $this->addError('date_to', $this->dateRangeValidationMessage());
    }

    private function dateRangeValidationMessage(): string
    {
        return substr(strtolower(app()->getLocale()), 0, 2) === 'ar'
            ? "\u{0644}\u{0627} \u{064A}\u{0645}\u{0643}\u{0646} \u{0623}\u{0646} \u{064A}\u{0643}\u{0648}\u{0646} \u{062A}\u{0627}\u{0631}\u{064A}\u{062E} \u{0627}\u{0644}\u{0646}\u{0647}\u{0627}\u{064A}\u{0629} \u{0642}\u{0628}\u{0644} \u{062A}\u{0627}\u{0631}\u{064A}\u{062E} \u{0627}\u{0644}\u{0628}\u{062F}\u{0627}\u{064A}\u{0629}."
            : tr('End date cannot be before start date.');
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

        return [$dateFrom, $dateTo];
    }

    private function applyPenaltyDateFilters($query)
    {
        [$dateFrom, $dateTo] = $this->getEffectiveDateRange();


        if ($this->hasInvalidDateRange($dateFrom, $dateTo)) {
            return $query->whereRaw('1 = 0');
        }
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


        if ($this->hasInvalidDateRange($dateFrom, $dateTo)) {
            $this->addDateRangeError();
            return;
        }
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

            $createdCount = (int) ($res['created'] ?? 0);
            $skippedReasons = $this->formatSkippedReasons($res['skipped'] ?? []);
            $calendarSkipMessage = $this->calendarSkipMessage($companyId, $dateFrom, $dateTo, $employeeIds, $createdCount);
            $toastType = $createdCount > 0 ? 'success' : 'info';
            $toastMessage = $message
                . ' | ' . tr('Processed logs:') . ' ' . ($res['processed'] ?? 0)
                . ' | ' . tr('Penalties created:') . ' ' . $createdCount
                . ' | ' . tr('Employees:') . ' ' . count($employeeIds);

            if ($createdCount === 0) {
                if ($calendarSkipMessage !== '') {
                    $toastMessage = $calendarSkipMessage;
                    $skippedReasons = '';
                } else {
                    $toastMessage .= ' | ' . tr('No penalties were created for the selected scope.');
                }

                if ($skippedReasons === '' && $calendarSkipMessage === '') {
                    $skippedReasons = tr('No billable violation was found or the records are outside the active penalty rules.');
                }
            }

            if ($skippedReasons !== '') {
                $toastMessage .= ' | ' . tr('Skipped:') . ' ' . $skippedReasons;
            }

            $this->dispatch('toast', [
                'type' => $toastType,
                'message' => $toastMessage,
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

    public function formatExemptionReason(?string $reason): string
    {
        $reason = trim($this->cleanAuditText((string) $reason));

        if ($reason === '') {
            return '-';
        }

        [$rawKey, $details] = array_pad(explode(' - ', $reason, 2), 2, '');
        $key = strtolower(str_replace(' ', '_', trim($rawKey)));
        $label = $this->exemptionReasonLabel($key, trim($rawKey));
        $details = trim($this->cleanAuditText((string) $details));

        return $details !== ''
            ? $label . ' - ' . $details
            : $label;
    }

    private function exemptionReasonLabel(string $key, string $fallback = ''): string
    {
        $isArabic = str_starts_with((string) app()->getLocale(), 'ar');
        $labels = [
            'business_mission' => ['ar' => "\u{0645}\u{0647}\u{0645}\u{0629} \u{0639}\u{0645}\u{0644}", 'en' => 'Business mission'],
            'emergency_case' => ['ar' => "\u{062D}\u{0627}\u{0644}\u{0629} \u{0637}\u{0627}\u{0631}\u{0626}\u{0629}", 'en' => 'Emergency case'],
            'technical_issue' => ['ar' => "\u{0645}\u{0634}\u{0643}\u{0644}\u{0629} \u{0641}\u{0646}\u{064A}\u{0629} / \u{062C}\u{0647}\u{0627}\u{0632}", 'en' => 'Technical / device issue'],
            'late_permission' => ['ar' => "\u{0625}\u{0630}\u{0646} \u{062A}\u{0623}\u{062E}\u{064A}\u{0631} / \u{0645}\u{063A}\u{0627}\u{062F}\u{0631}\u{0629}", 'en' => 'Late permission / leave'],
            'medical_emergency' => ['ar' => "\u{062D}\u{0627}\u{0644}\u{0629} \u{0637}\u{0628}\u{064A}\u{0629} \u{0637}\u{0627}\u{0631}\u{0626}\u{0629}", 'en' => 'Medical emergency'],
            'other' => ['ar' => "\u{0623}\u{062E}\u{0631}\u{0649}", 'en' => 'Other'],
        ];

        if (isset($labels[$key])) {
            return $labels[$key][$isArabic ? 'ar' : 'en'];
        }

        $fallback = trim($this->cleanAuditText($fallback));

        return $fallback !== ''
            ? $this->humanizeAuditToken($fallback)
            : '-';
    }

    public function hasActiveExemption(AttendanceDailyPenalty $penalty): bool
    {
        return (float) $penalty->exemption_amount > 0
            || (string) $penalty->exemption_status === 'approved';
    }

    public function hasExemptionHistory(AttendanceDailyPenalty $penalty): bool
    {
        return ! empty($this->parseExemptionHistory((string) $penalty->notes));
    }

    public function penaltyUiText(string $ar, string $en): string
    {
        if (! str_starts_with((string) app()->getLocale(), 'ar')) {
            return $en;
        }

        return match ($en) {
            'Exemption applied' => "\u{062A}\u{0645} \u{062A}\u{0637}\u{0628}\u{064A}\u{0642} \u{0627}\u{0644}\u{0625}\u{0639}\u{0641}\u{0627}\u{0621}",
            'Exemption cancelled' => "\u{062A}\u{0645} \u{0625}\u{0644}\u{063A}\u{0627}\u{0621} \u{0627}\u{0644}\u{0625}\u{0639}\u{0641}\u{0627}\u{0621}",
            'Previous exemption archived' => "\u{062A}\u{0645}\u{062A} \u{0623}\u{0631}\u{0634}\u{0641}\u{0629} \u{0627}\u{0644}\u{0625}\u{0639}\u{0641}\u{0627}\u{0621} \u{0627}\u{0644}\u{0633}\u{0627}\u{0628}\u{0642}",
            'Exemption Type' => "\u{0646}\u{0648}\u{0639} \u{0627}\u{0644}\u{0625}\u{0639}\u{0641}\u{0627}\u{0621}",
            'Exempt Amount' => "\u{0645}\u{0628}\u{0644}\u{063A} \u{0627}\u{0644}\u{0625}\u{0639}\u{0641}\u{0627}\u{0621}",
            'Net Amount' => "\u{0627}\u{0644}\u{0645}\u{0628}\u{0644}\u{063A} \u{0627}\u{0644}\u{0635}\u{0627}\u{0641}\u{064A}",
            'Reason' => "\u{0627}\u{0644}\u{0633}\u{0628}\u{0628}",
            'Exempted At' => "\u{062A}\u{0627}\u{0631}\u{064A}\u{062E} \u{0627}\u{0644}\u{0625}\u{0639}\u{0641}\u{0627}\u{0621}",
            'Full Waiver (100%)' => "\u{0627}\u{0644}\u{062A}\u{0646}\u{0627}\u{0632}\u{0644} \u{0627}\u{0644}\u{0643}\u{0627}\u{0645}\u{0644} (100%)",
            'Partial Exemption' => "\u{0625}\u{0639}\u{0641}\u{0627}\u{0621} \u{062C}\u{0632}\u{0626}\u{064A}",
            'None' => "\u{0644}\u{0627} \u{064A}\u{0648}\u{062C}\u{062F}",
            'Cancel the current exemption before creating a new one.' => "\u{0642}\u{0645} \u{0628}\u{0625}\u{0644}\u{063A}\u{0627}\u{0621} \u{0627}\u{0644}\u{0625}\u{0639}\u{0641}\u{0627}\u{0621} \u{0627}\u{0644}\u{062D}\u{0627}\u{0644}\u{064A} \u{0642}\u{0628}\u{0644} \u{0625}\u{0646}\u{0634}\u{0627}\u{0621} \u{0625}\u{0639}\u{0641}\u{0627}\u{0621} \u{062C}\u{062F}\u{064A}\u{062F}.",
            'Exemption History' => "\u{0633}\u{062C}\u{0644} \u{0627}\u{0644}\u{0625}\u{0639}\u{0641}\u{0627}\u{0621}\u{0627}\u{062A}",
            'By' => "\u{0628}\u{0648}\u{0627}\u{0633}\u{0637}\u{0629}",
            'No exemption history found.' => "\u{0644}\u{0627} \u{064A}\u{0648}\u{062C}\u{062F} \u{0633}\u{062C}\u{0644} \u{0625}\u{0639}\u{0641}\u{0627}\u{0621}\u{0627}\u{062A}.",
            'Close' => "\u{0625}\u{063A}\u{0644}\u{0627}\u{0642}",
            default => $ar,
        };
    }

    public function openExemptionHistoryModal($id): void
    {
        $this->requireAttendanceAny([
            'attendance.penalties.view',
            'attendance.penalties.view-subordinates',
            'attendance.penalties.manage',
            'attendance.penalties.waive',
        ]);

        $penalty = $this->findPenaltyOrFail((int) $id);

        $this->exemptionHistoryPenaltyPreview = [
            'employee_name' => $penalty->employee?->name_ar ?: ($penalty->employee?->name_en ?: '-'),
            'employee_no' => $penalty->employee?->employee_no ?: '-',
            'attendance_date' => $penalty->attendance_date,
        ];
        $this->exemptionHistory = $this->parseExemptionHistory((string) $penalty->notes);
        $this->showExemptionHistoryModal = true;
    }

    public function closeExemptionHistoryModal(): void
    {
        $this->showExemptionHistoryModal = false;
        $this->exemptionHistoryPenaltyPreview = null;
        $this->exemptionHistory = [];
    }

    private function parseExemptionHistory(string $notes): array
    {
        $history = [];

        foreach (preg_split('/\R/', $notes) ?: [] as $line) {
            $line = trim((string) $line);

            if (! str_starts_with($line, '[Audit] ')) {
                continue;
            }

            $body = substr($line, 8);
            $entry = null;

            if (preg_match('/^Exemption applied by (.*?) at (.*?) \| (.*)$/', $body, $matches)) {
                $entry = [
                    'title' => $this->penaltyUiText('تم تطبيق الإعفاء', 'Exemption applied'),
                    'actor' => $matches[1] ?: '-',
                    'date' => $matches[2] ?: '-',
                    'icon' => 'fa-gift',
                    'badge' => 'success',
                    'details' => $this->parseExemptionHistoryDetails($matches[3] ?? ''),
                ];
            } elseif (preg_match('/^Exemption cancelled by (.*?) at (.*?) \| (.*)$/', $body, $matches)) {
                $entry = [
                    'title' => $this->penaltyUiText('تم إلغاء الإعفاء', 'Exemption cancelled'),
                    'actor' => $matches[1] ?: '-',
                    'date' => $matches[2] ?: '-',
                    'icon' => 'fa-undo',
                    'badge' => 'danger',
                    'details' => $this->parseExemptionHistoryDetails($matches[3] ?? ''),
                ];
            } elseif (preg_match('/^Previous exemption archived before replacement at (.*?) \| (.*)$/', $body, $matches)) {
                $entry = [
                    'title' => $this->penaltyUiText('تمت أرشفة الإعفاء السابق', 'Previous exemption archived'),
                    'actor' => '-',
                    'date' => $matches[1] ?: '-',
                    'icon' => 'fa-archive',
                    'badge' => 'warning',
                    'details' => $this->parseExemptionHistoryDetails($matches[2] ?? ''),
                ];
            }

            if ($entry) {
                $history[] = $entry;
            }
        }

        return array_reverse($history);
    }

    private function parseExemptionHistoryDetails(string $details): array
    {
        $parsed = [];
        $details = $this->cleanAuditText($details);

        foreach (preg_split('/,\s*/', trim($details)) ?: [] as $part) {
            [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
            $key = trim($this->cleanAuditText((string) $key));
            $value = trim($this->cleanAuditText((string) $value));

            if ($key === '') {
                continue;
            }

            $parsed[] = [
                'label' => $this->exemptionHistoryDetailLabel($key),
                'value' => $this->formatExemptionHistoryValue($key, $value),
            ];
        }

        return $parsed;
    }

    private function exemptionHistoryDetailLabel(string $key): string
    {
        return match ($key) {
            'type', 'previous_type' => $this->penaltyUiText('نوع الإعفاء', 'Exemption Type'),
            'amount', 'previous_amount' => $this->penaltyUiText('مبلغ الإعفاء', 'Exempt Amount'),
            'net', 'previous_net' => $this->penaltyUiText('المبلغ الصافي', 'Net Amount'),
            'reason', 'previous_reason' => $this->penaltyUiText('السبب', 'Reason'),
            'exempted_at' => $this->penaltyUiText('تاريخ الإعفاء', 'Exempted At'),
            default => $this->humanizeAuditToken($key),
        };
    }

    private function formatExemptionHistoryValue(string $key, string $value): string
    {
        $value = trim($this->cleanAuditText($value));

        if (in_array($key, ['amount', 'previous_amount', 'net', 'previous_net'], true) && is_numeric($value)) {
            return number_format((float) $value, 2);
        }

        if (in_array($key, ['type', 'previous_type'], true)) {
            return match ($value) {
                'full' => $this->penaltyUiText('التنازل الكامل (100%)', 'Full Waiver (100%)'),
                'partial' => $this->penaltyUiText('إعفاء جزئي', 'Partial Exemption'),
                'none' => $this->penaltyUiText('لا يوجد', 'None'),
                default => $value !== '' ? $value : '-',
            };
        }

        if (in_array($key, ['reason', 'previous_reason'], true)) {
            return $this->formatExemptionReason($value);
        }

        return $value !== '' ? $value : '-';
    }

    private function humanizeAuditToken(string $value): string
    {
        $value = trim($this->cleanAuditText($value));

        if ($value === '') {
            return '-';
        }

        $value = str_replace(['_', '-'], ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return ucwords($value);
    }

    private function cleanAuditText(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (function_exists('mb_check_encoding') && mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        foreach (['Windows-1256', 'ISO-8859-6', 'Windows-1252', 'ISO-8859-1'] as $encoding) {
            if (! function_exists('mb_convert_encoding')) {
                break;
            }

            $converted = @mb_convert_encoding($value, 'UTF-8', $encoding);

            if (is_string($converted) && $converted !== '' && mb_check_encoding($converted, 'UTF-8')) {
                return $converted;
            }
        }

        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $value);

            if (is_string($converted)) {
                return $converted;
            }
        }

        return preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $value) ?? '';
    }

    private function formatSkippedReasons(array $skipped): string
    {
        if (empty($skipped)) {
            return '';
        }

        arsort($skipped);

        $labels = [
            'no_matching_absence_policy' => tr('No matching absence penalty policy'),
            'no_matching_penalty_policy' => tr('No matching penalty policy'),
            'covered_by_grace' => tr('Covered by grace allowance'),
            'approved_permission' => tr('Approved permission exists'),
            'no_effective_schedule' => tr('No effective work schedule'),
            'no_active_employees' => tr('No active employees in scope'),
            'employee_not_found_or_inactive' => tr('Employee is inactive or unavailable'),
            'no_attendance_policy' => tr('No attendance policy'),
            'no_billable_violation' => tr('No billable violation'),
            'already_confirmed' => tr('Already confirmed'),
            'zero_violation_minutes' => tr('Zero violation minutes'),
            'policy_action_not_deduction' => tr('Policy action is not deduction'),
            'absence_action_not_deduction' => tr('Absence action is not deduction'),
            'zero_penalty_amount' => tr('Zero penalty amount'),
            'zero_absence_amount' => tr('Zero absence amount'),
        ];

        return collect($skipped)
            ->take(3)
            ->map(fn ($count, $reason) => ($labels[$reason] ?? $reason) . ' (' . $count . ')')
            ->implode(', ');
    }

    private function calendarSkipMessage(int $companyId, string $dateFrom, string $dateTo, array $employeeIds, int $createdCount): string
    {
        if ($createdCount > 0 || empty($employeeIds)) {
            return '';
        }

        $summary = $this->calendarSkipSummary($companyId, $dateFrom, $dateTo, $employeeIds);

        if (
            ($summary['official_holiday'] ?? 0) <= 0
            && ($summary['exceptional_day'] ?? 0) <= 0
            && ($summary['weekly_off'] ?? 0) <= 0
            && ($summary['no_schedule'] ?? 0) <= 0
        ) {
            return '';
        }

        $isSingleDay = Carbon::parse($dateFrom)->toDateString() === Carbon::parse($dateTo)->toDateString();
        $isArabic = str_starts_with((string) app()->getLocale(), 'ar');

        if ($isSingleDay) {
            if (($summary['official_holiday'] ?? 0) > 0) {
                return $isArabic
                    ? "\u{0627}\u{0644}\u{064A}\u{0648}\u{0645} \u{0639}\u{0637}\u{0644}\u{0629} \u{0631}\u{0633}\u{0645}\u{064A}\u{0629}\u{060C} \u{0644}\u{0627} \u{064A}\u{062A}\u{0645} \u{0627}\u{062D}\u{062A}\u{0633}\u{0627}\u{0628} \u{0627}\u{0644}\u{062C}\u{0632}\u{0627}\u{0621}\u{0627}\u{062A}."
                    : 'This day is an official holiday; penalties are not calculated.';
            }

            if (($summary['exceptional_day'] ?? 0) > 0) {
                return $isArabic
                    ? "\u{0627}\u{0644}\u{064A}\u{0648}\u{0645} \u{0645}\u{0639}\u{062A}\u{0645}\u{062F} \u{0643}\u{064A}\u{0648}\u{0645} \u{0627}\u{0633}\u{062A}\u{062B}\u{0646}\u{0627}\u{0626}\u{064A}\u{060C} \u{0644}\u{0627} \u{064A}\u{062A}\u{0645} \u{0627}\u{062D}\u{062A}\u{0633}\u{0627}\u{0628} \u{0627}\u{0644}\u{062C}\u{0632}\u{0627}\u{0621}\u{0627}\u{062A}."
                    : 'This day is approved as an exceptional day; penalties are not calculated.';
            }

            if (($summary['weekly_off'] ?? 0) > 0) {
                return $isArabic
                    ? "\u{0627}\u{0644}\u{064A}\u{0648}\u{0645} \u{064A}\u{0648}\u{0645} \u{0631}\u{0627}\u{062D}\u{0629} \u{0623}\u{0633}\u{0628}\u{0648}\u{0639}\u{064A}\u{0629}\u{060C} \u{0644}\u{0627} \u{064A}\u{062A}\u{0645} \u{0627}\u{062D}\u{062A}\u{0633}\u{0627}\u{0628} \u{0627}\u{0644}\u{062C}\u{0632}\u{0627}\u{0621}\u{0627}\u{062A}."
                    : 'This day is a weekly day off; penalties are not calculated.';
            }

            return $isArabic
                ? "\u{0644}\u{0627} \u{064A}\u{0648}\u{062C}\u{062F} \u{062C}\u{062F}\u{0648}\u{0644} \u{0639}\u{0645}\u{0644} \u{0646}\u{0634}\u{0637} \u{0644}\u{0647}\u{0630}\u{0627} \u{0627}\u{0644}\u{064A}\u{0648}\u{0645}\u{060C} \u{0644}\u{0627} \u{064A}\u{062A}\u{0645} \u{0627}\u{062D}\u{062A}\u{0633}\u{0627}\u{0628} \u{0627}\u{0644}\u{062C}\u{0632}\u{0627}\u{0621}\u{0627}\u{062A}."
                : 'No active work schedule exists for this day; penalties are not calculated.';
        }

        $parts = [];

        if (($summary['official_holiday'] ?? 0) > 0) {
            $parts[] = ($isArabic ? "\u{0639}\u{0637}\u{0644}\u{0629} \u{0631}\u{0633}\u{0645}\u{064A}\u{0629}" : 'official holiday') . ' (' . $summary['official_holiday'] . ')';
        }

        if (($summary['exceptional_day'] ?? 0) > 0) {
            $parts[] = ($isArabic ? "\u{064A}\u{0648}\u{0645} \u{0627}\u{0633}\u{062A}\u{062B}\u{0646}\u{0627}\u{0626}\u{064A}" : 'exceptional day') . ' (' . $summary['exceptional_day'] . ')';
        }

        if (($summary['weekly_off'] ?? 0) > 0) {
            $parts[] = ($isArabic ? "\u{0631}\u{0627}\u{062D}\u{0629} \u{0623}\u{0633}\u{0628}\u{0648}\u{0639}\u{064A}\u{0629}" : 'weekly day off') . ' (' . $summary['weekly_off'] . ')';
        }

        if (($summary['no_schedule'] ?? 0) > 0) {
            $parts[] = ($isArabic ? "\u{0628}\u{062F}\u{0648}\u{0646} \u{062C}\u{062F}\u{0648}\u{0644} \u{0639}\u{0645}\u{0644}" : 'no work schedule') . ' (' . $summary['no_schedule'] . ')';
        }

        return $isArabic
            ? "\u{0644}\u{0627} \u{064A}\u{062A}\u{0645} \u{0627}\u{062D}\u{062A}\u{0633}\u{0627}\u{0628} \u{0627}\u{0644}\u{062C}\u{0632}\u{0627}\u{0621}\u{0627}\u{062A} \u{0644}\u{0644}\u{0623}\u{064A}\u{0627}\u{0645} \u{0627}\u{0644}\u{062A}\u{0627}\u{0644}\u{064A}\u{0629}: " . implode("\u{060C} ", $parts) . '.'
            : 'Penalties are not calculated for the following days: ' . implode(', ', $parts) . '.';
    }

    private function calendarSkipSummary(int $companyId, string $dateFrom, string $dateTo, array $employeeIds): array
    {
        $scheduleService = app(WorkScheduleService::class);
        $employees = Employee::withoutGlobalScope('active_only')
            ->whereIn('id', $employeeIds)
            ->get()
            ->keyBy('id');

        $summary = [
            'official_holiday' => 0,
            'exceptional_day' => 0,
            'weekly_off' => 0,
            'no_schedule' => 0,
        ];

        $cursor = Carbon::parse($dateFrom)->startOfDay();
        $end = Carbon::parse($dateTo)->startOfDay();

        while ($cursor->lte($end)) {
            $dateStr = $cursor->toDateString();
            $dayReason = null;
            $dayReasons = [];
            $hasWorkingSchedule = false;
            $holidays = $scheduleService->getHolidays($companyId, $dateStr, $dateStr);
            $employeeTotal = $employees->count();

            foreach ($employeeIds as $employeeId) {
                $employee = $employees->get((int) $employeeId);
                if (! $employee) {
                    continue;
                }

                $exceptionalDay = $scheduleService->getExceptionalDay($companyId, $dateStr, $employee);

                if ($exceptionalDay && (bool) ($exceptionalDay->is_holiday ?? true)) {
                    $dayReasons[] = (bool) ($exceptionalDay->is_official_holiday ?? false)
                        ? 'official_holiday'
                        : 'exceptional_day';
                    continue;
                }

                $schedule = $scheduleService->getEffectiveSchedule($companyId, $employee, $dateStr);
                $metrics = $scheduleService->getMetricsForDate($dateStr, $schedule, $holidays, $employee);
                $status = (string) ($metrics['status'] ?? '');

                if ($status === 'holiday') {
                    $dayReasons[] = 'official_holiday';
                    continue;
                }

                if ($status === 'workday') {
                    $hasWorkingSchedule = true;
                    break;
                }

                if ($status === 'off') {
                    $dayReasons[] = 'weekly_off';
                    continue;
                }

                if ($status === 'no_schedule') {
                    $dayReasons[] = 'no_schedule';
                }
            }

            if (! $hasWorkingSchedule && $employeeTotal > 0 && count($dayReasons) === $employeeTotal) {
                foreach (['official_holiday', 'exceptional_day', 'weekly_off', 'no_schedule'] as $reason) {
                    if (in_array($reason, $dayReasons, true)) {
                        $dayReason = $reason;
                        break;
                    }
                }
            }

            if ($dayReason) {
                $summary[$dayReason]++;
            }

            $cursor->addDay();
        }

        return $summary;
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

        if ($this->hasActiveExemption($penalty)) {
            $this->dispatch('toast', [
                'type' => 'warning',
                'message' => $this->penaltyUiText('قم بإلغاء الإعفاء الحالي قبل إنشاء إعفاء جديد.', 'Cancel the current exemption before creating a new one.')
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

        $localizedReason = $this->formatExemptionReason($reason);

        $notes = trim((string) $penalty->notes);

        if (
            (string) $penalty->exemption_status === 'approved'
            || (float) $penalty->exemption_amount > 0
        ) {
            $notes = trim(
                $notes
                . "\n[Audit] Previous exemption archived before replacement at "
                . now()
                . sprintf(
                    ' | type=%s, amount=%.2f, net=%.2f, reason=%s, exempted_at=%s',
                    (string) ($penalty->exemption_type ?? '-'),
                    (float) $penalty->exemption_amount,
                    (float) $penalty->net_amount,
                    $this->formatExemptionReason($penalty->exemption_reason),
                    $penalty->exempted_at ? (string) $penalty->exempted_at : '-'
                )
            );
        }

        $notes = trim(
            $notes
            . "\n[Audit] Exemption applied by "
            . auth()->user()->name
            . ' at '
            . now()
            . sprintf(
                ' | type=%s, amount=%.2f, net=%.2f, reason=%s',
                (string) $this->exemptionForm['type'],
                (float) $exemptAmount,
                max(0, $maximumAmount - $exemptAmount),
                $localizedReason
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
            'notes' => $notes,
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

    public function cancelExemption($id)
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

        if ((float) $penalty->exemption_amount <= 0 && (string) $penalty->exemption_status !== 'approved') {
            $this->dispatch('toast', [
                'type' => 'warning',
                'message' => tr('No active exemption was found for this penalty.')
            ]);

            return;
        }

        $notes = trim(
            (string) $penalty->notes
            . "\n[Audit] Exemption cancelled by "
            . auth()->user()->name
            . ' at '
            . now()
            . sprintf(
                ' | previous_type=%s, previous_amount=%.2f, previous_net=%.2f, previous_reason=%s',
                (string) ($penalty->exemption_type ?? '-'),
                (float) $penalty->exemption_amount,
                (float) $penalty->net_amount,
                $this->formatExemptionReason($penalty->exemption_reason)
            )
        );

        $penalty->update([
            'exemption_type' => 'none',
            'exemption_amount' => 0,
            'net_amount' => (float) $penalty->calculated_amount,
            'exemption_status' => 'none',
            'exemption_reason' => null,
            'exemption_attachment' => null,
            'exempted_by' => null,
            'exempted_at' => null,
            'status' => 'pending',
            'notes' => $notes,
        ]);

        $this->refreshData();

        $this->dispatch('toast', [
            'type' => 'info',
            'message' => tr('Exemption cancelled.')
        ]);
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

        $sevenDaysAgo = now()->subDays(7)->toDateString();

        $query = $this->buildPenaltiesQuery(false)
            ->whereIn('id', array_map('intval', $this->selectedPenalties))
            ->where('status', '!=', 'confirmed')
            ->whereDate('attendance_date', '>=', $sevenDaysAgo);

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


