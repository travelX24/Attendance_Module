<?php

namespace Athka\Attendance\Http\Livewire\DailyAttendance\Traits;

use Athka\Attendance\Models\AttendanceDailyLog;
use Athka\Attendance\Models\EmployeeWorkSchedule;
use Athka\SystemSettings\Models\AttendanceExceptionalDay;
use Carbon\Carbon;

trait WithAttendanceEdits
{
    // ==================== Edit Modal ====================
    public $showEditModal = false;
    public $editingLogId = null;
    public $editForm = [
        'periods' => [], // Dynamic periods: [['check_in_time' => '', 'check_out_time' => '']]
        'reason' => '',
    ];

    public $editHistory = [];

    public $editAttachment = null;

    public $showApprovedEditConfirmModal = false;
    public $approvedEditConfirmText = '';
    public $approvedEditConfirmUnderstood = false;

    public function openEditModal($logId)
    {
        $this->requireAttendanceAny('attendance.daily.manage');
        $this->resetModalFlags();
        $companyId = auth()->user()->saas_company_id;
        $log = AttendanceDailyLog::forCompany($companyId)->findOrFail($logId);

        // 7-day rule enforcement
        // 7-day rule enforcement
        $daysDiff = Carbon::parse($log->attendance_date)->diffInDays(now());

        if ($daysDiff > 7) {
            $this->dispatch('toast', [
                'type' => 'error',
                'message' => tr('Records older than 7 days cannot be edited.')
            ]);

            return;
        }

        if ($log->approval_status === 'approved') {
            $this->editingLogId = $logId;
            $this->showApprovedEditConfirmModal = true;
            return;
        }

        $this->openEditModalInternal($log);
    }

    // View Properties
    public $editingEmployeeName;
    public $editingEmployeeId;
    public $editingDate;

    private function openEditModalInternal(AttendanceDailyLog $log)
    {
        $this->editingLogId = $log->id;
        $this->editingEmployeeName = $log->employee->name_ar ?? $log->employee->name_en;
        $this->editingEmployeeId = $log->employee->employee_no;
        $this->editingDate = company_date($log->attendance_date);

        // Fetch Schedule structure using the service to account for exceptions
        $companyId = auth()->user()->saas_company_id;
        $date = Carbon::parse($log->attendance_date);
        $dateStr = $date->toDateString();

        $service = app(\Athka\SystemSettings\Services\WorkScheduleService::class);
        $ws = $service->getEffectiveSchedule($companyId, $log->employee, $dateStr);
        $holidays = $service->getHolidays($companyId, $dateStr, $dateStr);
        $metrics = $service->getMetricsForDate($dateStr, $ws, $holidays, $log->employee);

        // âœ… Check for Employee Specific Exception (Highest Priority)
        $empExt = \Athka\Attendance\Models\EmployeeWorkScheduleException::where('employee_id', $log->employee_id)
            ->whereDate('exception_date', $dateStr)
            ->first();

        $periodsStructure = [];
        if ($empExt && in_array($empExt->exception_type, ['time_override', 'work_day'], true)) {
            $periodsStructure[] = (object)[
                'start_time' => $empExt->start_time,
                'end_time' => $empExt->end_time,
            ];
        } elseif ($metrics['status'] === 'workday' && !empty($metrics['periods'])) {
            // Use metrics periods (these include schedule-level exceptions)
            foreach ($metrics['periods'] as $p) {
                $periodsStructure[] = (object)$p;
            }
        }

        // Initialize form periods
        $this->editForm['periods'] = [];

        // If we have structure, use it to initialize inputs
        if (count($periodsStructure) > 0) {
            foreach ($periodsStructure as $index => $p) {
                // Determine scheduled times
                $sIn = isset($p->start_time) ? company_time($p->start_time) : null;
                $sOut = isset($p->end_time) ? company_time($p->end_time) : null;

                // Initialize with EMPTY values by default
                $this->editForm['periods'][] = [
                    'check_in_time' => '',
                    'check_out_time' => '',
                    'work_schedule_period_id' => isset($p->id) && $p->id
                        ? (int) $p->id
                        : null,
                    'scheduled_in' => $sIn,
                    'scheduled_out' => $sOut,
                ];
            }

            // Now populate values from existing log data
            // We prioritize structured check_attempts, otherwise we pull from the detailed pulses table            // Map discovered punches to the period structure
            $punches = [];
            // Load from details table for multi-punch support
            $punches = $log->details()->orderBy('check_in_time', 'asc')->get()->map(fn($d) => [
                'check_in_time' => $d->check_in_time ? Carbon::parse($d->check_in_time)->format('H:i') : '',
                'check_out_time' => $d->check_out_time ? Carbon::parse($d->check_out_time)->format('H:i') : '',
                'work_schedule_period_id' => $d->work_schedule_period_id
                    ? (int) $d->work_schedule_period_id
                    : null,
            ])->toArray();

            // Fallback to main log if details are empty
            if (empty($punches) && ($log->check_in_time || $log->check_out_time)) {
                $punches[] = [
                    'check_in_time' => $log->check_in_time ? Carbon::parse($log->check_in_time)->format('H:i') : '',
                    'check_out_time' => $log->check_out_time ? Carbon::parse($log->check_out_time)->format('H:i') : '',
                ];
            }

            // Map discovered punches to the period structure
            foreach($punches as $i => $punch) {
                if (isset($this->editForm['periods'][$i])) {
                    $this->editForm['periods'][$i]['check_in_time'] = $punch['check_in_time'] ?? '';
                    $this->editForm['periods'][$i]['check_out_time'] = $punch['check_out_time'] ?? '';

                    if (! empty($punch['work_schedule_period_id'])) {
                        $this->editForm['periods'][$i]['work_schedule_period_id']
                            = (int) $punch['work_schedule_period_id'];
                    }
                } else {
                    // Extra punch record beyond schedule structure, add a new row
                    $this->editForm['periods'][] = [
                        'check_in_time' => $punch['check_in_time'] ?? '',
                        'check_out_time' => $punch['check_out_time'] ?? '',
                        'work_schedule_period_id' => $punch['work_schedule_period_id'] ?? null,
                        'scheduled_in' => null,
                        'scheduled_out' => null,
                    ];
                }
            }

        } else {
            // No schedule structure found
             $defaultPeriod = [
                'check_in_time' => $log->check_in_time ? Carbon::parse($log->check_in_time)->format('H:i') : '',
                'check_out_time' => $log->check_out_time ? Carbon::parse($log->check_out_time)->format('H:i') : '',
                'scheduled_in' => null,
                'scheduled_out' => null,
            ];

            $this->editForm['periods'] = [$defaultPeriod];
        }

        // Fetch History
        $this->loadEditHistory($log->id);

        // Initialize reason with the most recent one if it exists to show continuity
        $this->editForm['reason'] = count($this->editHistory) > 0 ? $this->editHistory[0]['reason'] : '';

        $this->showEditModal = true;
    }

    private function loadEditHistory($logId)
    {
        $this->editHistory = \Athka\Attendance\Models\AttendanceAuditLog::where('entity_type', 'attendance_daily_log')
            ->where('entity_id', $logId)
            ->whereIn('action', ['attendance.edited', 'attendance.edited_bulk'])
            ->with('actor')
            ->latest()
            ->get()
            ->map(function($log) {
                return [
                    'actor_name' => $log->actor ? ($log->actor->name_ar ?: $log->actor->name_en ?: $log->actor->name) : tr('System'),
                    'date' => $log->created_at->format('Y-m-d H:i'),
                    'reason' => $log->meta_json['reason'] ?? '-',
                ];
            })->toArray();
    }

    public function confirmEditApproved()
    {
        $this->requireAttendanceAny('attendance.daily.manage');
        $this->validate([
             'approvedEditConfirmText' => 'required|string|in:CONFIRM',
             'approvedEditConfirmUnderstood' => 'accepted'
        ]);

        $companyId = auth()->user()->saas_company_id;

        $logQ = AttendanceDailyLog::forCompany($companyId);

        $allowed = $this->allowedBranchIds();
        if (!empty($allowed)) {
            $logQ->whereHas('employee', fn ($q) => $q->whereIn('branch_id', $allowed));
        }

        $log = $logQ->findOrFail($this->editingLogId);
        $this->showApprovedEditConfirmModal = false;
        $this->openEditModalInternal($log);
    }

    public function addPeriodRow()
    {
        $this->requireAttendanceAny('attendance.daily.manage');
        $this->editForm['periods'][] = ['check_in_time' => '', 'check_out_time' => ''];
    }

    public function removePeriodRow($index)
    {
        $this->requireAttendanceAny('attendance.daily.manage');
        unset($this->editForm['periods'][$index]);
        $this->editForm['periods'] = array_values($this->editForm['periods']);
    }

    public function saveEdit()
    {
        $this->requireAttendanceAny('attendance.daily.manage');
        $this->validate([
            'editForm.periods.*.check_in_time' => 'nullable|date_format:H:i',
            'editForm.periods.*.check_out_time' => 'nullable|date_format:H:i',
            'editForm.reason' => 'required|string|min:3|max:500',
        ]);

        $this->validateSingleEditCheckoutIntervals();

        $companyId = auth()->user()->saas_company_id;
        $log = AttendanceDailyLog::forCompany($companyId)->findOrFail($this->editingLogId);

        $before = $log->toArray();

        // Aggregate periods
        $firstIn = null;
        $lastOut = null;
        $totalActualMinutes = 0;
        $validPeriods = [];

        foreach ($this->editForm['periods'] as $p) {
            $in = $p['check_in_time'];
            $out = $p['check_out_time'];

            if ($in || $out) {
                 $validPeriods[] = $p;
                 if ($in && (!$firstIn || $in < $firstIn)) $firstIn = $in;
                 if ($out && (!$lastOut || $out > $lastOut)) $lastOut = $out;

                 if ($in && $out) {
                     $start = Carbon::parse($in);
                     $end = Carbon::parse($out);
                     $totalActualMinutes += $end->diffInMinutes($start);
                 }
            }
        }

        // Update main log
        $dateStr = $log->attendance_date->toDateString();
        $log->check_in_time = $firstIn ? Carbon::parse($dateStr . ' ' . $firstIn) : null;
        $log->check_out_time = $lastOut ? Carbon::parse($dateStr . ' ' . $lastOut) : null;

        // Save structured periods in check_attempts (or meta_data if preferred, check_attempts seems standardized for multi-punch)
        $log->check_attempts = $validPeriods;

        $log->is_edited = true;
        // Recalculate based on new totals
        $log->actual_hours = round($totalActualMinutes / 60, 2);

        // Sync details before saving the parent log so model calculations use
        // the edited periods, especially when all periods are removed.
        $log->details()->delete();
        foreach ($validPeriods as $p) {
            $log->details()->create([
                'work_schedule_period_id' => $p['work_schedule_period_id'] ?? null,
                'check_in_time' => $p['check_in_time'] ?: null,
                'check_out_time' => $p['check_out_time'] ?: null,
                'attendance_status' => $log->attendance_status, // or match individual period status if we had it
                'meta_data' => ['source' => 'web_edit', 'edited_by' => auth()->id()],
            ]);
        }

        $log->unsetRelation('details');
        $log->save();
        $log->details()->update(['attendance_status' => $log->attendance_status]);
        // Log the change
        $this->auditLog(
            'attendance.edited',
            $log->employee_id,
            'attendance_daily_log',
            $log->id,
            $before,
            $log->toArray(),
            ['reason' => $this->editForm['reason']]
        );

        $this->showEditModal = false;
        $this->editingLogId = null;
        $this->loadStats();

        $this->dispatch('toast', [
            'type' => 'success',
            'message' => tr('Attendance record updated successfully')
        ]);
    }

    public function closeEditModal()
    {
        $this->showEditModal = false;
        $this->editingLogId = null;
        $this->reset(['editForm', 'editAttachment']);
    }

    // ==================== Monthly / Multi-Day Sheet Edit ====================
    public $showMonthlyEditModal = false;
    public $monthlyEditForm = []; // Array of day objects
    public $monthlyEditReason = '';
    public $editingMonth = ''; // Display string like "Feb 2026"

    public function openMonthlyEditModal($employeeId)
    {
         $this->requireAttendanceAny('attendance.daily.manage');
         $this->resetModalFlags();
         $companyId = auth()->user()->saas_company_id;
            $empQ = \Athka\Employees\Models\Employee::forCompany($companyId);

            $allowed = $this->allowedBranchIds();
            if (!empty($allowed)) {
                $empQ->whereIn('branch_id', $allowed);
            }

            $employee = $empQ->findOrFail($employeeId);
         $this->editingEmployeeName = $employee->name_ar ?? $employee->name_en;
         $this->editingEmployeeId = $employee->employee_no; // For display

         // Determine range based on filters, defaulting to current month if filter is partial or empty
         $start = $this->date_from ? Carbon::parse($this->date_from) : now()->startOfMonth();
         $end = $this->date_to ? Carbon::parse($this->date_to) : now()->endOfMonth();

         // Cap range to 31 days to avoid performance issues if user selected a huge range
         if ($end->diffInDays($start) > 31) {
             $end = $start->copy()->addDays(31);
         }

         $this->editingMonth = company_date($start, 'MMMM yyyy') . ($start->month != $end->month ? ' - ' . company_date($end, 'MMMM yyyy') : '');

         // Fetch existing logs
         $existingLogs = AttendanceDailyLog::forCompany($companyId)
             ->with('details') // âœ… Load details for multi-period support
             ->where('employee_id', $employeeId)
             ->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])
             ->get()
             ->keyBy(fn($l) => $l->attendance_date->format('Y-m-d'));

         $exceptions = \Athka\Attendance\Models\EmployeeWorkScheduleException::query()
             ->where('employee_id', $employeeId)
             ->whereBetween('exception_date', [$start->toDateString(), $end->toDateString()])
             ->get()
             ->keyBy(fn($ex) => $ex->exception_date->format('Y-m-d'));

         $companyExceptions = AttendanceExceptionalDay::query()
             ->where('company_id', $companyId)
             ->where('is_active', true)
             ->where(function($q) use ($start, $end) {
                 $q->whereBetween('start_date', [$start->toDateString(), $end->toDateString()])
                   ->orWhereBetween('end_date', [$start->toDateString(), $end->toDateString()])
                   ->orWhere(function($qq) use ($start, $end) {
                       $qq->where('start_date', '<=', $start->toDateString())
                          ->where('end_date', '>=', $end->toDateString());
                   });
             })
             ->get();

          $officialHolidays = \Athka\SystemSettings\Models\OfficialHolidayOccurrence::query()
              ->where('company_id', $companyId)
              ->where(function($q) use ($start, $end) {
                  $q->whereBetween('start_date', [$start->toDateString(), $end->toDateString()])
                    ->orWhereBetween('end_date', [$start->toDateString(), $end->toDateString()])
                    ->orWhere(function($qq) use ($start, $end) {
                        $qq->where('start_date', '<=', $start->toDateString())
                           ->where('end_date', '>=', $end->toDateString());
                    });
              })
              ->with('template')
              ->get();

         $this->monthlyEditForm = [];

         // Single source of truth for monthly attendance:
         // employee effective schedule + exceptions + holidays
         // + approved leaves + missions + permissions.
         $workScheduleService = app(
             \Athka\SystemSettings\Services\WorkScheduleService::class
         );

         $scheduleHolidays = $workScheduleService->getHolidays(
             $companyId,
             $start->toDateString(),
             $end->toDateString()
         );

         $employeeRequests = $workScheduleService->getEmployeeRequests(
             (int) $employee->id,
             $start->toDateString(),
             $end->toDateString()
         );

         // Iterate through each day in range
         $current = $start->copy();
         while ($current->lte($end)) {
                  $dateStr = $current->toDateString();
                  $log = $existingLogs->get($dateStr);
                  $ex = $exceptions->get($dateStr);

                  $compEx = $this->checkCompanyException($current, $employee, $companyExceptions, $officialHolidays);
                  $isException = (bool)$ex || (bool)$compEx;
                  $exceptionName = $ex ? match($ex->exception_type){
                      'off_day', 'day_off' => tr('Off Day'),
                      'work_day' => tr('Work Day'),
                      'overtime' => tr('Overtime'),
                      default => tr('Exception'),
                  } : ($compEx instanceof AttendanceExceptionalDay ? $compEx->name : ($compEx ? ($compEx->template?->name ?? tr('Holiday')) : null));

                  $ws = $workScheduleService->getEffectiveSchedule(
                       $companyId,
                       $employee,
                       $dateStr
                   );

                   $metrics = $workScheduleService->getMetricsForDate(
                       $dateStr,
                       $ws,
                       $scheduleHolidays,
                       $employee,
                       $employeeRequests
                   );

                   $effectiveStatus = (string) (
                       $metrics['status'] ?? 'no_schedule'
                   );

                   // A day is off ONLY when the employee's effective
                   // schedule says it is off. Weekday names are irrelevant.
                   $isScheduleDayOff = $effectiveStatus === 'off';

                   $schedPeriods = collect(
                       $metrics['periods'] ?? []
                   )->map(function ($period) {
                       return [
                           'id' => ! empty($period['id'])
                                ? (int) $period['id']
                                : null,

                            'start_raw' => ! empty($period['start_time'])
                                ? substr((string) $period['start_time'], 0, 5)
                                : null,

                            'end_raw' => ! empty($period['end_time'])
                                ? substr((string) $period['end_time'], 0, 5)
                                : null,

                            'is_night_shift' => (bool) (
                                $period['is_night_shift'] ?? false
                            ),

                            'start' => !empty($period['start_time'])
                               ? company_time($period['start_time'])
                               : '--:--',

                           'end' => !empty($period['end_time'])
                               ? company_time($period['end_time'])
                               : '--:--',

                           'is_leave' => (bool) (
                               $period['is_leave'] ?? false
                           ),

                           'leave_name' =>
                               $period['leave_name'] ?? null,
                       ];
                   })->values()->all();

                   // Preserve meaningful names returned by the engine.
                   if (!$exceptionName) {
                       $exceptionName = match ($effectiveStatus) {
                           'holiday', 'mission' =>
                               $metrics['holiday_name'] ?? null,

                           'on_leave' =>
                               $metrics['leave_name'] ?? null,

                           default => null,
                       };
                   }

                   $isException = $isException || in_array(
                       $effectiveStatus,
                       ['holiday', 'on_leave', 'mission'],
                       true
                   );

                   $dayOffStatus = match ($effectiveStatus) {
                       'off'         => 'day_off',
                       'holiday'     => 'holiday',
                       'on_leave'    => 'on_leave',
                       'mission'     => 'mission',
                       'no_schedule' => 'no_schedule',
                       default       => 'absent',
                   };


                  if ($log) {
                      $displayStatus = $log->attendance_status;

                      // If it's an exception day but log says absent, force it to show exception status
                      if (
                           $displayStatus === 'absent'
                           && empty($log->check_in_time)
                       ) {
                           $displayStatus = match ($effectiveStatus) {
                               'off'         => 'day_off',
                               'holiday'     => 'holiday',
                               'on_leave'    => 'on_leave',
                               'mission'     => 'mission',
                               'no_schedule' => 'no_schedule',
                               default       => $displayStatus,
                           };
                       }
                         $schedHours = (float) ($log->scheduled_hours ?? 0);
                       if ($schedHours <= 0 && !empty($schedPeriods)) {
                           $schedHours = (float) collect($schedPeriods)->sum(function($p) use ($dateStr) {
                               if (!empty($p['start_raw']) && !empty($p['end_raw'])) {
                                   $start = Carbon::parse($dateStr . ' ' . $p['start_raw']);
                                   $end = Carbon::parse($dateStr . ' ' . $p['end_raw']);
                                   if (!empty($p['is_night_shift']) || $end->lt($start)) {
                                       $end->addDay();
                                   }
                                   return round($start->diffInMinutes($end, true) / 60, 2);
                               }
                               return 0;
                           });
                       }

                      $day = [
                          'id' => $log->id,
                          'date' => $dateStr,
                          'is_day_off' => $isScheduleDayOff,
                          'status' => $displayStatus,
                          'scheduled_periods' => $schedPeriods,
                          'periods' => $log->details->isNotEmpty() ? $log->details->map(fn($d) => [
                              'id' => $d->id,
                              'work_schedule_period_id' => $d->work_schedule_period_id
                                  ? (int) $d->work_schedule_period_id
                                  : null,
                              'check_in' => $d->check_in_time ? Carbon::parse($d->check_in_time)->format('H:i') : '',
                              'check_out' => $d->check_out_time ? Carbon::parse($d->check_out_time)->format('H:i') : '',
                          ])->toArray() : [[
                              'id' => null,
                              'work_schedule_period_id' => null,
                              'check_in' => $log->check_in_time ? Carbon::parse($log->check_in_time)->format('H:i') : '',
                              'check_out' => $log->check_out_time ? Carbon::parse($log->check_out_time)->format('H:i') : '',
                          ]],
                          'scheduled_hours' => $schedHours,
                          'actual_hours' => (float) ($log->actual_hours ?? 0),
                          'notes' => $log->meta_data['notes'] ?? '',
                          'is_exception' => $isException,
                          'exception_name' => $exceptionName,
                      ];
                  } else {
                       $schedHours = (float) collect($schedPeriods)->sum(function($p) use ($dateStr) {
                           if (!empty($p['start_raw']) && !empty($p['end_raw'])) {
                               $start = Carbon::parse($dateStr . ' ' . $p['start_raw']);
                               $end = Carbon::parse($dateStr . ' ' . $p['end_raw']);
                               if (!empty($p['is_night_shift']) || $end->lt($start)) {
                                   $end->addDay();
                               }
                               return round($start->diffInMinutes($end, true) / 60, 2);
                           }
                           return 0;
                       });

                      $day = [
                          'id' => null,
                          'date' => $dateStr,
                          'is_day_off' => $isScheduleDayOff,
                          'status' => $dayOffStatus,
                          'scheduled_periods' => $schedPeriods,
                          'periods' => [[
                              'id' => null,
                              'work_schedule_period_id' => null,
                              'check_in' => '',
                              'check_out' => '',
                          ]],
                          'scheduled_hours' => $schedHours,
                          'actual_hours' => 0,
                          'notes' => '',
                          'is_exception' => $isException,
                          'exception_name' => $exceptionName,
                      ];
                  }

                   if ($compEx instanceof \Athka\Settings\Models\OfficialHolidayOccurrence) {
                         $day['is_exception'] = true;
                         $day['is_official_holiday'] = true;
                         $day['exception_name'] = $compEx->template?->name ?? 'Holiday';
                         $day['status'] = 'holiday';
                   }

                  $day['_original'] = $this->monthlyEditSnapshot($day);
                  $this->monthlyEditForm[] = $day;

             $current->addDay();
         }

         $this->monthlyEditReason = ''; // Initialize monthly reason
         $this->showMonthlyEditModal = true;
    }

    public function saveMonthlyEdit()
    {
        $this->requireAttendanceAny('attendance.daily.manage');
        $this->validate([
            'monthlyEditReason' => 'required|string|min:3',
            'monthlyEditForm.*.periods.*.check_in' => 'nullable|date_format:H:i',
            'monthlyEditForm.*.periods.*.check_out' => 'nullable|date_format:H:i',
        ]);


        $this->validateMonthlyEditCheckoutIntervals();

        $companyId = auth()->user()->saas_company_id;

        $updatedCount = 0;

        foreach ($this->monthlyEditForm as $row) {
             if (!$row['id']) continue;
             if (!$this->monthlyEditRowChanged($row)) continue;

             $rowDate = Carbon::parse($row['date']);
             $isOlderThan7Days = $rowDate->diffInDays(now()) > 7;
             $isHoliday = ($row['status'] === 'holiday') || ($row['is_official_holiday'] ?? false);

             if ($isHoliday || $isOlderThan7Days) {
                 $reasonMsg = $isHoliday
                     ? tr('Cannot edit attendance on official holiday: ') . $row['date']
                     : tr('Editing period expired (older than 7 days) for: ') . $row['date'];
                 $this->dispatch('toast', ['type' => 'error', 'message' => $reasonMsg]);
                 continue;
             }

             $log = AttendanceDailyLog::forCompany($companyId)->with('details')->find($row['id']);
             if (!$log) continue;


             $before = $log->toArray();

             // Process periods
             $firstIn = null;
             $lastOut = null;
             $validPeriodsData = [];

             foreach ($row['periods'] as $periodIndex => $p) {
                 if ($p['check_in'] || $p['check_out']) {
                     if (
                         empty($p['work_schedule_period_id'])
                         && empty($row['is_exception'])
                     ) {
                         $candidatePeriodId = (int) (
                             $row['scheduled_periods'][$periodIndex]['id'] ?? 0
                         );

                         if ($candidatePeriodId > 0) {
                             $p['work_schedule_period_id'] = $candidatePeriodId;
                         }
                     }

                     $validPeriodsData[] = $p;
                     if ($p['check_in'] && (!$firstIn || $p['check_in'] < $firstIn)) $firstIn = $p['check_in'];
                     if ($p['check_out'] && (!$lastOut || $p['check_out'] > $lastOut)) $lastOut = $p['check_out'];
                 }
             }

             // Update main log
             $log->check_in_time = $firstIn ? Carbon::parse($row['date'] . ' ' . $firstIn) : null;
             $log->check_out_time = $lastOut ? Carbon::parse($row['date'] . ' ' . $lastOut) : null;

             $log->attendance_status = $row['status'];
             $meta = $log->meta_data ?? [];
             $meta['notes'] = $row['notes'];
             $log->meta_data = $meta;
             $log->is_edited = true;
             $log->check_attempts = $validPeriodsData; // Store JSON as well

             // Sync Details table
             $log->details()->delete();
             foreach ($validPeriodsData as $p) {
                 $log->details()->create([
                     'work_schedule_period_id' => $p['work_schedule_period_id'] ?? null,
                     'check_in_time' => $p['check_in'] ?: null,
                     'check_out_time' => $p['check_out'] ?: null,
                     'attendance_status' => $log->attendance_status,
                     'meta_data' => ['source' => 'monthly_edit', 'edited_by' => auth()->id()]
                 ]);
             }

             // Recalculate Totals (Model handles this on saving, but we can call it to be safe)
             $log->calculateActualHours();
             $log->calculateCompliance();
             $log->save();

             $this->auditLog(
                'attendance.edited_bulk',
                $log->employee_id,
                'attendance_daily_log',
                $log->id,
                $before,
                $log->toArray(),
                ['reason' => $this->monthlyEditReason, 'bulk_sheet' => true]
             );

             $updatedCount++;
        }

        $this->showMonthlyEditModal = false;
        $this->loadStats();
        if ($updatedCount > 0) {
            $this->dispatch('toast', ['type' => 'success', 'message' => tr('Monthly sheet updated successfully.')]);
        }
    }

    private function monthlyEditRowChanged(array $row): bool
    {
        if (!array_key_exists('_original', $row) || !is_array($row['_original'])) {
            return true;
        }

        return $this->monthlyEditSnapshot($row) !== $row['_original'];
    }

    private function monthlyEditSnapshot(array $row): array
    {
        return [
            'status' => (string) ($row['status'] ?? ''),
            'notes' => trim((string) ($row['notes'] ?? '')),
            'periods' => $this->normalizeMonthlyEditPeriods($row['periods'] ?? []),
        ];
    }

    private function normalizeMonthlyEditPeriods(array $periods): array
    {
        return collect($periods)
            ->map(function ($period) {
                return [
                    'check_in' => trim((string) ($period['check_in'] ?? '')),
                    'check_out' => trim((string) ($period['check_out'] ?? '')),
                ];
            })
            ->filter(fn ($period) => $period['check_in'] !== '' || $period['check_out'] !== '')
            ->values()
            ->all();
    }

    public function addMonthlyPeriod($dayIndex)
    {
        $this->requireAttendanceAny('attendance.daily.manage');
        $this->monthlyEditForm[$dayIndex]['periods'][] = [
            'id' => null,
            'work_schedule_period_id' => null,
            'check_in' => '',
            'check_out' => '',
        ];
    }

    public function removeMonthlyPeriod($dayIndex, $periodIndex)
    {
        $this->requireAttendanceAny('attendance.daily.manage');
        if (count($this->monthlyEditForm[$dayIndex]['periods']) > 1) {
            unset($this->monthlyEditForm[$dayIndex]['periods'][$periodIndex]);
            $this->monthlyEditForm[$dayIndex]['periods'] = array_values($this->monthlyEditForm[$dayIndex]['periods']);
        }
    }

    private function checkCompanyException(Carbon $day, $employee, $companyExceptions, $officialHolidays = null)
    {
        $compEx = $companyExceptions->first(function($ce) use ($day, $employee) {
            $inDate = $day->between(
                Carbon::parse($ce->start_date)->startOfDay(),
                Carbon::parse($ce->end_date)->startOfDay()
            );
            if (!$inDate) return false;

            $applyOn = $ce->apply_on ?: 'everyone';
            if ($applyOn === 'everyone') return true;

            $include = is_array($ce->include) ? $ce->include : (json_decode($ce->include, true) ?: []);

            if ($applyOn === 'employees' || $applyOn === 'absence') {
                $targetIds = $include['employees'] ?? [];
                if (in_array((string)$employee->id, $targetIds)) return true;
            }

            if ($applyOn === 'departments') {
                $targetIds = $include['departments'] ?? [];
                if (in_array((string)$employee->department_id, $targetIds)) return true;
            }

            // Using branch_id for location scoping in this module
            if ($applyOn === 'locations' || $applyOn === 'branches') {
                $targetIds = $include['branches'] ?? $include['locations'] ?? [];
                if (in_array((string)$employee->branch_id, $targetIds)) return true;
            }

            return false;
        });

        if ($compEx) return $compEx;

        if ($officialHolidays) {
            return $officialHolidays->first(function($oh) use ($day) {
                return $day->between(
                    Carbon::parse($oh->start_date)->startOfDay(),
                    Carbon::parse($oh->end_date)->startOfDay()
                );
            });
        }

        return null;
    }
    /**
     * Validate manual web checkout using the same business rule used by
     * employee attendance checkout:
     *
     * - 60 minutes or more after check-in is allowed.
     * - Before 60 minutes, checkout is allowed only when the linked work
     *   schedule period has already reached its scheduled end.
     */
    private function validateSingleEditCheckoutIntervals(): void
    {
        $attendanceService = app(
            \Athka\SystemSettings\Services\AttendanceService::class
        );

        $errors = [];

        foreach (($this->editForm['periods'] ?? []) as $index => $period) {
            $checkIn = trim((string) ($period['check_in_time'] ?? ''));
            $checkOut = trim((string) ($period['check_out_time'] ?? ''));

            if ($checkIn === '' || $checkOut === '') {
                continue;
            }

            $actualStart = Carbon::parse($checkIn);
            $actualEnd = Carbon::parse($checkOut);

            $scheduledIn = trim((string) ($period['scheduled_in'] ?? ''));
            $scheduledOut = trim((string) ($period['scheduled_out'] ?? ''));

            $hasScheduledTimes = $scheduledIn !== ''
                && $scheduledIn !== '--:--'
                && $scheduledOut !== ''
                && $scheduledOut !== '--:--';

            $isNightPeriod = false;

            if ($hasScheduledTimes) {
                $scheduledStartTime = Carbon::parse($scheduledIn);
                $scheduledEndTime = Carbon::parse($scheduledOut);

                $isNightPeriod = $scheduledEndTime->lt($scheduledStartTime);
            }

            /*
             * An earlier clock time is considered next-day only for a real
             * overnight schedule. This prevents arbitrary reversed manual
             * attendance times from being accepted as a 20+ hour session.
             */
            if ($actualEnd->lt($actualStart) && ! $isNightPeriod) {
                $errors["editForm.periods.{$index}.check_out_time"]
                    = str_starts_with((string) app()->getLocale(), 'ar')
                        ? 'لا يمكن أن يكون وقت الحضور بعد وقت الانصراف.'
                        : 'Check-in time cannot be after check-out time.';

                continue;
            }

            $elapsedMinutes = $attendanceService->minutesBetween(
                $checkIn,
                $checkOut
            );

            if ($elapsedMinutes >= 60) {
                continue;
            }

            /*
             * Match mobile/server semantics:
             * reaching period end is an exception only when the punch is
             * actually linked to a work_schedule_period_id.
             */
            $periodId = (int) (
                $period['work_schedule_period_id'] ?? 0
            );

            if ($periodId > 0 && $hasScheduledTimes) {
                $requiredMinutes = $attendanceService->minutesBetween(
                    $checkIn,
                    $scheduledOut
                );

                if ($elapsedMinutes >= $requiredMinutes) {
                    continue;
                }
            }

            $errors["editForm.periods.{$index}.check_out_time"]
                = str_starts_with((string) app()->getLocale(), 'ar')
                    ? 'لا يمكنك تسجيل الانصراف إلا بعد مرور ساعة كاملة من وقت الحضور، أو عند بلوغ نهاية فترة العمل.'
                    : 'You cannot check out until one hour has passed since check-in, or the linked work period has ended.';
        }

        if ($errors !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages(
                $errors
            );
        }
    }
    /**
     * Validate monthly attendance edits using the employee checkout rule.
     *
     * - 60+ minutes after check-in is allowed.
     * - Before 60 minutes, checkout is allowed only after the linked normal
     *   work-schedule period reaches its end.
     * - Crossing midnight is accepted only for an actual night period.
     */
    private function validateMonthlyEditCheckoutIntervals(): void
    {
        $attendanceService = app(
            \Athka\SystemSettings\Services\AttendanceService::class
        );

        $errors = [];

        foreach ($this->monthlyEditForm as $dayIndex => $row) {
            if (empty($row['id'])) {
                continue;
            }

            if (! $this->monthlyEditRowChanged($row)) {
                continue;
            }

            $scheduledPeriods = $row['scheduled_periods'] ?? [];

            foreach (($row['periods'] ?? []) as $periodIndex => $period) {
                $checkIn = trim((string) (
                    $period['check_in'] ?? ''
                ));

                $checkOut = trim((string) (
                    $period['check_out'] ?? ''
                ));

                if ($checkIn === '' || $checkOut === '') {
                    continue;
                }

                $scheduledByIndex =
                    $scheduledPeriods[$periodIndex] ?? null;

                $periodId = (int) (
                    $period['work_schedule_period_id'] ?? 0
                );

                /*
                 * Old web-edited normal records may have lost their ID.
                 * Recover it using the corresponding normal schedule period.
                 *
                 * Exception-day IDs are intentionally not treated as
                 * work_schedule_period IDs.
                 */
                if (
                    $periodId <= 0
                    && empty($row['is_exception'])
                    && is_array($scheduledByIndex)
                ) {
                    $periodId = (int) (
                        $scheduledByIndex['id'] ?? 0
                    );
                }

                $linkedScheduledPeriod = null;

                if (
                    $periodId > 0
                    && empty($row['is_exception'])
                ) {
                    foreach ($scheduledPeriods as $scheduledPeriod) {
                        if (
                            (int) ($scheduledPeriod['id'] ?? 0)
                            === $periodId
                        ) {
                            $linkedScheduledPeriod = $scheduledPeriod;
                            break;
                        }
                    }
                }

                /*
                 * We may still use the effective schedule period to determine
                 * whether this is genuinely an overnight shift.
                 */
                $nightReference = $linkedScheduledPeriod
                    ?? $scheduledByIndex;

                $isNightPeriod = false;

                if (is_array($nightReference)) {
                    $scheduledStart = trim((string) (
                        $nightReference['start_raw'] ?? ''
                    ));

                    $scheduledEnd = trim((string) (
                        $nightReference['end_raw'] ?? ''
                    ));

                    $isNightPeriod = (bool) (
                        $nightReference['is_night_shift'] ?? false
                    );

                    if (
                        ! $isNightPeriod
                        && $scheduledStart !== ''
                        && $scheduledEnd !== ''
                    ) {
                        $isNightPeriod =
                            strcmp($scheduledEnd, $scheduledStart) < 0;
                    }
                }

                $actualStart = Carbon::parse($checkIn);
                $actualEnd = Carbon::parse($checkOut);

                /*
                 * Do not turn an arbitrary reversed manual time into a
                 * next-day checkout unless this is actually a night shift.
                 */
                if (
                    $actualEnd->lt($actualStart)
                    && ! $isNightPeriod
                ) {
                    $errors[
                        "monthlyEditForm.{$dayIndex}.periods.{$periodIndex}.check_out"
                    ] = str_starts_with(
                        (string) app()->getLocale(),
                        'ar'
                    )
                        ? 'لا يمكن أن يكون وقت الحضور بعد وقت الانصراف.'
                        : 'Check-in time cannot be after check-out time.';

                    continue;
                }

                $elapsedMinutes = $attendanceService->minutesBetween(
                    $checkIn,
                    $checkOut
                );

                /*
                 * Main rule: one full hour has passed.
                 */
                if ($elapsedMinutes >= 60) {
                    continue;
                }

                /*
                 * Same exception used by employee checkout:
                 * before one hour is allowed only when this punch is linked
                 * to a real work_schedule_period and its end was reached.
                 */
                if ($linkedScheduledPeriod !== null) {
                    $scheduledOut = trim((string) (
                        $linkedScheduledPeriod['end_raw'] ?? ''
                    ));

                    if ($scheduledOut !== '') {
                        $requiredMinutes =
                            $attendanceService->minutesBetween(
                                $checkIn,
                                $scheduledOut
                            );

                        if ($elapsedMinutes >= $requiredMinutes) {
                            continue;
                        }
                    }
                }

                $errors[
                    "monthlyEditForm.{$dayIndex}.periods.{$periodIndex}.check_out"
                ] = str_starts_with(
                    (string) app()->getLocale(),
                    'ar'
                )
                    ? 'لا يمكنك تسجيل الانصراف إلا بعد مرور ساعة كاملة من وقت الحضور، أو عند بلوغ نهاية فترة العمل.'
                    : 'You cannot check out until one hour has passed since check-in, or the linked work period has ended.';
            }
        }

        if ($errors !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages(
                $errors
            );
        }
    }
}
