<?php

namespace Athka\Attendance\Http\Livewire\WorkSchedules\Traits;

use Athka\Employees\Models\Employee;
use Athka\Attendance\Models\EmployeeWorkSchedule;
use Athka\Attendance\Models\EmployeeWorkScheduleException;
use Athka\Attendance\Models\AttendanceAuditLog;
use Athka\SystemSettings\Models\WorkSchedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

trait WithScheduleExceptions
{
    // Exceptions Modal
    public $showExceptionsModal = false;
    public $exceptionsEmployeeId = null;
    public $exceptionsEmployeeName = '';
    public $exceptionsAssignmentId = null;
    public $exceptionEditId = null;

    public $exceptionForm = [
        'exception_date' => '',
        'exception_type' => 'time_override',
        'start_time' => '',
        'end_time' => '',
        'notes' => '',
    ];


    public $exceptionsList = [];

    public $showHistoryModal = false;
    public $historyList = [];
    public $historyEmployeeName = '';
    public $historyScheduleNames = [];

    public function openExceptionsModal($employeeId): void
    {
        $this->requireAttendanceAny(['attendance.schedules.view', 'attendance.schedules.view-subordinates', 'attendance.schedules.manage']);
        $this->resetModalFlags();
        $companyId = $this->getCompanyId();

        $empQ = Employee::withoutGlobalScope('active_only')
            ->forCompany($companyId)
            ->whereKey($employeeId);

        $locationCol = $this->resolveEmployeeLocationColumn();
        if ($locationCol && !empty($this->allowedLocationIds)) {
            $empQ->whereIn($locationCol, $this->allowedLocationIds);
        }

        $emp = $empQ->first();

        if (!$emp) {
            $this->dispatch('toast', [
                'type' => 'error',
                'message' => tr('Employee not found'),
            ]);
            return;
        }

        $today = now()->toDateString();

        $assignment = EmployeeWorkSchedule::query()
            ->where('employee_work_schedules.saas_company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->where('start_date', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>=', $today);
            })
            ->whereExists(function ($q) use ($companyId) {
                $q->selectRaw('1')
                    ->from('work_schedules')
                    ->whereColumn('work_schedules.id', 'employee_work_schedules.work_schedule_id')
                    ->where('work_schedules.saas_company_id', $companyId)
                    ->where(function ($scheduleQuery) {
                        $scheduleQuery->where('work_schedules.is_active', true)
                            ->orWhereNull('work_schedules.is_active');
                    });
            })
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->first();

        if (!$assignment) {
            $this->dispatch('toast', [
                'type' => 'warning',
                'message' => tr('This employee has no active schedule to apply exceptions on.'),
            ]);
            return;
        }

        $this->exceptionsEmployeeId = (int) $employeeId;
        $this->exceptionsEmployeeName = app()->isLocale('ar') ? ($emp->name_ar ?: $emp->name_en) : ($emp->name_en ?: $emp->name_ar);
        $this->exceptionsAssignmentId = (int) $assignment->id;

        $this->resetExceptionForm();
        $this->refreshExceptionsList();
        $this->showExceptionsModal = true;
    }

    public function resetExceptionForm(): void
    {
        $this->exceptionEditId = null;
        $this->exceptionForm = [
            'exception_date' => now()->format('Y-m-d'),
            'exception_type' => 'time_override',
            'start_time' => '',
            'end_time' => '',
            'notes' => '',
        ];

    }



    public function refreshExceptionsList(): void
    {
        if (!$this->exceptionsEmployeeId) {
            $this->exceptionsList = [];
            return;
        }
        $companyId = $this->getCompanyId();
        $this->exceptionsList = EmployeeWorkScheduleException::where('saas_company_id', $companyId)
            ->where('employee_id', $this->exceptionsEmployeeId)
            ->orderByDesc('exception_date')
            ->get()
            ->toArray();
    }

    public function saveException(): void
    {
        $this->requireAttendanceAny('attendance.schedules.manage');
        $companyId = $this->getCompanyId();
        $rules = [
            'exceptionForm.exception_date' => 'required|date',
            'exceptionForm.exception_type' => 'required|in:time_override,day_off,work_day',
            'exceptionForm.notes' => 'nullable|string|max:1000',
        ];

        $type = (string)($this->exceptionForm['exception_type'] ?? '');

        if (in_array($type, ['time_override', 'work_day'], true)) {
            $rules['exceptionForm.start_time'] = 'required|date_format:H:i';
            $rules['exceptionForm.end_time'] = 'required|date_format:H:i|after:exceptionForm.start_time';
        }

        $this->validate($rules);

        $exceptionDate = Carbon::parse($this->exceptionForm['exception_date'])->toDateString();
        $existingException = EmployeeWorkScheduleException::query()
            ->where('saas_company_id', $companyId)
            ->where('employee_id', $this->exceptionsEmployeeId)
            ->whereDate('exception_date', $exceptionDate)
            ->when($this->exceptionEditId, fn ($q) => $q->where('id', '!=', (int) $this->exceptionEditId))
            ->first();

        if ($existingException) {
            $this->dispatch('toast', [
                'type' => 'warning',
                'message' => tr('An exception already exists for this employee on this date. Edit the existing exception instead.'),
            ]);
            return;
        }

        $assignment = $this->resolveExceptionAssignment($companyId, $exceptionDate);

        if (!$assignment) {
            $this->dispatch('toast', [
                'type' => 'error',
                'message' => tr('This employee has no schedule for the selected exception date.'),
            ]);
            return;
        }

        $hasTimes = in_array($type, ['time_override', 'work_day'], true);

        $payload = [
            'saas_company_id' => $companyId,
            'employee_id' => $this->exceptionsEmployeeId,
            'employee_work_schedule_id' => $assignment->id,
            'work_schedule_id' => $assignment->work_schedule_id,
            'exception_date' => $exceptionDate,
            'exception_type' => $this->exceptionForm['exception_type'],
            'start_time' => $hasTimes ? ($this->exceptionForm['start_time'] ?: null) : null,
            'end_time' => $hasTimes ? ($this->exceptionForm['end_time'] ?: null) : null,
            'notes' => $this->exceptionForm['notes'],
        ];

        if ($this->exceptionEditId) {
            $ex = EmployeeWorkScheduleException::findOrFail($this->exceptionEditId);
            $before = $ex->toArray();
            $ex->update($payload);
            $this->auditLog('work_schedule.exception_updated', (int)$this->exceptionsEmployeeId, 'exception', (int)$ex->id, $before, $ex->toArray());
        } else {
            $ex = EmployeeWorkScheduleException::create($payload);
            $this->auditLog('work_schedule.exception_created', (int)$this->exceptionsEmployeeId, 'exception', (int)$ex->id, null, $ex->toArray());
        }

        $this->resetExceptionForm();
        $this->refreshExceptionsList();
        $this->dispatch('toast', ['type' => 'success', 'message' => tr('Exception saved')]);
    }

    private function resolveExceptionAssignment(int $companyId, string $exceptionDate): ?EmployeeWorkSchedule
    {
        $baseQuery = EmployeeWorkSchedule::query()
            ->where('employee_work_schedules.saas_company_id', $companyId)
            ->where('employee_id', $this->exceptionsEmployeeId)
            ->where('start_date', '<=', $exceptionDate)
            ->where(function ($q) use ($exceptionDate) {
                $q->whereNull('end_date')
                    ->orWhere('end_date', '>=', $exceptionDate);
            })
            ->whereExists(function ($q) use ($companyId) {
                $q->selectRaw('1')
                    ->from('work_schedules')
                    ->whereColumn('work_schedules.id', 'employee_work_schedules.work_schedule_id')
                    ->where('work_schedules.saas_company_id', $companyId)
                    ->where(function ($scheduleQuery) {
                        $scheduleQuery->where('work_schedules.is_active', true)
                            ->orWhereNull('work_schedules.is_active');
                    });
            });

        if ($this->exceptionsAssignmentId) {
            $assignment = (clone $baseQuery)
                ->whereKey($this->exceptionsAssignmentId)
                ->first();

            if ($assignment) {
                return $assignment;
            }
        }

        return $baseQuery
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->first();
    }

    public function editException($exceptionId): void
    {
        $this->requireAttendanceAny('attendance.schedules.manage');
        $ex = EmployeeWorkScheduleException::findOrFail($exceptionId);
        $this->exceptionEditId = (int)$ex->id;
        $this->exceptionForm = [
            'exception_date' => $ex->exception_date->toDateString(),
            'exception_type' => $ex->exception_type,
            'start_time' => $ex->start_time ? substr($ex->start_time, 0, 5) : '',
            'end_time' => $ex->end_time ? substr($ex->end_time, 0, 5) : '',
            'notes' => $ex->notes,
        ];
    }

    public function deleteException($exceptionId): void
    {
        $this->requireAttendanceAny('attendance.schedules.manage');
        $ex = EmployeeWorkScheduleException::findOrFail($exceptionId);
        $before = $ex->toArray();
        $ex->delete();
        $this->auditLog('work_schedule.exception_deleted', (int)$this->exceptionsEmployeeId, 'exception', (int)$exceptionId, $before, null);
        $this->refreshExceptionsList();
        $this->dispatch('toast', ['type' => 'info', 'message' => tr('Exception deleted')]);
    }

    public function openHistoryModal($employeeId): void
    {
        $this->requireAttendanceAny(['attendance.schedules.view', 'attendance.schedules.view-subordinates']);
        $this->resetModalFlags();
        $companyId = $this->getCompanyId();
        $emp = Employee::forCompany($companyId)->findOrFail($employeeId);
        $this->historyEmployeeName = app()->isLocale('ar') ? ($emp->name_ar ?: $emp->name_en) : ($emp->name_en ?: $emp->name_ar);
        $this->historyList = AttendanceAuditLog::where('saas_company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->with('actor')
            ->orderByDesc('id')
            ->get();

        $scheduleIds = $this->historyList
            ->flatMap(function ($log) {
                $before = is_array($log->before_json) ? $log->before_json : [];
                $after = is_array($log->after_json) ? $log->after_json : [];

                return [
                    $before['work_schedule_id'] ?? null,
                    $after['work_schedule_id'] ?? null,
                ];
            })
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $this->historyScheduleNames = $scheduleIds->isEmpty()
            ? []
            : WorkSchedule::where('saas_company_id', $companyId)
                ->whereIn('id', $scheduleIds)
                ->pluck('name', 'id')
                ->toArray();

        $this->showHistoryModal = true;
    }
}



