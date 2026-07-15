<?php

namespace Athka\Attendance\Http\Livewire\LeavesPermissions\Traits;

use Athka\Employees\Models\Employee;
use Athka\SystemSettings\Models\LeavePolicy;
use Athka\SystemSettings\Models\LeavePolicyYear; 
use Athka\SystemSettings\Models\OperationalCalendar;
use Athka\SystemSettings\Models\OfficialHolidayOccurrence;
use Athka\SystemSettings\Services\WorkScheduleService;
use Athka\Attendance\Models\AttendanceLeaveRequest;
use Athka\Attendance\Models\AttendanceLeaveCutRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

trait WithLeaveRequests
{
    public bool $createLeaveOpen = false;
    public int $employee_id = 0;
    public int $leave_policy_id = 0;
    public string $start_date = '';
    public string $end_date = '';
    public string $reason = '';
    public ?int $replacement_employee_id = null;
    public string $create_leave_duration_unit = 'full_day';
    public bool $create_leave_attachment_required = false;
    public array $create_leave_attachment_types = [];

    public bool $createGroupLeaveOpen = false;
    public array $group_employee_ids = [];    
    public int $group_leave_policy_id = 0;
    public string $group_start_date = '';
    public string $group_end_date = '';
    public string $group_reason = '';
    public bool $group_leave_deduct_from_balance = false;


    public string $group_leave_duration_unit = 'full_day';
    public bool $group_leave_attachment_required = false;
    public array $group_leave_attachment_types = [];

    public $group_attachment = null;
    public int $create_leave_attachment_max_mb = 2;

    public string $create_leave_note_text = '';
    public bool $create_leave_note_ack_required = false;
    public bool $leave_note_ack = false;

    public string $leave_half_day_part = 'first_half'; 
    public int $leave_work_schedule_period_id = 0;
    public string $leave_from_time = ''; 
    public string $leave_to_time = '';   
    public int $leave_minutes = 0;

    public $leave_attachment = null;

    public ?int $groupDepartmentId = null;
    public ?int $groupJobTitleId = null;
    public array $groupEmployeeIds = [];
    public ?int $groupBranchId = null;
    public string $groupContractType = '';

    public ?float $group_leave_hours = null;               
    public string $group_leave_half_day_period = 'am';    

    public bool $cutLeaveOpen = false;
    public int $cut_leave_request_id = 0;
    public string $cut_new_end_date = '';
    public string $cut_reason = '';

    public string $group_leave_half_day_part = 'first_half';
    public string $group_leave_from_time = '';
    public string $group_leave_to_time = '';
    public int $group_leave_minutes = 0;

    protected function leavePoliciesCompanyColumn(): ?string
    {
        if (!Schema::hasTable('leave_policies')) return null;

        if (Schema::hasColumn('leave_policies', 'saas_company_id')) return 'saas_company_id';
        if (Schema::hasColumn('leave_policies', 'company_id')) return 'company_id';

        return null;
    }

    protected function applyLeavePolicyYearFilter($q): void
    {
        if (! $this->selectedYearId || ! $this->leavePolicyYearColumn) return;

        $yearTable = (new LeavePolicyYear())->getTable();
        $yearCoCol = $this->detectCompanyColumn($yearTable); 

        $yearRow = LeavePolicyYear::query()
            ->when($yearCoCol, fn ($q) => $q->where($yearCoCol, $this->companyId))
            ->where('id', (int) $this->selectedYearId)
            ->first();

        if (! $yearRow) return;

        if ($this->leavePolicyYearColumn === 'year') {
            if (Schema::hasColumn('leave_policies', 'year')) {
                $q->where('year', (int) $yearRow->year);
            }
            return;
        }

        $q->where($this->leavePolicyYearColumn, (int) $this->selectedYearId);
    }

    public function getCreateLeavePoliciesProperty()
    {
        $companyCol = $this->leavePoliciesCompanyColumn();
        $q = LeavePolicy::query();
        if ($companyCol) $q->where($companyCol, $this->companyId);

        if (Schema::hasColumn('leave_policies', 'is_active')) $q->where('is_active', true);

        $this->applyLeavePolicyYearFilter($q);

        $empId = (int) $this->employee_id;
        if ($empId <= 0) {
            return $q->orderBy('name')->get();
        }

        $allowed = $this->lpAllowedBranchIdsSafe();
        $branchCol = $this->employeeBranchColumn ?: $this->detectEmployeeBranchColumn();

        $employee = Employee::query()
            ->when($this->employeeCompanyColumn, fn ($q2) => $q2->where($this->employeeCompanyColumn, $this->companyId))
            ->when($branchCol && !empty($allowed), fn ($q2) => $q2->whereIn($branchCol, $allowed))
            ->find($empId);

        if (!$employee) return collect();

        $gender = $this->normalizeEmployeeGender($employee);

        if (Schema::hasColumn('leave_policies', 'gender') && in_array($gender, ['male', 'female'], true)) {
            $q->whereIn('gender', ['all', $gender]);
        }

        return $q->get()->filter(function ($p) use ($employee) {
            $excluded = (array) ($p->excluded_contract_types ?? []);
            return !in_array($employee->contract_type, $excluded);
        });
    }

    public function respondToReplacementRequest(int $id, string $action): void
    {
        $employeeId = auth()->user()->employee_id;
        if (!$employeeId) return;

        $row = AttendanceLeaveRequest::where('id', $id)
            ->where('replacement_employee_id', $employeeId)
            ->where('replacement_status', 'pending')
            ->firstOrFail();

        if ($action === 'approve') {
            $row->update(['replacement_status' => 'approved']);
            session()->flash('success', tr('Accepted as replacement successfully'));
        } else {
            $row->update(['replacement_status' => 'rejected']);
            session()->flash('success', tr('Rejected as replacement successfully'));
        }

        $this->resetPage('leavePage');
    }

    public function getReplacementEmployeesProperty()
    {
        $empId = (int) $this->employee_id;
        if ($empId <= 0) return collect();

        $employee = Employee::find($empId);
        if (!$employee) return collect();

        $q = Employee::query()
            ->where('id', '!=', $empId);

        if ($this->employeeCompanyColumn) {
            $q->where($this->employeeCompanyColumn, $this->companyId);
        }

        // --- Smart Filtering ---
        $deptCol = $this->employeeDepartmentColumn;
        $titleCol = $this->employeeJobTitleColumn;

        $q->where(function ($sub) use ($employee, $deptCol, $titleCol) {
            $hasCondition = false;
            if ($deptCol && $deptValue = $employee->getAttribute($deptCol)) {
                $sub->orWhere($deptCol, $deptValue);
                $hasCondition = true;
            }
            if ($titleCol && $titleValue = $employee->getAttribute($titleCol)) {
                $sub->orWhere($titleCol, $titleValue);
                $hasCondition = true;
            }

            // If no department or title is set for the employee, we don't restrict (to keep it flexible)
            if (!$hasCondition) {
                $sub->whereRaw('1=1');
            }
        });

        // Apply same branch restriction if exists
        $allowed = $this->lpAllowedBranchIdsSafe();
        $branchCol = $this->employeeBranchColumn;
        if ($branchCol && !empty($allowed)) {
            $q->whereIn($branchCol, $allowed);
        }

        return $q->orderBy('id', 'desc')->limit(50)->get();
    }

    public function updatedEmployeeId($value): void
    {
        $this->leave_policy_id = 0;
        $this->replacement_employee_id = null;
        $this->resetCreateLeavePolicyMeta();
    }

    public function updatedLeavePolicyId($value): void
    {
        $this->hydrateCreateLeavePolicyMeta(true); // ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ Ãƒâ„¢Ã¢â‚¬Â¡Ãƒâ„¢Ã¢â‚¬Â ÃƒËœÃ‚Â§ Ãƒâ„¢Ã‚ÂÃƒâ„¢Ã¢â‚¬Å¡ÃƒËœÃ‚Â·
    }

    public function updatedStartDate($value): void
    {
        // Ãƒâ„¢Ã¢â‚¬Å¾Ãƒâ„¢Ã‹â€  Ãƒâ„¢Ã¢â‚¬Â ÃƒËœÃ‚ÂµÃƒâ„¢Ã‚Â Ãƒâ„¢Ã…Â Ãƒâ„¢Ã‹â€ Ãƒâ„¢Ã¢â‚¬Â¦ ÃƒËœÃ‚Â£Ãƒâ„¢Ã‹â€  ÃƒËœÃ‚Â³ÃƒËœÃ‚Â§ÃƒËœÃ‚Â¹ÃƒËœÃ‚Â§ÃƒËœÃ‚Âª Ãƒâ„¢Ã¢â‚¬Â ÃƒËœÃ‚Â®Ãƒâ„¢Ã¢â‚¬Å¾Ãƒâ„¢Ã…Â  end_date = start_date ÃƒËœÃ‚ÂªÃƒâ„¢Ã¢â‚¬Å¾Ãƒâ„¢Ã¢â‚¬Å¡ÃƒËœÃ‚Â§ÃƒËœÃ‚Â¦Ãƒâ„¢Ã…Â ÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Â¹
        if ($this->create_leave_duration_unit !== 'full_day') {
            $this->end_date = (string) $value;
        }

        $this->leave_work_schedule_period_id = 0;
    }

    public function updatedLeaveFromTime(): void { $this->syncLeaveMinutes(); }
    public function updatedLeaveToTime(): void { $this->syncLeaveMinutes(); }

    protected function syncLeaveMinutes(): void
    {
        if ($this->create_leave_duration_unit !== 'hours') {
            $this->leave_minutes = 0;
            return;
        }
        $this->leave_minutes = $this->computeMinutesSafe($this->leave_from_time, $this->leave_to_time);
    }

    // =========================================================
    // ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ Open/Close
    // =========================================================
    public function openCreateLeave(): void
    {
        $this->requireAttendanceAny('requests.leaves.create');
        $this->resetValidation();
        $this->employeeSearch = '';
        $this->employee_id = 0;
        $this->leave_policy_id = 0;
        $this->start_date = '';
        $this->end_date = '';
        $this->reason = '';
        $this->replacement_employee_id = null;

        $this->resetCreateLeavePolicyMeta();

        $this->createLeaveOpen = true;
    }

    public function closeCreateLeave(): void { $this->createLeaveOpen = false; }

    // =========================================================
    // ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ Save Leave (policy-driven)
    // =========================================================
    public function saveLeave(): void
    {
        $this->requireAttendanceAny('requests.leaves.create');
        $this->ensureCanManage();

        // 0) Early validation to avoid 404/500 before policy-specific rules
        $this->validate([
            'employee_id' => 'required|integer|min:1',
            'leave_policy_id' => 'required|integer|min:1',
        ]);

        // 1) Validate employee exists (same company)
        $allowed = $this->lpAllowedBranchIdsSafe();
        $branchCol = $this->employeeBranchColumn ?: $this->detectEmployeeBranchColumn();

        $employee = Employee::query()
            ->when($this->employeeCompanyColumn, fn ($q) => $q->where($this->employeeCompanyColumn, $this->companyId))
            ->when($branchCol && !empty($allowed), fn ($q) => $q->whereIn($branchCol, $allowed))
            ->findOrFail((int) $this->employee_id);

        // 2) Validate policy is allowed for this employee
        $policy = $this->findAllowedPolicyForEmployee($employee, (int) $this->leave_policy_id);

        // 3) Build + validate rules based on policy settings
        $rules = $this->buildCreateLeaveRulesFromPolicy($policy);
        $data = $this->validate(
            $rules,
            $this->leaveRequestsValidationMessages(),
            $this->leaveRequestsValidationAttributes()
        );

        // Ã¢Å“â€¦ Check Workflow existence
        if (class_exists(\Athka\SystemSettings\Services\Approvals\ApprovalService::class)) {
            $approvalService = app(\Athka\SystemSettings\Services\Approvals\ApprovalService::class);
            $workflowReason = null;
            $hasWorkflow = $approvalService->hasApproversForEmployee('leaves', (int) $employee->id, (int) $this->companyId, $workflowReason);
            $hasPolicies = $approvalService->hasActivePolicies('leaves', (int) $this->companyId);

            if ($hasPolicies) {
                if (!$hasWorkflow) {
                    $msg = match ($workflowReason) {
                        'missing_direct_manager' => tr('Cannot submit request: Your direct manager is not assigned in the system.'),
                        'invalid_user_approver' => tr('Cannot submit request: The approval workflow has an approver user that is not linked to an employee.'),
                        'no_steps_defined' => tr('Cannot submit request: The approval policy has no approval steps.'),
                        'no_matching_policy' => tr('Cannot submit request: No approval policy matches this employee.'),
                        'invalid_employee_approver', 'unresolvable_approver_step' => tr('Cannot submit request: The approval workflow contains an invalid approver.'),
                        default => tr('Cannot submit request, please contact administration to review the approval workflow.'),
                    };
                    $this->addError('start_date', $msg);
                    return;
                }
            } else {
                // No policies at all, fallback to check if at least something (manager) is available
                $hasManager = $approvalService->resolveDirectManagerId((int) $employee->id) > 0;
                if (!$hasWorkflow && !$hasManager) {
                    $this->addError('start_date', tr('Cannot submit request, please contact administration to assign an approval workflow.'));
                    return;
                }
            }
        }

        // 4) Normalize dates based on duration unit
        $start = Carbon::parse($data['start_date'])->startOfDay();
        $end = $this->create_leave_duration_unit === 'full_day'
            ? Carbon::parse($data['end_date'])->startOfDay()
            : $start->copy();

        // 5) Notice rules from settings
        if (!$this->validatePolicyNoticeWindow($policy, $start)) {
            return;
        }

        // Ã¢Å“â€¦ Exceptional Day Overlap Check
        if (class_exists(\Athka\SystemSettings\Services\WorkScheduleService::class)) {
            $wsService = app(\Athka\SystemSettings\Services\WorkScheduleService::class);
            $currDate = $start->copy();
            while ($currDate->lte($end)) {
                $exDay = $wsService->getExceptionalDay($this->companyId, $currDate->toDateString(), $employee);
                if ($exDay && (bool)($exDay->is_holiday ?? true)) {
                    $isOfficial = (bool)($exDay->is_official_holiday ?? false);
                    $typeLabel = $isOfficial ? tr('Official Holiday') : tr('Exceptional Day');
                    $msgPart = tr('Cannot request leave on this date');
                    
                    $msg = $msgPart . ': ' . $typeLabel . ' - ' . ($exDay->name ?? '') . ' (' . $currDate->toDateString() . ')';
                    $this->addError('start_date', $msg);
                    return;
                }
                $currDate->addDay();
            }
        }

        // 5.1) Main employee: check overlap (already on leave or is a replacement)
        $empCheck = $this->isEmployeeLeavePeriodAvailable((int) $employee->id, $start, $end);
        if (!$empCheck['ok']) {
            $this->addError('start_date', $empCheck['message']);
            return;
        }

        // 5.2) Replacement employee: check overlap
        if (!empty($data['replacement_employee_id'])) {
            $repCheck = $this->isEmployeeLeavePeriodAvailable((int) $data['replacement_employee_id'], $start, $end);
            if (!$repCheck['ok']) {
                $this->addError('replacement_employee_id', $repCheck['message']);
                return;
            }
        }

        // 6) Compute requested days + extra fields
        $requestedDays = 0.0;
        $halfPart = null;
        $fromTime = null;
        $toTime = null;
        $minutes = null;
        $workSchedulePeriodId = null;

        if ($this->create_leave_duration_unit === 'half_day') {
            $periodId = (int) ($data['leave_work_schedule_period_id'] ?? 0);
            $period = $this->resolveLeaveWorkSchedulePeriod($employee, $start, $periodId);
            if (!$period) {
                return;
            }

            $base = $this->computeRequestedDays($policy, $start, $start);
            if ($base <= 0) {
                $this->addError('start_date', tr('Selected date is not eligible for this policy'));
                return;
            }

            $halfPart = 'work_period';
            $fromTime = substr((string) $period['start_time'], 0, 5);
            $toTime = substr((string) $period['end_time'], 0, 5);
            $minutes = $this->computePeriodMinutes($start, $period);
            $workSchedulePeriodId = $periodId;
            $requestedDays = 0.5;
        }
        elseif ($this->create_leave_duration_unit === 'hours') {
            $fromTime = (string) ($data['leave_from_time'] ?? '');
            $toTime   = (string) ($data['leave_to_time'] ?? '');

            $mins = $this->computeMinutesSafe($fromTime, $toTime);
            if ($mins <= 0) {
                $this->addError('leave_to_time', tr('End time must be after start time'));
                return;
            }

            if (! $this->validateHoursWithinWorkWindow($start, $fromTime, $toTime)) {
                return;
            }

            // Ensure it's a valid day
            $base = $this->computeRequestedDays($policy, $start, $start);
            if ($base <= 0) {
                $this->addError('start_date', tr('Selected date is not eligible for this policy'));
                return;
            }

            // ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ NEW: workday minutes Ãƒâ„¢Ã¢â‚¬Â¦Ãƒâ„¢Ã¢â‚¬Â  policy.settings ÃƒËœÃ‚Â£Ãƒâ„¢Ã‹â€  Ãƒâ„¢Ã¢â‚¬Â¦Ãƒâ„¢Ã¢â‚¬Â  work_schedules ÃƒËœÃ‚Â£Ãƒâ„¢Ã‹â€  fallback config
            $settings = (array) ($policy->settings ?? []);
            $workdayMinutesSetting = data_get($settings, 'workday_minutes', null);

            if ($workdayMinutesSetting !== null) {
                $workdayMinutes = (int) $workdayMinutesSetting;
            } else {
                $workdayMinutes = (int) $this->getWorkdayMinutesForDate($start);
                if ($workdayMinutes <= 0) {
                    $workdayMinutes = (int) config('attendance.workday_minutes', 480);
                }
            }

            $workdayMinutes = max($workdayMinutes, 1);

            $minutes = $mins;
            $this->leave_minutes = $mins;

            // Store as fraction of day (Ãƒâ„¢Ã¢â‚¬Â¦ÃƒËœÃ‚Â«Ãƒâ„¢Ã¢â‚¬Å¾ÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Â¹ 2 ÃƒËœÃ‚Â³ÃƒËœÃ‚Â§ÃƒËœÃ‚Â¹ÃƒËœÃ‚Â§ÃƒËœÃ‚Âª Ãƒâ„¢Ã¢â‚¬Â¦Ãƒâ„¢Ã¢â‚¬Â  8 = 0.25)
            $requestedDays = round($mins / $workdayMinutes, 6);
        }
        else {
            $requestedDays = $this->computeRequestedDays($policy, $start, $end);
        }

        if ($requestedDays <= 0) {
            $msg = tr('The selected range contains no working days for this leave policy.');
            $this->addError('start_date', $msg);
            $this->addError('end_date', $msg);
            return;
        }

        // 7) Determine policy year id
        $yearId = $this->selectedYearId ?: ($this->leavePolicyYearColumn ? (int) $policy->getAttribute($this->leavePolicyYearColumn) : 0);

        // 8) Attachment store (if enabled)
        $attachmentPath = null;
        $attachmentName = null;

        if ($this->create_leave_attachment_required && $this->leave_attachment) {
            $dir = 'attendance/leaves/company-' . $this->companyId . '/employee-' . (int) $employee->id;

            $disk = config('filesystems.default', 'public');
            $attachmentName = method_exists($this->leave_attachment, 'getClientOriginalName')
                ? $this->leave_attachment->getClientOriginalName()
                : null;

            $attachmentPath = $this->leave_attachment->storePublicly($dir, $disk);
        }

        $balance = DB::table('attendance_leave_balances')
            ->where('company_id', $this->companyId)
            ->where('employee_id', $employee->id)
            ->where('leave_policy_id', $policy->id)
            ->where('policy_year_id', $yearId)
            ->first();
        $takenForBalance = $balance ? (float) $balance->taken_days : (float) AttendanceLeaveRequest::query()
            ->where('company_id', $this->companyId)
            ->where('employee_id', $employee->id)
            ->where('leave_policy_id', $policy->id)
            ->where('policy_year_id', $yearId)
            ->where('status', 'approved')
            ->sum('requested_days');

        $remaining = (float) ($this->calculateLeaveBalanceAmounts($policy, $employee, $takenForBalance)['remaining'] ?? 0);

        $isException = false;
        $exceptionStatus = null;
        
        if ($requestedDays > $remaining) {
            $settings = (array) ($policy->settings ?? []);
            $deductionPolicy = (string) ($settings['deduction_policy'] ?? 'balance_only');

            if ($deductionPolicy === 'balance_only') {
                $msg = tr('Your balance is insufficient and the policy does not allow exceeding it.');
                $this->addError('start_date', $msg);
                $this->addError('end_date', $msg);
                return;
            }

            $isException = true;
            $exceptionStatus = 'pending_hr';
        }

        // 9) Create row
        $row = AttendanceLeaveRequest::create([
            'company_id' => $this->companyId,
            'employee_id' => (int) $employee->id,
            'leave_policy_id' => (int) $policy->id,
            'policy_year_id' => $yearId,

            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),

            'requested_days' => $requestedDays,
            'reason' => $data['reason'] ?? null,

            // Ã¢Å“â€¦ NEW fields
            'duration_unit' => $this->create_leave_duration_unit,
            'half_day_part' => $halfPart,
            'from_time' => $fromTime,
            'to_time' => $toTime,
            'minutes' => $minutes,
            'work_schedule_period_id' => $workSchedulePeriodId,

            'attachment_path' => $attachmentPath,
            'attachment_name' => $attachmentName,
            'note_ack' => (bool) $this->leave_note_ack,

            'source' => 'hr',
            'status' => 'pending',
            'requested_by' => auth()->id(),
            'requested_at' => now(),
            
            'is_exception' => $isException,
            'exception_status' => $exceptionStatus,
            'replacement_employee_id' => $data['replacement_employee_id'] ?? null,
            'replacement_status' => !empty($data['replacement_employee_id']) ? 'pending' : null,
        ]);

        // Ã¢Å“â€¦ Integrate with Approval Workflow
        try {
            $approvalService = app(\Athka\SystemSettings\Services\Approvals\ApprovalService::class);
            $src = $approvalService->getRequestSource('leaves');
            if ($src) {
                $approvalService->ensureTasksForRequest($src, $row, (int) $this->companyId);
            }
        } catch (\Exception $e) {
            \Log::error("Approval Task Generation Error (Leave): " . $e->getMessage());
        }

        $this->logAction('leave', (int) $row->id, 'created', [
            'requested_days' => $requestedDays,
            'duration_unit' => $this->create_leave_duration_unit,
            'minutes' => $minutes,
            'work_schedule_period_id' => $workSchedulePeriodId,
        ], (int) $row->employee_id);

        session()->flash('success', tr('Saved successfully'));
        $this->dispatch('toast', [
            'type'    => 'success',
            'title'   => tr('Success'),
            'message' => tr('Saved successfully'),
        ]);
        $this->dispatch('leave-request-updated');
        $this->closeCreateLeave();
        $this->resetPage('leavePage');
    }

    // =========================================================
    // ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ Helpers: policy meta/rules/filtering
    // =========================================================
    protected function resetCreateLeavePolicyMeta(): void
    {
        $this->create_leave_duration_unit = 'full_day';

        $this->create_leave_attachment_required = false;
        $this->create_leave_attachment_types = ['pdf', 'jpg', 'jpeg', 'png'];
        $this->create_leave_attachment_max_mb = 2;

        $this->create_leave_note_text = '';
        $this->create_leave_note_ack_required = false;
        $this->leave_note_ack = false;

        $this->leave_half_day_part = 'first_half';
        $this->leave_work_schedule_period_id = 0;
        $this->leave_from_time = '';
        $this->leave_to_time = '';
        $this->leave_minutes = 0;

        $this->leave_attachment = null;
        $this->replacement_employee_id = null;
    }

    protected function hydrateCreateLeavePolicyMeta(bool $resetInputs = false): void
    {
        $policyId = (int) $this->leave_policy_id;
        if ($policyId <= 0) {
            $this->resetCreateLeavePolicyMeta();
            return;
        }

        $companyCol = $this->leavePoliciesCompanyColumn();

        $policy = LeavePolicy::query()
            ->when($companyCol, fn ($q) => $q->where($companyCol, $this->companyId))
            ->find($policyId);

        if (!$policy) {
            $this->resetCreateLeavePolicyMeta();
            return;
        }

        $settings = (array) ($policy->settings ?? []);
        $unit = (string) data_get($settings, 'duration_unit', 'full_day');
        $unit = in_array($unit, ['full_day', 'half_day', 'hours'], true) ? $unit : 'full_day';

        $noteText = (string) data_get($settings, 'note_text', '');
        $noteRequired = (bool) data_get($settings, 'note_required', false);
        $noteAckRequired = (bool) data_get($settings, 'note_ack_required', false);

        $types = data_get($settings, 'attachments.types', ['pdf', 'jpg', 'jpeg', 'png']);
        $types = is_array($types) ? array_values($types) : ['pdf', 'jpg', 'png'];
        $types = array_values(array_intersect($types, ['pdf', 'jpg', 'jpeg', 'png']));
        if (empty($types)) $types = ['pdf', 'jpg', 'jpeg', 'png'];

        $maxMb = (int) data_get($settings, 'attachments.max_mb', 2);
        if ($maxMb <= 0) $maxMb = 2;

        $requiresAttachment = (bool) ($policy->requires_attachment ?? false);
        $needAttachment = $requiresAttachment || $noteRequired || trim($noteText) !== '';

        // ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ meta Ãƒâ„¢Ã‚ÂÃƒâ„¢Ã¢â‚¬Å¡ÃƒËœÃ‚Â· (ÃƒËœÃ‚Â¨ÃƒËœÃ‚Â¯Ãƒâ„¢Ã‹â€ Ãƒâ„¢Ã¢â‚¬Â  Ãƒâ„¢Ã¢â‚¬Å¾Ãƒâ„¢Ã¢â‚¬Â¦ÃƒËœÃ‚Â³ Ãƒâ„¢Ã¢â‚¬Â¦ÃƒËœÃ‚Â¯ÃƒËœÃ‚Â®Ãƒâ„¢Ã¢â‚¬Å¾ÃƒËœÃ‚Â§ÃƒËœÃ‚Âª ÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Å¾Ãƒâ„¢Ã¢â‚¬Â¦ÃƒËœÃ‚Â³ÃƒËœÃ‚ÂªÃƒËœÃ‚Â®ÃƒËœÃ‚Â¯Ãƒâ„¢Ã¢â‚¬Â¦)
        $this->create_leave_duration_unit = $unit;
        $this->create_leave_note_text = $noteText;
        $this->create_leave_note_ack_required = $noteAckRequired;

        $this->create_leave_attachment_required = $needAttachment;
        $this->create_leave_attachment_types = $types;
        $this->create_leave_attachment_max_mb = $maxMb;

        if ($this->create_leave_duration_unit !== 'full_day' && $this->start_date !== '') {
            $this->end_date = $this->start_date;
        }

        // ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ Reset ÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Å¾Ãƒâ„¢Ã¢â‚¬Â¦ÃƒËœÃ‚Â¯ÃƒËœÃ‚Â®Ãƒâ„¢Ã¢â‚¬Å¾ÃƒËœÃ‚Â§ÃƒËœÃ‚Âª Ãƒâ„¢Ã‚ÂÃƒâ„¢Ã¢â‚¬Å¡ÃƒËœÃ‚Â· ÃƒËœÃ‚Â¹Ãƒâ„¢Ã¢â‚¬Â ÃƒËœÃ‚Â¯ ÃƒËœÃ‚ÂªÃƒËœÃ‚ÂºÃƒâ„¢Ã…Â Ãƒâ„¢Ã…Â ÃƒËœÃ‚Â± policy (Ãƒâ„¢Ã¢â‚¬Â¦ÃƒËœÃ‚Â´ ÃƒËœÃ‚Â¹Ãƒâ„¢Ã¢â‚¬Â ÃƒËœÃ‚Â¯ ÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Å¾ÃƒËœÃ‚Â­Ãƒâ„¢Ã‚ÂÃƒËœÃ‚Â¸)
        if ($resetInputs) {
            $this->leave_attachment = null;
            $this->leave_note_ack = false;
            $this->leave_minutes = 0;
            $this->leave_from_time = '';
            $this->leave_to_time = '';
            $this->leave_half_day_part = 'first_half';
            $this->leave_work_schedule_period_id = 0;
        }
    }

    protected function buildCreateLeaveRulesFromPolicy(LeavePolicy $policy): array
    {
        $this->hydrateCreateLeavePolicyMeta(false);

        $mimes = implode(',', $this->create_leave_attachment_types);
        $maxKb = (int) $this->create_leave_attachment_max_mb * 1024;

        $rules = [
            'employee_id' => ['required', 'integer', 'min:1'],
            'leave_policy_id' => ['required', 'integer', 'min:1'],

            'start_date' => ['required', 'date'],
            'end_date' => $this->create_leave_duration_unit === 'full_day'
                ? ['required', 'date', 'after_or_equal:start_date']
                : ['nullable', 'date'],

            'reason' => ['nullable', 'string', 'max:2000'],
            'replacement_employee_id' => ['nullable', 'integer', 'different:employee_id'],
        ];

        if ($this->create_leave_duration_unit === 'half_day') {
            $rules['leave_work_schedule_period_id'] = ['required', 'integer', 'min:1'];
            $rules['leave_half_day_part'] = ['nullable'];
        } else {
            $rules['leave_work_schedule_period_id'] = ['nullable', 'integer'];
            $rules['leave_half_day_part'] = ['nullable'];
        }

        if ($this->create_leave_duration_unit === 'hours') {
            $rules['leave_from_time'] = [
                'required', 'string', 'max:32',
                function ($attr, $value, $fail) {
                    if (! $this->parseTimeSafe((string) $value)) {
                        $fail(tr('Start time is not valid.'));
                    }
                },
            ];

            $rules['leave_to_time'] = [
                'required', 'string', 'max:32',
                function ($attr, $value, $fail) {
                    if (! $this->parseTimeSafe((string) $value)) {
                        $fail(tr('End time is not valid.'));
                    }
                },
            ];
        } else {
            $rules['leave_from_time'] = ['nullable', 'string', 'max:32'];
            $rules['leave_to_time'] = ['nullable', 'string', 'max:32'];
        }

        $rules['leave_attachment'] = $this->create_leave_attachment_required
            ? ['required', 'file', 'max:' . $maxKb, 'mimes:' . $mimes]
            : ['nullable', 'file', 'max:' . $maxKb, 'mimes:' . $mimes];

        $rules['leave_note_ack'] = $this->create_leave_note_ack_required
            ? ['accepted']
            : ['nullable'];

        return $rules;
    }

    protected function validatePolicyNoticeWindow(LeavePolicy $policy, Carbon $start): bool
    {
        $s = (array) ($policy->settings ?? []);

        $minDays = (int) data_get($s, 'notice.min_days', 0);
        $maxAdvance = (int) data_get($s, 'notice.max_advance_days', 3650);
        $allowRetro = (bool) data_get($s, 'notice.allow_retroactive', false);

        $today = now()->startOfDay();

        if (!$allowRetro && $start->lt($today)) {
            $this->addError('start_date', tr('Retroactive requests are not allowed for this policy.'));
            return false;
        }

        if ($minDays > 0) {
            $minDate = $today->copy()->addDays($minDays);
            if ($start->lt($minDate)) {
                $this->addError('start_date', tr('This policy requires advance notice.'));
                return false;
            }
        }

        if ($maxAdvance > 0) {
            $maxDate = $today->copy()->addDays($maxAdvance);
            if ($start->gt($maxDate)) {
                $this->addError('start_date', tr('Selected date is too far in the future for this policy.'));
                return false;
            }
        }

        return true;
    }

    protected function findAllowedPolicyForEmployee(Employee $employee, int $policyId): LeavePolicy
    {
        $gender = $this->normalizeEmployeeGender($employee);

        $companyCol = $this->leavePoliciesCompanyColumn();

        $q = LeavePolicy::query()->where('id', $policyId);
        if ($companyCol) $q->where($companyCol, $this->companyId);

        if (Schema::hasColumn('leave_policies', 'is_active')) $q->where('is_active', true);

        // ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ HR screen: Ãƒâ„¢Ã¢â‚¬Å¾ÃƒËœÃ‚Â§ Ãƒâ„¢Ã¢â‚¬Â Ãƒâ„¢Ã¢â‚¬Å¡Ãƒâ„¢Ã…Â Ãƒâ„¢Ã¢â‚¬ËœÃƒËœÃ‚Â¯ ÃƒËœÃ‚Â¨Ãƒâ„¢Ã¢â€šÂ¬ show_in_app ÃƒËœÃ‚Â­ÃƒËœÃ‚ÂªÃƒâ„¢Ã¢â‚¬Â° ÃƒËœÃ‚ÂªÃƒËœÃ‚Â¸Ãƒâ„¢Ã¢â‚¬Â¡ÃƒËœÃ‚Â± Ãƒâ„¢Ã†â€™Ãƒâ„¢Ã¢â‚¬Å¾ ÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Å¾ÃƒËœÃ‚Â³Ãƒâ„¢Ã…Â ÃƒËœÃ‚Â§ÃƒËœÃ‚Â³ÃƒËœÃ‚Â§ÃƒËœÃ‚Âª ÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Å¾Ãƒâ„¢Ã‚ÂÃƒËœÃ‚Â¹Ãƒâ„¢Ã¢â‚¬ËœÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Å¾ÃƒËœÃ‚Â©
        // if (Schema::hasColumn('leave_policies', 'show_in_app')) $q->where('show_in_app', true);

        // ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ year filter (id vs year)
        $this->applyLeavePolicyYearFilter($q);

        if (Schema::hasColumn('leave_policies', 'gender') && in_array($gender, ['male', 'female'], true)) {
            $q->whereIn('gender', ['all', $gender]);
        }

        $policy = $q->first();

        if (!$policy) {
            abort(422, tr('This policy is not available for the selected employee.'));
        }

        // ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ Check contract type exclusions
        $excluded = (array) ($policy->excluded_contract_types ?? []);
        if (in_array($employee->contract_type, $excluded)) {
            abort(422, tr('Your contract type is not eligible for this leave policy.'));
        }

        return $policy;
    }

    protected function normalizeEmployeeGender(Employee $employee): string
    {
        $raw = strtolower(trim((string) ($employee->gender ?? $employee->sex ?? '')));

        $map = [
            'm' => 'male', 'male' => 'male', 'ÃƒËœÃ‚Â°Ãƒâ„¢Ã†â€™ÃƒËœÃ‚Â±' => 'male', 'man' => 'male',
            'f' => 'female', 'female' => 'female', 'ÃƒËœÃ‚Â£Ãƒâ„¢Ã¢â‚¬Â ÃƒËœÃ‚Â«Ãƒâ„¢Ã¢â‚¬Â°' => 'female', 'woman' => 'female',
        ];

        return $map[$raw] ?? $raw;
    }

    // =========================================================
    // ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ NEW: work_schedules helpers (safe fallback)
    // =========================================================
    protected function normalizeWorkingDaysArray($raw): array
    {
        if (!is_array($raw)) return [];

        $map = [
            'sunday' => 0, 'sun' => 0,
            'monday' => 1, 'mon' => 1,
            'tuesday' => 2, 'tue' => 2,
            'wednesday' => 3, 'wed' => 3,
            'thursday' => 4, 'thu' => 4,
            'friday' => 5, 'fri' => 5,
            'saturday' => 6, 'sat' => 6,
        ];

        $out = [];
        foreach ($raw as $d) {
            if (is_numeric($d)) { $out[] = (int) $d; continue; }
            $k = strtolower(trim((string) $d));
            if (isset($map[$k])) $out[] = (int) $map[$k];
        }

        $out = array_values(array_unique(array_filter($out, fn ($x) => $x >= 0 && $x <= 6)));
        return $out;
    }

    protected function pickRowTime($row, array $candidates): ?string
    {
        foreach ($candidates as $col) {
            if (isset($row->{$col}) && $row->{$col}) {
                return substr((string) $row->{$col}, 0, 5);
            }
        }
        return null;
    }

    protected function pickRowBool($row, array $candidates): bool
    {
        foreach ($candidates as $col) {
            if (isset($row->{$col})) {
                return (bool) $row->{$col};
            }
        }
        return false;
    }

    protected function getDefaultWorkScheduleRow(): ?object
    {
        if (!Schema::hasTable('work_schedules')) return null;

        $table = 'work_schedules';
        $coCol = $this->detectCompanyColumn($table);

        return DB::table($table)
            ->when($coCol, fn ($q) => $q->where($coCol, $this->companyId))
            ->when(Schema::hasColumn($table, 'is_active'), fn ($q) => $q->where('is_active', 1))
            ->when(Schema::hasColumn($table, 'is_default'), fn ($q) => $q->orderByDesc('is_default'))
            ->orderByDesc('id')
            ->first();
    }

    protected function getWorkSchedulePeriodsForDate(Carbon $date): array
    {
        $schedule = $this->getDefaultWorkScheduleRow();
        $sid = (int) ($schedule->id ?? 0);
        if ($sid <= 0) return [];

        // 1) exceptions (specific_date first, then day_of_week)
        if (Schema::hasTable('work_schedule_exceptions')) {
            $exTable = 'work_schedule_exceptions';
            $dateStr = $date->toDateString();

            $dowInt = (int) $date->dayOfWeek;          // 0..6
            $dowFull = strtolower($date->format('l')); // saturday
            $dowShort = substr($dowFull, 0, 3);        // sat

            $ex = DB::table($exTable)
                ->where('work_schedule_id', $sid)
                ->when(Schema::hasColumn($exTable, 'is_active'), fn ($q) => $q->where('is_active', 1))
                ->where(function ($q) use ($dateStr, $dowInt, $dowFull, $dowShort) {
                    if (Schema::hasColumn('work_schedule_exceptions', 'specific_date')) {
                        $q->whereDate('specific_date', $dateStr);
                    }

                    if (Schema::hasColumn('work_schedule_exceptions', 'day_of_week')) {
                        $q->orWhereIn('day_of_week', [$dowInt, $dowFull, $dowShort]);
                    }
                })
                ->get();

            if ($ex->isNotEmpty()) {
                $specific = $ex->filter(function ($r) use ($dateStr) {
                    if (!property_exists($r, 'specific_date')) return false;
                    if (empty($r->specific_date)) return false;
                    return substr((string)$r->specific_date, 0, 10) === $dateStr;
                });

                $rows = $specific->isNotEmpty() ? $specific : $ex->filter(fn ($r) => empty($r->specific_date ?? null));

                return $rows->map(function ($r) {
                    $start = $this->pickRowTime($r, ['start_time', 'from_time', 'starts_at', 'shift_start']);
                    $end   = $this->pickRowTime($r, ['end_time', 'to_time', 'ends_at', 'shift_end']);

                    return [
                        'start' => $start ?: '',
                        'end' => $end ?: '',
                        'is_night' => $this->pickRowBool($r, ['is_night_shift', 'night_shift', 'is_night']),
                    ];
                })->values()->all();
            }
        }

        // 2) base periods
        if (!Schema::hasTable('work_schedule_periods')) return [];

        $pTable = 'work_schedule_periods';

        $rows = DB::table($pTable)
            ->where('work_schedule_id', $sid)
            ->orderBy(Schema::hasColumn($pTable, 'sort_order') ? 'sort_order' : 'id')
            ->get();

        return $rows->map(function ($r) {
            $start = $this->pickRowTime($r, ['start_time', 'from_time', 'starts_at', 'shift_start']);
            $end   = $this->pickRowTime($r, ['end_time', 'to_time', 'ends_at', 'shift_end']);

            return [
                'start' => $start ?: '',
                'end' => $end ?: '',
                'is_night' => $this->pickRowBool($r, ['is_night_shift', 'night_shift', 'is_night']),
            ];
        })->values()->all();
    }

    public function getLeaveWorkPeriodOptionsProperty(): array
    {
        if ($this->create_leave_duration_unit !== 'half_day' || (int) $this->employee_id <= 0 || trim($this->start_date) === '') {
            return [];
        }

        try {
            $employee = Employee::query()
                ->when($this->employeeCompanyColumn, fn ($q) => $q->where($this->employeeCompanyColumn, $this->companyId))
                ->find((int) $this->employee_id);

            if (!$employee) {
                return [];
            }

            return $this->getEmployeeWorkSchedulePeriodsForDate($employee, Carbon::parse($this->start_date));
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected function getEmployeeWorkSchedulePeriodsForDate(Employee $employee, Carbon $date): array
    {
        if (!class_exists(WorkScheduleService::class)) {
            return [];
        }

        try {
            $service = app(WorkScheduleService::class);
            $dateStr = $date->toDateString();
            $schedule = $service->getEffectiveSchedule((int) $this->companyId, $employee, $dateStr);
            $holidays = $service->getHolidays((int) $this->companyId, $dateStr, $dateStr);
            $metrics = $service->getMetricsForDate($dateStr, $schedule, $holidays, $employee, [
                'leaves' => collect(),
                'missions' => collect(),
                'permissions' => collect(),
            ]);

            return collect($metrics['periods'] ?? [])
                ->map(function ($period) {
                    $start = substr((string) ($period['start_time'] ?? $period['start'] ?? ''), 0, 5);
                    $end = substr((string) ($period['end_time'] ?? $period['end'] ?? ''), 0, 5);
                    $id = (int) ($period['id'] ?? 0);

                    return [
                        'id' => $id,
                        'start_time' => $start,
                        'end_time' => $end,
                        'is_night_shift' => (bool) ($period['is_night_shift'] ?? $period['is_night'] ?? false),
                        'label' => trim(($start ?: '--:--') . ' - ' . ($end ?: '--:--')),
                    ];
                })
                ->filter(fn ($period) => (int) $period['id'] > 0 && $period['start_time'] !== '' && $period['end_time'] !== '')
                ->values()
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    protected function resolveLeaveWorkSchedulePeriod(Employee $employee, Carbon $date, int $periodId): ?array
    {
        $periods = $this->getEmployeeWorkSchedulePeriodsForDate($employee, $date);

        if (count($periods) <= 1) {
            $this->addError('leave_work_schedule_period_id', tr('Half-day leave is only available when the employee schedule has more than one work period.'));
            return null;
        }

        if ($periodId <= 0) {
            $this->addError('leave_work_schedule_period_id', tr('Please select the work period.'));
            return null;
        }

        foreach ($periods as $period) {
            if ((int) $period['id'] === $periodId) {
                return $period;
            }
        }

        $this->addError('leave_work_schedule_period_id', tr('Selected work period is not available for this employee.'));
        return null;
    }

    protected function computePeriodMinutes(Carbon $date, array $period): int
    {
        $start = $this->parseTimeSafe((string) ($period['start_time'] ?? ''));
        $end = $this->parseTimeSafe((string) ($period['end_time'] ?? ''));

        if (!$start || !$end) {
            return 0;
        }

        $startAt = $date->copy()->setTime($start->hour, $start->minute, 0);
        $endAt = $date->copy()->setTime($end->hour, $end->minute, 0);

        if ((bool) ($period['is_night_shift'] ?? false) || $endAt->lte($startAt)) {
            $endAt->addDay();
        }

        return max(0, (int) $startAt->diffInMinutes($endAt, false));
    }
    protected function getWorkdayMinutesForDate(Carbon $date): int
    {
        $periods = $this->getWorkSchedulePeriodsForDate($date);
        if (empty($periods)) return 0;

        $sum = 0;
        foreach ($periods as $p) {
            $a = $this->parseTimeSafe((string) ($p['start'] ?? ''));
            $b = $this->parseTimeSafe((string) ($p['end'] ?? ''));
            if (!$a || !$b) continue;

            $aDT = $date->copy()->setTime($a->hour, $a->minute, 0);
            $bDT = $date->copy()->setTime($b->hour, $b->minute, 0);

            $night = (bool) ($p['is_night'] ?? false);
            if ($night || $bDT->lte($aDT)) $bDT->addDay();

            $mins = $aDT->diffInMinutes($bDT, false);
            if ($mins > 0) $sum += $mins;
        }

        return max(0, $sum);
    }

    // =========================================================
    // ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ Time parsing + minutes
    // =========================================================
    protected function computeMinutesSafe(string $from, string $to): int
    {
        $a = $this->parseTimeSafe($from);
        $b = $this->parseTimeSafe($to);

        if (! $a || ! $b) return 0;

        // ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ NEW: Ãƒâ„¢Ã¢â‚¬Å¾Ãƒâ„¢Ã‹â€  "ÃƒËœÃ‚Â¥Ãƒâ„¢Ã¢â‚¬Å¾Ãƒâ„¢Ã¢â‚¬Â°" <= "Ãƒâ„¢Ã¢â‚¬Â¦Ãƒâ„¢Ã¢â‚¬Â " => ÃƒËœÃ‚ÂºÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Å¾ÃƒËœÃ‚Â¨ÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Â¹ Ãƒâ„¢Ã‹â€ ÃƒËœÃ‚Â±ÃƒËœÃ‚Â¯Ãƒâ„¢Ã…Â ÃƒËœÃ‚Â© Ãƒâ„¢Ã¢â‚¬Å¾Ãƒâ„¢Ã…Â Ãƒâ„¢Ã¢â‚¬Å¾Ãƒâ„¢Ã…Â ÃƒËœÃ‚Â© (ÃƒËœÃ‚ÂªÃƒËœÃ‚Â¹ÃƒËœÃ‚Â¨ÃƒËœÃ‚Â± Ãƒâ„¢Ã¢â‚¬Â¦Ãƒâ„¢Ã¢â‚¬Â ÃƒËœÃ‚ÂªÃƒËœÃ‚ÂµÃƒâ„¢Ã‚Â ÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Å¾Ãƒâ„¢Ã¢â‚¬Å¾Ãƒâ„¢Ã…Â Ãƒâ„¢Ã¢â‚¬Å¾)
        if ($b->lte($a)) {
            $hasAmPm = (bool) preg_match('/\b(AM|PM)\b/i', ($from . ' ' . $to));

            // Ãƒâ„¢Ã¢â‚¬Å¾Ãƒâ„¢Ã‹â€  ÃƒËœÃ‚Â¯ÃƒËœÃ‚Â§ÃƒËœÃ‚Â®Ãƒâ„¢Ã¢â‚¬Å¾ AM/PM: ÃƒËœÃ‚Â§ÃƒËœÃ‚Â¹ÃƒËœÃ‚ÂªÃƒËœÃ‚Â¨ÃƒËœÃ‚Â± "ÃƒËœÃ‚Â¥Ãƒâ„¢Ã¢â‚¬Å¾Ãƒâ„¢Ã¢â‚¬Â°" Ãƒâ„¢Ã‚ÂÃƒâ„¢Ã…Â  ÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Å¾Ãƒâ„¢Ã…Â Ãƒâ„¢Ã‹â€ Ãƒâ„¢Ã¢â‚¬Â¦ ÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Å¾ÃƒËœÃ‚ÂªÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Å¾Ãƒâ„¢Ã…Â 
            if ($hasAmPm) {
                $b = $b->copy()->addDay();
            } else {
                // fallback: Ãƒâ„¢Ã¢â‚¬Å¾Ãƒâ„¢Ã‹â€  Ãƒâ„¢Ã†â€™Ãƒâ„¢Ã¢â‚¬Å¾ÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Â¡Ãƒâ„¢Ã¢â‚¬Â¦ÃƒËœÃ‚Â§ ÃƒËœÃ‚Â£Ãƒâ„¢Ã¢â‚¬Å¡Ãƒâ„¢Ã¢â‚¬Å¾ Ãƒâ„¢Ã¢â‚¬Â¦Ãƒâ„¢Ã¢â‚¬Â  12 Ãƒâ„¢Ã‹â€ Ãƒâ„¢Ã‚ÂÃƒâ„¢Ã…Â Ãƒâ„¢Ã¢â‚¬Â¡ ÃƒËœÃ‚Â§ÃƒËœÃ‚Â­ÃƒËœÃ‚ÂªÃƒâ„¢Ã¢â‚¬Â¦ÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Å¾ ÃƒËœÃ‚Â¥ÃƒËœÃ‚Â¯ÃƒËœÃ‚Â®ÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Å¾ ÃƒËœÃ‚Â¨ÃƒËœÃ‚Â¯Ãƒâ„¢Ã‹â€ Ãƒâ„¢Ã¢â‚¬Â  24h
                if ($a->hour < 12 && $b->hour < 12) {
                    $try = $b->copy()->addHours(12);
                    $b = $try->gt($a) ? $try : $b->copy()->addDay();
                } else {
                    $b = $b->copy()->addDay();
                }
            }
        }

        $diff = $a->diffInMinutes($b, false);
        return $diff > 0 ? $diff : 0;
    }

    protected function parseTimeSafe(string $t): ?Carbon
    {
        $t = trim($t);
        if ($t === '') return null;

        // ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ Ãƒâ„¢Ã¢â‚¬Â ÃƒËœÃ‚Â¸Ãƒâ„¢Ã¢â‚¬ËœÃƒâ„¢Ã‚Â Ãƒâ„¢Ã†â€™Ãƒâ„¢Ã¢â‚¬Å¾ ÃƒËœÃ‚Â¹Ãƒâ„¢Ã¢â‚¬Å¾ÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Â¦ÃƒËœÃ‚Â§ÃƒËœÃ‚Âª ÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Å¾ÃƒËœÃ‚Â§ÃƒËœÃ‚ÂªÃƒËœÃ‚Â¬ÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Â¡/ÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Å¾Ãƒâ„¢Ã¢â‚¬Â¦ÃƒËœÃ‚Â­ÃƒËœÃ‚Â§ÃƒËœÃ‚Â±Ãƒâ„¢Ã‚Â ÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Å¾ÃƒËœÃ‚Â®Ãƒâ„¢Ã‚ÂÃƒâ„¢Ã…Â ÃƒËœÃ‚Â© ÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Å¾Ãƒâ„¢Ã¢â‚¬Â¦ÃƒËœÃ‚Â­ÃƒËœÃ‚ÂªÃƒâ„¢Ã¢â‚¬Â¦Ãƒâ„¢Ã¢â‚¬Å¾ÃƒËœÃ‚Â© (RTL/LTR/Bidi)
        $t = preg_replace('/[\x{061C}\x{200E}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}]/u', '', $t);

        // ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ ÃƒËœÃ‚Â§ÃƒËœÃ‚Â³ÃƒËœÃ‚ÂªÃƒËœÃ‚Â¨ÃƒËœÃ‚Â¯ÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Å¾ Ãƒâ„¢Ã¢â‚¬Â¦ÃƒËœÃ‚Â³ÃƒËœÃ‚Â§Ãƒâ„¢Ã‚ÂÃƒËœÃ‚Â§ÃƒËœÃ‚Âª ÃƒËœÃ‚Â®ÃƒËœÃ‚Â§ÃƒËœÃ‚ÂµÃƒËœÃ‚Â© (NBSP Ãƒâ„¢Ã‹â€ ÃƒËœÃ‚ÂºÃƒâ„¢Ã…Â ÃƒËœÃ‚Â±Ãƒâ„¢Ã¢â‚¬Â¡ÃƒËœÃ‚Â§) ÃƒËœÃ‚Â¥Ãƒâ„¢Ã¢â‚¬Å¾Ãƒâ„¢Ã¢â‚¬Â° Ãƒâ„¢Ã¢â‚¬Â¦ÃƒËœÃ‚Â³ÃƒËœÃ‚Â§Ãƒâ„¢Ã‚ÂÃƒËœÃ‚Â© ÃƒËœÃ‚Â¹ÃƒËœÃ‚Â§ÃƒËœÃ‚Â¯Ãƒâ„¢Ã…Â ÃƒËœÃ‚Â©
        $t = str_replace(["\xC2\xA0"], ' ', $t);
        $t = preg_replace('/\s+/u', ' ', $t);

        // ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ ÃƒËœÃ‚ÂªÃƒËœÃ‚Â­Ãƒâ„¢Ã‹â€ Ãƒâ„¢Ã…Â Ãƒâ„¢Ã¢â‚¬Å¾ ÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Å¾ÃƒËœÃ‚Â£ÃƒËœÃ‚Â±Ãƒâ„¢Ã¢â‚¬Å¡ÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Â¦ ÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Å¾ÃƒËœÃ‚Â¹ÃƒËœÃ‚Â±ÃƒËœÃ‚Â¨Ãƒâ„¢Ã…Â ÃƒËœÃ‚Â©/ÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Å¾Ãƒâ„¢Ã‚ÂÃƒËœÃ‚Â§ÃƒËœÃ‚Â±ÃƒËœÃ‚Â³Ãƒâ„¢Ã…Â ÃƒËœÃ‚Â© ÃƒËœÃ‚Â¥Ãƒâ„¢Ã¢â‚¬Å¾Ãƒâ„¢Ã¢â‚¬Â° ÃƒËœÃ‚Â£ÃƒËœÃ‚Â±Ãƒâ„¢Ã¢â‚¬Å¡ÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Â¦ ÃƒËœÃ‚Â¥Ãƒâ„¢Ã¢â‚¬Â ÃƒËœÃ‚Â¬Ãƒâ„¢Ã¢â‚¬Å¾Ãƒâ„¢Ã…Â ÃƒËœÃ‚Â²Ãƒâ„¢Ã…Â ÃƒËœÃ‚Â© (ÃƒËœÃ‚Â§ÃƒËœÃ‚Â­ÃƒËœÃ‚ÂªÃƒâ„¢Ã…Â ÃƒËœÃ‚Â§ÃƒËœÃ‚Â·)
        $t = str_replace(
            ['Ãƒâ„¢Ã‚Â ','Ãƒâ„¢Ã‚Â¡','Ãƒâ„¢Ã‚Â¢','Ãƒâ„¢Ã‚Â£','Ãƒâ„¢Ã‚Â¤','Ãƒâ„¢Ã‚Â¥','Ãƒâ„¢Ã‚Â¦','Ãƒâ„¢Ã‚Â§','Ãƒâ„¢Ã‚Â¨','Ãƒâ„¢Ã‚Â©','Ãƒâ€ºÃ‚Â°','Ãƒâ€ºÃ‚Â±','Ãƒâ€ºÃ‚Â²','Ãƒâ€ºÃ‚Â³','Ãƒâ€ºÃ‚Â´','Ãƒâ€ºÃ‚Âµ','Ãƒâ€ºÃ‚Â¶','Ãƒâ€ºÃ‚Â·','Ãƒâ€ºÃ‚Â¸','Ãƒâ€ºÃ‚Â¹'],
            ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'],
            $t
        );

        // ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ ÃƒËœÃ‚Â¯ÃƒËœÃ‚Â¹Ãƒâ„¢Ã¢â‚¬Â¦ ÃƒËœÃ‚Â¹ÃƒËœÃ‚Â±ÃƒËœÃ‚Â¨Ãƒâ„¢Ã…Â  (Ãƒâ„¢Ã¢â‚¬Å¾Ãƒâ„¢Ã‹â€  ÃƒËœÃ‚Â­ÃƒËœÃ‚ÂµÃƒâ„¢Ã¢â‚¬Å¾)
        $t = str_replace(['ÃƒËœÃ‚Âµ', 'ÃƒËœÃ‚ÂµÃƒËœÃ‚Â¨ÃƒËœÃ‚Â§ÃƒËœÃ‚Â­ÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Â¹', 'ÃƒËœÃ‚ÂµÃƒËœÃ‚Â¨ÃƒËœÃ‚Â§ÃƒËœÃ‚Â­ÃƒËœÃ‚Â§'], 'AM', $t);
        $t = str_replace(['Ãƒâ„¢Ã¢â‚¬Â¦', 'Ãƒâ„¢Ã¢â‚¬Â¦ÃƒËœÃ‚Â³ÃƒËœÃ‚Â§ÃƒËœÃ‚Â¡Ãƒâ„¢Ã¢â‚¬Â¹', 'Ãƒâ„¢Ã¢â‚¬Â¦ÃƒËœÃ‚Â³ÃƒËœÃ‚Â§ÃƒËœÃ‚Â¡'], 'PM', $t);

        // ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ Ãƒâ„¢Ã‹â€ ÃƒËœÃ‚Â­Ãƒâ„¢Ã¢â‚¬ËœÃƒËœÃ‚Â¯ AM/PM + ÃƒËœÃ‚Â¹ÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Å¾ÃƒËœÃ‚Â¬ "03:28PM" ÃƒËœÃ‚Â¨ÃƒËœÃ‚Â¯Ãƒâ„¢Ã‹â€ Ãƒâ„¢Ã¢â‚¬Â  Ãƒâ„¢Ã¢â‚¬Â¦ÃƒËœÃ‚Â³ÃƒËœÃ‚Â§Ãƒâ„¢Ã‚ÂÃƒËœÃ‚Â©
        $t = str_ireplace(['am', 'pm'], ['AM', 'PM'], $t);
        $t = preg_replace('/(\d)(AM|PM)$/i', '$1 $2', $t);

        // ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ Regex fallback Ãƒâ„¢Ã¢â‚¬Å¡Ãƒâ„¢Ã‹â€ Ãƒâ„¢Ã…Â  ÃƒËœÃ‚Â¬ÃƒËœÃ‚Â¯Ãƒâ„¢Ã¢â‚¬Â¹ÃƒËœÃ‚Â§: Ãƒâ„¢Ã…Â Ãƒâ„¢Ã¢â‚¬Å¾Ãƒâ„¢Ã¢â‚¬Å¡ÃƒËœÃ‚Â· "03:40 PM" ÃƒËœÃ‚Â­ÃƒËœÃ‚ÂªÃƒâ„¢Ã¢â‚¬Â° Ãƒâ„¢Ã¢â‚¬Å¾Ãƒâ„¢Ã‹â€  Ãƒâ„¢Ã‚ÂÃƒâ„¢Ã…Â Ãƒâ„¢Ã¢â‚¬Â¡ ÃƒËœÃ‚Â±Ãƒâ„¢Ã¢â‚¬Â¦Ãƒâ„¢Ã‹â€ ÃƒËœÃ‚Â²/Ãƒâ„¢Ã‚ÂÃƒâ„¢Ã‹â€ ÃƒËœÃ‚Â§ÃƒËœÃ‚ÂµÃƒâ„¢Ã¢â‚¬Å¾ ÃƒËœÃ‚ÂºÃƒËœÃ‚Â±Ãƒâ„¢Ã…Â ÃƒËœÃ‚Â¨ÃƒËœÃ‚Â©
        if (preg_match('/^\s*(\d{1,2})\s*[:ÃƒÂ¯Ã‚Â¼Ã…Â¡Ãƒâ„¢Ã‚Â«.]\s*(\d{2})(?:\s*[:ÃƒÂ¯Ã‚Â¼Ã…Â¡Ãƒâ„¢Ã‚Â«.]\s*(\d{2}))?\s*(AM|PM)?\s*$/i', $t, $m)) {
            $h = (int) $m[1];
            $i = (int) $m[2];
            $ampm = strtoupper(trim((string)($m[4] ?? '')));

            if ($ampm === 'PM' && $h < 12) $h += 12;
            if ($ampm === 'AM' && $h === 12) $h = 0;

            if ($h >= 0 && $h <= 23 && $i >= 0 && $i <= 59) {
                return Carbon::create(2000, 1, 1, $h, $i, 0);
            }
        }

        // ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ ÃƒËœÃ‚ÂµÃƒâ„¢Ã…Â ÃƒËœÃ‚Âº Ãƒâ„¢Ã¢â‚¬Â¦ÃƒËœÃ‚Â¨ÃƒËœÃ‚Â§ÃƒËœÃ‚Â´ÃƒËœÃ‚Â±ÃƒËœÃ‚Â©
        $formats = [
            'H:i', 'H:i:s',
            'h:i A', 'h:i:s A',
            'g:i A', 'g:i:s A',
            'h:iA',  'h:i:sA',
            'g:iA',  'g:i:sA',
        ];

        foreach ($formats as $fmt) {
            try {
                $dt = Carbon::createFromFormat($fmt, $t);
                return $dt->setDate(2000, 1, 1);
            } catch (\Throwable $e) {}
        }

        // ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ fallback ÃƒËœÃ‚Â£ÃƒËœÃ‚Â®Ãƒâ„¢Ã…Â ÃƒËœÃ‚Â±
        try {
            return Carbon::parse($t)->setDate(2000, 1, 1);
        } catch (\Throwable $e) {
            return null;
        }
    }

    // =========================================================
    // ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ Existing Group Leave/Cut Leave code (unchanged)
    // =========================================================
    public function openCreateGroupLeave(): void
    {
        $this->requireAttendanceAny('attendance.leaves.manage');
        $this->resetValidation();

        $this->createGroupLeaveOpen = true;

        $this->group_leave_deduct_from_balance = false;
        $this->group_leave_policy_id = 0;

        $this->group_start_date = '';
        $this->group_end_date = '';
        $this->group_reason = '';

        $this->groupEmployeeSearch = '';
        $this->groupDepartmentId = null;
        $this->groupJobTitleId = null;
        $this->groupEmployeeIds = [];
        $this->groupBranchId = null;
        $this->groupContractType = '';

        // Ãƒâ„¢Ã¢â‚¬Å¡ÃƒËœÃ‚Â¨Ãƒâ„¢Ã¢â‚¬Å¾: $this->resetGroupLeavePolicyMeta();
        // ÃƒËœÃ‚Â¨ÃƒËœÃ‚Â¹ÃƒËœÃ‚Â¯: Ãƒâ„¢Ã¢â‚¬Â ÃƒËœÃ‚Â¬ÃƒËœÃ‚Â¨ÃƒËœÃ‚Â±Ãƒâ„¢Ã¢â‚¬Â¡ÃƒËœÃ‚Â§ Full Day (ÃƒËœÃ‚Â¨ÃƒËœÃ‚Â¯Ãƒâ„¢Ã‹â€ Ãƒâ„¢Ã¢â‚¬Â  ÃƒËœÃ‚Â³Ãƒâ„¢Ã…Â ÃƒËœÃ‚Â§ÃƒËœÃ‚Â³ÃƒËœÃ‚Â©)
        $this->group_leave_duration_unit = 'full_day';
        $this->group_leave_half_day_part = 'first_half';
        $this->group_leave_from_time = '';
        $this->group_leave_to_time = '';
        $this->group_leave_minutes = 0;
    }


    public function closeCreateGroupLeave(): void { $this->createGroupLeaveOpen = false; }


    public function openCutLeave(): void
    {
        $this->requireAttendanceAny('attendance.leaves.manage');
        $this->resetValidation();
        $this->cutLeaveOpen = true;
        $this->cut_leave_request_id = 0;
        $this->cut_new_end_date = '';
        $this->cut_reason = '';
    }

    public function closeCutLeave(): void { $this->cutLeaveOpen = false; }

    public function saveCutLeaveRequest(): void
    {
        $this->requireAttendanceAny('attendance.leaves.manage');
        $this->ensureCanManage();

        $data = $this->validate(
            [
                'cut_leave_request_id' => ['required', 'integer', 'min:1'],
                'cut_new_end_date' => ['required', 'date'],
                'cut_reason' => ['nullable', 'string', 'max:2000'],
            ],
            $this->leaveRequestsValidationMessages(),
            $this->leaveRequestsValidationAttributes()
        );

      $allowed = $this->lpAllowedBranchIdsSafe();
        $branchCol = $this->employeeBranchColumn ?: $this->detectEmployeeBranchColumn();

        $original = AttendanceLeaveRequest::query()
            ->where('company_id', $this->companyId)
            ->when($branchCol && !empty($allowed), function ($q) use ($branchCol, $allowed) {
                $q->whereHas('employee', fn ($e) => $e->whereIn($branchCol, $allowed));
            })
            ->findOrFail((int) $data['cut_leave_request_id']);

        if ($original->status !== 'approved' || $original->salary_processed_at) {
            session()->flash('error', tr('Invalid cut operation'));
            return;
        }

        $origStart = Carbon::parse($original->start_date)->startOfDay();
        $origEnd   = Carbon::parse($original->end_date)->startOfDay();

        if ($origStart->equalTo($origEnd)) {
            session()->flash('error', tr('This leave is only one day. Use Cancel instead of Cut.'));
            return;
        }

        $cutEnd = Carbon::parse($data['cut_new_end_date'])->startOfDay();

        // Ãƒâ„¢Ã¢â‚¬Å¾ÃƒËœÃ‚Â§ÃƒËœÃ‚Â²Ãƒâ„¢Ã¢â‚¬Â¦ Ãƒâ„¢Ã…Â Ãƒâ„¢Ã†â€™Ãƒâ„¢Ã‹â€ Ãƒâ„¢Ã¢â‚¬Â  ÃƒËœÃ‚Â¯ÃƒËœÃ‚Â§ÃƒËœÃ‚Â®Ãƒâ„¢Ã¢â‚¬Å¾ ÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Å¾Ãƒâ„¢Ã¢â‚¬Â¦ÃƒËœÃ‚Â¯ÃƒËœÃ‚Â© Ãƒâ„¢Ã‹â€ ÃƒËœÃ‚Â£Ãƒâ„¢Ã¢â‚¬Å¡Ãƒâ„¢Ã¢â‚¬Å¾ Ãƒâ„¢Ã¢â‚¬Â¦Ãƒâ„¢Ã¢â‚¬Â  ÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Å¾Ãƒâ„¢Ã¢â‚¬Â Ãƒâ„¢Ã¢â‚¬Â¡ÃƒËœÃ‚Â§Ãƒâ„¢Ã…Â ÃƒËœÃ‚Â©
        if ($cutEnd->lt($origStart) || $cutEnd->gte($origEnd)) {
            session()->flash('error', tr('Invalid cut end date.'));
            return;
        }

        AttendanceLeaveCutRequest::create([
            'company_id' => $this->companyId,
            'original_leave_request_id' => (int) $original->id,
            'employee_id' => (int) $original->employee_id,
            'leave_policy_id' => (int) $original->leave_policy_id,
            'policy_year_id' => (int) $original->policy_year_id,
            'original_start_date' => $origStart->toDateString(),
            'original_end_date'   => $origEnd->toDateString(),
            'cut_end_date'        => $cutEnd->toDateString(),
            'postponed_start_date'=> $cutEnd->copy()->addDay()->toDateString(),
            'postponed_end_date'  => $origEnd->toDateString(),
            'reason' => $data['cut_reason'] ?? null,
            'status' => 'pending',
            'requested_by' => auth()->id(),
            'requested_at' => now(),
        ]);

        session()->flash('success', tr('Saved successfully'));
        $this->dispatch('toast', [
            'type'    => 'success',
            'title'   => tr('Success'),
            'message' => tr('Saved successfully'),
        ]);
        $this->closeCutLeave();
    }


    protected function computeRequestedDays(LeavePolicy $policy, Carbon $start, Carbon $end): float
    {
        $settings = (array) ($policy->settings ?? []);
        $weekendPolicy = (string) data_get($settings, 'weekend_policy', 'exclude');
        $workingDays = $this->companyWorkingDays();
        $holidays = OfficialHolidayOccurrence::where('company_id', $this->companyId)
            ->where(fn($q) => $q->whereBetween('start_date', [$start->toDateString(), $end->toDateString()])
                ->orWhereBetween('end_date', [$start->toDateString(), $end->toDateString()]))
            ->get();

        $wsService = class_exists(\Athka\SystemSettings\Services\WorkScheduleService::class) 
            ? app(\Athka\SystemSettings\Services\WorkScheduleService::class) : null;
            
        $employee = null;
        if (!empty($this->employee_id)) {
            $employee = \Athka\Employees\Models\Employee::find($this->employee_id);
        }

        $days = 0.0;
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            if ($holidays->contains(fn($h) => $cursor->between($h->start_date, $h->end_date))) {
                $cursor->addDay();
                continue;
            }

            $isWorkday = false;
            if ($weekendPolicy === 'include') {
                $isWorkday = true;
            } else {
                if ($wsService && $employee) {
                    $schedule = $wsService->getEffectiveSchedule((int)$this->companyId, $employee, $cursor->toDateString());
                    if ($schedule) {
                        $raw = $schedule->work_days ?? [];
                        $workDaysArr = is_string($raw) ? json_decode($raw, true) : $raw;
                        $workDaysArr = is_array($workDaysArr) ? array_map('strtolower', $workDaysArr) : [];
                        $dayNameStr = strtolower($cursor->englishDayOfWeek);
                        $isWorkday = in_array($dayNameStr, $workDaysArr, true);
                    } else {
                        $isWorkday = in_array((int)$cursor->dayOfWeek, $workingDays, true);
                    }
                } else {
                    $isWorkday = in_array((int)$cursor->dayOfWeek, $workingDays, true);
                }
            }

            if ($isWorkday) {
                $days += 1;
            }
            $cursor->addDay();
        }

        return $days;
    }

    protected function companyWorkingDays(): array
    {
        // 1) OperationalCalendar (existing behavior)
        $calTable = (new OperationalCalendar())->getTable();
        $calCoCol = $this->detectCompanyColumn($calTable);

        $row = OperationalCalendar::query()
            ->when($calCoCol, fn ($q) => $q->where($calCoCol, $this->companyId))
            ->first();

        $days = is_string($row?->working_days) ? json_decode($row->working_days, true) : $row?->working_days;
        $norm = $this->normalizeWorkingDaysArray($days);

        if (!empty($norm)) return $norm;

        // 2) work_schedules
        if (Schema::hasTable('work_schedules')) {
            $schedule = $this->getDefaultWorkScheduleRow();

            $raw = null;
            if ($schedule) {
                // candidates: work_days | working_days
                if (property_exists($schedule, 'work_days')) $raw = $schedule->work_days;
                elseif (property_exists($schedule, 'working_days')) $raw = $schedule->working_days;
            }

            $rawDays = is_string($raw) ? json_decode($raw, true) : $raw;
            $norm = $this->normalizeWorkingDaysArray($rawDays);

            if (!empty($norm)) return $norm;
        }

        // 3) default fallback
        return [6, 0, 1, 2, 3];
    }

    protected function leaveRequestsValidationMessages(): array
    {
        return [
            // ====== Create Leave ======
            'employee_id.required'     => tr('Please select an employee.'),
            'employee_id.integer'      => tr('Invalid employee.'),
            'employee_id.min'          => tr('Please select an employee.'),

            'leave_policy_id.required' => tr('Please select a leave policy.'),
            'leave_policy_id.integer'  => tr('Invalid leave policy.'),
            'leave_policy_id.min'      => tr('Please select a leave policy.'),

            'start_date.required'      => tr('Start date is required.'),
            'start_date.date'          => tr('Start date is not valid.'),

            'end_date.required'        => tr('End date is required.'),
            'end_date.date'            => tr('End date is not valid.'),
            'end_date.after_or_equal'  => tr('End date must be after or equal to start date.'),

            'leave_half_day_part.required' => tr('Please select half day type.'),

            'leave_from_time.required'    => tr('Start time is required.'),
            'leave_from_time.date_format' => tr('Start time format must be HH:MM.'),

            'leave_to_time.required'      => tr('End time is required.'),
            'leave_to_time.date_format'   => tr('End time format must be HH:MM.'),

            'leave_attachment.required' => tr('Attachment is required.'),
            'leave_attachment.file'     => tr('Attachment must be a file.'),
            'leave_attachment.max'      => tr('Attachment size is too large.'),
            'leave_attachment.mimes'    => tr('Attachment type is not allowed.'),

            'leave_note_ack.accepted'   => tr('You must acknowledge the note.'),

            // ====== Group Leave (NOW: no policy) ======
            // Ãƒâ„¢Ã¢â‚¬Å¡ÃƒËœÃ‚Â¨Ãƒâ„¢Ã¢â‚¬Å¾:
            // 'group_leave_policy_id.required' => tr('Please select a leave policy.'),
            // 'group_leave_policy_id.min'      => tr('Please select a leave policy.'),

            'group_leave_policy_id.required' => tr('Please select a leave policy.'),
            'group_leave_policy_id.min'      => tr('Please select a leave policy.'),

            'group_start_date.required'      => tr('Start date is required.'),
            'group_start_date.date'          => tr('Start date is not valid.'),

            'group_end_date.required'        => tr('End date is required.'),
            'group_end_date.date'            => tr('End date is not valid.'),
            'group_end_date.after_or_equal'  => tr('End date must be after or equal to start date.'),

            // ÃƒÂ¢Ã…â€œÃ¢â‚¬Â¦ NEW: group reason required
            'group_reason.required'          => tr('Reason is required.'),

            'groupEmployeeIds.required'      => tr('Please select at least one employee.'),
            'groupEmployeeIds.array'         => tr('Employees list is not valid.'),
            'groupEmployeeIds.min'           => tr('Please select at least one employee.'),

            // ====== Cut Leave ======
            'cut_leave_request_id.required' => tr('Please select an approved leave request.'),
            'cut_leave_request_id.min'      => tr('Please select an approved leave request.'),

            'cut_new_end_date.required'     => tr('Cut end date is required.'),
            'cut_new_end_date.date'         => tr('Cut end date is not valid.'),
        ];
    }

    protected function leaveRequestsValidationAttributes(): array
    {
        return [
            // Create Leave
            'employee_id'        => tr('Employee'),
            'leave_policy_id'    => tr('Policy'),
            'start_date'         => tr('Start Date'),
            'end_date'           => tr('End Date'),
            'leave_half_day_part'=> tr('Half day'),
            'leave_work_schedule_period_id'=> tr('Work period'),
            'leave_from_time'    => tr('From'),
            'leave_to_time'      => tr('To'),
            'leave_attachment'   => tr('Attachment'),
            'leave_note_ack'     => tr('Note'),

            // Group Leave
            'group_leave_policy_id' => tr('Policy'),
            'group_start_date'      => tr('Start Date'),
            'group_end_date'        => tr('End Date'),
            'group_reason'          => tr('Reason'),
            'groupEmployeeIds'      => tr('Employees'),

            // Cut Leave
            'cut_leave_request_id'  => tr('Approved Leave'),
            'cut_new_end_date'      => tr('Cut End Date'),
            'cut_reason'            => tr('Reason'),
        ];
    }


    protected function getCompanyWorkWindow(?string $date = null): array
    {
        $defaultStart = config('attendance.work_start', '08:00');
        $defaultEnd   = config('attendance.work_end', '16:00');

        $d = $date ? Carbon::parse($date)->startOfDay() : now()->startOfDay();

        $periods = $this->getWorkSchedulePeriodsForDate($d);
        if (!empty($periods)) {
            $starts = [];
            $ends = [];
            $hasNight = false;

            foreach ($periods as $p) {
                $s = (string) ($p['start'] ?? '');
                $e = (string) ($p['end'] ?? '');
                if ($s !== '') $starts[] = $s;
                if ($e !== '') $ends[] = $e;
                if (!empty($p['is_night'])) $hasNight = true;
            }

            sort($starts);
            sort($ends);

            $minStart = $starts[0] ?? $defaultStart;
            $maxEnd   = $hasNight ? '23:59' : ($ends[count($ends) - 1] ?? $defaultEnd);

            return [$minStart, $maxEnd];
        }

        $calTable = (new OperationalCalendar())->getTable();
        $calCoCol = $this->detectCompanyColumn($calTable);

        $row = OperationalCalendar::query()
            ->when($calCoCol, fn ($q) => $q->where($calCoCol, $this->companyId))
            ->first();

        if (! $row) {
            return [$defaultStart, $defaultEnd];
        }

        $candidatesStart = ['work_start_time','start_time','starts_at','shift_start','from_time'];
        $candidatesEnd   = ['work_end_time','end_time','ends_at','shift_end','to_time'];

        $start = null;
        foreach ($candidatesStart as $col) {
            if (isset($row->{$col}) && $row->{$col}) { $start = substr((string)$row->{$col}, 0, 5); break; }
        }

        $end = null;
        foreach ($candidatesEnd as $col) {
            if (isset($row->{$col}) && $row->{$col}) { $end = substr((string)$row->{$col}, 0, 5); break; }
        }

        return [$start ?: $defaultStart, $end ?: $defaultEnd];
    }

    protected function validateHoursWithinWorkWindow(Carbon $date, string $from, string $to): bool
    {
        $workingDays = $this->companyWorkingDays();
        if (! in_array((int) $date->dayOfWeek, $workingDays, true)) {
            $this->addError('start_date', tr('Selected date is not a working day.'));
            return false;
        }

        $fromT = $this->parseTimeSafe($from);
        $toT   = $this->parseTimeSafe($to);
        if (! $fromT || ! $toT) return true; 

        $fromDT = $date->copy()->setTime($fromT->hour, $fromT->minute, 0);
        $toDT   = $date->copy()->setTime($toT->hour,   $toT->minute,   0);

        $periods = $this->getWorkSchedulePeriodsForDate($date);

        if (empty($periods)) {
            [$ws, $we] = $this->getCompanyWorkWindow($date->toDateString());

            $wsT = $this->parseTimeSafe($ws);
            $weT = $this->parseTimeSafe($we);
            if (! $wsT || ! $weT) return true;

            $wsDT = $date->copy()->setTime($wsT->hour, $wsT->minute, 0);
            $weDT = $date->copy()->setTime($weT->hour, $weT->minute, 0);

            if ($toDT->lte($fromDT)) {
                $this->addError('leave_to_time', tr('End time must be after start time'));
                return false;
            }

            if ($fromDT->lt($wsDT) || $toDT->gt($weDT)) {
                $this->addError('leave_from_time', tr('Time must be within working hours') . " ($ws - $we)");
                return false;
            }

            return true;
        }

        if ($toDT->lte($fromDT)) {
            $hasNight = collect($periods)->contains(fn ($p) => !empty($p['is_night']));
            if (! $hasNight) {
                $this->addError('leave_to_time', tr('End time must be after start time'));
                return false;
            }
            $toDT->addDay();
        }

        foreach ($periods as $p) {
            $ps = $this->parseTimeSafe((string) ($p['start'] ?? ''));
            $pe = $this->parseTimeSafe((string) ($p['end'] ?? ''));
            if (! $ps || ! $pe) continue;

            $psDT = $date->copy()->setTime($ps->hour, $ps->minute, 0);
            $peDT = $date->copy()->setTime($pe->hour, $pe->minute, 0);

            $night = (bool) ($p['is_night'] ?? false);
            if ($night || $peDT->lte($psDT)) $peDT->addDay();

            if ($fromDT->gte($psDT) && $toDT->lte($peDT)) {
                return true;
            }
        }

        [$ws, $we] = $this->getCompanyWorkWindow($date->toDateString());
        $this->addError('leave_from_time', tr('Time must be within working hours') . " ($ws - $we)");
        return false;
    }

    public function updatedGroupLeaveDurationUnit($value): void
    {
        if (in_array($value, ['half_day', 'hours'], true)) {
            $this->group_end_date = $this->normalizeDate($this->group_start_date);
        }

        if ($value !== 'hours') {
            $this->group_leave_hours = null;
        }
        if ($value !== 'half_day') {
            $this->group_leave_half_day_period = 'am';
        }
    }


    private function normalizeDate(?string $date): string
    {
        $date = (string) $date;
        return $date ? substr($date, 0, 10) : '';
    }

    private function calcPartialRequestedDays(string $unit, ?float $hours, LeavePolicy $policy): float
    {
        $hoursPerDay = (float) ($policy->hours_per_day ?? 8);

        if ($unit === 'half_day') {
            return 0.5;
        }

        if ($unit === 'hours') {
            $h = (float) ($hours ?? 0);
            return $hoursPerDay > 0 ? round($h / $hoursPerDay, 4) : 0.0;
        }

        return 0.0;
    }
    protected function currentCompanyId(): int
    {
        return (int) (auth()->user()->saas_company_id ?? auth()->user()->company_id ?? 0);
    }

    protected function companyColumnFor(string $table): ?string
    {
        if (\Schema::hasColumn($table, 'saas_company_id')) return 'saas_company_id';
        if (\Schema::hasColumn($table, 'company_id')) return 'company_id';
        return null;
    }

    protected function hydratePolicyMeta(int $policyId, string $context = 'create'): void
    {
        if (!$policyId) return;

        $policy = \Athka\SystemSettings\Models\LeavePolicy::query()
            ->whereKey($policyId)
            ->first();

        if (!$policy) return;

        $durationUnit = $policy->duration_unit ?? 'full_day';
        $attachReq    = (bool) ($policy->attachment_required ?? false);
        $attachTypes  = (array) ($policy->attachment_types ?? []);

        if ($context === 'group') {
            $this->group_leave_duration_unit = $durationUnit;
            $this->group_leave_attachment_required = $attachReq;
            $this->group_leave_attachment_types = $attachTypes;
            return;
        }

        $this->create_leave_duration_unit = $durationUnit;
        $this->create_leave_attachment_required = $attachReq;
        $this->create_leave_attachment_types = $attachTypes;
    }

    protected function buildLeavePayload(array $data): array
    {
        $companyCol = $this->companyColumnFor('attendance_leave_requests');

        $payload = [
            'employee_id'     => $data['employee_id'],
            'leave_policy_id' => $data['leave_policy_id'],
            'start_date'      => $data['start_date'],
            'end_date'        => $data['end_date'],
            'reason'          => $data['reason'] ?? null,

            'duration_unit'   => $data['duration_unit'] ?? 'full_day',
            'status'          => $data['status'] ?? 'pending',
        ];

        if ($companyCol) {
            $payload[$companyCol] = $this->currentCompanyId();
        }

        return $payload;
    }

    public function saveGroupLeave(): void
    {
        $this->requireAttendanceAny('attendance.leaves.manage');
        $this->ensureCanManage();

        $policy = null;

        if (! $this->group_leave_deduct_from_balance) {
            $this->group_leave_policy_id = 0;
            $this->group_leave_duration_unit = 'full_day';
            $this->group_leave_half_day_part = 'first_half';
            $this->group_leave_from_time = '';
            $this->group_leave_to_time = '';
            $this->group_leave_minutes = 0;
        } else {
            $companyCol = $this->leavePoliciesCompanyColumn();

            $q = LeavePolicy::query()
                ->whereKey((int) $this->group_leave_policy_id)
                ->when($companyCol, fn ($qq) => $qq->where($companyCol, $this->companyId));

            if (Schema::hasColumn('leave_policies', 'is_active')) {
                $q->where('is_active', true);
            }

            $this->applyLeavePolicyYearFilter($q);

            $policy = $q->first();

            if (! $policy) {
                $this->addError('group_leave_policy_id', tr('Please select a valid leave policy.'));
                return;
            }

            $this->hydrateGroupLeavePolicyMeta(false);
        }

        $rules = [
            'group_leave_policy_id' => $this->group_leave_deduct_from_balance
                ? ['required', 'integer', 'min:1']
                : ['nullable'],

            'group_start_date' => ['required', 'date'],

            'group_end_date' => $this->group_leave_duration_unit === 'full_day'
                ? ['required', 'date', 'after_or_equal:group_start_date']
                : ['nullable', 'date'],

            'group_reason' => ['required', 'string', 'min:2', 'max:2000'],

            'groupEmployeeIds' => ['required', 'array', 'min:1'],
            'groupEmployeeIds.*' => ['integer', 'distinct'],
        ];

        if ($this->group_leave_duration_unit === 'half_day') {
            $rules['group_leave_half_day_part'] = ['required', Rule::in(['first_half', 'second_half'])];
        } else {
            $rules['group_leave_half_day_part'] = ['nullable'];
        }

        if ($this->group_leave_duration_unit === 'hours') {
            $rules['group_leave_from_time'] = [
                'required', 'string', 'max:32',
                function ($attr, $value, $fail) {
                    if (! $this->parseTimeSafe((string) $value)) {
                        $fail(tr('Start time is not valid.'));
                    }
                },
            ];

            $rules['group_leave_to_time'] = [
                'required', 'string', 'max:32',
                function ($attr, $value, $fail) {
                    if (! $this->parseTimeSafe((string) $value)) {
                        $fail(tr('End time is not valid.'));
                    }
                },
            ];
        } else {
            $rules['group_leave_from_time'] = ['nullable', 'string', 'max:32'];
            $rules['group_leave_to_time'] = ['nullable', 'string', 'max:32'];
        }

        $data = $this->validate(
            $rules,
            $this->leaveRequestsValidationMessages(),
            $this->leaveRequestsValidationAttributes()
        );

        $start = Carbon::parse($data['group_start_date'])->startOfDay();
        $end = $this->group_leave_duration_unit === 'full_day'
            ? Carbon::parse($data['group_end_date'])->startOfDay()
            : $start->copy();

        $requestedDays = 0.0;
        $halfPart = null;
        $fromTime = null;
        $toTime = null;
        $minutes = null;

        if ($this->group_leave_deduct_from_balance && $policy) {
            if (! $this->validatePolicyNoticeWindow($policy, $start)) {
                return;
            }

            if ($this->group_leave_duration_unit === 'half_day') {
                $halfPart = (string) ($data['group_leave_half_day_part'] ?? 'first_half');

                $base = $this->computeRequestedDays($policy, $start, $start);
                $requestedDays = $base > 0 ? 0.5 : 0.0;
            } elseif ($this->group_leave_duration_unit === 'hours') {
                $fromTime = (string) ($data['group_leave_from_time'] ?? '');
                $toTime   = (string) ($data['group_leave_to_time'] ?? '');

                $mins = $this->computeMinutesSafe($fromTime, $toTime);
                if ($mins <= 0) {
                    $this->addError('group_leave_to_time', tr('End time must be after start time'));
                    return;
                }

                if (! $this->validateHoursWithinWorkWindowGeneric(
                    $start,
                    $fromTime,
                    $toTime,
                    'group_leave_from_time',
                    'group_leave_to_time'
                )) {
                    return;
                }

                $base = $this->computeRequestedDays($policy, $start, $start);
                if ($base <= 0) {
                    $this->addError('group_start_date', tr('Selected date is not eligible for this policy'));
                    return;
                }

                $settings = (array) ($policy->settings ?? []);
                $workdayMinutesSetting = data_get($settings, 'workday_minutes', null);

                if ($workdayMinutesSetting !== null) {
                    $workdayMinutes = (int) $workdayMinutesSetting;
                } else {
                    $workdayMinutes = (int) $this->getWorkdayMinutesForDate($start);
                    if ($workdayMinutes <= 0) {
                        $workdayMinutes = (int) config('attendance.workday_minutes', 480);
                    }
                }

                $workdayMinutes = max($workdayMinutes, 1);

                $minutes = $mins;
                $this->group_leave_minutes = $mins;

                $requestedDays = round($mins / $workdayMinutes, 6);
            } else {
                $requestedDays = $this->computeRequestedDays($policy, $start, $end);
            }
        } else {
            $requestedDays = $this->computeGroupAbsenceDays($start, $end);
        }

        if ($requestedDays <= 0) {
            $this->addError('group_start_date', tr('Invalid date range'));
            return;
        }

        // Ã¢Å“â€¦ Exceptional Day Overlap Check (Group)
        if (class_exists(\Athka\SystemSettings\Services\WorkScheduleService::class)) {
            $wsService = app(\Athka\SystemSettings\Services\WorkScheduleService::class);
            $currDate = $start->copy();
            while ($currDate->lte($end)) {
                $exDay = $wsService->getExceptionalDay($this->companyId, $currDate->toDateString());
                if ($exDay && (bool)($exDay->is_holiday ?? true)) {
                    $isOfficial = (bool)($exDay->is_official_holiday ?? false);
                    $typeLabel = $isOfficial ? tr('Official Holiday') : tr('Exceptional Day');
                    $msgPart = tr('Cannot request leave on this date');
                    
                    $msg = $msgPart . ': ' . $typeLabel . ' - ' . ($exDay->name ?? '') . ' (' . $currDate->toDateString() . ')';
                    $this->addError('group_start_date', $msg);
                    return;
                }
                $currDate->addDay();
            }
        }

        $yearId = $this->selectedYearId ? (int) $this->selectedYearId : null;

        DB::transaction(function () use ($start, $end, $requestedDays, $data, $yearId, $policy, $halfPart, $fromTime, $toTime, $minutes) {
            foreach ($this->groupEmployeeIds as $empId) {
                $allowed = $this->lpAllowedBranchIdsSafe();
                $branchCol = $this->employeeBranchColumn ?: $this->detectEmployeeBranchColumn();

                $employee = Employee::query()
                    ->when($this->employeeCompanyColumn, fn ($q) => $q->where($this->employeeCompanyColumn, $this->companyId))
                    ->when($branchCol && !empty($allowed), fn ($q) => $q->whereIn($branchCol, $allowed))
                    ->findOrFail((int) $empId);

                $isException = false;
                $exceptionStatus = null;
                
                if ($this->group_leave_deduct_from_balance && $policy && $yearId) {
                    $balance = DB::table('attendance_leave_balances')
                        ->where('company_id', $this->companyId)
                        ->where('employee_id', $employee->id)
                        ->where('leave_policy_id', $policy->id)
                        ->where('policy_year_id', $yearId)
                        ->first();
                    $takenForBalance = $balance ? (float) $balance->taken_days : (float) AttendanceLeaveRequest::query()
                        ->where('company_id', $this->companyId)
                        ->where('employee_id', $employee->id)
                        ->where('leave_policy_id', $policy->id)
                        ->where('policy_year_id', $yearId)
                        ->where('status', 'approved')
                        ->sum('requested_days');

                    $remaining = (float) ($this->calculateLeaveBalanceAmounts($policy, $employee, $takenForBalance)['remaining'] ?? 0);

                    if ($requestedDays > $remaining) {
                        $isException = true;
                        $exceptionStatus = 'pending_hr';
                    }
                }

                $row = AttendanceLeaveRequest::create([
                    'company_id' => $this->companyId,
                    'employee_id' => (int) $employee->id,

                    'leave_policy_id' => $this->group_leave_deduct_from_balance && $policy
                        ? (int) $policy->id
                        : null,

                    'policy_year_id' => $yearId,

                    'start_date' => $start->toDateString(),
                    'end_date'   => $end->toDateString(),

                    'requested_days' => $requestedDays,
                    'reason' => $data['group_reason'] ?? null,

                    'duration_unit' => $this->group_leave_deduct_from_balance
                        ? $this->group_leave_duration_unit
                        : 'full_day',

                    'half_day_part' => $halfPart,
                    'from_time' => $fromTime,
                    'to_time' => $toTime,
                    'minutes' => $minutes,

                    'source' => 'hr',
                    'status' => 'pending',
                    'requested_by' => auth()->id(),
                    'requested_at' => now(),
                    
                    'is_exception' => $isException,
                    'exception_status' => $exceptionStatus,
                ]);

                $this->logAction('leave', (int) $row->id, 'created', [
                    'requested_days' => $requestedDays,
                    'mode' => $this->group_leave_deduct_from_balance
                        ? 'group_leave_with_policy'
                        : 'group_absence_no_policy',
                ], (int) $row->employee_id);
            }
        });

        session()->flash('success', tr('Saved successfully'));
        $this->dispatch('toast', [
            'type'    => 'success',
            'title'   => tr('Success'),
            'message' => tr('Saved successfully'),
        ]);
        $this->createGroupLeaveOpen = false;
        $this->resetPage('leavePage');
    }

    protected function computeGroupAbsenceDays(Carbon $start, Carbon $end): float
    {
        // Ãƒâ„¢Ã¢â‚¬Â ÃƒËœÃ‚Â¹ÃƒËœÃ‚ÂªÃƒËœÃ‚Â¨ÃƒËœÃ‚Â±Ãƒâ„¢Ã¢â‚¬Â¡ "ÃƒËœÃ‚ÂºÃƒâ„¢Ã…Â ÃƒËœÃ‚Â§ÃƒËœÃ‚Â¨ Ãƒâ„¢Ã¢â‚¬Â¦Ãƒâ„¢Ã‚ÂÃƒËœÃ‚Â¨ÃƒËœÃ‚Â±Ãƒâ„¢Ã¢â‚¬ËœÃƒËœÃ‚Â±" => Ãƒâ„¢Ã¢â‚¬Â ÃƒËœÃ‚Â¹ÃƒËœÃ‚Â¯ Ãƒâ„¢Ã‚ÂÃƒâ„¢Ã¢â‚¬Å¡ÃƒËœÃ‚Â· ÃƒËœÃ‚Â£Ãƒâ„¢Ã…Â ÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Â¦ ÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Å¾ÃƒËœÃ‚Â¯Ãƒâ„¢Ã‹â€ ÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Â¦ÃƒËœÃ…â€™ Ãƒâ„¢Ã‹â€ Ãƒâ„¢Ã¢â‚¬Â ÃƒËœÃ‚Â³ÃƒËœÃ‚ÂªÃƒËœÃ‚Â«Ãƒâ„¢Ã¢â‚¬Â Ãƒâ„¢Ã…Â  ÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Å¾ÃƒËœÃ‚Â¹ÃƒËœÃ‚Â·Ãƒâ„¢Ã¢â‚¬Å¾ ÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Å¾ÃƒËœÃ‚Â±ÃƒËœÃ‚Â³Ãƒâ„¢Ã¢â‚¬Â¦Ãƒâ„¢Ã…Â ÃƒËœÃ‚Â©
        $workingDays = $this->companyWorkingDays();

        $holidays = OfficialHolidayOccurrence::where('company_id', $this->companyId)
            ->where(function ($q) use ($start, $end) {
                $q->whereBetween('start_date', [$start->toDateString(), $end->toDateString()])
                ->orWhereBetween('end_date',   [$start->toDateString(), $end->toDateString()]);
            })
            ->get();

        $days = 0.0;
        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            if ($holidays->contains(fn($h) => $cursor->between($h->start_date, $h->end_date))) {
                $cursor->addDay();
                continue;
            }

            if (in_array((int) $cursor->dayOfWeek, $workingDays, true)) {
                $days += 1.0;
            }

            $cursor->addDay();
        }

        return $days;
    }

    protected function resetGroupLeaveForm(): void
    {
        $this->groupEmployeeIds = [];
        $this->groupBranchId = null;
        $this->groupContractType = '';
        $this->group_leave_policy_id = 0;
        $this->group_start_date = '';
        $this->group_end_date = '';
        $this->group_reason = '';
        $this->group_attachment = null;

        $this->group_leave_duration_unit = 'full_day';
        $this->group_leave_attachment_required = false;
        $this->group_leave_attachment_types = [];
    }

    public function updatedGroupLeavePolicyId($value): void
    {
        $this->hydrateGroupLeavePolicyMeta(true);
    }

    public function updatedGroupStartDate($value): void
    {
        if ($this->group_leave_duration_unit !== 'full_day') {
            $this->group_end_date = (string) $value;
        }
    }

    public function updatedGroupLeaveFromTime(): void { $this->syncGroupLeaveMinutes(); }
    public function updatedGroupLeaveToTime(): void { $this->syncGroupLeaveMinutes(); }

    protected function syncGroupLeaveMinutes(): void
    {
        if ($this->group_leave_duration_unit !== 'hours') {
            $this->group_leave_minutes = 0;
            return;
        }

        $this->group_leave_minutes = $this->computeMinutesSafe(
            (string) $this->group_leave_from_time,
            (string) $this->group_leave_to_time
        );
    }

    protected function resetGroupLeavePolicyMeta(): void
    {
        $this->group_leave_duration_unit = 'full_day';
        $this->group_leave_half_day_part = 'first_half';
        $this->group_leave_from_time = '';
        $this->group_leave_to_time = '';
        $this->group_leave_minutes = 0;
    }

    protected function hydrateGroupLeavePolicyMeta(bool $resetInputs = false): void
    {
        $policyId = (int) $this->group_leave_policy_id;
        if ($policyId <= 0) {
            $this->resetGroupLeavePolicyMeta();
            return;
        }

        $companyCol = $this->leavePoliciesCompanyColumn();

        $policy = LeavePolicy::query()
            ->when($companyCol, fn ($q) => $q->where($companyCol, $this->companyId))
            ->find($policyId);

        if (!$policy) {
            $this->resetGroupLeavePolicyMeta();
            return;
        }

        $settings = (array) ($policy->settings ?? []);
        $unit = (string) data_get($settings, 'duration_unit', 'full_day');
        $unit = in_array($unit, ['full_day', 'half_day', 'hours'], true) ? $unit : 'full_day';

        $this->group_leave_duration_unit = $unit;

        // Ãƒâ„¢Ã¢â‚¬Å¾Ãƒâ„¢Ã‹â€  Ãƒâ„¢Ã¢â‚¬Â ÃƒËœÃ‚ÂµÃƒâ„¢Ã‚Â Ãƒâ„¢Ã…Â Ãƒâ„¢Ã‹â€ Ãƒâ„¢Ã¢â‚¬Â¦ ÃƒËœÃ‚Â£Ãƒâ„¢Ã‹â€  ÃƒËœÃ‚Â³ÃƒËœÃ‚Â§ÃƒËœÃ‚Â¹ÃƒËœÃ‚Â§ÃƒËœÃ‚Âª: ÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Å¾Ãƒâ„¢Ã¢â‚¬Â Ãƒâ„¢Ã¢â‚¬Â¡ÃƒËœÃ‚Â§Ãƒâ„¢Ã…Â ÃƒËœÃ‚Â© = ÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Å¾ÃƒËœÃ‚Â¨ÃƒËœÃ‚Â¯ÃƒËœÃ‚Â§Ãƒâ„¢Ã…Â ÃƒËœÃ‚Â©
        if ($this->group_leave_duration_unit !== 'full_day' && $this->group_start_date !== '') {
            $this->group_end_date = $this->group_start_date;
        }

        if ($resetInputs) {
            $this->group_leave_half_day_part = 'first_half';
            $this->group_leave_from_time = '';
            $this->group_leave_to_time = '';
            $this->group_leave_minutes = 0;
        }
    }
    protected function validateHoursWithinWorkWindowGeneric(Carbon $date, string $from, string $to, string $fromKey, string $toKey): bool
    {
        $workingDays = $this->companyWorkingDays();
        if (! in_array((int) $date->dayOfWeek, $workingDays, true)) {
            $this->addError('group_start_date', tr('Selected date is not a working day.'));
            return false;
        }

        $fromT = $this->parseTimeSafe($from);
        $toT   = $this->parseTimeSafe($to);
        if (! $fromT || ! $toT) return true;

        $fromDT = $date->copy()->setTime($fromT->hour, $fromT->minute, 0);
        $toDT   = $date->copy()->setTime($toT->hour,   $toT->minute,   0);

        $periods = $this->getWorkSchedulePeriodsForDate($date);

        if (empty($periods)) {
            [$ws, $we] = $this->getCompanyWorkWindow($date->toDateString());

            $wsT = $this->parseTimeSafe($ws);
            $weT = $this->parseTimeSafe($we);
            if (! $wsT || ! $weT) return true;

            $wsDT = $date->copy()->setTime($wsT->hour, $wsT->minute, 0);
            $weDT = $date->copy()->setTime($weT->hour, $weT->minute, 0);

            if ($toDT->lte($fromDT)) {
                $this->addError($toKey, tr('End time must be after start time'));
                return false;
            }

            if ($fromDT->lt($wsDT) || $toDT->gt($weDT)) {
                $this->addError($fromKey, tr('Time must be within working hours') . " ($ws - $we)");
                return false;
            }

            return true;
        }

        if ($toDT->lte($fromDT)) {
            $hasNight = collect($periods)->contains(fn ($p) => !empty($p['is_night']));
            if (! $hasNight) {
                $this->addError($toKey, tr('End time must be after start time'));
                return false;
            }
            $toDT->addDay();
        }

        foreach ($periods as $p) {
            $ps = $this->parseTimeSafe((string) ($p['start'] ?? ''));
            $pe = $this->parseTimeSafe((string) ($p['end'] ?? ''));
            if (! $ps || ! $pe) continue;

            $psDT = $date->copy()->setTime($ps->hour, $ps->minute, 0);
            $peDT = $date->copy()->setTime($pe->hour, $pe->minute, 0);

            $night = (bool) ($p['is_night'] ?? false);
            if ($night || $peDT->lte($psDT)) $peDT->addDay();

            if ($fromDT->gte($psDT) && $toDT->lte($peDT)) return true;
        }

        [$ws, $we] = $this->getCompanyWorkWindow($date->toDateString());
        $this->addError($fromKey, tr('Time must be within working hours') . " ($ws - $we)");
        return false;
    }
    protected function lpAllowedBranchIdsSafe(): array
    {
        // Ãƒâ„¢Ã¢â‚¬Å¾Ãƒâ„¢Ã‹â€  Ãƒâ„¢Ã¢â‚¬Â¦Ãƒâ„¢Ã‹â€ ÃƒËœÃ‚Â¬Ãƒâ„¢Ã‹â€ ÃƒËœÃ‚Â¯ÃƒËœÃ‚Â© ÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Å¾ÃƒËœÃ‚Â¯ÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Å¾ÃƒËœÃ‚Â© ÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Å¾ÃƒËœÃ‚Â£ÃƒËœÃ‚Â³ÃƒËœÃ‚Â§ÃƒËœÃ‚Â³Ãƒâ„¢Ã…Â ÃƒËœÃ‚Â© ÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Å¾ÃƒËœÃ‚ÂªÃƒâ„¢Ã…Â  ÃƒËœÃ‚Â£ÃƒËœÃ‚Â¶Ãƒâ„¢Ã‚ÂÃƒâ„¢Ã¢â‚¬Â ÃƒËœÃ‚Â§Ãƒâ„¢Ã¢â‚¬Â¡ÃƒËœÃ‚Â§ Ãƒâ„¢Ã‚ÂÃƒâ„¢Ã…Â  WithLeavePermissionsFilters ÃƒËœÃ‚Â§ÃƒËœÃ‚Â³ÃƒËœÃ‚ÂªÃƒËœÃ‚Â®ÃƒËœÃ‚Â¯Ãƒâ„¢Ã¢â‚¬Â¦Ãƒâ„¢Ã¢â‚¬Â¡ÃƒËœÃ‚Â§
        if (method_exists($this, 'lpAllowedBranchIds')) {
            try {
                $ids = $this->lpAllowedBranchIds();
                return is_array($ids) ? array_values(array_filter(array_map('intval', $ids))) : [];
            } catch (\Throwable $e) {
                // fallback ÃƒËœÃ‚ÂªÃƒËœÃ‚Â­ÃƒËœÃ‚Âª
            }
        }

        // fallback ÃƒËœÃ‚Â¨ÃƒËœÃ‚Â³Ãƒâ„¢Ã…Â ÃƒËœÃ‚Â· (Ãƒâ„¢Ã¢â‚¬Å¾Ãƒâ„¢Ã‹â€  Ãƒâ„¢Ã¢â‚¬Â¦ÃƒËœÃ‚Â§ ÃƒËœÃ‚Â·ÃƒËœÃ‚Â¨Ãƒâ„¢Ã¢â‚¬Å¡ÃƒËœÃ‚Âª lpAllowedBranchIds ÃƒËœÃ‚Â¨ÃƒËœÃ‚Â¹ÃƒËœÃ‚Â¯)
        $user = auth()->user();
        if (!$user) return [];

        if (isset($user->access_scope) && $user->access_scope === 'all_branches') {
            return []; // ÃƒËœÃ‚Â¨ÃƒËœÃ‚Â¯Ãƒâ„¢Ã‹â€ Ãƒâ„¢Ã¢â‚¬Â  Ãƒâ„¢Ã¢â‚¬Å¡Ãƒâ„¢Ã…Â Ãƒâ„¢Ã‹â€ ÃƒËœÃ‚Â¯
        }

        if (Schema::hasTable('branch_user_access')) {
            $ids = DB::table('branch_user_access')
                ->where('user_id', (int) $user->id)
                ->pluck('branch_id')
                ->all();

            $ids = array_values(array_filter(array_map('intval', $ids)));
            if (!empty($ids)) return $ids;
        }

        $bid = (int) ($user->branch_id ?? 0);
        return $bid > 0 ? [$bid] : [];
    }


    public function updatedGroupLeaveDeductFromBalance($value): void
    {
        if (! (bool) $value) {
            $this->group_leave_policy_id = 0;
            $this->group_leave_duration_unit = 'full_day';
            $this->group_leave_half_day_part = 'first_half';
            $this->group_leave_from_time = '';
            $this->group_leave_to_time = '';
            $this->group_leave_minutes = 0;
        }
    }

    protected function isEmployeeLeavePeriodAvailable(int $employeeId, Carbon $start, Carbon $end, ?int $ignoreId = null): array
    {
        // A) Already has an overlapping leave/request?
        $existing = AttendanceLeaveRequest::query()
            ->where('employee_id', $employeeId)
            ->whereIn('status', ['pending', 'approved'])
            ->when($ignoreId, fn($q)=>$q->where('id', '!=', $ignoreId))
            ->where(function($q) use ($start, $end) {
                $q->whereDate('start_date', '<=', $end)
                  ->whereDate('end_date', '>=', $start);
            })
            ->first();

        if ($existing) {
            return [
                'ok' => false,
                'message' => tr('Employee already has an overlapping leave/request in this period.')
            ];
        }

        // B) Is this employee a replacement for someone else in this period?
        $replacementFor = AttendanceLeaveRequest::query()
            ->with('employee')
            ->where('replacement_employee_id', $employeeId)
            ->whereIn('status', ['pending', 'approved'])
            ->when($ignoreId, fn($q)=>$q->where('id', '!=', $ignoreId))
            ->where(function($q) use ($start, $end) {
                $q->whereDate('start_date', '<=', $end)
                  ->whereDate('end_date', '>=', $start);
            })
            ->first();

        if ($replacementFor) {
            $name = $replacementFor->employee?->name_ar ?? $replacementFor->employee?->name_en ?? $replacementFor->employee?->name ?? ('#' . $replacementFor->employee_id);
            return [
                'ok' => false,
                'message' => tr('Employee is already assigned as a replacement for') . ': ' . $name
            ];
        }

        return ['ok' => true];
    }
}



