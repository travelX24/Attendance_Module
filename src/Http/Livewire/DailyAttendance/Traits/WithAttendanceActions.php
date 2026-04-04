<?php

namespace Athka\Attendance\Http\Livewire\DailyAttendance\Traits;

use Athka\Attendance\Models\AttendanceDailyLog;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

trait WithAttendanceActions
{
    // ==================== Modals ====================
    public $showApprovalModal = false;
    public $approvingLogId = null;
    public $approvalNotes = '';

    public $showRejectModal = false;
    public $rejectingLogId = null;
    public $rejectNotes = '';

    public $showUnapproveModal = false;
    public $unapprovingLogId = null;
    public $unapproveReason = '';
    public $unapproveUnderstood = false;

    public $showBulkApprovalModal = false;
    public $bulkSummary = [
        'total_count' => 0,
        'ready_count' => 0,
        'issues_count' => 0,
        'avg_hours' => 0,
        'has_issues' => false,
    ];

    public $selectedLogs = [];
    public $selectAll = false;
    public $approvalPreview = [];
    public $approvalEditHistory = [];
    public $approvalIssues = [];

    public function openApprovalModal($logId)
    {
        $this->resetModalFlags();
        $companyId = auth()->user()->saas_company_id;

        $logQ = AttendanceDailyLog::forCompany($companyId)->with(['employee', 'auditLogs']);

        $allowed = $this->allowedBranchIds();
        if (!empty($allowed)) {
            $logQ->whereHas('employee', fn ($q) => $q->whereIn('branch_id', $allowed));
        }

        $log = $logQ->findOrFail($logId);

        if (in_array($log->approval_status, ['approved', 'rejected'])) {
            $this->dispatch('toast', ['type' => 'error', 'message' => tr('Record already processed')]);
            return;
        }

        $this->approvingLogId = $logId;
        $this->buildApprovalPreview($log);
        $this->showApprovalModal = true;
    }

    private function buildApprovalPreview(AttendanceDailyLog $log)
    {
        $this->approvalIssues = [];
        $this->approvalPreview = [
            'employee_name' => $log->employee->name_ar ?? $log->employee->name_en,
            'employee_no' => $log->employee->employee_no ?? '-',
            'attendance_date' => \Carbon\Carbon::parse($log->attendance_date)->format('Y-m-d'),
            'status' => $log->attendance_status,
            'check_in' => $log->check_in_hm,
            'check_out' => $log->check_out_hm,
            'scheduled_in' => $log->scheduled_check_in_hm,
            'scheduled_out' => $log->scheduled_check_out_hm,
            'actual_hours' => (float)$log->actual_hours,
            'scheduled_hours' => (float)$log->scheduled_hours,
            'compliance_percentage' => (float)$log->compliance_percentage,
            'schedule_name' => $log->workSchedule->name ?? '-',
        ];

        // 1. Audit Logs for specific edits
        $edits = $log->auditLogs()->where('action', 'attendance.edited')->count();
        if ($edits > 0) {
            $this->approvalIssues[] = str_replace(':count', $edits, tr('This record was manually edited :count times'));
        }

        // 2. Auto Checkout warning
        if ($log->attendance_status === 'auto_checkout') {
            $this->approvalIssues[] = tr('System performed auto-checkout due to missing punch.');
        }

        // 3. History Preview with all detail keys
        $this->approvalEditHistory = $log->auditLogs()
            ->with('actor')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($a) => [
                'actor' => $a->actor->name ?? tr('System'),
                'action' => tr(str_replace(['attendance.', '_'], ['', ' '], $a->action)),
                'at' => $a->created_at->format('Y-m-d H:i'),
                'from_in' => $a->before_json['check_in_time'] ?? null,
                'to_in' => $a->after_json['check_in_time'] ?? null,
                'reason' => $a->meta_json['reason'] ?? ($a->meta_json['notes'] ?? null)
            ])->toArray();
    }

    private function calculateLateEarlyMinutes($date, $scheduledIn, $scheduledOut, $checkIn, $checkOut)
    {
        $late = 0;
        $early = 0;

        if ($scheduledIn && $checkIn) {
            $s = Carbon::parse("$date $scheduledIn");
            $c = Carbon::parse("$date $checkIn");
            if ($c->gt($s)) $late = $c->diffInMinutes($s);
        }

        if ($scheduledOut && $checkOut) {
            $s = Carbon::parse("$date $scheduledOut");
            $c = Carbon::parse("$date $checkOut");
            if ($c->lt($s)) $early = $s->diffInMinutes($c);
        }

        return ['late' => $late, 'early' => $early];
    }

    public function approveSingle()
    {
        $companyId = auth()->user()->saas_company_id;
        $logQ = AttendanceDailyLog::forCompany($companyId);

        $allowed = $this->allowedBranchIds();
        if (!empty($allowed)) {
            $logQ->whereHas('employee', fn ($q) => $q->whereIn('branch_id', $allowed));
        }

        $log = $logQ->findOrFail($this->approvingLogId);

        $before = $log->toArray();
        $log->update([
            'approval_status' => 'approved',
            'approved_by' => auth()->id(),
            'approved_at' => now(),
            'approval_notes' => $this->approvalNotes
        ]);

        $this->auditLog('attendance.approved', $log->employee_id, 'attendance_daily_log', $log->id, $before, $log->toArray());

        $this->showApprovalModal = false;
        $this->approvingLogId = null;
        $this->approvalNotes = '';
        $this->loadStats();
        $this->dispatch('toast', ['type' => 'success', 'message' => tr('Record approved successfully')]);
    }

    public function openRejectModal($logId)
    {
        $this->resetModalFlags();
        $companyId = auth()->user()->saas_company_id;
        
        $logQ = AttendanceDailyLog::forCompany($companyId);

        $allowed = $this->allowedBranchIds();
        if (!empty($allowed)) {
            $logQ->whereHas('employee', fn ($q) => $q->whereIn('branch_id', $allowed));
        }

        $log = $logQ->findOrFail($logId);

        if (in_array($log->approval_status, ['approved', 'rejected'])) {
            $this->dispatch('toast', ['type' => 'error', 'message' => tr('Record already processed')]);
            return;
        }

        $this->rejectingLogId = $logId;
        $this->showRejectModal = true;
    }

    public function rejectSingle()
    {
        $this->validate(['rejectNotes' => 'required|string|min:3|max:500']);

        $companyId = auth()->user()->saas_company_id;
        $log = AttendanceDailyLog::forCompany($companyId)->findOrFail($this->rejectingLogId);

        $before = $log->toArray();
        $log->update([
            'approval_status' => 'rejected',
            'rejected_by' => auth()->id(),
            'rejected_at' => now(),
            'reject_reason' => $this->rejectNotes
        ]);

        $this->auditLog('attendance.rejected', $log->employee_id, 'attendance_daily_log', $log->id, $before, $log->toArray());

        $this->showRejectModal = false;
        $this->rejectingLogId = null;
        $this->rejectNotes = '';
        $this->loadStats();
        $this->dispatch('toast', ['type' => 'warning', 'message' => tr('Record rejected')]);
    }

    public function openUnapproveModal($logId)
    {
        $this->resetModalFlags();
        $companyId = auth()->user()->saas_company_id;
        $log = AttendanceDailyLog::forCompany($companyId)->findOrFail($logId);

        if ($log->approval_status === 'pending') return;

        $this->unapprovingLogId = $logId;
        $this->showUnapproveModal = true;
    }

    public function unapproveSingle()
    {
        $this->validate(['unapproveReason' => 'required|string|min:3|max:500', 'unapproveUnderstood' => 'accepted']);

        $companyId = auth()->user()->saas_company_id;
        $log = AttendanceDailyLog::forCompany($companyId)->findOrFail($this->unapprovingLogId);

        $before = $log->toArray();
        $log->update([
            'approval_status' => 'pending',
            'revoked_by' => auth()->id(),
            'revoked_at' => now(),
            'revoke_reason' => $this->unapproveReason,
            'approved_by' => null,
            'approved_at' => null,
            'rejected_by' => null,
            'rejected_at' => null,
        ]);

        $this->auditLog('attendance.unapproved', $log->employee_id, 'attendance_daily_log', $log->id, $before, $log->toArray());

        $this->showUnapproveModal = false;
        $this->unapprovingLogId = null;
        $this->unapproveReason = '';
        $this->unapproveUnderstood = false;
        $this->loadStats();
        $this->dispatch('toast', ['type' => 'info', 'message' => tr('Record re-opened for review')]);
    }

    public function openBulkApprovalModal()
    {
        if (empty($this->selectedLogs)) {
            $this->dispatch('toast', ['type' => 'warning', 'message' => tr('No records selected')]);
            return;
        }

        $companyId = auth()->user()->saas_company_id;
        $logs = AttendanceDailyLog::forCompany($companyId)
            ->whereIn('id', $this->selectedLogs)
            ->get();

        $readyCount = 0;
        $issuesCount = 0;
        $totalHours = 0;

        foreach ($logs as $log) {
            if ($log->approval_status !== 'pending') continue;

            $readyCount++;
            $totalHours += $log->actual_hours;

            // Check if it has issues
            $hasIssues = ($log->attendance_status === 'auto_checkout' || $log->attendance_status === 'absent');
            if ($hasIssues) $issuesCount++;
        }

        $this->bulkSummary = [
            'total_count' => count($this->selectedLogs),
            'ready_count' => $readyCount,
            'issues_count' => $issuesCount,
            'avg_hours' => $readyCount > 0 ? round($totalHours / $readyCount, 2) : 0,
            'has_issues' => $issuesCount > 0,
        ];

        $this->showBulkApprovalModal = true;
    }

    public function confirmBulkApprove()
    {
        $companyId = auth()->user()->saas_company_id;
        $approvedCount = 0;
        $skippedCount = 0;

        DB::transaction(function () use ($companyId, &$approvedCount, &$skippedCount) {
            foreach ($this->selectedLogs as $logId) {
                $logQ = AttendanceDailyLog::forCompany($companyId);

                $allowed = $this->allowedBranchIds();
                if (!empty($allowed)) {
                    $logQ->whereHas('employee', fn ($q) => $q->whereIn('branch_id', $allowed));
                }

                $log = $logQ->find($logId);
                if (!$log || in_array($log->approval_status, ['approved', 'rejected'], true)) {
                    $skippedCount++;
                    continue;
                }

                $before = $log->toArray();
                $log->update(['approval_status' => 'approved', 'approved_by' => auth()->id(), 'approved_at' => now()]);

                $this->auditLog('attendance.approved', $log->employee_id, 'attendance_daily_log', $log->id, $before, $log->toArray(), ['bulk' => true]);
                $approvedCount++;
            }
        });

        $this->showBulkApprovalModal = false;
        $this->selectedLogs = [];
        $this->selectAll = false;
        $this->loadStats();

        $this->dispatch('toast', [
            'type' => 'success',
            'message' => str_replace(':count', $approvedCount, tr('Successfully approved :count records.'))
        ]);
    }

    public function updatedSelectAll($value)
    {
        if ($value) {
            $this->selectedLogs = $this->attendanceLogs->pluck('id')->toArray();
        } else {
            $this->selectedLogs = [];
        }
    }

    public function generateMissingLogs($silent = false)
    {
        $companyId = auth()->user()->saas_company_id;
        $start = Carbon::parse($this->date_from);
        $end = Carbon::parse($this->date_to);

        // Safety cap: don't auto-generate more than a month
        if ($start->diffInDays($end) > 31) {
            if (!$silent) $this->dispatch('toast', ['type' => 'error', 'message' => tr('Date range too large. Max 31 days.')]);
            return;
        }

        if ($end->isFuture()) {
            $end = now(); 
        }
        
        if ($start->isFuture()) {
             return;
        }

        // 1. Fetch relevant employees
        $empQ = \Athka\Employees\Models\Employee::forCompany($companyId)
                ->where('status', 'active');

        $allowed = method_exists($this, 'allowedBranchIds') ? $this->allowedBranchIds() : [];
        if (!empty($allowed)) {
            $empQ->whereIn('branch_id', $allowed);
        }

        $employees = $empQ->pluck('id', 'id');
        $employeeIds = $employees->keys()->all();

        // 2. Sync Existing Logs in range (to fix any inconsistencies)
        $existingQ = AttendanceDailyLog::forCompany($companyId)
             ->whereBetween('attendance_date', [$start->toDateString(), $end->toDateString()]);

        if (!empty($employeeIds)) {
            $existingQ->whereIn('employee_id', $employeeIds);
        }

        $existingLogs = $existingQ->get();
        
        foreach ($existingLogs as $log) {
            // Only trigger save if it looks incomplete or we want to be sure
            // A simple save() triggers our model's booted logic.
            if ($log->scheduled_hours <= 0 || ($log->check_in_time && !$log->check_out_time) || $log->attendance_status === 'absent' || $log->attendance_status === 'on_leave') {
                $log->save();
            }
        }

        // 3. Generate Missing Logs
        $count = 0;
        $period = \Carbon\CarbonPeriod::create($start, $end);

        foreach ($period as $date) {
            $dateStr = $date->format('Y-m-d');
            
            $existingQ = AttendanceDailyLog::forCompany($companyId)
                    ->where('attendance_date', $dateStr);

                if (!empty($allowed)) {
                    $existingQ->whereIn('employee_id', $employeeIds);
                }

                $existingIds = $existingQ->pluck('employee_id')->toArray();

            $missingIds = $employees->diff($existingIds);

            foreach ($missingIds as $empId) {
                // Creating via model also triggers booted logic to find schedule
                AttendanceDailyLog::create([
                    'saas_company_id' => $companyId,
                    'employee_id' => $empId,
                    'attendance_date' => $dateStr,
                    'attendance_status' => 'absent', 
                    'approval_status' => 'pending',
                ]);
                $count++;
            }
        }

        if ($count > 0) {
            if (!$silent) $this->dispatch('toast', ['type' => 'success', 'message' => str_replace(':count', $count, tr('Generated :count missing records.'))]);
            $this->loadStats(); 
        } elseif (!$silent) {
            $this->dispatch('toast', ['type' => 'info', 'message' => tr('No missing records found.')]);
        }
    }
}


