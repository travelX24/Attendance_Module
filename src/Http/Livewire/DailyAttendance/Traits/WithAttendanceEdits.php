<?php

namespace Athka\Attendance\Http\Livewire\DailyAttendance\Traits;

use Athka\Attendance\Models\AttendanceDailyLog;
use Athka\Attendance\Models\EmployeeWorkSchedule;
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
        
        // Fetch Schedule for structure
        $companyId = auth()->user()->saas_company_id;
        $date = Carbon::parse($log->attendance_date);
        $dayName = strtolower($date->format('l'));

        $assignment = EmployeeWorkSchedule::where('saas_company_id', $companyId)
            ->where('employee_id', $log->employee_id)
            ->where('is_active', true)
            ->where('start_date', '<=', $date)
            ->where(function($q) use ($date) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $date);
            })
            ->with(['workSchedule.periods'])
            ->first();

        $periodsStructure = [];
        if ($assignment && $assignment->workSchedule) {
            $workSchedule = $assignment->workSchedule;
            $workDays = is_array($workSchedule->work_days) ? $workSchedule->work_days : [];

            if (in_array($dayName, $workDays)) {
                $periodsStructure = $workSchedule->periods;
            }
        }

        // Initialize form periods
        $this->editForm['periods'] = [];

        // If we have structure, use it to initialize inputs
        if (count($periodsStructure) > 0) {
            foreach ($periodsStructure as $index => $p) {
                // Determine scheduled times from period configuration
                $sIn = null;
                $sOut = null;
                
                if (isset($p->start_time)) {
                    try {
                        $sIn = Carbon::parse($p->start_time)->format('h:i A');
                    } catch (\Exception $e) {
                         $sIn = substr($p->start_time, 0, 5);
                    }
                }
                
                if (isset($p->end_time)) {
                    try {
                         $sOut = Carbon::parse($p->end_time)->format('h:i A');
                    } catch (\Exception $e) {
                         $sOut = substr($p->end_time, 0, 5);
                    }
                }

                // Initialize with EMPTY values by default. Do NOT copy main log blindly to all periods.
                $this->editForm['periods'][] = [
                    'check_in_time' => '', 
                    'check_out_time' => '',
                    'scheduled_in' => $sIn,   
                    'scheduled_out' => $sOut, 
                ];
            }
            
            // Now populate values from existing log data
            if (!empty($log->check_attempts) && is_array($log->check_attempts) && count($log->check_attempts) > 0) {
                 // We have detailed attempts, map them to periods by index
                 foreach($log->check_attempts as $i => $attempt) {
                     if (isset($this->editForm['periods'][$i])) {
                         $this->editForm['periods'][$i]['check_in_time'] = $attempt['check_in_time'] ?? '';
                         $this->editForm['periods'][$i]['check_out_time'] = $attempt['check_out_time'] ?? '';
                     } else {
                         // Extra attempt beyond schedule structure, add new row
                         $this->editForm['periods'][] = [
                             'check_in_time' => $attempt['check_in_time'] ?? '',
                             'check_out_time' => $attempt['check_out_time'] ?? '',
                             'scheduled_in' => null,
                             'scheduled_out' => null,
                         ];
                     }
                 }
            } else {
                 // No structured attempts (legacy or single punch). 
                 // Map the Main Log In/Out to the FIRST period only.
                 if ($log->check_in_time || $log->check_out_time) {
                     if (isset($this->editForm['periods'][0])) {
                        $this->editForm['periods'][0]['check_in_time'] = $log->check_in_time ? $log->check_in_time->format('H:i') : '';
                        $this->editForm['periods'][0]['check_out_time'] = $log->check_out_time ? $log->check_out_time->format('H:i') : '';
                     }
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
             ->with('details') // âœ… Load details for multi-period support
             ->where('employee_id', $employeeId)
             ->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()])
             ->get()
             ->keyBy(fn($l) => $l->attendance_date->format('Y-m-d'));

         $this->monthlyEditForm = [];
         
         // Iterate through each day in range
         $current = $start->copy();
         while ($current->lte($end)) {
             $dateStr = $current->toDateString();
             $log = $existingLogs->get($dateStr);

             if ($log) {
                 // Prepare periods
                 $periods = [];
                 if ($log->details->isNotEmpty()) {
                     foreach ($log->details as $d) {
                         $periods[] = [
                             'id' => $d->id,
                             'check_in' => $d->check_in_time ? \Carbon\Carbon::parse($d->check_in_time)->format('H:i') : '',
                             'check_out' => $d->check_out_time ? \Carbon\Carbon::parse($d->check_out_time)->format('H:i') : '',
                         ];
                     }
                 } else {
                     // Fallback to main log times if no details exist
                     $periods[] = [
                         'id' => null,
                         'check_in' => $log->check_in_time ? $log->check_in_time->format('H:i') : '',
                         'check_out' => $log->check_out_time ? $log->check_out_time->format('H:i') : '',
                     ];
                 }

                 $this->monthlyEditForm[] = [
                     'id' => $log->id, 
                     'date' => $dateStr,
                     'is_weekend' => in_array($current->dayOfWeek, [\Carbon\Carbon::FRIDAY, \Carbon\Carbon::SATURDAY]),
                     'status' => $log->attendance_status,
                     'scheduled_in' => $log->scheduled_check_in,
                     'scheduled_out' => $log->scheduled_check_out,
                     'periods' => $periods, // âœ… Multiple periods
                     'scheduled_hours' => $log->scheduled_hours,
                     'actual_hours' => $log->actual_hours,
                     'notes' => $log->meta_data['notes'] ?? '',
                 ];
             } else {
                 $this->monthlyEditForm[] = [
                     'id' => null, 
                     'date' => $dateStr,
                     'is_weekend' => in_array($current->dayOfWeek, [\Carbon\Carbon::FRIDAY, \Carbon\Carbon::SATURDAY]),
                     'status' => 'absent', 
                     'scheduled_in' => null,
                     'scheduled_out' => null,
                     'periods' => [['id' => null, 'check_in' => '', 'check_out' => '']], 
                     'scheduled_hours' => 0,
                     'actual_hours' => 0,
                     'notes' => '',
                 ];
             }
             
             $current->addDay();
         }
         
         // Initialize reason with the most recent one if it exists to show continuity
        // $this->editForm['reason'] = count($this->editHistory) > 0 ? $this->editHistory[0]['reason'] : ''; // This line was misplaced
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
}


