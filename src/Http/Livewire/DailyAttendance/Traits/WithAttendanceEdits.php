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
        $this->resetModalFlags();
        $companyId = auth()->user()->saas_company_id;
        $log = AttendanceDailyLog::forCompany($companyId)->findOrFail($logId);

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
        $this->editingDate = $log->attendance_date->format('Y-m-d');
        
        // Fetch Schedule structure using the service to account for exceptions
        $companyId = auth()->user()->saas_company_id;
        $date = Carbon::parse($log->attendance_date);
        $dateStr = $date->toDateString();

        $service = app(\Athka\SystemSettings\Services\WorkScheduleService::class);
        $ws = $service->getEffectiveSchedule($companyId, $log->employee, $dateStr);
        $holidays = $service->getHolidays($companyId, $dateStr, $dateStr);
        $metrics = $service->getMetricsForDate($dateStr, $ws, $holidays, $log->employee);

        // ✅ Check for Employee Specific Exception (Highest Priority)
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
                $sIn = isset($p->start_time) ? Carbon::parse($p->start_time)->format('h:i A') : null;
                $sOut = isset($p->end_time) ? Carbon::parse($p->end_time)->format('h:i A') : null;

                // Initialize with EMPTY values by default
                $this->editForm['periods'][] = [
                    'check_in_time' => '', 
                    'check_out_time' => '',
                    'scheduled_in' => $sIn,   
                    'scheduled_out' => $sOut, 
                ];
            }
            
            // Now populate values from existing log data
            // We prioritize structured check_attempts, otherwise we pull from the detailed pulses table
            $punches = [];
            if (!empty($log->check_attempts) && is_array($log->check_attempts) && count($log->check_attempts) > 0) {
                 $punches = $log->check_attempts;
            } else {
                 // Load from details table for multi-punch support
                 $punches = $log->details()->orderBy('check_in_time', 'asc')->get()->map(fn($d) => [
                     'check_in_time' => $d->start_time ?: ($d->check_in_time ? \Carbon\Carbon::parse($d->check_in_time)->format('H:i') : ''),
                     'check_out_time' => $d->end_time   ?: ($d->check_out_time ? \Carbon\Carbon::parse($d->check_out_time)->format('H:i') : ''),
                 ])->toArray();

                 // Fallback to main log if details are empty (legacy or single-punch systems)
                 if (empty($punches) && ($log->check_in_time || $log->check_out_time)) {
                     $punches[] = [
                         'check_in_time' => $log->check_in_time ? $log->check_in_time->format('H:i') : '',
                         'check_out_time' => $log->check_out_time ? $log->check_out_time->format('H:i') : '',
                     ];
                 }
            }

            // Map discovered punches to the period structure
            foreach($punches as $i => $punch) {
                if (isset($this->editForm['periods'][$i])) {
                    $this->editForm['periods'][$i]['check_in_time'] = $punch['check_in_time'] ?? '';
                    $this->editForm['periods'][$i]['check_out_time'] = $punch['check_out_time'] ?? '';
                } else {
                    // Extra punch record beyond schedule structure, add a new row
                    $this->editForm['periods'][] = [
                        'check_in_time' => $punch['check_in_time'] ?? '',
                        'check_out_time' => $punch['check_out_time'] ?? '',
                        'scheduled_in' => null,
                        'scheduled_out' => null,
                    ];
                }
            }

        } else {
            // No schedule structure found
             $defaultPeriod = [
                'check_in_time' => $log->check_in_hm ?? '',
                'check_out_time' => $log->check_out_hm ?? '',
                'scheduled_in' => null,
                'scheduled_out' => null,
            ];
            
            if (!empty($log->check_attempts) && is_array($log->check_attempts)) {
                $this->editForm['periods'] = $log->check_attempts;
            } else {
                $this->editForm['periods'] = [$defaultPeriod];
            }
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
        $this->editForm['periods'][] = ['check_in_time' => '', 'check_out_time' => ''];
    }

    public function removePeriodRow($index)
    {
        unset($this->editForm['periods'][$index]);
        $this->editForm['periods'] = array_values($this->editForm['periods']);
    }

    public function saveEdit()
    {
        $this->validate([
            'editForm.periods.*.check_in_time' => 'required|date_format:H:i',
            'editForm.periods.*.check_out_time' => 'required|date_format:H:i|after:editForm.periods.*.check_in_time',
            'editForm.reason' => 'required|string|min:3|max:500',
        ]);

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
            
            if ($in && $out) {
                 $validPeriods[] = $p;
                 if (!$firstIn || $in < $firstIn) $firstIn = $in;
                 if (!$lastOut || $out > $lastOut) $lastOut = $out;
                 
                 $start = Carbon::parse($in);
                 $end = Carbon::parse($out);
                 $totalActualMinutes += $end->diffInMinutes($start);
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

        $log->calculateCompliance();
        $log->save();

        // âœ… Sync with AttendanceDailyDetail table for consistency (Mobile App uses this)
        $log->details()->delete();
        foreach ($validPeriods as $p) {
            $log->details()->create([
                'check_in_time' => $p['check_in_time'],
                'check_out_time' => $p['check_out_time'],
                'attendance_status' => $log->attendance_status, // or match individual period status if we had it
                'meta_data' => ['source' => 'web_edit', 'edited_by' => auth()->id()],
            ]);
        }

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
         
         $this->editingMonth = $start->translatedFormat('M Y') . ($start->month != $end->month ? ' - ' . $end->translatedFormat('M Y') : '');

         // Fetch existing logs
         $existingLogs = AttendanceDailyLog::forCompany($companyId)
             ->with('details') // ✅ Load details for multi-period support
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
         
         // Iterate through each day in range
         $current = $start->copy();
         while ($current->lte($end)) {
                  $dateStr = $current->toDateString();
                  $log = $existingLogs->get($dateStr);
                  $ex = $exceptions->get($dateStr);

                  $compEx = $this->checkCompanyException($current, $employee, $companyExceptions, $officialHolidays);
                  $isException = (bool)$ex || (bool)$compEx;
                  $exceptionName = $ex ? match($ex->exception_type){
                      'off_day' => tr('Off Day'),
                      'work_day' => tr('Work Day'),
                      'overtime' => tr('Overtime'),
                      default => tr('Exception'),
                  } : ($compEx instanceof AttendanceExceptionalDay ? $compEx->name : ($compEx ? ($compEx->template?->name ?? tr('Holiday')) : null));

                  $isWeekend = in_array($current->dayOfWeek, [\Carbon\Carbon::FRIDAY, \Carbon\Carbon::SATURDAY]);

                  // Fetch Schedule periods for this day
                  $schedPeriods = [];
                  $dayName = strtolower($current->format('l'));

                  // Only fetch schedule if it's NOT an "Off Day" exception
                  $isOffDay = ($ex && $ex->exception_type === 'off_day') || ($compEx);

                  if (!$isOffDay) {
                      $ws = app(\Athka\SystemSettings\Services\WorkScheduleService::class)->getEffectiveSchedule($companyId, $employee, $current->toDateString());

                      if ($ws) {
                          if (in_array($dayName, is_array($ws->work_days) ? $ws->work_days : [])) {
                              foreach ($ws->periods as $p) {
                                  $schedPeriods[] = [
                                      'start' => $p->start_time ? Carbon::parse($p->start_time)->format('H:i') : '--:--',
                                      'end' => $p->end_time ? Carbon::parse($p->end_time)->format('H:i') : '--:--',
                                  ];
                              }
                          }
                      }
                  }

                  // Default status for no-work days
                  $dayOffStatus = 'absent';
                  if ($ex && $ex->exception_type === 'off_day') {
                      $dayOffStatus = 'day_off';
                  } elseif ($compEx) {
                      $dayOffStatus = 'holiday';
                  } elseif ($isWeekend || empty($schedPeriods)) {
                      $defaultHolidayStatus = (auth()->user()->saas_company_id == 1) ? 'holiday' : 'day_off'; // Consistency
                      $dayOffStatus = 'day_off'; 
                      // If it's a weekend or no schedule, it's a Day Off/Holiday
                      $dayOffStatus = 'day_off';
                  }

                  if ($log) {
                      $displayStatus = $log->attendance_status;
                      
                      // If it's an exception day but log says absent, force it to show exception status
                      if ($displayStatus === 'absent' && $isException && empty($log->check_in_time)) {
                           $displayStatus = ($compEx) ? 'holiday' : (($ex && $ex->exception_type === 'off_day') ? 'day_off' : 'absent');
                      }

                      $day = [
                          'id' => $log->id, 
                          'date' => $dateStr,
                          'is_weekend' => $isWeekend,
                          'status' => $displayStatus,
                          'scheduled_periods' => $schedPeriods,
                          'periods' => $log->details->isNotEmpty() ? $log->details->map(fn($d) => [
                              'id' => $d->id,
                              'check_in' => $d->check_in_time ? \Carbon\Carbon::parse($d->check_in_time)->format('H:i') : '',
                              'check_out' => $d->check_out_time ? \Carbon\Carbon::parse($d->check_out_time)->format('H:i') : '',
                          ])->toArray() : [[
                              'id' => null,
                              'check_in' => $log->check_in_time ? $log->check_in_time->format('H:i') : '',
                              'check_out' => $log->check_out_time ? $log->check_out_time->format('H:i') : '',
                          ]],
                          'scheduled_hours' => $log->scheduled_hours,
                          'actual_hours' => $log->actual_hours,
                          'notes' => $log->meta_data['notes'] ?? '',
                          'is_exception' => $isException,
                          'exception_name' => $exceptionName,
                      ];
                  } else {
                      $day = [
                          'id' => null, 
                          'date' => $dateStr,
                          'is_weekend' => $isWeekend,
                          'status' => $dayOffStatus, 
                          'scheduled_periods' => $schedPeriods,
                          'periods' => [['id' => null, 'check_in' => '', 'check_out' => '']], 
                          'scheduled_hours' => 0,
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
                  
                  $this->monthlyEditForm[] = $day;
             
             $current->addDay();
         }
         
         $this->monthlyEditReason = ''; // Initialize monthly reason
         $this->showMonthlyEditModal = true;
    }

    public function saveMonthlyEdit()
    {
        $this->validate([
            'monthlyEditReason' => 'required|string|min:3',
            'monthlyEditForm.*.periods.*.check_in' => 'nullable|date_format:H:i',
            'monthlyEditForm.*.periods.*.check_out' => 'nullable|date_format:H:i|after:monthlyEditForm.*.periods.*.check_in',
        ]);

        $companyId = auth()->user()->saas_company_id;
        
        foreach ($this->monthlyEditForm as $row) {
             if (!$row['id']) continue;

             $log = AttendanceDailyLog::forCompany($companyId)->with('details')->find($row['id']);
             if (!$log) continue;

             $before = $log->toArray();
             
             // Process periods
             $firstIn = null;
             $lastOut = null;
             $validPeriodsData = [];

             foreach ($row['periods'] as $p) {
                 if ($p['check_in'] && $p['check_out']) {
                     $validPeriodsData[] = $p;
                     if (!$firstIn || $p['check_in'] < $firstIn) $firstIn = $p['check_in'];
                     if (!$lastOut || $p['check_out'] > $lastOut) $lastOut = $p['check_out'];
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
                     'check_in_time' => $p['check_in'],
                     'check_out_time' => $p['check_out'],
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
        }
        
        $this->showMonthlyEditModal = false;
        $this->loadStats();
        $this->dispatch('toast', ['type' => 'success', 'message' => tr('Monthly sheet updated successfully.')]);
    }

    public function addMonthlyPeriod($dayIndex)
    {
        $this->monthlyEditForm[$dayIndex]['periods'][] = ['id' => null, 'check_in' => '', 'check_out' => ''];
    }

    public function removeMonthlyPeriod($dayIndex, $periodIndex)
    {
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
}


