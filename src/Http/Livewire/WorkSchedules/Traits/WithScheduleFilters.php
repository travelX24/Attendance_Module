<?php

namespace Athka\Attendance\Http\Livewire\WorkSchedules\Traits;

use Athka\Employees\Models\Employee;
use Athka\SystemSettings\Models\WorkSchedule;
use Athka\Attendance\Models\EmployeeWorkSchedule;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

trait WithScheduleFilters
{
    public $search = '';
    public $department_id = 'all';
    public $location_id = 'all'; 
    public $schedule_type = 'all';

    public $forcedLocationId = null;
    public $allowedLocationIds = []; 
    public $work_schedule_id = 'all';

    public $status_filter = 'active';
    public $filterWarning = 'all'; 


    public $earlyWarnings = [
        'no_schedule_overdue'   => 0, // Ø£Ø­Ù…Ø±: Ø¨Ø¯ÙˆÙ† Ø¬Ø¯ÙˆÙ„ > 3 Ø£ÙŠØ§Ù… Ø¹Ù…Ù„
        'ending_soon'           => 0, // Ø£ØµÙØ±: ÙŠÙ†ØªÙ‡ÙŠ Ø®Ù„Ø§Ù„ 3 Ø£ÙŠØ§Ù… Ø¹Ù…Ù„
        'contract_conflict'     => 0, // Ø£Ø²Ø±Ù‚: ØªØ¹Ø§Ø±Ø¶ Ù…Ø¹ Ø§Ù„Ø¹Ù‚Ø¯ (Ø­Ø§Ù„ÙŠØ§Ù‹ placeholder)
        'changed_too_much'      => 0, // Ø¨Ø±ØªÙ‚Ø§Ù„ÙŠ: Ø£ÙƒØ«Ø± Ù…Ù† Ù…Ø±ØªÙŠÙ† Ø¨Ø§Ù„Ø´Ù‡Ø±
        'inactive_schedule'     => 0, // Ù…Ø±Ø¨ÙˆØ· Ø¨Ø¬Ø¯ÙˆÙ„ Ù…Ø¹Ø·Ù„ ÙÙŠ Ø§Ù„Ø¥Ø¹Ø¯Ø§Ø¯Ø§Øª
        'multiple_active'       => 0, // Ø£ÙƒØ«Ø± Ù…Ù† Ø¬Ø¯ÙˆÙ„ active Ù„Ù†ÙØ³ Ø§Ù„Ù…ÙˆØ¸Ù
        'invalid_dates'         => 0, // end_date Ù‚Ø¨Ù„ start_date
    ];

    public $incorrectEmployeeIds = [];
    public $warningIds = [
        'no_schedule_overdue' => [],
        'ending_soon' => [],
        'changed_too_much' => [],
        'inactive_schedule' => [],
    ];

    public $warningEmployees = [
        'total_employees' => [],
        'no_schedule_overdue' => [],
        'ending_soon' => [],
        'changed_too_much' => [],
        'inactive_schedule' => [],
    ];

    public $stats = [
        'without_schedule' => 0,
        'active_schedules' => 0,
        'incorrect_assignments' => 0,
        'total_employees' => 0,
    ];

    public function setWarningFilter($type)
    {
        if ($this->filterWarning === $type) {
            $this->filterWarning = 'all';
        } else {
            $this->filterWarning = $type;
        }
        $this->resetPage();
        $this->loadStats();
    }

    public function updatedFilterWarning()
    {
        $this->resetPage();
        $this->loadStats();
    }

    public function updatedSearch()
    {
        $this->resetPage();
        $this->loadStats();
    }

   public function updatedDepartmentId()
    {
        $this->resetPage();
        $this->loadStats();
    }

   public function updatedLocationId()
    {
        if (!empty($this->forcedLocationId)) {
            $this->location_id = (string) $this->forcedLocationId;
        }

        $this->resetPage();
        $this->loadStats();
    }

    public function updatedWorkScheduleId()
    {
        $this->resetPage();
        $this->loadStats();
    }

    public function updatedScheduleType()
    {
        $this->resetPage();
        $this->loadStats();
    }


    public function loadStats()
    {
       $companyId = $this->getCompanyId();

        $baseEmployees = Employee::forCompany($companyId)->where('status', 'active');

      $locationCol = $this->resolveEmployeeLocationColumn();

        $effectiveLocationId = !empty($this->forcedLocationId)
            ? (int) $this->forcedLocationId
            : (($this->location_id !== 'all' && $this->location_id !== '' && $this->location_id !== null)
                ? (int) $this->location_id
                : null);

        if ($locationCol && $effectiveLocationId) {
            $baseEmployees->where($locationCol, $effectiveLocationId);
        }

        $employeeIdsScope = (clone $baseEmployees)->pluck('id')->all();

        $this->stats['total_employees'] = count($employeeIdsScope);
        $this->warningEmployees['total_employees'] = $this->getEmployeeNamesForPop($employeeIdsScope);

        $employeesWithScheduleIds = EmployeeWorkSchedule::where('saas_company_id', $companyId)
            ->where('is_active', true)
            ->when(!empty($employeeIdsScope), fn ($q) => $q->whereIn('employee_id', $employeeIdsScope), fn ($q) => $q->whereRaw('1=0'))
            ->pluck('employee_id')
            ->toArray();

        $this->stats['without_schedule'] = empty($employeeIdsScope)
            ? 0
            : (clone $baseEmployees)->whereNotIn('id', $employeesWithScheduleIds ?: [0])->count();

        $this->stats['active_schedules'] = WorkSchedule::where('saas_company_id', $companyId)
            ->where('is_active', true)
            ->count();
            
        $today = now()->startOfDay();
        $cutoffNoSchedule = $this->addBusinessDays($today, -3)->endOfDay();
        $soonEndDate      = $this->addBusinessDays($today,  3)->toDateString();

        $noScheduleOverdueIds = empty($employeeIdsScope)
        ? []
        : (clone $baseEmployees)
            ->whereNotIn('id', $employeesWithScheduleIds ?: [0])
            ->where('created_at', '<=', $cutoffNoSchedule)
            ->pluck('id')
            ->all();

        $this->warningIds['no_schedule_overdue'] = $noScheduleOverdueIds;
        $this->earlyWarnings['no_schedule_overdue'] = count($noScheduleOverdueIds);
        $this->warningEmployees['no_schedule_overdue'] = $this->getEmployeeNamesForPop($noScheduleOverdueIds);

       $endingSoonIds = EmployeeWorkSchedule::where('saas_company_id', $companyId)
            ->where('is_active', true)
            ->when(!empty($employeeIdsScope), fn ($q) => $q->whereIn('employee_id', $employeeIdsScope), fn ($q) => $q->whereRaw('1=0'))
            ->where('assignment_type', '!=', 'rotation')
            ->whereNotNull('end_date')
            ->whereBetween('end_date', [$today->toDateString(), $soonEndDate])
            ->pluck('employee_id')
            ->unique()
            ->all();


        $this->warningIds['ending_soon'] = $endingSoonIds;
        $this->earlyWarnings['ending_soon'] = count($endingSoonIds);
        $this->warningEmployees['ending_soon'] = $this->getEmployeeNamesForPop($endingSoonIds);

        $monthStart = now()->startOfMonth();
        $monthEnd   = now()->endOfMonth();

        $changedTooMuchIds = EmployeeWorkSchedule::where('saas_company_id', $companyId)
            ->where('assignment_type', '!=', 'rotation')
            ->when(!empty($employeeIdsScope), fn ($q) => $q->whereIn('employee_id', $employeeIdsScope), fn ($q) => $q->whereRaw('1=0'))
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->select('employee_id', DB::raw('COUNT(*) as c'))
            ->groupBy('employee_id')
            ->having('c', '>', 2)
            ->pluck('employee_id')
            ->all();


        $this->warningIds['changed_too_much'] = $changedTooMuchIds;
        $this->earlyWarnings['changed_too_much'] = count($changedTooMuchIds);
        $this->warningEmployees['changed_too_much'] = $this->getEmployeeNamesForPop($changedTooMuchIds);

        $contractConflictIds = $this->detectContractConflictEmployeeIds($companyId);
        $this->earlyWarnings['contract_conflict'] = count($contractConflictIds);

        $inactiveScheduleIds = EmployeeWorkSchedule::query()
            ->where('employee_work_schedules.saas_company_id', $companyId)
            ->when(!empty($employeeIdsScope), fn ($q) => $q->whereIn('employee_id', $employeeIdsScope), fn ($q) => $q->whereRaw('1=0'))
            ->where('employee_work_schedules.is_active', true)
            ->join('work_schedules', 'work_schedules.id', '=', 'employee_work_schedules.work_schedule_id')
            ->where('work_schedules.is_active', false)
            ->pluck('employee_work_schedules.employee_id')
            ->unique()
            ->all();

        $this->warningIds['inactive_schedule'] = $inactiveScheduleIds;
        $this->earlyWarnings['inactive_schedule'] = count($inactiveScheduleIds);
        $this->warningEmployees['inactive_schedule'] = $this->getEmployeeNamesForPop($inactiveScheduleIds);

        $multipleActiveIds = EmployeeWorkSchedule::where('saas_company_id', $companyId)
            ->where('is_active', true)
            ->when(!empty($employeeIdsScope), fn ($q) => $q->whereIn('employee_id', $employeeIdsScope), fn ($q) => $q->whereRaw('1=0'))
            ->select('employee_id', DB::raw('COUNT(*) as c'))
            ->groupBy('employee_id')
            ->having('c', '>', 1)
            ->pluck('employee_id')
            ->all();

        $this->earlyWarnings['multiple_active'] = count($multipleActiveIds);

        $invalidDatesIds = EmployeeWorkSchedule::where('saas_company_id', $companyId)
            ->where('is_active', true)
            ->when(!empty($employeeIdsScope), fn ($q) => $q->whereIn('employee_id', $employeeIdsScope), fn ($q) => $q->whereRaw('1=0'))
            ->whereNotNull('end_date')
            ->whereColumn('end_date', '<', 'start_date')
            ->pluck('employee_id')
            ->unique()
            ->all();

        $this->earlyWarnings['invalid_dates'] = count($invalidDatesIds);

        $incorrectEmployeeIds = array_values(array_unique(array_merge(
            $noScheduleOverdueIds,
            $endingSoonIds,
            $changedTooMuchIds,
            $contractConflictIds,
            $inactiveScheduleIds,
            $multipleActiveIds,
            $invalidDatesIds
        )));

        $this->incorrectEmployeeIds = $incorrectEmployeeIds;
        $this->stats['incorrect_assignments'] = count($incorrectEmployeeIds);
    }

    protected function getEmployeeNamesForPop(array $ids): array
    {
        if (empty($ids)) return [];
        
        $limit = 10;
        $displayIds = array_slice($ids, 0, $limit);
        
        $names = Employee::whereIn('id', $displayIds)
            ->select('id', 'name_ar', 'name_en')
            ->get()
            ->map(fn($e) => $e->name_ar ?: $e->name_en)
            ->toArray();
            
        return $names;
    }
}


