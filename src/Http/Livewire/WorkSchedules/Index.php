<?php

namespace Athka\Attendance\Http\Livewire\WorkSchedules;

use Livewire\Component;
use Livewire\WithPagination;
use Athka\Employees\Models\Employee;
use Athka\SystemSettings\Models\WorkSchedule;
use Athka\SystemSettings\Models\Department;
use Athka\Attendance\Models\EmployeeWorkSchedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use Athka\Attendance\Http\Livewire\WorkSchedules\Traits\WithScheduleFilters;
use Athka\Attendance\Http\Livewire\WorkSchedules\Traits\WithScheduleAssignments;
use Athka\Attendance\Http\Livewire\WorkSchedules\Traits\WithScheduleExceptions;
use Athka\Attendance\Http\Livewire\WorkSchedules\Traits\WithScheduleHelpers;
use Carbon\Carbon;
use Athka\Attendance\Models\EmployeeShiftRotation;
use Athka\Attendance\Http\Livewire\Traits\WithDataScoping;


class Index extends Component
{
    use WithPagination, 
        WithScheduleFilters, 
        WithScheduleAssignments, 
        WithScheduleExceptions, 
        WithScheduleHelpers,
        WithDataScoping;
    
        public $activeTab = 'list'; // 'list' or 'summary'
        public $summaryPeriod = 'weekly'; // 'weekly' or 'monthly'
        public $summaryDate;
        public $modalTrigger = 0;

        public $showScheduleEyeModal = false;
        public $scheduleEyeEmployeeId = null;
        public $scheduleEyeEmployeeName = '';
        public $scheduleEyeRows = [];

        public $showScheduleEditModal = false;
        public $editScheduleAssignmentId = null;
        public $editScheduleForm = [
            'work_schedule_id' => '',
            'start_date' => '',
            'end_date' => '',
        ];

       protected $queryString = [
            'search' => ['except' => ''],
            'department_id' => ['except' => ''],
            'location_id' => ['except' => 'all'],
            'schedule_type' => ['except' => ''],
            'work_schedule_id' => ['except' => 'all'],
        ];


        protected $listeners = [
            'triggerOpenBulkModal'         => 'openBulkModal',
            'triggerOpenRotationModal'     => 'openRotationModal',
            'refreshWorkSchedules'         => '$refresh',
        ];


   public function mount()
    {
        $companyId = $this->getCompanyId();

        $this->allowedLocationIds = $this->resolveCurrentUserAllowedLocationIds($companyId);

        $this->forcedLocationId = (count($this->allowedLocationIds) === 1)
            ? (int) $this->allowedLocationIds[0]
            : null;

        if (!empty($this->forcedLocationId)) {
            $this->location_id = (string) $this->forcedLocationId;
        }
        $this->loadStats();
        $this->bulkFormData['start_date'] = now()->format('Y-m-d');
        $this->summaryDate = now()->format('Y-m-d');
    }

    public function setTab($tab)
    {
        $this->activeTab = $tab;
        if ($tab === 'summary') {
            $this->resetPage();
        }
    }

    public function setSummaryPeriod($period)
    {
        $this->summaryPeriod = $period;
    }

    public function nextPeriod()
    {
        $date = Carbon::parse($this->summaryDate);
        if ($this->summaryPeriod === 'weekly') {
            $this->summaryDate = $date->addWeek()->toDateString();
        } else {
            $this->summaryDate = $date->addMonth()->toDateString();
        }
    }

    public function prevPeriod()
    {
        $date = Carbon::parse($this->summaryDate);
        if ($this->summaryPeriod === 'weekly') {
            $this->summaryDate = $date->subWeek()->toDateString();
        } else {
            $this->summaryDate = $date->subMonth()->toDateString();
        }
    }

    public function goToToday()
    {
        $this->summaryDate = now()->toDateString();
    }

    public function resetModalFlags(): void
    {
        $this->modalTrigger++;
        $this->showExceptionsModal = false;
        $this->showHistoryModal = false;
        $this->showCriteriaModal = false;
        $this->showBulkModal = false;

        $this->showScheduleEyeModal = false;
        $this->showScheduleEditModal = false;
    }


    public function render()
    {
        $companyId = $this->getCompanyId();
        $query = Employee::forCompany($companyId)
            ->where('status', 'active')
            ->with(['department', 'jobTitle']);

        // âœ… Data scoping
        $query = $this->applyDataScoping($query, 'attendance.schedules.view', 'attendance.schedules.view-subordinates', '');

        if ($this->search) {
            $query->where(function($q) {
                $q->where('name_ar', 'like', '%' . $this->search . '%')
                  ->orWhere('name_en', 'like', '%' . $this->search . '%')
                  ->orWhere('employee_no', 'like', '%' . $this->search . '%');
            });
        }

       if ($this->department_id && $this->department_id !== 'all') {
            $query->where('department_id', $this->department_id);
        }

       $locationCol = $this->resolveEmployeeLocationColumn();

        if ($locationCol && !empty($this->allowedLocationIds)) {
            $query->whereIn($locationCol, $this->allowedLocationIds);
        }

        $effectiveLocationId = !empty($this->forcedLocationId)
            ? (int) $this->forcedLocationId
            : (($this->location_id !== 'all' && $this->location_id !== '' && $this->location_id !== null)
                ? (int) $this->location_id
                : null);

        if ($locationCol && $effectiveLocationId) {
            $query->where($locationCol, $effectiveLocationId);
        }

        $today = now()->toDateString();

        $query->addSelect([
            'current_schedule_name' => WorkSchedule::select('name')
                ->join('employee_work_schedules', 'work_schedules.id', '=', 'employee_work_schedules.work_schedule_id')
                ->whereColumn('employee_work_schedules.employee_id', 'employees.id')
                ->where('employee_work_schedules.is_active', true)
                ->where('employee_work_schedules.saas_company_id', $companyId)
                ->where('work_schedules.saas_company_id', $companyId)
                ->limit(1),

            'current_schedule_id' => EmployeeWorkSchedule::select('work_schedule_id')
                ->whereColumn('employee_id', 'employees.id')
                ->where('is_active', true)
                ->where('saas_company_id', $companyId)
                ->limit(1),

            'current_schedule_start' => EmployeeWorkSchedule::select('start_date')
                ->whereColumn('employee_id', 'employees.id')
                ->where('is_active', true)
                ->where('saas_company_id', $companyId)
                ->limit(1),

            'current_schedule_end' => EmployeeWorkSchedule::select('end_date')
                ->whereColumn('employee_id', 'employees.id')
                ->where('is_active', true)
                ->where('saas_company_id', $companyId)
                ->limit(1),

            'is_schedule_disabled' => WorkSchedule::selectRaw('NOT work_schedules.is_active')
                ->join('employee_work_schedules', 'work_schedules.id', '=', 'employee_work_schedules.work_schedule_id')
                ->whereColumn('employee_work_schedules.employee_id', 'employees.id')
                ->where('employee_work_schedules.is_active', true)
                ->where('employee_work_schedules.saas_company_id', $companyId)
                ->limit(1),

            'next_schedule_name' => WorkSchedule::select('name')
                ->join('employee_work_schedules', 'work_schedules.id', '=', 'employee_work_schedules.work_schedule_id')
                ->whereColumn('employee_work_schedules.employee_id', 'employees.id')
                ->where('employee_work_schedules.saas_company_id', $companyId)
                ->where('work_schedules.saas_company_id', $companyId)
                ->whereDate('employee_work_schedules.start_date', '>', $today)
                ->orderBy('employee_work_schedules.start_date')
                ->limit(1),

            'next_schedule_start' => EmployeeWorkSchedule::select('start_date')
                ->whereColumn('employee_id', 'employees.id')
                ->where('saas_company_id', $companyId)
                ->whereDate('start_date', '>', $today)
                ->orderBy('start_date')
                ->limit(1),

            'next_schedule_end' => EmployeeWorkSchedule::select('end_date')
                ->whereColumn('employee_id', 'employees.id')
                ->where('saas_company_id', $companyId)
                ->whereDate('start_date', '>', $today)
                ->orderBy('start_date')
                ->limit(1),

          'future_schedules_count' => EmployeeWorkSchedule::selectRaw('COUNT(*)')
                ->whereColumn('employee_id', 'employees.id')
                ->where('saas_company_id', $companyId)
                ->whereDate('start_date', '>', $today),

            'all_schedules_count' => EmployeeWorkSchedule::selectRaw('COUNT(*)')
                ->whereColumn('employee_id', 'employees.id')
                ->where('saas_company_id', $companyId),

        ]);



        if ($this->filterWarning !== 'all') {
             $idsToFilter = [];
             if ($this->filterWarning === 'no_schedule') $idsToFilter = $this->warningIds['no_schedule_overdue'] ?? [];
             elseif ($this->filterWarning === 'ending_soon') $idsToFilter = $this->warningIds['ending_soon'] ?? [];
             elseif ($this->filterWarning === 'changed_too_much') $idsToFilter = $this->warningIds['changed_too_much'] ?? [];
             elseif ($this->filterWarning === 'inactive_schedule') $idsToFilter = $this->warningIds['inactive_schedule'] ?? [];
             
             if (empty($idsToFilter)) {
                 $query->whereRaw('1=0');
             } else {
                 $query->whereIn('employees.id', $idsToFilter);
             }
        }

       if ($this->schedule_type === 'unlinked') {
            $query->havingRaw('current_schedule_name IS NULL');
        } elseif ($this->schedule_type === 'linked') {
            $query->havingRaw('current_schedule_name IS NOT NULL');
        }

        if ($this->work_schedule_id !== 'all' && $this->work_schedule_id !== '' && $this->work_schedule_id !== null) {
            $query->havingRaw('current_schedule_id = ?', [(int) $this->work_schedule_id]);
        }


        $jobTitles = [];
        if (Schema::hasTable('job_titles')) {
            [$arCol, $enCol, $fallbackCol] = $this->resolveJobTitlesLabelColumns();
            $jobTitlesList = DB::table('job_titles')->where('saas_company_id', $companyId)->orderBy('id')->get();
            foreach ($jobTitlesList as $row) {
                $jobTitles[] = (object) [
                    'id'       => (int) $row->id,
                    'title_ar' => $arCol ? ($row->{$arCol} ?? null) : null,
                    'title_en' => $enCol ? ($row->{$enCol} ?? null) : null,
                    'title'    => $fallbackCol ? ($row->{$fallbackCol} ?? null) : null,
                ];
            }
        }

        $locations = [];
        foreach (['locations', 'work_locations', 'branches'] as $tbl) {
            if (Schema::hasTable($tbl)) {
            $qLoc = DB::table($tbl)->where('saas_company_id', $companyId)->orderBy('id');

            if (!empty($this->allowedLocationIds)) {
                $qLoc->whereIn('id', $this->allowedLocationIds);
            }

            $locations = $qLoc->get(['id','name']);
                break;
            }
        }

        $contractTypes = [];
        $contractCol = $this->resolveEmployeeContractTypeColumn();
        if ($contractCol) {
            $contractTypes = Employee::forCompany($companyId)->where('status', 'active')->whereNotNull($contractCol)->distinct()->pluck($contractCol)->filter()->values()->all();
        }

        $employees = $query->paginate(10);

        $summaryData = [];
        $summaryDays = [];
        if ($this->activeTab === 'summary') {
            $date = Carbon::parse($this->summaryDate);
            if ($this->summaryPeriod === 'weekly') {
                $start = $date->copy()->startOfWeek(Carbon::SATURDAY);
                $end = $start->copy()->addDays(6);
            } else {
                $start = $date->copy()->startOfMonth();
                $end = $date->copy()->endOfMonth();
            }

            $current = $start->copy();
            while ($current->lte($end)) {
                $summaryDays[] = [
                    'date' => $current->toDateString(),
                    'label' => $current->translatedFormat('D d/m'),
                    'day_name' => strtolower($current->format('l')),
                    'is_today' => $current->isToday(),
                ];
                $current->addDay();
            }

            foreach ($employees as $employee) {
                $preview = $this->buildSchedulePreview($employee->id, $companyId, $start, $end);
                $summaryData[$employee->id] = $preview['rows'];
            }
        }

        return view('attendance::livewire.work-schedules.index', [
            'employees'      => $employees,
            'departments'    => Department::where('saas_company_id', $companyId)->get(),
            'workSchedules'  => WorkSchedule::where('saas_company_id', $companyId)->where('is_active', true)->get(),
            'workSchedulesAll' => WorkSchedule::where('saas_company_id', $companyId)->get(),
            'jobTitles'      => $jobTitles,
            'locations'      => $locations,
            'contractTypes'  => $contractTypes,
            'summaryDays'    => $summaryDays,
            'summaryData'    => $summaryData,
        ])->layout('layouts.company-admin');
    }
    public function openScheduleEyeModal(int $employeeId): void
    {
        $companyId = $this->getCompanyId();

        $empQ = Employee::forCompany($companyId)->whereKey($employeeId);

        // âœ… Data scoping
        $empQ = $this->applyDataScoping($empQ, 'attendance.schedules.view', 'attendance.schedules.view-subordinates', '');

        $locationCol = $this->resolveEmployeeLocationColumn();
        if ($locationCol && !empty($this->allowedLocationIds)) {
            $empQ->whereIn($locationCol, $this->allowedLocationIds);
        }

        $emp = $empQ->firstOrFail(['id', 'name_ar', 'name_en']);

        $this->scheduleEyeEmployeeId = (int) $emp->id;
        $this->scheduleEyeEmployeeName = (string) ($emp->name_ar ?: $emp->name_en ?: ('#' . $emp->id));

        $today = now()->startOfDay();

        $rows = EmployeeWorkSchedule::query()
            ->where('employee_work_schedules.employee_id', $employeeId)
            ->where('employee_work_schedules.saas_company_id', $companyId)
            ->leftJoin('work_schedules', function ($join) use ($companyId) {
                $join->on('work_schedules.id', '=', 'employee_work_schedules.work_schedule_id')
                    ->where('work_schedules.saas_company_id', '=', $companyId);
            })
            ->orderByDesc('employee_work_schedules.start_date')
            ->orderByDesc('employee_work_schedules.id')
            ->get([
                'employee_work_schedules.id',
                'employee_work_schedules.work_schedule_id',
                'employee_work_schedules.start_date',
                'employee_work_schedules.end_date',
                'employee_work_schedules.is_active',
                'employee_work_schedules.assignment_type',
                DB::raw('work_schedules.name as schedule_name'),
            ]);

        $this->scheduleEyeRows = $rows->map(function ($r) use ($today) {
            $start = $r->start_date ? Carbon::parse($r->start_date)->startOfDay() : null;
            $end   = $r->end_date ? Carbon::parse($r->end_date)->startOfDay() : null;

            $status = 'inactive';
            if ((int) $r->is_active === 1) {
                $status = 'active';
            } elseif ($start && $start->gt($today)) {
                $status = 'future';
            } elseif ($end && $end->lt($today)) {
                $status = 'past';
            } elseif ($start && $start->lte($today) && (!$end || $end->gte($today))) {
                $status = 'range';
            }

            $type = (string) ($r->assignment_type ?? '');

            return [
                'id' => (int) $r->id,
                'schedule_name' => (string) ($r->schedule_name ?: ('#' . $r->work_schedule_id)),
                'start_date' => (string) ($r->start_date ?: '-'),
                'end_date' => (string) ($r->end_date ?: ''),
                'is_active' => (int) $r->is_active,
                'assignment_type' => $type,
                'status' => $status,
                'can_edit' => ($type !== 'rotation') || ($status === 'future'),

                        ];
        })->toArray();

        $this->showScheduleEyeModal = true;
    }

    public function openScheduleEditModal(int $assignmentId): void
    {
        $companyId = $this->getCompanyId();

        $row = EmployeeWorkSchedule::where('saas_company_id', $companyId)
            ->whereKey($assignmentId)
            ->firstOrFail();

        $type = (string) ($row->assignment_type ?? '');
        if ($type === 'rotation') {
            $start = $row->start_date ? \Carbon\Carbon::parse($row->start_date)->startOfDay() : null;

            if ($start && $start->lte(\Carbon\Carbon::today())) {
                $this->dispatch('toast', [
                    'type' => 'warning',
                    'message' => tr('Only future rotation schedules can be edited here.'),
                ]);
                return;
            }
        }


        $this->editScheduleAssignmentId = (int) $row->id;

        $this->editScheduleForm = [
            'work_schedule_id' => (string) $row->work_schedule_id,
            'start_date'       => (string) $row->start_date,
            'end_date'         => (string) ($row->end_date ?? ''),
        ];

        $this->showScheduleEditModal = true;
    }

    public function saveScheduleEdit(): void
    {
        $companyId = $this->getCompanyId();

        $this->validate([
            'editScheduleForm.work_schedule_id' => 'required|exists:work_schedules,id',
            'editScheduleForm.start_date'       => 'required',
            'editScheduleForm.end_date'         => 'nullable',
        ]);

        $row = EmployeeWorkSchedule::where('saas_company_id', $companyId)
            ->whereKey((int) $this->editScheduleAssignmentId)
            ->firstOrFail();

        $startYmd = $this->normalizeDateInputToGregorianYmd($this->editScheduleForm['start_date']);
        if (!$startYmd) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'editScheduleForm.start_date' => tr('Invalid date format.'),
            ]);
        }

        $endYmd = null;
        $rawEnd = trim((string) ($this->editScheduleForm['end_date'] ?? ''));
        if ($rawEnd !== '') {
            $endYmd = $this->normalizeDateInputToGregorianYmd($rawEnd);
            if (!$endYmd) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'editScheduleForm.end_date' => tr('Invalid date format.'),
                ]);
            }
        }

        if ((string) ($row->assignment_type ?? '') === 'rotation') {
            if ($newStart->lte(Carbon::today())) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'editScheduleForm.start_date' => tr('Rotation edit here is allowed for future schedules only.'),
                ]);
            }
        }

        $this->assertNoOverlappingAssignments((int) $row->employee_id, (int) $companyId, $newStart, $newEnd, (int) $row->id);

        $today = Carbon::today();
        $activateNow = $newStart->lte($today) && (!$newEnd || $newEnd->gte($today));

        DB::transaction(function () use ($row, $companyId, $startYmd, $endYmd, $activateNow) {
            $before = $row->fresh()->toArray();

            if ($activateNow) {
                EmployeeWorkSchedule::where('employee_id', $row->employee_id)
                    ->where('saas_company_id', $companyId)
                    ->where('is_active', true)
                    ->where('id', '!=', $row->id)
                    ->update(['is_active' => false]);
            }

            $row->update([
                'work_schedule_id' => (int) $this->editScheduleForm['work_schedule_id'],
                'start_date'       => $startYmd,
                'end_date'         => $endYmd ?: null,
                'is_active'        => $activateNow,
            ]);

            $after = $row->fresh()->toArray();

            $this->auditLog(
                'work_schedule.edited',
                (int) $row->employee_id,
                'employee_work_schedule',
                (int) $row->id,
                $before,
                $after,
                ['source' => 'eye-modal-edit']
            );
        });

        $this->showScheduleEditModal = false;

        if ($this->scheduleEyeEmployeeId) {
            $this->openScheduleEyeModal((int) $this->scheduleEyeEmployeeId);
        }

        $this->loadStats();
        $this->dispatch('refreshWorkSchedules');

        $this->dispatch('toast', [
            'type' => 'success',
            'message' => tr('Schedule updated successfully'),
        ]);
    }

}


