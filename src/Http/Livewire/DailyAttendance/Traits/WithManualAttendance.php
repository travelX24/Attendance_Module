<?php

namespace Athka\Attendance\Http\Livewire\DailyAttendance\Traits;

use Athka\Employees\Models\Employee;
use Athka\Attendance\Models\AttendanceDailyLog;
use Athka\Attendance\Models\EmployeeWorkSchedule;
use Carbon\Carbon;

trait WithManualAttendance
{
    // ==================== Create Modal ====================
    public $showCreateModal = false;
    public $createForm = [
        'employee_id' => '',
        'attendance_date' => '',
        'periods' => [], // Dynamic periods from schedule
        'notes' => '',
    ];

    public function openCreateModal()
    {
        $this->resetModalFlags();
        $this->createForm = [
            'employee_id' => '',
            'attendance_date' => now()->format('Y-m-d'),
            'periods' => [
                ['check_in_time' => '', 'check_out_time' => '']
            ],
            'notes' => '',
        ];
        $this->showCreateModal = true;
    }

    public function updatedCreateFormEmployeeId()
    {
        $this->fetchWorkSchedulePeriods();
    }

    public function updatedCreateFormAttendanceDate()
    {
        $this->fetchWorkSchedulePeriods();
    }

    private function fetchWorkSchedulePeriods()
    {
        if (empty($this->createForm['employee_id']) || empty($this->createForm['attendance_date'])) {
            return;
        }

        $companyId = auth()->user()->saas_company_id;
        $date = Carbon::parse($this->createForm['attendance_date']);
        $dayName = strtolower($date->format('l'));

        $assignment = EmployeeWorkSchedule::where('saas_company_id', $companyId)
            ->where('employee_id', $this->createForm['employee_id'])
            ->where('is_active', true)
            ->where('start_date', '<=', $date)
            ->where(function($q) use ($date) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $date);
            })
            ->with(['workSchedule.periods'])
            ->first();

        if ($assignment && $assignment->workSchedule) {
            $workSchedule = $assignment->workSchedule;
            $workDays = is_array($workSchedule->work_days) ? $workSchedule->work_days : [];

            // Check if today is a working day
            if (in_array($dayName, $workDays)) {
                $periods = $workSchedule->periods;
                
                if ($periods->count() > 0) {
                    $this->createForm['periods'] = $periods->map(fn($p) => [
                        'check_in_time' => substr($p->start_time, 0, 5), // Note: using start_time from schema
                        'check_out_time' => substr($p->end_time, 0, 5)   // Note: using end_time from schema
                    ])->toArray();
                    return;
                }
            }
        }

        $this->createForm['periods'] = [['check_in_time' => '', 'check_out_time' => '']];
    }

    public function saveManualAttendance()
    {
        $this->validate([
            'createForm.employee_id' => 'required|exists:employees,id',
            'createForm.attendance_date' => 'required|date',
            'createForm.periods.*.check_in_time' => 'required|date_format:H:i',
            'createForm.periods.*.check_out_time' => 'nullable|date_format:H:i|after:createForm.periods.*.check_in_time',
            'createForm.notes' => 'nullable|string|max:1000',
        ]);

        $companyId = auth()->user()->saas_company_id;
        $empQ = Employee::where('saas_company_id', $companyId);

        $allowed = $this->allowedBranchIds();
        if (!empty($allowed)) {
            $empQ->whereIn('branch_id', $allowed);
        }

        $employee = $empQ->findOrFail($this->createForm['employee_id']);
        // Check if attendance already exists
        $existing = AttendanceDailyLog::forCompany($companyId)
            ->where('employee_id', $employee->id)
            ->where('attendance_date', $this->createForm['attendance_date'])
            ->first();

        if ($existing) {
            $msg = $existing->attendance_status === 'on_leave' 
                ? tr('Employee is on leave on this date. Attendance cannot be recorded.')
                : tr('Attendance record already exists for this employee on this date');

            $this->dispatch('toast', ['type' => 'error', 'message' => $msg]);
            return;
        }

        // Aggregate multiple periods for the daily summary
        $firstIn = null;
        $lastOut = null;
        $totalActualMinutes = 0;

        foreach ($this->createForm['periods'] as $p) {
            $in = $p['check_in_time'];
            $out = $p['check_out_time'];
            if ($in) {
                if (!$firstIn || $in < $firstIn) $firstIn = $in;
                if ($out) {
                    if (!$lastOut || $out > $lastOut) $lastOut = $out;
                    $start = Carbon::parse($in);
                    $end = Carbon::parse($out);
                    $totalActualMinutes += $end->diffInMinutes($start);
                }
            }
        }

        $log = AttendanceDailyLog::create([
            'saas_company_id' => $companyId,
            'employee_id' => $employee->id,
            'attendance_date' => $this->createForm['attendance_date'],
            'attendance_status' => 'present',
            'approval_status' => 'pending',
            'source' => 'manual',
            'is_edited' => false,
            'check_in_time' => $firstIn ? Carbon::parse($this->createForm['attendance_date'] . ' ' . $firstIn) : null,
            'check_out_time' => $lastOut ? Carbon::parse($this->createForm['attendance_date'] . ' ' . $lastOut) : null,
            'actual_hours' => round($totalActualMinutes / 60, 2),
            'check_attempts' => $this->createForm['periods'],
            'meta_data' => array_filter([
                'manual_notes' => $this->createForm['notes'] ?: null,
                'input_periods' => $this->createForm['periods'],
            ]),
        ]);

        $log->calculateActualHours();
        $log->calculateCompliance();
        $log->save();

        $this->auditLog('attendance.manual_created', $employee->id, 'attendance_daily_log', $log->id, null, $log->toArray());

        $this->showCreateModal = false;
        $this->reset('createForm');
        $this->loadStats();
        $this->dispatch('toast', ['type' => 'success', 'message' => tr('Manual attendance created successfully')]);
    }
}


