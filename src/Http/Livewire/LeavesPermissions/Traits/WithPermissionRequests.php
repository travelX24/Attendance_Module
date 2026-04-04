<?php

namespace Athka\Attendance\Http\Livewire\LeavesPermissions\Traits;

use Athka\Employees\Models\Employee;
use Athka\Attendance\Models\AttendancePermissionRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait WithPermissionRequests
{
    // âœ… Create Permission Modal
    public bool $createPermissionOpen = false;

    // âœ… Fix: Ø­ØªÙ‰ Ù„Ùˆ ÙƒØ§Ù† render ÙŠØ³ØªØ®Ø¯Ù…Ù‡ ÙˆÙ…Ø§ Ù‡Ùˆ Ù…Ø¹Ø±Ù Ø¹Ù†Ø¯Ùƒ
    public bool $createGroupPermissionOpen = false;

    // Form fields (must match blade)
    public ?int $permission_employee_id = null;
    public string $permission_date = '';
    public string $from_time = '';
    public string $to_time = '';
    public int $minutes = 0;
    public string $permission_reason = '';

    // âœ… NEW: Group Permission fields
    public array $groupPermissionEmployeeIds = [];
    public string $group_permission_date = '';
    public string $group_from_time = '';
    public string $group_to_time = '';
    public int $group_minutes = 0;
    public string $group_permission_reason = '';

    public function openCreatePermission(): void
    {
        $this->resetValidation();

        $this->permission_employee_id = null;
        $this->permission_date = '';
        $this->from_time = '';
        $this->to_time = '';
        $this->minutes = 0;
        $this->permission_reason = '';

        $this->createPermissionOpen = true;
    }

    public function closeCreatePermission(): void
    {
        $this->createPermissionOpen = false;
    }

    // âœ… NEW: Group Permission Open/Close
    public function openCreateGroupPermission(): void
    {
        $this->resetValidation();

        $this->groupPermissionEmployeeIds = [];
        $this->group_permission_date = '';
        $this->group_from_time = '';
        $this->group_to_time = '';
        $this->group_minutes = 0;
        $this->group_permission_reason = '';

        $this->createGroupPermissionOpen = true;
    }

    public function closeCreateGroupPermission(): void
    {
        $this->createGroupPermissionOpen = false;
    }

    public function updatedFromTime($value): void
    {
        $this->syncPermissionMinutes();
    }

    public function updatedToTime($value): void
    {
        $this->syncPermissionMinutes();
    }

    // âœ… NEW: Group time change -> compute minutes
    public function updatedGroupFromTime($value): void
    {
        $this->syncGroupPermissionMinutes();
    }

    public function updatedGroupToTime($value): void
    {
        $this->syncGroupPermissionMinutes();
    }

    protected function syncPermissionMinutes(): void
    {
        // ÙŠØ¹ØªÙ…Ø¯ Ø¹Ù„Ù‰ computeMinutesSafe + parseTimeSafe Ø§Ù„Ù…ÙˆØ¬ÙˆØ¯Ø© Ø¹Ù†Ø¯Ùƒ ÙÙŠ WithLeaveRequests
        if (method_exists($this, 'computeMinutesSafe')) {
            $mins = (int) $this->computeMinutesSafe((string) $this->from_time, (string) $this->to_time);
            $this->minutes = max(0, $mins);
        }
    }

    protected function syncGroupPermissionMinutes(): void
    {
        if (method_exists($this, 'computeMinutesSafe')) {
            $mins = (int) $this->computeMinutesSafe((string) $this->group_from_time, (string) $this->group_to_time);
            $this->group_minutes = max(0, $mins);
        }
    }

    public function savePermission(): void
    {
        $this->ensureCanManage();

        $policy = $this->permissionPolicyRow();
        if (!$policy) {
            $this->addError('permission_date', tr('Permission settings are not configured for this year.'));
            return;
        }

        if (Schema::hasColumn($policy->getTable(), 'is_active') && !$policy->is_active) {
            $this->addError('permission_date', tr('Permission settings are currently inactive.'));
            return;
        }

        $rules = [
            'permission_employee_id' => ['required', 'integer', 'min:1'],
            'permission_date' => ['required', 'date'],

            'from_time' => [
                'required', 'string', 'max:32',
                function ($attr, $value, $fail) {
                    if (!method_exists($this, 'parseTimeSafe') || !$this->parseTimeSafe((string) $value)) {
                        $fail(tr('Start time is not valid.'));
                    }
                },
            ],

            'to_time' => [
                'required', 'string', 'max:32',
                function ($attr, $value, $fail) {
                    if (!method_exists($this, 'parseTimeSafe') || !$this->parseTimeSafe((string) $value)) {
                        $fail(tr('End time is not valid.'));
                    }
                },
            ],

            'minutes' => ['nullable', 'integer', 'min:60'],
            'permission_reason' => ['nullable', 'string', 'max:2000'],
        ];

        $messages = [
            'permission_employee_id.required' => tr('Please select an employee.'),
            'permission_employee_id.integer'  => tr('Please select a valid employee.'),
            'permission_employee_id.min'      => tr('Please select an employee.'),

            'permission_date.required'        => tr('Please select a date.'),
            'permission_date.date'            => tr('Date is not valid.'),

            'from_time.required'              => tr('Start time is required.'),
            'from_time.max'                   => tr('Start time is too long.'),
            'to_time.required'                => tr('End time is required.'),
            'to_time.max'                     => tr('End time is too long.'),

            'minutes.integer'                 => tr('Minutes must be a number.'),
            'minutes.min'                     => tr('Permission duration must be at least 1 hour (60 minutes).'),

            'permission_reason.max'           => tr('Reason is too long.'),
        ];

        $attributes = [
            'permission_employee_id' => tr('Employee'),
            'permission_date'        => tr('Date'),
            'from_time'              => tr('From time'),
            'to_time'                => tr('To time'),
            'minutes'                => tr('Minutes'),
            'permission_reason'      => tr('Reason'),
        ];

        $data = $this->validate($rules, $messages, $attributes);

        // âœ… Employee (same company)
     $allowed = method_exists($this, 'lpAllowedBranchIdsSafe')
            ? (array) $this->lpAllowedBranchIdsSafe()
            : [];

        $allowed = array_values(array_filter(array_map('intval', $allowed)));

        $branchCol = $this->employeeBranchColumn
            ?? (method_exists($this, 'detectEmployeeBranchColumn') ? $this->detectEmployeeBranchColumn() : 'branch_id');

        $employee = Employee::query()
            ->when(property_exists($this, 'employeeCompanyColumn') && $this->employeeCompanyColumn, fn ($q) => $q->where($this->employeeCompanyColumn, $this->companyId))
            ->when($branchCol && !empty($allowed), fn ($q) => $q->whereIn($branchCol, $allowed))
            ->findOrFail((int) $data['permission_employee_id']);
            

        $date = Carbon::parse($data['permission_date'])->startOfDay();
        $from = (string) $data['from_time'];
        $to   = (string) $data['to_time'];

        // âœ… Compute minutes from times (source of truth)
        if (!method_exists($this, 'computeMinutesSafe')) {
            $this->addError('from_time', 'computeMinutesSafe() is missing.');
            return;
        }

        $mins = (int) $this->computeMinutesSafe($from, $to);
        if ($mins < 60) {
            $this->addError('to_time', tr('Permission duration must be at least 1 hour (60 minutes).'));
            return;
        }

        // ✅ validate within working hours (بنفس منطق الشفت عندك)
        if (!$this->validatePermissionWithinWorkWindow($date, $from, $to)) {
            return;
        }

        $this->minutes = $mins;

        $approvalRequired = $this->isPermissionApprovalRequired();

        // ✅ Check Workflow existence (only if approval is required)
        if ($approvalRequired && class_exists(\Athka\SystemSettings\Services\Approvals\ApprovalService::class)) {
            $approvalService = app(\Athka\SystemSettings\Services\Approvals\ApprovalService::class);
            if (!$approvalService->hasApproversForEmployee('permissions', (int) $employee->id, (int) $this->companyId)) {
                $this->addError('permission_date', 'لا يمكن تقديم الطلب، يرجى التواصل مع الإدارة لتعيين تسلسل موافقات (سير عمل) خاص بك.');
                return;
            }
        }

        // ✅ NEW: hard limits (per request / per day / per month)
        if (!$this->validatePermissionLimits((int) $employee->id, $date, $mins)) {
            return;
        }

        $permTable = (new AttendancePermissionRequest())->getTable();
        $companyCol = method_exists($this, 'detectCompanyColumn') ? $this->detectCompanyColumn($permTable) : null;

        $payload = [
            'employee_id' => (int) $employee->id,
            'permission_date' => $date->toDateString(),
            'from_time' => $from,
            'to_time' => $to,
            'minutes' => $mins,
            'reason' => $data['permission_reason'] ?? null,
            'status' => $approvalRequired ? 'pending' : 'approved',
        ];

        // âœ… company column (company_id vs saas_company_id)
        if ($companyCol && Schema::hasColumn($permTable, $companyCol)) {
            $payload[$companyCol] = (int) $this->companyId;
        }

        // âœ… optional columns (safe)
        if (Schema::hasColumn($permTable, 'requested_by')) $payload['requested_by'] = auth()->id();
        if (Schema::hasColumn($permTable, 'requested_at')) $payload['requested_at'] = now();

        $row = AttendancePermissionRequest::create($payload);

        // ✅ Integrate with Approval Workflow (only if approval is required)
        if ($approvalRequired) {
            try {
                $approvalService = app(\Athka\SystemSettings\Services\Approvals\ApprovalService::class);
                $src = $approvalService->getRequestSource('permissions');
                if ($src) {
                    $approvalService->ensureTasksForRequest($src, $row, (int) $this->companyId);
                }
            } catch (\Exception $e) {
                \Log::error("Approval Task Generation Error (Permission): " . $e->getMessage());
            }
        } elseif (!$approvalRequired && class_exists(\App\Notifications\ApprovalTaskNotification::class)) {
            try {
                $userTarget = \App\Models\User::where('employee_id', $employee->id)->first();
                if ($userTarget) {
                    $dummyTask = new \Athka\SystemSettings\Models\ApprovalTask([
                        'operation_key' => 'permissions',
                        'approvable_type' => 'permissions',
                        'approvable_id' => $row->id,
                        'request_employee_id' => $employee->id,
                        'status' => 'approved',
                    ]);
                    $dummyTask->id = 0; // Prevent null ID issue
                    $userTarget->notify(new \App\Notifications\ApprovalTaskNotification($dummyTask, 'submitted'));
                    $userTarget->notify(new \App\Notifications\ApprovalTaskNotification($dummyTask, 'resolution'));
                }
            } catch (\Exception $e) {}
        }

        // ✅ activity log (لو عندك logAction)
        if (method_exists($this, 'logAction')) {
            $this->logAction('permission', 0, 'created', ['minutes' => $mins], (int) $employee->id);
        }

        session()->flash('success', tr('Saved successfully'));
        
        $this->dispatch('toast', [
            'type'    => 'success',
            'title'   => tr('Success'),
            'message' => tr('Saved successfully'),
        ]);

        $this->dispatch('permission-request-updated');
        $this->closeCreatePermission();
        $this->resetPage('permPage');
    }

    // âœ… NEW: Group Permission Save
    public function saveGroupPermission(): void
    {
        $this->ensureCanManage();

        $policy = $this->permissionPolicyRow();
        if (!$policy) {
            $this->addError('group_permission_date', tr('Permission settings are not configured for this year.'));
            return;
        }

        if (Schema::hasColumn($policy->getTable(), 'is_active') && !$policy->is_active) {
            $this->addError('group_permission_date', tr('Permission settings are currently inactive.'));
            return;
        }

        $rules = [
            'groupPermissionEmployeeIds' => ['required', 'array', 'min:1'],
            'group_permission_date' => ['required', 'date'],

            'group_from_time' => [
                'required', 'string', 'max:32',
                function ($attr, $value, $fail) {
                    if (!method_exists($this, 'parseTimeSafe') || !$this->parseTimeSafe((string) $value)) {
                        $fail(tr('Start time is not valid.'));
                    }
                },
            ],

            'group_to_time' => [
                'required', 'string', 'max:32',
                function ($attr, $value, $fail) {
                    if (!method_exists($this, 'parseTimeSafe') || !$this->parseTimeSafe((string) $value)) {
                        $fail(tr('End time is not valid.'));
                    }
                },
            ],

            'group_minutes' => ['nullable', 'integer', 'min:60'],
            'group_permission_reason' => ['nullable', 'string', 'max:2000'],
        ];

        $messages = [
            'groupPermissionEmployeeIds.required' => tr('Please select at least one employee.'),
            'groupPermissionEmployeeIds.array'    => tr('Please select at least one employee.'),
            'groupPermissionEmployeeIds.min'      => tr('Please select at least one employee.'),

            'group_permission_date.required'      => tr('Please select a date.'),
            'group_permission_date.date'          => tr('Date is not valid.'),

            'group_from_time.required'            => tr('Start time is required.'),
            'group_from_time.max'                 => tr('Start time is too long.'),
            'group_to_time.required'              => tr('End time is required.'),
            'group_to_time.max'                   => tr('End time is too long.'),

            'group_minutes.integer'               => tr('Minutes must be a number.'),
            'group_minutes.min'                   => tr('Permission duration must be at least 1 hour (60 minutes).'),

            'group_permission_reason.max'         => tr('Reason is too long.'),
        ];

        $attributes = [
            'groupPermissionEmployeeIds' => tr('Employees'),
            'group_permission_date'      => tr('Date'),
            'group_from_time'            => tr('From time'),
            'group_to_time'              => tr('To time'),
            'group_minutes'              => tr('Minutes'),
            'group_permission_reason'    => tr('Reason'),
        ];

        $data = $this->validate($rules, $messages, $attributes);

        if (!method_exists($this, 'computeMinutesSafe')) {
            $this->addError('group_from_time', 'computeMinutesSafe() is missing.');
            return;
        }

        $ids = array_values(array_unique(array_map('intval', (array) ($data['groupPermissionEmployeeIds'] ?? []))));
        $ids = array_filter($ids, fn ($v) => $v > 0);
        if (empty($ids)) {
            $this->addError('groupPermissionEmployeeIds', tr('Please select at least one employee.'));
            return;
        }

        $date = Carbon::parse($data['group_permission_date'])->startOfDay();
        $from = (string) $data['group_from_time'];
        $to   = (string) $data['group_to_time'];

        $mins = (int) $this->computeMinutesSafe($from, $to);
        if ($mins < 60) {
            $this->addError('group_to_time', tr('Permission duration must be at least 1 hour (60 minutes).'));
            return;
        }
        $this->group_minutes = $mins;

        // âœ… Validate within work window (Ù…Ø±Ø© ÙˆØ§Ø­Ø¯Ø©)
        if (!$this->validateGroupPermissionWithinWorkWindow($date, $from, $to)) {
            return;
        }

        // âœ… Max per request (Ù…Ø±Ø© ÙˆØ§Ø­Ø¯Ø©)
        $maxPerRequest = $this->getPermissionMaxMinutesPerRequest($date);
        if ($maxPerRequest > 0 && $mins > $maxPerRequest) {
            $this->addError('group_to_time', tr('Permission duration exceeds the allowed limit') . ' (' . $this->fmtMinutes($maxPerRequest) . ').');
            return;
        }

        // âœ… Load employees in one query
        $allowed = method_exists($this, 'lpAllowedBranchIdsSafe')
            ? (array) $this->lpAllowedBranchIdsSafe()
            : [];

        $allowed = array_values(array_filter(array_map('intval', $allowed)));

        $branchCol = $this->employeeBranchColumn
            ?? (method_exists($this, 'detectEmployeeBranchColumn') ? $this->detectEmployeeBranchColumn() : 'branch_id');

        $employees = Employee::query()
            ->when(property_exists($this, 'employeeCompanyColumn') && $this->employeeCompanyColumn, fn ($q) => $q->where($this->employeeCompanyColumn, $this->companyId))
            ->when($branchCol && !empty($allowed), fn ($q) => $q->whereIn($branchCol, $allowed))
            ->whereIn('id', $ids)
            ->get();

        $employeesById = $employees->keyBy('id');

        // âœ… If any missing
        $missing = array_values(array_diff($ids, $employeesById->keys()->all()));
        if (!empty($missing)) {
            $this->addError('groupPermissionEmployeeIds', tr('Some selected employees are not valid.'));
            return;
        }

        // âœ… Build sums maps (daily + monthly) once
        $permTable = (new AttendancePermissionRequest())->getTable();
        $permCoCol = method_exists($this, 'detectCompanyColumn') ? $this->detectCompanyColumn($permTable) : null;

        $base = AttendancePermissionRequest::query()
            ->when($permCoCol, fn ($q) => $q->where($permCoCol, $this->companyId))
            ->whereIn('employee_id', $ids)
            ->whereIn('status', ['pending', 'approved']);

        $usedTodayMap = (clone $base)
            ->whereDate('permission_date', $date->toDateString())
            ->selectRaw('employee_id, SUM(minutes) as total')
            ->groupBy('employee_id')
            ->pluck('total', 'employee_id')
            ->toArray();

        $start = $date->copy()->startOfMonth()->toDateString();
        $end   = $date->copy()->endOfMonth()->toDateString();

        $usedMonthMap = (clone $base)
            ->whereBetween('permission_date', [$start, $end])
            ->selectRaw('employee_id, SUM(minutes) as total')
            ->groupBy('employee_id')
            ->pluck('total', 'employee_id')
            ->toArray();

        // âœ… Check daily/monthly limits per employee
        $dailyExceeded = [];
        $monthlyExceeded = [];

        $maxPerDay = $this->getPermissionMaxMinutesPerDay($date);
        $maxPerMonth = $this->getPermissionMaxMinutesPerMonth((int) $ids[0], $date); // policy row same for all

        foreach ($ids as $empId) {
            $usedToday = (int) ($usedTodayMap[$empId] ?? 0);
            $usedMonth = (int) ($usedMonthMap[$empId] ?? 0);

            if ($maxPerDay > 0 && ($usedToday + $mins) > $maxPerDay) {
                $dailyExceeded[] = $empId;
            }

            if ($maxPerMonth > 0 && ($usedMonth + $mins) > $maxPerMonth) {
                if (!$this->isPermissionAllowedAfterLimit()) {
                    $monthlyExceeded[] = $empId;
                }
            }
        }

        if (!empty($dailyExceeded)) {
            $names = collect($dailyExceeded)->map(function ($id) use ($employeesById) {
                $e = $employeesById->get($id);
                $name = $e?->name_ar ?? $e?->name_en ?? $e?->name ?? $e?->full_name ?? ('#' . $id);
                return $name . ' (#' . $id . ')';
            })->implode(', ');

            $this->addError(
                'groupPermissionEmployeeIds',
                tr('Daily permission limit exceeded') . ' (' . $this->fmtMinutes($maxPerDay) . '): ' . $names
            );
            return;
        }

        if (!empty($monthlyExceeded)) {
            $names = collect($monthlyExceeded)->map(function ($id) use ($employeesById) {
                $e = $employeesById->get($id);
                $name = $e?->name_ar ?? $e?->name_en ?? $e?->name ?? $e?->full_name ?? ('#' . $id);
                return $name . ' (#' . $id . ')';
            })->implode(', ');

            $this->addError(
                'groupPermissionEmployeeIds',
                tr('Monthly permission limit exceeded') . ' (' . $this->fmtMinutes($maxPerMonth) . '): ' . $names
            );
            return;
        }

        $approvalRequired = $this->isPermissionApprovalRequired();

        // âœ… Create all rows in a transaction (all-or-nothing)
        DB::transaction(function () use ($ids, $date, $from, $to, $mins, $approvalRequired, $permTable, $permCoCol, $data) {

            foreach ($ids as $empId) {
                $payload = [
                    'employee_id' => (int) $empId,
                    'permission_date' => $date->toDateString(),
                    'from_time' => $from,
                    'to_time' => $to,
                    'minutes' => $mins,
                    'reason' => $data['group_permission_reason'] ?? null,
                    'status' => $approvalRequired ? 'pending' : 'approved',
                ];

                if ($permCoCol && Schema::hasColumn($permTable, $permCoCol)) {
                    $payload[$permCoCol] = (int) $this->companyId;
                }

                if (Schema::hasColumn($permTable, 'requested_by')) $payload['requested_by'] = auth()->id();
                if (Schema::hasColumn($permTable, 'requested_at')) $payload['requested_at'] = now();

                $row = AttendancePermissionRequest::create($payload);

                // ✅ No approval workflow for individual rows in group request if $approvalRequired is false
                // But usually we handle it row by row if needed.
                // Added check to ensure row is auto-approved if needed.
                
                if ($approvalRequired) {
                    try {
                        $approvalService = app(\Athka\SystemSettings\Services\Approvals\ApprovalService::class);
                        $src = $approvalService->getRequestSource('permissions');
                        if ($src) {
                            $approvalService->ensureTasksForRequest($src, $row, (int) $this->companyId);
                        }
                    } catch (\Exception $e) {
                        \Log::error("Approval Task Generation Error (Permission Group): " . $e->getMessage());
                    }
                } elseif (!$approvalRequired && class_exists(\App\Notifications\ApprovalTaskNotification::class)) {
                    try {
                        $userTarget = \App\Models\User::where('employee_id', $empId)->first();
                        if ($userTarget) {
                            $dummyTask = new \Athka\SystemSettings\Models\ApprovalTask([
                                'operation_key' => 'permissions',
                                'approvable_type' => 'permissions',
                                'approvable_id' => $row->id,
                                'request_employee_id' => $empId,
                                'status' => 'approved',
                            ]);
                            $dummyTask->id = 0; // Prevent null ID issue
                            $userTarget->notify(new \App\Notifications\ApprovalTaskNotification($dummyTask, 'submitted'));
                            $userTarget->notify(new \App\Notifications\ApprovalTaskNotification($dummyTask, 'resolution'));
                        }
                    } catch (\Exception $e) {}
                }

                if (method_exists($this, 'logAction')) {
                    $this->logAction('permission', 0, 'created', ['minutes' => $mins, 'group' => true], (int) $empId);
                }
            }
        });

        session()->flash('success', tr('Saved successfully') . ' (' . count($ids) . ')');
        $this->dispatch('permission-request-updated');
        $this->closeCreateGroupPermission();
        $this->resetPage('permPage');
    }

    // âœ… Group Work Window validation (same logic but fields for group)
    protected function validateGroupPermissionWithinWorkWindow(Carbon $date, string $from, string $to): bool
    {
        if (!method_exists($this, 'companyWorkingDays') || !method_exists($this, 'parseTimeSafe')) {
            return true;
        }

        $workingDays = (array) $this->companyWorkingDays();
        if (!in_array((int) $date->dayOfWeek, $workingDays, true)) {
            $this->addError('group_permission_date', tr('Selected date is not a working day.'));
            return false;
        }

        $fromT = $this->parseTimeSafe($from);
        $toT   = $this->parseTimeSafe($to);
        if (!$fromT || !$toT) return true;

        $fromDT = $date->copy()->setTime($fromT->hour, $fromT->minute, 0);
        $toDT   = $date->copy()->setTime($toT->hour,   $toT->minute,   0);

        $periods = method_exists($this, 'getWorkSchedulePeriodsForDate')
            ? (array) $this->getWorkSchedulePeriodsForDate($date)
            : [];

        if (empty($periods) && method_exists($this, 'getCompanyWorkWindow')) {
            [$ws, $we] = $this->getCompanyWorkWindow($date->toDateString());

            $wsT = $this->parseTimeSafe((string) $ws);
            $weT = $this->parseTimeSafe((string) $we);
            if (!$wsT || !$weT) return true;

            $wsDT = $date->copy()->setTime($wsT->hour, $wsT->minute, 0);
            $weDT = $date->copy()->setTime($weT->hour, $weT->minute, 0);

            if ($toDT->lte($fromDT)) {
                $this->addError('group_to_time', tr('End time must be after start time'));
                return false;
            }

            if ($fromDT->lt($wsDT) || $toDT->gt($weDT)) {
                $this->addError('group_from_time', tr('Time must be within working hours') . " ($ws - $we)");
                return false;
            }

            return true;
        }

        if ($toDT->lte($fromDT)) {
            $hasNight = collect($periods)->contains(fn ($p) => !empty($p['is_night']));
            if (!$hasNight) {
                $this->addError('group_to_time', tr('End time must be after start time'));
                return false;
            }
            $toDT->addDay();
        }

        foreach ($periods as $p) {
            $ps = $this->parseTimeSafe((string) ($p['start'] ?? ''));
            $pe = $this->parseTimeSafe((string) ($p['end'] ?? ''));
            if (!$ps || !$pe) continue;

            $psDT = $date->copy()->setTime($ps->hour, $ps->minute, 0);
            $peDT = $date->copy()->setTime($pe->hour, $pe->minute, 0);

            $night = (bool) ($p['is_night'] ?? false);
            if ($night || $peDT->lte($psDT)) $peDT->addDay();

            if ($fromDT->gte($psDT) && $toDT->lte($peDT)) {
                return true;
            }
        }

        if (method_exists($this, 'getCompanyWorkWindow')) {
            [$ws, $we] = $this->getCompanyWorkWindow($date->toDateString());
            $this->addError('group_from_time', tr('Time must be within working hours') . " ($ws - $we)");
            return false;
        }

        return true;
    }

    // =========================================================
    // âœ… Permission hard limits (existing)
    // =========================================================
    protected function validatePermissionLimits(int $employeeId, Carbon $date, int $mins): bool
    {
        $maxPerRequest = $this->getPermissionMaxMinutesPerRequest($date);
        if ($maxPerRequest > 0 && $mins > $maxPerRequest) {
            if (!$this->isPermissionAllowedAfterLimit()) {
                $this->addError('to_time', tr('Permission duration exceeds the allowed limit') . ' (' . $this->fmtMinutes($maxPerRequest) . ').');
                return false;
            }
        }

        $permTable = (new AttendancePermissionRequest())->getTable();
        $permCoCol = method_exists($this, 'detectCompanyColumn') ? $this->detectCompanyColumn($permTable) : null;

        $base = AttendancePermissionRequest::query()
            ->when($permCoCol, fn ($q) => $q->where($permCoCol, $this->companyId))
            ->where('employee_id', $employeeId)
            ->whereIn('status', ['pending', 'approved']);

        $maxPerDay = $this->getPermissionMaxMinutesPerDay($date);
        if ($maxPerDay > 0) {
            $usedToday = (int) (clone $base)
                ->whereDate('permission_date', $date->toDateString())
                ->sum('minutes');

            if (($usedToday + $mins) > $maxPerDay) {
                if (!$this->isPermissionAllowedAfterLimit()) {
                    $errorMsg = tr('Daily permission limit exceeded, please contact administration.');
                    $this->addError('permission_date', $errorMsg);
                    $this->dispatch('toast', type: 'error', message: $errorMsg);
                    return false;
                }
            }
        }

        $maxPerMonth = $this->getPermissionMaxMinutesPerMonth($employeeId, $date);
        if ($maxPerMonth > 0) {
            $start = $date->copy()->startOfMonth()->toDateString();
            $end   = $date->copy()->endOfMonth()->toDateString();

            $usedMonth = (int) (clone $base)
                ->whereBetween('permission_date', [$start, $end])
                ->sum('minutes');

            if (($usedMonth + $mins) > $maxPerMonth) {
                if (!$this->isPermissionAllowedAfterLimit()) {
                    $this->addError('permission_date', tr('Monthly permission limit exceeded') . ' (' . $this->fmtMinutes($maxPerMonth) . ').');
                    return false;
                }
            }
        }

        return true;
    }

    protected function getPermissionMaxMinutesPerRequest(Carbon $date): int
    {
        $row = $this->permissionPolicyRow();
        if ($row && (int) ($row->max_request_minutes ?? 0) > 0) {
            return (int) $row->max_request_minutes;
        }

        $hours = $this->permissionSetting('perm_max_request_hours', null);
        if ($hours === null) {
            $hours = $this->permissionSetting('perm_max_request_minutes', null);
        }

        $mins = $this->permissionSettingHoursToMinutes($hours);
        if ($mins > 0) return $mins;

        $m = (int) config('attendance.permission_max_minutes_per_request', 0);
        if ($m > 0) return $m;

        $h = (float) config('attendance.permission_max_hours_per_request', 0);
        return $h > 0 ? (int) round($h * 60) : 0;
    }

    protected function getPermissionMaxMinutesPerDay(Carbon $date): int
    {
        // Use Max Minutes Per Request as the definitive Daily Limit logic requested by the user
        return $this->getPermissionMaxMinutesPerRequest($date);
    }

    protected function getPermissionMaxMinutesPerMonth(int $employeeId, Carbon $date): int
    {
        $row = $this->permissionPolicyRow();
        if ($row && (int) ($row->monthly_limit_minutes ?? 0) > 0) {
            return (int) $row->monthly_limit_minutes;
        }

        $hours = $this->permissionSetting('perm_monthly_limit_hours', null);
        $mins = $this->permissionSettingHoursToMinutes($hours);
        if ($mins > 0) return $mins;

        $m = (int) config('attendance.permission_max_minutes_per_month', 0);
        if ($m > 0) return $m;

        $h = (float) config('attendance.permission_max_hours_per_month', 0);
        return $h > 0 ? (int) round($h * 60) : 0;
    }

    protected function fmtMinutes(int $mins): string
    {
        $mins = max(0, $mins);
        $h = intdiv($mins, 60);
        $m = $mins % 60;

        if ($h > 0 && $m > 0) return $h . 'h ' . $m . 'm';
        if ($h > 0) return $h . 'h';
        return $m . 'm';
    }

    protected function permissionSetting(string $key, $default = null)
    {
        foreach ([
            'getAttendanceSetting',
            'attendanceSetting',
            'getCompanySetting',
            'companySetting',
        ] as $method) {
            if (method_exists($this, $method)) {
                try {
                    $v = $this->{$method}($key, $default);
                    if ($v !== null) return $v;
                } catch (\Throwable $e) {
                    // ignore
                }
            }
        }

        foreach (['permissionSettings', 'permissionsSettings', 'attendanceSettings', 'settings'] as $bag) {
            if (property_exists($this, $bag) && is_array($this->{$bag})) {
                $v = data_get($this->{$bag}, $key);
                if ($v !== null) return $v;
            }
        }

        $prop = str_replace('.', '_', $key);
        if (property_exists($this, $prop)) {
            $v = $this->{$prop};
            return $v !== null ? $v : $default;
        }

        if (property_exists($this, $key)) {
            $v = $this->{$key};
            return $v !== null ? $v : $default;
        }

        return $default;
    }

    protected function permissionSettingHoursToMinutes($hours): int
    {
        $h = (float) ($hours ?? 0);
        if ($h <= 0) return 0;
        return (int) round($h * 60);
    }

    protected function isPermissionApprovalRequired(): bool
    {
        $row = $this->permissionPolicyRow();
        if ($row !== null) {
            return (bool) $row->approval_required;
        }

        $v = $this->permissionSetting('perm_approval_required', true);
        return (bool) $v;
    }

    protected function permissionAfterLimitPolicy(): string
    {
        $row = $this->permissionPolicyRow();
        if ($row !== null) {
            return (string) ($row->deduction_policy ?? 'not_allowed_after_limit');
        }

        return (string) $this->permissionSetting('perm_deduction_policy', 'not_allowed_after_limit');
    }

    protected function isPermissionAllowedAfterLimit(): bool
    {
        $policy = strtolower(trim($this->permissionAfterLimitPolicy()));
        return $policy !== 'not_allowed_after_limit' && $policy !== 'not_allowed';
    }

    protected function permissionPolicyRow(): ?\Athka\SystemSettings\Models\PermissionPolicy
    {
        static $cached = null;

        if ($cached !== null) {
            return $cached;
        }

        if (!class_exists(\Athka\SystemSettings\Models\PermissionPolicy::class)) {
            $cached = null;
            return null;
        }

        $companyId = (int) ($this->companyId ?? 0);
        if ($companyId <= 0 && method_exists($this, 'resolveCompanyId')) {
            $companyId = (int) $this->resolveCompanyId();
        }
        if ($companyId <= 0) {
            $cached = null;
            return null;
        }

        $yearId = 0;
        if (property_exists($this, 'selectedYearId') && !empty($this->selectedYearId)) {
            $yearId = (int) $this->selectedYearId;
        }

        if ($yearId <= 0 && class_exists(\Athka\SystemSettings\Models\LeavePolicyYear::class)) {
            $yearId = (int) \Athka\SystemSettings\Models\LeavePolicyYear::query()
                ->where('company_id', $companyId)
                ->where('is_active', true)
                ->value('id');
        }

        if ($yearId <= 0) {
            $cached = null;
            return null;
        }

        $cached = \Athka\SystemSettings\Models\PermissionPolicy::query()
            ->where('company_id', $companyId)
            ->where('policy_year_id', $yearId)
            ->first();

        return $cached;
    }

}


