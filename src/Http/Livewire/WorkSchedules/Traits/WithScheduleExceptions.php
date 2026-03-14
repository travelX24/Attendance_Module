<?php

namespace Athka\Attendance\Http\Livewire\WorkSchedules\Traits;

use Athka\Employees\Models\Employee;
use Athka\Attendance\Models\EmployeeWorkSchedule;
use Athka\Attendance\Models\EmployeeWorkScheduleException;
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

    public function openExceptionsModal($employeeId): void
    {
        $this->resetModalFlags();
        $companyId = $this->getCompanyId();

        $empQ = Employee::forCompany($companyId)->where('status', 'active')->whereKey($employeeId);

        $locationCol = $this->resolveEmployeeLocationColumn();
        if ($locationCol && !empty($this->allowedLocationIds)) {
            $empQ->whereIn($locationCol, $this->allowedLocationIds);
        }

        $emp = $empQ->firstOrFail();

        $assignment = EmployeeWorkSchedule::where('saas_company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->where('is_active', true)
            ->latest('id')
            ->first();

        if (!$assignment) {
            $this->dispatch('toast', [
                'type' => 'error',
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
        $assignment = EmployeeWorkSchedule::where('saas_company_id', $companyId)
            ->where('employee_id', $this->exceptionsEmployeeId)
            ->where('is_active', true)
            ->latest('id')
            ->first();


        $payload = [
            'saas_company_id' => $companyId,
            'employee_id' => $this->exceptionsEmployeeId,
            'employee_work_schedule_id' => $assignment->id,
            'exception_date' => $this->exceptionForm['exception_date'],
            'exception_type' => $this->exceptionForm['exception_type'],
            'start_time' => $this->exceptionForm['start_time'] ?: null,
            'end_time' => $this->exceptionForm['end_time'] ?: null,
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

    public function editException($exceptionId): void
    {
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
        $ex = EmployeeWorkScheduleException::findOrFail($exceptionId);
        $before = $ex->toArray();
        $ex->delete();
        $this->auditLog('work_schedule.exception_deleted', (int)$this->exceptionsEmployeeId, 'exception', (int)$exceptionId, $before, null);
        $this->refreshExceptionsList();
        $this->dispatch('toast', ['type' => 'info', 'message' => tr('Exception deleted')]);
    }

    public function openHistoryModal($employeeId): void
    {
        $this->resetModalFlags();
        $companyId = $this->getCompanyId();
        $emp = Employee::forCompany($companyId)->findOrFail($employeeId);
        $this->historyEmployeeName = app()->isLocale('ar') ? ($emp->name_ar ?: $emp->name_en) : ($emp->name_en ?: $emp->name_ar);
        $this->historyList = EmployeeWorkSchedule::where('saas_company_id', $companyId)
            ->where('employee_id', $employeeId)
            ->with('workSchedule')
            ->orderByDesc('id')
            ->get();
        $this->showHistoryModal = true;
    }
}


