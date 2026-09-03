<?php

namespace Athka\Attendance\Http\Livewire\DailyPenalties;

use Livewire\Component;
use Livewire\WithPagination;
use App\Services\ExcelExportService;
use Athka\Attendance\Models\AttendanceDailyLog;
use Athka\Attendance\Models\AttendanceDailyPenalty;
use Athka\Attendance\Models\AttendancePenaltyExemptionHistory;
use Athka\Employees\Models\Employee;
use Athka\SystemSettings\Models\Department;
use Athka\SystemSettings\Models\JobTitle;
use Athka\SystemSettings\Models\AttendancePolicy;
use Athka\SystemSettings\Models\AttendancePenaltyPolicy;
use Athka\SystemSettings\Models\Currency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    public function manualRefreshData()
    {
        $this->refreshData();

        $this->dispatch('toast', [
            'type' => 'success',
            'message' => $this->penaltyUiText('', 'Penalty results refreshed.'),
        ]);
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
            $attendanceWarningMessage = $this->formatAttendanceWarnings(
                $res['warnings'] ?? []
            );
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

            if ($attendanceWarningMessage !== '') {
                $toastMessage .= ' | ' . $attendanceWarningMessage;
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


    private function formatAttendanceWarnings(array $warnings): string
    {
        $messages = [];

        $invalidOrder = (int) (
            $warnings['invalid_attendance_order'] ?? 0
        );

        if ($invalidOrder > 0) {
            $messages[] = str_starts_with(
                (string) app()->getLocale(),
                'ar'
            )
                ? "وقت الحضور بعد وقت الانصراف؛ تم اعتبار الموظف غائبًا عند حساب الجزاء. ({$invalidOrder})"
                : "Check-in is after check-out; the employee was treated as absent for penalty calculation. ({$invalidOrder})";
        }

        $afterSchedule = (int) (
            $warnings['attendance_after_schedule_end'] ?? 0
        );

        if ($afterSchedule > 0) {
            $messages[] = str_starts_with(
                (string) app()->getLocale(),
                'ar'
            )
                ? "لا يمكن تسجيل الحضور بعد انتهاء الجدول وتجاوز حد الغياب المسموح؛ تم اعتبار الموظف غائبًا. ({$afterSchedule})"
                : "Check-in exceeded the allowed absence threshold after the schedule ended; the employee was treated as absent. ({$afterSchedule})";
        }

        return implode(' | ', $messages);
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
        return $this->hasActiveExemption($penalty)
            || ! empty($this->parseExemptionHistory((string) $penalty->notes, $penalty))
            || str_contains((string) $penalty->notes, '[Audit]')
            || str_contains((string) $penalty->notes, 'Exemption')
            || ! empty($penalty->exempted_by);
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
            'Cancel Confirmation' => "\u{0625}\u{0644}\u{063A}\u{0627}\u{0621} \u{0627}\u{0644}\u{0627}\u{0639}\u{062A}\u{0645}\u{0627}\u{062F}",
            'Only confirmed penalties can be unconfirmed.' => "\u{064A}\u{0645}\u{0643}\u{0646} \u{0625}\u{0644}\u{063A}\u{0627}\u{0621} \u{0627}\u{0639}\u{062A}\u{0645}\u{0627}\u{062F} \u{0627}\u{0644}\u{062C}\u{0632}\u{0627}\u{0621}\u{0627}\u{062A} \u{0627}\u{0644}\u{0645}\u{0639}\u{062A}\u{0645}\u{062F}\u{0629} \u{0641}\u{0642}\u{0637}.",
            'Penalty confirmation cancelled.' => "\u{062A}\u{0645} \u{0625}\u{0644}\u{063A}\u{0627}\u{0621} \u{0627}\u{0639}\u{062A}\u{0645}\u{0627}\u{062F} \u{0627}\u{0644}\u{062C}\u{0632}\u{0627}\u{0621}.",
            'Only pending or waived penalties can be confirmed.' => "\u{064A}\u{0645}\u{0643}\u{0646} \u{0627}\u{0639}\u{062A}\u{0645}\u{0627}\u{062F} \u{0627}\u{0644}\u{062C}\u{0632}\u{0627}\u{0621}\u{0627}\u{062A} \u{0642}\u{064A}\u{062F} \u{0627}\u{0644}\u{0627}\u{0646}\u{062A}\u{0638}\u{0627}\u{0631} \u{0623}\u{0648} \u{0627}\u{0644}\u{0645}\u{062A}\u{0646}\u{0627}\u{0632}\u{0644} \u{0639}\u{0646}\u{0647}\u{0627} \u{0641}\u{0642}\u{0637}.",
            'Penalties cannot be deleted. Use exemption or update attendance then recalculate.' => "\u{0644}\u{0627} \u{064A}\u{0645}\u{0643}\u{0646} \u{062D}\u{0630}\u{0641} \u{0627}\u{0644}\u{062C}\u{0632}\u{0627}\u{0621}\u{0627}\u{062A}. \u{0627}\u{0633}\u{062A}\u{062E}\u{062F}\u{0645} \u{0627}\u{0644}\u{0625}\u{0639}\u{0641}\u{0627}\u{0621} \u{0623}\u{0648} \u{0639}\u{062F}\u{0644} \u{0633}\u{062C}\u{0644} \u{0627}\u{0644}\u{062D}\u{0636}\u{0648}\u{0631} \u{062B}\u{0645} \u{0623}\u{0639}\u{062F} \u{0627}\u{0644}\u{0627}\u{062D}\u{062A}\u{0633}\u{0627}\u{0628}.",
            'Penalty results refreshed.' => "\u{062A}\u{0645} \u{062A}\u{062D}\u{062F}\u{064A}\u{062B} \u{0646}\u{062A}\u{0627}\u{0626}\u{062C} \u{0627}\u{0644}\u{062C}\u{0632}\u{0627}\u{0621}\u{0627}\u{062A}.",
            'Employee' => "\u{0627}\u{0644}\u{0645}\u{0648}\u{0638}\u{0641}",
            'Dept/Job' => "\u{0627}\u{0644}\u{0642}\u{0633}\u{0645}/\u{0627}\u{0644}\u{0648}\u{0638}\u{064A}\u{0641}\u{0629}",
            'Schedule Time' => "\u{062C}\u{062F}\u{0648}\u{0644}\u{0629} \u{0627}\u{0644}\u{0648}\u{0642}\u{062A}",
            'Actual Time' => "\u{0627}\u{0644}\u{0648}\u{0642}\u{062A} \u{0627}\u{0644}\u{0641}\u{0639}\u{0644}\u{064A}",
            'Violation' => "\u{0627}\u{0644}\u{0645}\u{062E}\u{0627}\u{0644}\u{0641}\u{0629}",
            'Duration' => "\u{0627}\u{0644}\u{0645}\u{062F}\u{0629}",
            'Calculated' => "\u{0645}\u{062D}\u{0633}\u{0648}\u{0628}",
            'Exemption' => "\u{0627}\u{0644}\u{0625}\u{0639}\u{0641}\u{0627}\u{0621}",
            'Status' => "\u{0627}\u{0644}\u{062D}\u{0627}\u{0644}\u{0629}",
            'Delay' => "\u{062A}\u{0623}\u{062E}\u{064A}\u{0631}",
            'Early Departure' => "\u{0627}\u{0644}\u{0645}\u{063A}\u{0627}\u{062F}\u{0631}\u{0629} \u{0627}\u{0644}\u{0645}\u{0628}\u{0643}\u{0631}\u{0629}",
            'Absent' => "\u{063A}\u{0627}\u{0626}\u{0628}",
            'Auto Checkout' => "\u{0627}\u{0644}\u{0627}\u{0646}\u{0635}\u{0631}\u{0627}\u{0641} \u{0627}\u{0644}\u{062A}\u{0644}\u{0642}\u{0627}\u{0626}\u{064A}",
            'Pending' => "\u{0642}\u{064A}\u{062F} \u{0627}\u{0644}\u{0627}\u{0646}\u{062A}\u{0638}\u{0627}\u{0631}",
            'Confirmed' => "\u{062A}\u{0645} \u{0627}\u{0644}\u{0627}\u{0639}\u{062A}\u{0645}\u{0627}\u{062F}",
            'Waived' => "\u{062A}\u{0646}\u{0627}\u{0632}\u{0644}",
            'min' => "\u{062F}\u{0642}\u{064A}\u{0642}\u{0629}",
            'YER' => "\u{0631}.\u{064A}",
            'By' => "\u{0628}\u{0648}\u{0627}\u{0633}\u{0637}\u{0629}",
            'Attachment' => "\u{0627}\u{0644}\u{0645}\u{0631}\u{0641}\u{0642}",
            'View Attachment' => "\u{0639}\u{0631}\u{0636} \u{0627}\u{0644}\u{0645}\u{0631}\u{0641}\u{0642}",
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

        $dbRecords = AttendancePenaltyExemptionHistory::with(['appliedBy', 'cancelledBy'])
            ->where('attendance_daily_penalty_id', $penalty->id)
            ->orderBy('id', 'desc')
            ->get();

        if ($dbRecords->isNotEmpty()) {
            $this->exemptionHistory = $dbRecords->map(function ($record) use ($penalty) {
                $isApplied = $record->action === 'applied';
                $actorUser = $isApplied ? $record->appliedBy : $record->cancelledBy;
                $actorName = $actorUser ? $actorUser->name : '-';
                $eventDate = $isApplied ? $record->applied_at : ($record->cancelled_at ?: $record->created_at);

                $attachmentUrl = null;
                if ($record->attachment_path) {
                    $attachmentUrl = route('secure.attendance.attachments.penalty', [
                        'penalty' => $penalty->id,
                        'path' => $record->attachment_path,
                    ]);
                }

                return [
                    'id' => $record->id,
                    'action' => $record->action,
                    'status' => $record->status,
                    'status_label' => $record->status === 'active'
                        ? $this->penaltyUiText('فعال', 'Active')
                        : $this->penaltyUiText('تم الإلغاء', 'Cancelled'),
                    'title' => $isApplied
                        ? $this->penaltyUiText('تم تطبيق الإعفاء', 'Exemption applied')
                        : $this->penaltyUiText('تم إلغاء الإعفاء', 'Exemption cancelled'),
                    'icon' => $isApplied ? 'fa-gift' : 'fa-ban',
                    'actor' => $actorName,
                    'date' => $eventDate ? company_date($eventDate, 'Y-m-d H:i:s') : '-',
                    'exemption_type' => match ($record->exemption_type) {
                        'full' => $this->penaltyUiText('التنازل الكامل (100%)', 'Full Waiver (100%)'),
                        'partial' => $this->penaltyUiText('إعفاء جزئي', 'Partial Exemption'),
                        default => $record->exemption_type ?: '-',
                    },
                    'exemption_amount' => number_format((float) $record->exemption_amount, 2) . ' ' . $this->penaltyUiText('ر.ي', 'YER'),
                    'net_amount' => number_format((float) $record->net_amount, 2) . ' ' . $this->penaltyUiText('ر.ي', 'YER'),
                    'reason' => $this->formatExemptionReason($record->reason),
                    'attachment_url' => $attachmentUrl,
                    'attachment_name' => $record->attachment_path ? basename($record->attachment_path) : null,
                ];
            })->toArray();
        } else {
            $this->exemptionHistory = $this->parseExemptionHistory((string) $penalty->notes, $penalty);

            if (empty($this->exemptionHistory) && $this->hasActiveExemption($penalty)) {
                $this->exemptionHistory = [$this->buildCurrentExemptionHistoryEntry($penalty)];
            }

            if (
                $penalty->exemption_attachment
                && ! empty($this->exemptionHistory)
                && ! $this->historyContainsAttachment($this->exemptionHistory)
            ) {
                $this->exemptionHistory[0]['details'][] = $this->exemptionAttachmentHistoryDetail(
                    $penalty,
                    (string) $penalty->exemption_attachment
                );
            }
        }

        $this->showExemptionHistoryModal = true;
    }

    public function closeExemptionHistoryModal(): void
    {
        $this->showExemptionHistoryModal = false;
        $this->exemptionHistoryPenaltyPreview = null;
        $this->exemptionHistory = [];
    }

    private function parseExemptionHistory(string $notes, ?AttendanceDailyPenalty $penalty = null): array
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
                    'details' => $this->parseExemptionHistoryDetails($matches[3] ?? '', $penalty),
                ];
            } elseif (preg_match('/^Exemption cancelled by (.*?) at (.*?) \| (.*)$/', $body, $matches)) {
                $entry = [
                    'title' => $this->penaltyUiText('تم إلغاء الإعفاء', 'Exemption cancelled'),
                    'actor' => $matches[1] ?: '-',
                    'date' => $matches[2] ?: '-',
                    'icon' => 'fa-undo',
                    'badge' => 'danger',
                    'details' => $this->parseExemptionHistoryDetails($matches[3] ?? '', $penalty),
                ];
            } elseif (preg_match('/^Previous exemption archived before replacement at (.*?) \| (.*)$/', $body, $matches)) {
                $entry = [
                    'title' => $this->penaltyUiText('تمت أرشفة الإعفاء السابق', 'Previous exemption archived'),
                    'actor' => '-',
                    'date' => $matches[1] ?: '-',
                    'icon' => 'fa-archive',
                    'badge' => 'warning',
                    'details' => $this->parseExemptionHistoryDetails($matches[2] ?? '', $penalty),
                ];
            }

            if ($entry) {
                $history[] = $entry;
            }
        }

        return array_reverse($history);
    }

    private function buildCurrentExemptionHistoryEntry(AttendanceDailyPenalty $penalty): array
    {
        $details = sprintf(
            'type=%s, amount=%.2f, net=%.2f, reason=%s, exempted_at=%s%s',
            (string) ($penalty->exemption_type ?? '-'),
            (float) $penalty->exemption_amount,
            (float) $penalty->net_amount,
            $this->formatExemptionReason($penalty->exemption_reason),
            $penalty->exempted_at ? (string) $penalty->exempted_at : '-',
            $penalty->exemption_attachment ? ', attachment=' . $penalty->exemption_attachment : ''
        );

        return [
            'title' => $this->penaltyUiText('', 'Exemption applied'),
            'actor' => $penalty->exemptedBy?->name ?: '-',
            'date' => $penalty->exempted_at ? (string) $penalty->exempted_at : '-',
            'icon' => 'fa-gift',
            'badge' => 'success',
            'details' => $this->parseExemptionHistoryDetails($details, $penalty),
        ];
    }

    private function parseExemptionHistoryDetails(string $details, ?AttendanceDailyPenalty $penalty = null): array
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

            $detail = [
                'key' => $key,
                'label' => $this->exemptionHistoryDetailLabel($key),
                'value' => $this->formatExemptionHistoryValue($key, $value),
            ];

            $url = $this->exemptionHistoryAttachmentUrl($key, $value, $penalty);

            if ($url) {
                $detail['url'] = $url;
                $detail['value'] = $this->penaltyUiText('', 'View Attachment');
            }

            $parsed[] = $detail;
        }

        return $parsed;
    }

    private function historyContainsAttachment(array $history): bool
    {
        foreach ($history as $entry) {
            foreach (($entry['details'] ?? []) as $detail) {
                if (in_array($detail['key'] ?? '', ['attachment', 'previous_attachment'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function exemptionAttachmentHistoryDetail(AttendanceDailyPenalty $penalty, string $path): array
    {
        return [
            'key' => 'attachment',
            'label' => $this->penaltyUiText('', 'Attachment'),
            'value' => $this->penaltyUiText('', 'View Attachment'),
            'url' => route('secure.attendance.attachments.penalty', [
                'penalty' => $penalty->id,
                'path' => $path,
            ]),
        ];
    }

    private function exemptionHistoryDetailLabel(string $key): string
    {
        return match ($key) {
            'type', 'previous_type' => $this->penaltyUiText('نوع الإعفاء', 'Exemption Type'),
            'amount', 'previous_amount' => $this->penaltyUiText('مبلغ الإعفاء', 'Exempt Amount'),
            'net', 'previous_net' => $this->penaltyUiText('المبلغ الصافي', 'Net Amount'),
            'reason', 'previous_reason' => $this->penaltyUiText('السبب', 'Reason'),
            'exempted_at' => $this->penaltyUiText('تاريخ الإعفاء', 'Exempted At'),
            'attachment', 'previous_attachment' => $this->penaltyUiText('', 'Attachment'),
            default => $this->humanizeAuditToken($key),
        };
    }

    private function exemptionHistoryAttachmentUrl(string $key, string $value, ?AttendanceDailyPenalty $penalty): ?string
    {
        if (! $penalty || ! in_array($key, ['attachment', 'previous_attachment'], true)) {
            return null;
        }

        $path = trim($this->cleanAuditText($value));

        if ($path === '' || $path === '-') {
            return null;
        }

        return route('secure.attendance.attachments.penalty', [
            'penalty' => $penalty->id,
            'path' => $path,
        ]);
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

        if ($this->isValidUtf8($value)) {
            return $value;
        }

        if (function_exists('iconv')) {
            foreach (['CP1256', 'WINDOWS-1256', 'ISO-8859-6', 'WINDOWS-1252', 'ISO-8859-1'] as $encoding) {
                $converted = @iconv($encoding, 'UTF-8//IGNORE', $value);

                if (is_string($converted) && $converted !== '' && $this->isValidUtf8($converted)) {
                    return $converted;
                }
            }

            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $value);

            if (is_string($converted) && $this->isValidUtf8($converted)) {
                return $converted;
            }
        }

        return preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $value) ?? '';
    }

    private function isValidUtf8(string $value): bool
    {
        if (function_exists('mb_check_encoding')) {
            return mb_check_encoding($value, 'UTF-8');
        }

        return preg_match('//u', $value) === 1;
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

                if ($exceptionalDay && $this->exceptionalDayStopsPenalties($exceptionalDay)) {
                    $dayReasons[] = (bool) ($exceptionalDay->is_official_holiday ?? false)
                        ? 'official_holiday'
                        : 'exceptional_day';
                    continue;
                }

                if ($holidays->isNotEmpty()) {
                    $dayReasons[] = 'official_holiday';
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

    private function exceptionalDayStopsPenalties($exceptionalDay): bool
    {
        if (! $exceptionalDay) {
            return false;
        }

        if ((bool) ($exceptionalDay->is_official_holiday ?? false)) {
            return true;
        }

        return (bool) ($exceptionalDay->is_holiday ?? false)
            && empty($exceptionalDay->apply_on);
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
        $attachmentPath = $penalty->exemption_attachment ?: null;

        if ($this->exemptionForm['attachment']) {
            $attachmentPath = $this->exemptionForm['attachment']->store(
                'attendance/exemptions',
                'public'
            );
        }

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
                    ' | type=%s, amount=%.2f, net=%.2f, reason=%s, exempted_at=%s%s',
                    (string) ($penalty->exemption_type ?? '-'),
                    (float) $penalty->exemption_amount,
                    (float) $penalty->net_amount,
                    $this->formatExemptionReason($penalty->exemption_reason),
                    $penalty->exempted_at ? (string) $penalty->exempted_at : '-',
                    $penalty->exemption_attachment ? ', previous_attachment=' . $penalty->exemption_attachment : ''
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
                ' | type=%s, amount=%.2f, net=%.2f, reason=%s%s',
                (string) $this->exemptionForm['type'],
                (float) $exemptAmount,
                max(0, $maximumAmount - $exemptAmount),
                $localizedReason,
                $attachmentPath ? ', attachment=' . $attachmentPath : ''
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

        if ($attachmentPath) {
            $updateData['exemption_attachment'] = $attachmentPath;
        }

        $penalty->update($updateData);

        AttendancePenaltyExemptionHistory::create([
            'saas_company_id' => (int) $penalty->saas_company_id,
            'attendance_daily_penalty_id' => (int) $penalty->id,
            'employee_id' => (int) $penalty->employee_id,
            'attendance_date' => $penalty->attendance_date,
            'violation_type' => $penalty->violation_type,
            'action' => 'applied',
            'status' => 'active',
            'exemption_type' => $this->exemptionForm['type'],
            'exemption_amount' => $exemptAmount,
            'net_amount' => max(0, $maximumAmount - $exemptAmount),
            'reason' => $reason,
            'attachment_path' => $attachmentPath ?: $penalty->exemption_attachment,
            'applied_by' => auth()->id(),
            'applied_at' => now(),
        ]);

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

        $prevType = (string) ($penalty->exemption_type ?? '-');
        $prevAmount = (float) $penalty->exemption_amount;
        $prevNet = (float) $penalty->net_amount;
        $prevReason = $penalty->exemption_reason;
        $prevAttachment = $penalty->exemption_attachment;
        $prevAppliedBy = $penalty->exempted_by;
        $prevAppliedAt = $penalty->exempted_at;

        $notes = trim(
            (string) $penalty->notes
            . "\n[Audit] Exemption cancelled by "
            . auth()->user()->name
            . ' at '
            . now()
            . sprintf(
                ' | previous_type=%s, previous_amount=%.2f, previous_net=%.2f, previous_reason=%s%s',
                $prevType,
                $prevAmount,
                $prevNet,
                $this->formatExemptionReason($prevReason),
                $prevAttachment ? ', previous_attachment=' . $prevAttachment : ''
            )
        );

        AttendancePenaltyExemptionHistory::where('attendance_daily_penalty_id', $penalty->id)
            ->where('status', 'active')
            ->update([
                'status' => 'cancelled',
                'cancelled_by' => auth()->id(),
                'cancelled_at' => now(),
            ]);

        AttendancePenaltyExemptionHistory::create([
            'saas_company_id' => (int) $penalty->saas_company_id,
            'attendance_daily_penalty_id' => (int) $penalty->id,
            'employee_id' => (int) $penalty->employee_id,
            'attendance_date' => $penalty->attendance_date,
            'violation_type' => $penalty->violation_type,
            'action' => 'cancelled',
            'status' => 'cancelled',
            'exemption_type' => $prevType,
            'exemption_amount' => $prevAmount,
            'net_amount' => $prevNet,
            'reason' => $prevReason,
            'attachment_path' => $prevAttachment,
            'applied_by' => $prevAppliedBy,
            'applied_at' => $prevAppliedAt,
            'cancelled_by' => auth()->id(),
            'cancelled_at' => now(),
        ]);

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

        if (! $this->isConfirmablePenaltyStatus((string) $penalty->status)) {
            $this->dispatch('toast', [
                'type' => 'error',
                'message' => $this->penaltyUiText(
                    'يمكن اعتماد الجزاءات قيد الانتظار أو المتنازل عنها فقط.',
                    'Only pending or waived penalties can be confirmed.'
                )
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

        if (! $this->isConfirmablePenaltyStatus((string) $penalty->status)) {
            $this->closeConfirmModal();
            $this->dispatch('toast', [
                'type' => 'error',
                'message' => $this->penaltyUiText(
                    'يمكن اعتماد الجزاءات قيد الانتظار أو المتنازل عنها فقط.',
                    'Only pending or waived penalties can be confirmed.'
                )
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

    public function cancelConfirmation($id)
    {
        $this->requireAttendanceAny('attendance.penalties.manage');

        $penalty = $this->findPenaltyOrFail((int) $id);

        if ($penalty->status !== 'confirmed') {
            $this->dispatch('toast', [
                'type' => 'error',
                'message' => $this->penaltyUiText('', 'Only confirmed penalties can be unconfirmed.')
            ]);

            return;
        }

        $nextStatus = $this->hasActiveExemption($penalty) && (float) $penalty->net_amount <= 0
            ? 'waived'
            : 'pending';

        $penalty->update([
            'status' => $nextStatus,
            'confirmed_by' => null,
            'confirmed_at' => null,
            'notes' => trim(
                (string) $penalty->notes
                . "\n[Audit] Penalty confirmation cancelled by "
                . auth()->user()->name
                . ' at '
                . now()
            ),
        ]);

        $this->refreshData();

        $this->dispatch('toast', [
            'type' => 'success',
            'message' => $this->penaltyUiText('', 'Penalty confirmation cancelled.')
        ]);
    }

    private function isConfirmablePenaltyStatus(string $status): bool
    {
        return in_array($status, ['pending', 'waived'], true);
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

        $this->dispatch('toast', [
            'type' => 'warning',
            'message' => $this->penaltyUiText('', 'Penalties cannot be deleted. Use exemption or update attendance then recalculate.'),
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
        $currency = $this->exportCurrency();
        $filename = "daily_penalties_" . now()->format('YmdHis');

        return $exporter->export(
            $filename,
            $this->exportHeaders(),
            $this->exportRows($penalties, $currency),
            ['rtl' => $this->isRtlLocale()]
        );
    }

    public function exportPdf()
    {
        $this->requireAttendanceAny('attendance.penalties.export');
        $penalties = $this->getPenaltiesQuery()->get();
        $currency = $this->exportCurrency();
        $stats = $this->stats;
        [$dateFrom, $dateTo] = $this->getEffectiveDateRange();
        $company = \Athka\Saas\Models\SaasCompany::find(auth()->user()->saas_company_id ?? 0);

        // Convert company logo to base64 for reliable PDF rendering
        $companyLogoBase64 = null;
        if (!empty($company?->logo_path)) {
            $possiblePaths = [
                \Illuminate\Support\Facades\Storage::disk('public')->path($company->logo_path),
                public_path('storage/' . ltrim($company->logo_path, '/\\')),
                storage_path('app/public/' . ltrim($company->logo_path, '/\\')),
            ];
            foreach ($possiblePaths as $p) {
                if (file_exists($p)) {
                    $mime = mime_content_type($p) ?: 'image/png';
                    $data = file_get_contents($p);
                    if ($data) {
                        $companyLogoBase64 = 'data:' . $mime . ';base64,' . base64_encode($data);
                    }
                    break;
                }
            }
        }

        $pdf = Pdf::loadView('attendance::pdf.daily-penalties', [
            'headers' => $this->exportHeaders(),
            'rows' => $this->exportRows($penalties, $currency),
            'stats' => $stats,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'currency' => $currency,
            'company' => $company,
            'companyLogo' => $companyLogoBase64,
            'isRtl' => $this->isRtlLocale(),
            'reshaper' => fn ($text) => $this->pdfReshape($text),
        ])->setOption([
            'isRemoteEnabled' => true,
            'defaultFont' => 'DejaVu Sans',
            'isHtml5ParserEnabled' => true,
        ])->setPaper('a4', 'landscape');

        return response()->streamDownload(function () use ($pdf) {
            echo $pdf->stream();
        }, "daily_penalties_" . now()->format('YmdHis') . ".pdf");
    }

    private function exportHeaders(): array
    {
        return [
            $this->penaltyUiText('', 'Employee'),
            $this->penaltyUiText('', 'Dept/Job'),
            $this->penaltyUiText('', 'Schedule Time'),
            $this->penaltyUiText('', 'Actual Time'),
            $this->penaltyUiText('', 'Violation'),
            $this->penaltyUiText('', 'Duration'),
            $this->penaltyUiText('', 'Calculated'),
            $this->penaltyUiText('', 'Exemption'),
            $this->penaltyUiText('', 'Net Amount'),
            $this->penaltyUiText('', 'Status'),
        ];
    }

    private function exportRows($penalties, array $currency): array
    {
        return $penalties->map(function (AttendanceDailyPenalty $penalty) use ($currency) {
            $employee = $penalty->employee;
            $times = $this->penaltyTimeColumns($penalty);
            $department = trim((string) ($employee?->department?->name ?? ''));
            $jobTitle = trim((string) ($employee?->jobTitle?->name ?? ''));
            $deptJob = trim(implode(' / ', array_filter([$department, $jobTitle])));
            $employeeName = $this->employeeDisplayName($employee);
            $employeeText = $employeeName;

            return [
                $employeeText,
                $deptJob !== '' ? $deptJob : '-',
                $times['scheduled'],
                $times['actual'],
                $this->violationLabel((string) $penalty->violation_type),
                ((int) $penalty->violation_minutes) . ' ' . $this->penaltyUiText('', 'min'),
                $this->formatMoneyForExport($penalty->calculated_amount, $currency),
                $this->formatExemptionForExport($penalty, $currency),
                $this->formatMoneyForExport($penalty->net_amount, $currency),
                $this->statusLabel((string) $penalty->status),
            ];
        })->all();
    }

    private function employeeDisplayName($employee): string
    {
        if (! $employee) {
            return '-';
        }

        $isArabic = $this->isRtlLocale();

        return (string) (
            $isArabic
                ? ($employee->name_ar ?: $employee->name_en ?: $employee->name ?? '-')
                : ($employee->name_en ?: $employee->name_ar ?: $employee->name ?? '-')
        );
    }

    public function getSchedulePeriodsForPenalty(AttendanceDailyPenalty $penalty)
    {
        $attendanceLog = $penalty->attendanceLog;
        $workScheduleId = $attendanceLog?->work_schedule_id;

        if (!$workScheduleId && $penalty->employee_id) {
            $ews = DB::table('employee_work_schedules')
                ->where('employee_id', $penalty->employee_id)
                ->where('is_active', true)
                ->where('start_date', '<=', $penalty->attendance_date)
                ->where(function ($q) use ($penalty) {
                    $q->whereNull('end_date')->orWhere('end_date', '>=', $penalty->attendance_date);
                })
                ->orderByDesc('id')
                ->first();

            $workScheduleId = $ews?->work_schedule_id;
        }

        if ($workScheduleId) {
            return DB::table('work_schedule_periods')
                ->where('work_schedule_id', $workScheduleId)
                ->orderBy('sort_order')
                ->get();
        }

        return collect();
    }

    private function penaltyTimeColumns(AttendanceDailyPenalty $penalty): array
    {
        $attendanceLog = $penalty->attendanceLog;
        $schedulePeriods = $this->getSchedulePeriodsForPenalty($penalty);

        if ($schedulePeriods->count() > 1) {
            $scheduledFormatted = $schedulePeriods->map(fn($p) => 
                $this->formatPenaltyTime($p->start_time) . ' - ' . $this->formatPenaltyTime($p->end_time)
            )->implode(' | ');

            $details = DB::table('attendance_daily_details')
                ->where('daily_log_id', $attendanceLog?->id)
                ->whereNotNull('work_schedule_period_id')
                ->get()
                ->keyBy('work_schedule_period_id');

            if ($details->isNotEmpty()) {
                $actualFormatted = $schedulePeriods->map(function ($p) use ($details) {
                    $d = $details->get($p->id);
                    $in = $this->formatPenaltyTime($d?->check_in_time);
                    $out = $this->formatPenaltyTime($d?->check_out_time);
                    return "{$in} - {$out}";
                })->implode(' | ');
            } else {
                $actualIn = $this->formatPenaltyTime($attendanceLog?->check_in_time);
                $actualOut = $this->formatPenaltyTime($attendanceLog?->check_out_time);
                $actualFormatted = "{$actualIn} - {$actualOut}";
            }

            return [
                'scheduled' => $scheduledFormatted,
                'actual' => $actualFormatted,
            ];
        }

        $scheduledIn = $this->formatPenaltyTime($attendanceLog?->scheduled_check_in);
        $scheduledOut = $this->formatPenaltyTime($attendanceLog?->scheduled_check_out);
        $actualIn = $this->formatPenaltyTime($attendanceLog?->check_in_time);
        $actualOut = $this->formatPenaltyTime($attendanceLog?->check_out_time);

        return [
            'scheduled' => "{$scheduledIn} - {$scheduledOut}",
            'actual' => "{$actualIn} - {$actualOut}",
        ];
    }

    private function formatPenaltyTime($time): string
    {
        if (empty($time)) {
            return '-';
        }

        try {
            return Carbon::parse($time)->format('H:i');
        } catch (\Throwable $e) {
            return '-';
        }
    }

    private function violationLabel(string $type): string
    {
        return match ($type) {
            'delay' => $this->penaltyUiText('', 'Delay'),
            'early_departure' => $this->penaltyUiText('', 'Early Departure'),
            'absent' => $this->penaltyUiText('', 'Absent'),
            'auto_checkout' => $this->penaltyUiText('', 'Auto Checkout'),
            default => $type !== '' ? $type : '-',
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'pending' => $this->penaltyUiText('', 'Pending'),
            'confirmed' => $this->penaltyUiText('', 'Confirmed'),
            'waived' => $this->penaltyUiText('', 'Waived'),
            default => $status !== '' ? $status : '-',
        };
    }

    private function formatExemptionForExport(AttendanceDailyPenalty $penalty, array $currency): string
    {
        if ((float) $penalty->exemption_amount <= 0) {
            return '-';
        }

        $reason = $this->formatExemptionReason((string) $penalty->exemption_reason);

        return '-' . $this->formatMoneyForExport($penalty->exemption_amount, $currency)
            . ($reason !== '-' ? ' - ' . $reason : '');
    }

    private function formatMoneyForExport($amount, array $currency): string
    {
        $label = $currency['code'] ?: ($currency['label'] ?? 'YER');
        return number_format((float) $amount, 2) . ' ' . $label;
    }

    private function exportCurrency(): array
    {
        $companyId = (int) (auth()->user()?->saas_company_id ?? 0);
        $fallback = 'YER';

        $currency = Currency::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->orderByDesc('is_default')
            ->orderBy('id')
            ->first(['code', 'symbol', 'name']);

        $code = trim((string) ($currency?->code ?: $fallback));
        $symbol = trim((string) ($currency?->symbol ?: ''));
        $name = trim((string) ($currency?->name ?: ''));

        return [
            'code' => $code,
            'symbol' => $symbol ?: $code,
            'name' => $name ?: $code,
            'label' => $code,
        ];
    }

    private function isRtlLocale(): bool
    {
        return in_array(substr((string) app()->getLocale(), 0, 2), ['ar', 'fa', 'ur', 'he'], true);
    }

    private function pdfReshape($text)
    {
        if ($text === null || $text === '') {
            return '';
        }

        $str = (string) $text;

        // Do not reshape pure numbers, dates, times, amounts, or time ranges
        if (preg_match('/^[\d\s\.\,\:\/\-\+]+$/u', $str) || preg_match('/^[\d\.\,\s]+(YER|SAR|USD|ر\.ي|ر\.س|\$)?$/u', $str)) {
            return $str;
        }

        if (class_exists('\Athka\Employees\Support\ArabicHelper')) {
            return \Athka\Employees\Support\ArabicHelper::prepareForPdf($str);
        }

        return $str;
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
        $query = $this->excludePenaltiesCoveredByAbsence($query);
        $query = $this->applyPenaltyFilters($query);

        return $query;
    }

    private function excludePenaltiesCoveredByAbsence($query)
    {
        return $query->where(function ($outer) {
            $outer->where('violation_type', 'absent')
                ->orWhereNotIn('violation_type', ['delay', 'early_departure', 'auto_checkout'])
                ->orWhereNotExists(function ($subQuery) {
                    $subQuery->selectRaw('1')
                        ->from('attendance_daily_penalties as absence_penalties')
                        ->whereColumn('absence_penalties.saas_company_id', 'attendance_daily_penalties.saas_company_id')
                        ->whereColumn('absence_penalties.employee_id', 'attendance_daily_penalties.employee_id')
                        ->whereColumn('absence_penalties.attendance_date', 'attendance_daily_penalties.attendance_date')
                        ->where('absence_penalties.violation_type', 'absent');
                });
        });
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


