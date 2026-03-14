<?php

namespace Athka\Attendance\Observers;

use Athka\Attendance\Models\EmployeeWorkSchedule;
use Athka\Employees\Models\Employee;
use App\Models\User;
use App\Notifications\MissingWorkScheduleNotification;
use App\Notifications\DocumentExpiryNotification;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class EmployeeObserver
{
    /**
     * Handle the Employee "creating" event.
     */
    public function creating(Employee $employee): void
    {
        if (! auth()->check()) {
            return;
        }

        $user = auth()->user();

        if (empty($employee->saas_company_id) && ! empty($user->saas_company_id)) {
            $employee->saas_company_id = (int) $user->saas_company_id;
        }

        if (! empty($employee->branch_id)) {
            return;
        }

        if (($user->access_scope ?? 'all') === 'branch' && ! empty($user->branch_id)) {
            $employee->branch_id = (int) $user->branch_id;

            return;
        }

        $branchId = request()->input('branch_id');
        if (! empty($branchId)) {
            $employee->branch_id = (int) $branchId;
        }
    }

    /**
     * Handle the Employee "created" event.
     */
    public function created(Employee $employee): void
    {
        // For new employees, they won't have a schedule yet.
        // Alert HR immediately so they don't forget.
        if (strtolower($employee->status) === 'active') {
            $this->checkAndNotifyMissingSchedule($employee);
            $this->checkAndNotifyExpiry($employee, true); // Force immediate check on creation
        }
    }

    /**
     * Handle the Employee "updated" event.
     */
    public function updated(Employee $employee): void
    {
        // 1. Check if status has changed away from ACTIVE
        if ($employee->isDirty('status')) {
            $newStatus = strtolower($employee->status);
            $oldStatus = strtolower($employee->getOriginal('status'));

            // If it was active and is no longer active
            if ($oldStatus === 'active' && $newStatus !== 'active') {
                $this->deactivateWorkSchedules($employee);
            }

            // If it became active, ensure it has a schedule
            if ($newStatus === 'active' && $oldStatus !== 'active') {
                $this->checkAndNotifyMissingSchedule($employee);
                $this->checkAndNotifyExpiry($employee, true); // Force immediate check on activation
            }
        }

        // 2. Check if national_id_expiry has changed to a near date
        if ($employee->isDirty('national_id_expiry') && strtolower($employee->status) === 'active') {
            $this->checkAndNotifyExpiry($employee, true); // Force immediate check on manual date update
        }
    }

    /**
     * Check if employee is missing a schedule and notify HR.
     */
    private function checkAndNotifyMissingSchedule(Employee $employee): void
    {
        $today = Carbon::today()->toDateString();
        
        $hasSchedule = DB::table('employee_work_schedules')
            ->where('employee_id', $employee->id)
            ->where('is_active', true)
            ->where('start_date', '<=', $today)
            ->where(function ($q) use ($today) {
                $q->whereNull('end_date')
                  ->orWhere('end_date', '>=', $today);
            })
            ->exists();

        if (!$hasSchedule) {
            $nameAr = $employee->name_ar;
            $nameEn = $employee->name_en;

            // Find HR Managers in the same company
            $hrManagers = User::role('company-admin')
                ->where('saas_company_id', $employee->saas_company_id)
                ->get();

            foreach ($hrManagers as $manager) {
                $manager->notify(new MissingWorkScheduleNotification(
                    1, 
                    (int)$employee->saas_company_id, 
                    $nameAr, 
                    $nameEn,
                    route('company-admin.attendance.work-schedules.index'),
                    'schedule'
                ));
            }
        }
    }

    /**
     * Check if national ID or contract is expiring and notify HR.
     */
    private function checkAndNotifyExpiry(Employee $employee, bool $force = false): void
    {
        if (empty($employee->national_id_expiry)) {
            return;
        }

        $today = Carbon::today();
        $expiryDate = Carbon::parse($employee->national_id_expiry);
        $daysLeft = $today->diffInDays($expiryDate, false);

        // Notify if it matches any of our target check days, 
        // OR if $force is true and it's within 30 days (useful for manual updates)
        $checkDays = [30, 18, 14, 7, 3, 1, 0];

        if (in_array($daysLeft, $checkDays) || ($force && $daysLeft <= 30 && $daysLeft >= 0)) {
            $data = [
                'type' => 'iqama',
                'name_ar' => $employee->name_ar,
                'name_en' => $employee->name_en,
                'date' => $employee->national_id_expiry,
                'days' => $daysLeft,
                'url' => route('company-admin.employees.edit', ['employeeId' => $employee->id]),
                'target' => 'employee',
            ];

            // Notify HR Managers
            $hrManagers = User::role('company-admin')
                ->where('saas_company_id', $employee->saas_company_id)
                ->get();

            foreach ($hrManagers as $manager) {
                $manager->notify(new DocumentExpiryNotification($data));
            }
        }
    }

    /**
     * Deactivate all work schedules for the employee.
     */
    private function deactivateWorkSchedules(Employee $employee): void
    {
        EmployeeWorkSchedule::where('employee_id', $employee->id)
            ->where('is_active', true)
            ->update([
                'is_active' => false,
                'notes' => ($employee->notes ?? '')."\n[Auto] Deactivated due to employee status change to: ".$employee->status.' at '.now()->toDateTimeString(),
            ]);
    }
}


