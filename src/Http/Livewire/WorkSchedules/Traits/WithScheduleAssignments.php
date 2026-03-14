<?php

namespace Athka\Attendance\Http\Livewire\WorkSchedules\Traits;

use Athka\Employees\Models\Employee;
use Athka\Attendance\Models\EmployeeWorkSchedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Athka\Attendance\Models\EmployeeShiftRotation;
use Carbon\Carbon;
use Athka\SystemSettings\Models\WorkSchedule;
use Illuminate\Validation\ValidationException;

trait WithScheduleAssignments
{
    public $selectedEmployees = [];
    public $showBulkModal = false;

    public $bulkModalMode = 'single';

    public $bulkFormData = [
        'work_schedule_id' => '',
        'start_date' => '',
        'end_date' => '',
        'is_permanent' => true,

        'work_periods' => [],

        'is_rotation' => false,
        'rotation_work_schedule_id' => '',
        'rotation_days' => 7,

        'work_periods_a' => [],
        'work_periods_b' => [],
    ];

    public $showCriteriaModal = false;
    public $criteriaForm = [
        'department_id' => 'all',
        'job_title_id'  => 'all',
        'location_id'   => 'all',
        'contract_type' => 'all',
        'mode'          => 'replace', 
    ];

    public $criteriaPreviewTotal = 0;
    public $criteriaPreviewEmployees = [];
    public $criteriaPreviewSelected = [];
    public $bulkContextMeta = [];


    public $showSchedulePreviewModal = false;
    public $previewEmployeeId = null;
    public $previewEmployee = null;

    public $previewForm = [
        'from' => '',
        'to'   => '',
    ];

    public $previewRows = [];
    public $previewMeta = [];


    public function updatedSelectedEmployees()
    {
        $this->dispatch('selected-employees-count-updated', [
            'count' => count($this->selectedEmployees)
        ]);
    }

    public function openBulkModal()
    {
        $this->resetModalFlags();

        $this->bulkModalMode = 'single';
        $this->bulkFormData['is_rotation'] = false;
        $this->bulkFormData['rotation_work_schedule_id'] = '';
        $this->bulkFormData['rotation_days'] = 7;

        if (count($this->selectedEmployees) > 0) {
            $this->showBulkModal = true;
        }
    }

    public function openBulkModalForSingleEmployee($employeeId)
    {
        $this->resetModalFlags();

        $this->bulkModalMode = 'single';
        $this->bulkFormData['is_rotation'] = false;
        $this->bulkFormData['rotation_work_schedule_id'] = '';
        $this->bulkFormData['rotation_days'] = 7;

        $this->selectedEmployees = [(int) $employeeId];
        $this->bulkContextMeta = ['source' => 'single'];
        $this->showBulkModal = true;
    }

    public function applyBulkAssignment()
    {
        $rules = [
            'bulkFormData.work_schedule_id' => 'required|exists:work_schedules,id',
            'bulkFormData.start_date' => 'required',
        ];

        $isRotation = !empty($this->bulkFormData['is_rotation']);

        if ($isRotation) {
            $rules['bulkFormData.rotation_work_schedule_id'] = 'required|exists:work_schedules,id|different:bulkFormData.work_schedule_id';
            $rules['bulkFormData.rotation_days'] = 'required|integer|min:1|max:365';

            $rules['bulkFormData.work_periods_a'] = 'array|min:1';
            $rules['bulkFormData.work_periods_b'] = 'array|min:1';
        } else {
            $rules['bulkFormData.work_periods'] = 'array|min:1';
        }

        if (empty($this->bulkFormData['is_permanent']) || $this->bulkFormData['is_permanent'] === '0') {
            $rules['bulkFormData.end_date'] = 'required|date|after_or_equal:bulkFormData.start_date';
        }

        $this->validate($rules);


        $companyId = $this->getCompanyId();

        DB::transaction(function () use ($companyId, $isRotation) {
            foreach ($this->selectedEmployees as $employeeId) {

                $before = EmployeeWorkSchedule::where('employee_id', $employeeId)
                    ->where('saas_company_id', $companyId)
                    ->where('is_active', true)
                    ->latest('id')
                    ->first();

                $today = Carbon::today();

                $startYmd = $this->normalizeDateInputToGregorianYmd($this->bulkFormData['start_date']);
                if (!$startYmd) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'bulkFormData.start_date' => tr('Invalid date format.'),
                    ]);
                }

                $this->bulkFormData['start_date'] = $startYmd;
                $newStart = Carbon::createFromFormat('Y-m-d', $startYmd)->startOfDay();

                $newEnd = null;
                if (empty($this->bulkFormData['is_permanent']) || $this->bulkFormData['is_permanent'] === '0') {
                    $endYmd = $this->normalizeDateInputToGregorianYmd($this->bulkFormData['end_date']);
                    if (!$endYmd) {
                        throw \Illuminate\Validation\ValidationException::withMessages([
                            'bulkFormData.end_date' => tr('Invalid date format.'),
                        ]);
                    }
                    $this->bulkFormData['end_date'] = $endYmd;
                    $newEnd = Carbon::createFromFormat('Y-m-d', $endYmd)->startOfDay();
                }

                $currentActive = EmployeeWorkSchedule::where('employee_id', $employeeId)
                    ->where('saas_company_id', $companyId)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->latest('id')
                    ->first();

                $activeRotation = EmployeeShiftRotation::where('employee_id', $employeeId)
                    ->where('saas_company_id', $companyId)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->latest('id')
                    ->first();

                if ($newStart->gt($today)) {
                    $this->closeAssignmentBeforeDate($currentActive, $newStart);
                    $this->closeRotationBeforeDate($activeRotation, $newStart);
                }

                $closeDate = $newStart->copy()->subDay()->toDateString();

                EmployeeWorkSchedule::where('employee_id', $employeeId)
                    ->where('saas_company_id', $companyId)
                    ->whereDate('start_date', '<', $newStart->toDateString())
                    ->where(function ($q) use ($newStart) {
                        $q->whereNull('end_date')
                        ->orWhereDate('end_date', '>=', $newStart->toDateString());
                    })
                    ->update(['end_date' => $closeDate]);

                EmployeeShiftRotation::where('employee_id', $employeeId)
                    ->where('saas_company_id', $companyId)
                    ->whereDate('start_date', '<', $newStart->toDateString())
                    ->where(function ($q) use ($newStart) {
                        $q->whereNull('end_date')
                        ->orWhereDate('end_date', '>=', $newStart->toDateString());
                    })
                    ->update(['end_date' => $closeDate]);

                $nextFuture = EmployeeWorkSchedule::where('employee_id', $employeeId)
                    ->where('saas_company_id', $companyId)
                    ->whereDate('start_date', '>', $newStart->toDateString())
                    ->orderBy('start_date')
                    ->first();

                if ($nextFuture) {
                    $maxEnd = Carbon::parse($nextFuture->start_date)->startOfDay()->subDay();
                    if (!$newEnd || $newEnd->gt($maxEnd)) {
                        $newEnd = $maxEnd;
                    }
                }

                $this->assertNoOverlappingAssignments($employeeId, $companyId, $newStart, $newEnd);

                $activateNow = $newStart->lte($today) && (!$newEnd || $newEnd->gte($today));

                if ($activateNow) {
                    $closeDate = $newStart->copy()->subDay()->toDateString();

                    EmployeeWorkSchedule::where('employee_id', $employeeId)
                        ->where('saas_company_id', $companyId)
                        ->where('is_active', true)
                        ->whereDate('start_date', '<', $newStart->toDateString())
                        ->where(function ($q) use ($newStart) {
                            $q->whereNull('end_date')
                            ->orWhereDate('end_date', '>=', $newStart->toDateString());
                        })
                        ->update(['end_date' => $closeDate]);

                    EmployeeShiftRotation::where('employee_id', $employeeId)
                        ->where('saas_company_id', $companyId)
                        ->where('is_active', true)
                        ->whereDate('start_date', '<', $newStart->toDateString())
                        ->where(function ($q) use ($newStart) {
                            $q->whereNull('end_date')
                            ->orWhereDate('end_date', '>=', $newStart->toDateString());
                        })
                        ->update(['end_date' => $closeDate]);

                    EmployeeWorkSchedule::where('employee_id', $employeeId)
                        ->where('saas_company_id', $companyId)
                        ->where('is_active', true)
                        ->update(['is_active' => false]);

                    EmployeeShiftRotation::where('employee_id', $employeeId)
                        ->where('saas_company_id', $companyId)
                        ->where('is_active', true)
                        ->update(['is_active' => false]);
                }

                if ($isRotation) {
                    $rotation = EmployeeShiftRotation::create([
                        'employee_id' => $employeeId,
                        'saas_company_id' => $companyId,
                        'work_schedule_id_a' => (int) $this->bulkFormData['work_schedule_id'],
                        'work_schedule_id_b' => (int) $this->bulkFormData['rotation_work_schedule_id'],
                        'start_date' => $this->bulkFormData['start_date'],
                        'end_date' => $newEnd ? $newEnd->toDateString() : null,
                        'rotation_days' => (int) $this->bulkFormData['rotation_days'],
                        'is_active' => $activateNow,
                    ]);

                    $start = Carbon::parse($this->bulkFormData['start_date'])->startOfDay();
                    $cycleEnd = $start->copy()->addDays(((int) $this->bulkFormData['rotation_days']) - 1);

                    if (!empty($rotation->end_date) && $cycleEnd->gt($rotation->end_date)) {
                        $cycleEnd = Carbon::parse($rotation->end_date)->endOfDay();
                    }

                    $after = EmployeeWorkSchedule::create([
                        'employee_id'      => $employeeId,
                        'work_schedule_id' => (int) $this->bulkFormData['work_schedule_id'],
                        'start_date'       => $newStart->toDateString(),
                        'end_date'         => $newEnd ? $newEnd->toDateString() : null,
                        'is_active'        => $activateNow,
                        'assignment_type'  => 'rotation',
                        'saas_company_id'  => $companyId,
                    ]);
                } else {
                    $after = EmployeeWorkSchedule::create([
                        'employee_id'      => $employeeId,
                        'work_schedule_id' => (int) $this->bulkFormData['work_schedule_id'],
                        'start_date'       => $newStart->toDateString(),
                        'end_date'         => $newEnd ? $newEnd->toDateString() : null,
                        'is_active'        => $activateNow,
                        'assignment_type'  => count($this->selectedEmployees) > 1 ? 'bulk' : 'individual',
                        'saas_company_id'  => $companyId,
                    ]);
                }

                $baseMeta = [
                    'bulk'           => count($this->selectedEmployees) > 1,
                    'selected_count' => count($this->selectedEmployees),
                ];

                $this->auditLog(
                    $before ? 'work_schedule.changed' : 'work_schedule.assigned',
                    (int) $employeeId,
                    'employee_work_schedule',
                    (int) $after->id,
                    $before?->toArray(),
                    $after->toArray(),
                    array_merge($baseMeta, $this->bulkContextMeta ?: [])
                );
            }
        });


        $this->showBulkModal = false;
        $this->selectedEmployees = [];
        $this->bulkContextMeta = [];
        $this->loadStats();

        $this->dispatch('toast', [
            'type' => 'success',
            'message' => tr('Schedule assigned successfully')
        ]);
    }

    public function openCriteriaModal()
    {
        $this->resetModalFlags();
        $this->criteriaForm = [
            'department_id' => 'all',
            'job_title_id'  => 'all',
            'location_id'   => 'all',
            'contract_type' => 'all',
            'mode'          => 'replace',
        ];
        $this->criteriaPreviewEmployees = [];
        $this->criteriaPreviewTotal = 0;
        $this->criteriaPreviewSelected = [];
        $this->showCriteriaModal = true;
    }

    public function updatedCriteriaForm()
    {
    }

    public function previewCriteriaSelection()
    {
        $this->refreshCriteriaPreview();
    }

    public function selectAllCriteriaPreview()
    {
        $this->criteriaPreviewSelected = array_map('strval', array_column($this->criteriaPreviewEmployees, 'id'));
    }

    public function clearCriteriaPreviewSelection()
    {
        $this->criteriaPreviewSelected = [];
    }

    private function refreshCriteriaPreview()
    {
        $companyId = $this->getCompanyId();

        $query = Employee::forCompany($companyId)->where('status', 'active');

        $locationCol = $this->resolveEmployeeLocationColumn();
        if ($locationCol && !empty($this->allowedLocationIds)) {
            $query->whereIn($locationCol, $this->allowedLocationIds);
        }
        if ($this->criteriaForm['department_id'] !== 'all') {
            $query->where('department_id', $this->criteriaForm['department_id']);
        }

        $jobTitleCol = $this->resolveEmployeeJobTitleColumn();
        if ($jobTitleCol && $this->criteriaForm['job_title_id'] !== 'all') {
            $query->where($jobTitleCol, $this->criteriaForm['job_title_id']);
        }

        $locationCol = $this->resolveEmployeeLocationColumn();
        if ($locationCol && $this->criteriaForm['location_id'] !== 'all') {
            $query->where($locationCol, $this->criteriaForm['location_id']);
        }

        $contractCol = $this->resolveEmployeeContractTypeColumn();
        if ($contractCol && $this->criteriaForm['contract_type'] !== 'all') {
            $query->where($contractCol, $this->criteriaForm['contract_type']);
        }

        $employees = $query->with(['department', 'jobTitle'])
            ->limit(200)
            ->get(['id', 'name_ar', 'name_en', 'employee_no', 'department_id', 'job_title_id']);

        $this->criteriaPreviewEmployees = $employees->map(fn ($e) => [
            'id' => $e->id,
            'name' => app()->isLocale('ar') ? ($e->name_ar ?: $e->name_en) : ($e->name_en ?: $e->name_ar),
            'employee_no' => $e->employee_no,
            'department' => $e->department?->name ?? '-',
            'job_title' => $e->jobTitle?->name ?? '-',
        ])->toArray();

        $this->criteriaPreviewTotal = count($this->criteriaPreviewEmployees);
        $this->criteriaPreviewSelected = array_column($this->criteriaPreviewEmployees, 'id');
    }

    public function applyCriteriaSelectionToBulk()
    {
        if (empty($this->criteriaPreviewSelected)) {
            $this->dispatch('toast', ['type' => 'warning', 'message' => tr('No employees selected')]);
            return;
        }

        $this->selectedEmployees = array_map('intval', $this->criteriaPreviewSelected);
        $this->bulkContextMeta = [
            'source' => 'criteria',
            'criteria' => $this->criteriaForm,
        ];

        $this->showCriteriaModal = false;
        $this->showBulkModal = true;
    }

    public function openRotationModal()
    {
        $this->resetModalFlags();

        $this->bulkModalMode = 'rotation';
        $this->bulkFormData['is_rotation'] = true;

        $this->bulkFormData['is_permanent'] = true;
        $this->bulkFormData['end_date'] = '';


        $this->bulkFormData['rotation_work_schedule_id'] = '';
        $this->bulkFormData['rotation_days'] = $this->bulkFormData['rotation_days'] ?: 7;

        if (count($this->selectedEmployees) > 0) {
            $this->showBulkModal = true;
        }
    }

    public function openRotationModalForSingleEmployee($employeeId)
    {
        $this->resetModalFlags();

        $this->bulkModalMode = 'rotation';
        $this->bulkFormData['is_rotation'] = true;

        $this->bulkFormData['is_permanent'] = true;
        $this->bulkFormData['end_date'] = '';

        $this->bulkFormData['rotation_work_schedule_id'] = '';
        $this->bulkFormData['rotation_days'] = $this->bulkFormData['rotation_days'] ?: 7;

        $this->selectedEmployees = [(int) $employeeId];
        $this->bulkContextMeta = ['source' => 'single-rotation'];
        $this->showBulkModal = true;
    }

    public function updatedBulkFormDataWorkScheduleId($value): void
    {
        $periodKeys = $this->defaultPeriodKeysForScheduleId($value);

        $this->bulkFormData['work_periods'] = $periodKeys;

        if (($this->bulkModalMode ?? 'single') === 'rotation' || !empty($this->bulkFormData['is_rotation'])) {
            $this->bulkFormData['work_periods_a'] = $periodKeys;
        }
    }

    public function updatedBulkFormDataRotationWorkScheduleId($value): void
    {
        $this->bulkFormData['work_periods_b'] = $this->defaultPeriodKeysForScheduleId($value);
    }

    private function defaultPeriodKeysForScheduleId($id): array
    {
        $id = (int) $id;
        if ($id <= 0) return [];

        $periods = $this->extractSchedulePeriodsByScheduleId($id);
        if (empty($periods)) return [];

        return array_keys($periods);
    }

    private function extractSchedulePeriodsByScheduleId(int $scheduleId): array
    {
        $rows = DB::table('work_schedule_periods')
            ->where('work_schedule_id', $scheduleId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'start_time', 'end_time']);

        $out = [];

        foreach ($rows as $r) {
            $s = substr((string) $r->start_time, 0, 5);
            $e = substr((string) $r->end_time, 0, 5);

            if ($s && $e) {
                $out[(string) $r->id] = $s . ' - ' . $e;
            }
        }

        return $out;
    }

    private function extractSchedulePeriods($sch): array
    {
        $formatTime = function ($value): ?string {
            if ($value === null || $value === '') return null;
            if ($value instanceof \Carbon\CarbonInterface) return $value->format('H:i');

            $str = (string) $value;
            if (preg_match('/^\d{2}:\d{2}/', $str)) return substr($str, 0, 5);

            try { return \Carbon\Carbon::parse($str)->format('H:i'); }
            catch (\Throwable $e) { return null; }
        };

        $add = function (&$out, $key, $from, $to) use ($formatTime) {
            $f = $formatTime($from);
            $t = $formatTime($to);
            if ($f && $t) $out[$key] = "{$f} - {$t}";
        };

        $periods = [];

        $pairs = [
            ['k' => 'p1', 'f' => 'period1_start', 't' => 'period1_end'],
            ['k' => 'p2', 'f' => 'period2_start', 't' => 'period2_end'],

            ['k' => 'p1', 'f' => 'shift1_start',  't' => 'shift1_end'],
            ['k' => 'p2', 'f' => 'shift2_start',  't' => 'shift2_end'],

            ['k' => 'p1', 'f' => 'from_time_1',   't' => 'to_time_1'],
            ['k' => 'p2', 'f' => 'from_time_2',   't' => 'to_time_2'],

            ['k' => 'p1', 'f' => 'time_from_1',   't' => 'time_to_1'],
            ['k' => 'p2', 'f' => 'time_from_2',   't' => 'time_to_2'],
        ];

        foreach ($pairs as $p) {
            if (isset($sch->{$p['f']}) || isset($sch->{$p['t']})) {
                $add($periods, $p['k'], $sch->{$p['f']} ?? null, $sch->{$p['t']} ?? null);
            }
        }

        foreach (['periods', 'work_periods', 'shifts', 'time_periods'] as $attr) {
            $raw = $sch->{$attr} ?? null;
            if (!$raw) continue;

            if (is_string($raw)) {
                $decoded = json_decode($raw, true);
                if (json_last_error() === JSON_ERROR_NONE) $raw = $decoded;
            }

            if (is_array($raw)) {
                $i = 1;
                foreach ($raw as $row) {
                    $from = $row['from'] ?? $row['start'] ?? $row['time_from'] ?? $row['start_time'] ?? null;
                    $to   = $row['to']   ?? $row['end']   ?? $row['time_to']   ?? $row['end_time']   ?? null;
                    $add($periods, 'p' . $i, $from, $to);
                    $i++;
                }
            }
        }

        if (empty($periods)) {
            $from = $sch->start_time ?? $sch->from_time ?? $sch->time_from ?? $sch->start_at ?? $sch->check_in_time ?? $sch->in_time ?? null;
            $to   = $sch->end_time   ?? $sch->to_time   ?? $sch->time_to   ?? $sch->end_at   ?? $sch->check_out_time ?? $sch->out_time ?? null;

            $f = $formatTime($from);
            $t = $formatTime($to);
            if ($f && $t) $periods['main'] = "{$f} - {$t}";
        }

        return $periods;
    }

    private function closeAssignmentBeforeDate(?EmployeeWorkSchedule $assignment, Carbon $newStart): void
    {
        if (!$assignment) return;

        $curStart = Carbon::parse($assignment->start_date)->startOfDay();
        $curEnd   = $assignment->end_date ? Carbon::parse($assignment->end_date)->startOfDay() : null;

        if ($curEnd && $curEnd->lt($newStart)) return;

        $newEnd = $newStart->copy()->subDay();

        if ($newEnd->lt($curStart)) {
            $newEnd = $curStart;
        }

        $assignment->update(['end_date' => $newEnd->toDateString()]);
    }

    private function closeRotationBeforeDate(?EmployeeShiftRotation $rotation, Carbon $newStart): void
    {
        if (!$rotation) return;

        $rotStart = Carbon::parse($rotation->start_date)->startOfDay();
        $rotEnd   = $rotation->end_date ? Carbon::parse($rotation->end_date)->startOfDay() : null;

        if ($rotEnd && $rotEnd->lt($newStart)) return;

        $newEnd = $newStart->copy()->subDay();

        if ($newEnd->lt($rotStart)) {
            $newEnd = $rotStart;
        }

        $rotation->update(['end_date' => $newEnd->toDateString()]);
    }

    private function assertNoOverlappingAssignments(
        int $employeeId,
        int $companyId,
        Carbon $newStart,
        ?Carbon $newEnd,
        ?int $ignoreId = null
    ): void {
        $start = $newStart->toDateString();
        $end   = $newEnd ? $newEnd->toDateString() : null;

        $q = EmployeeWorkSchedule::query()
            ->where('employee_id', $employeeId)
            ->where('saas_company_id', $companyId);

        if ($ignoreId) {
            $q->where('id', '!=', $ignoreId);
        }

        if ($end) {
            $q->whereDate('start_date', '<=', $end)
            ->where(function ($qq) use ($start) {
                $qq->whereNull('end_date')->orWhereDate('end_date', '>=', $start);
            });
        } else {
            $q->where(function ($qq) use ($start) {
                $qq->whereNull('end_date')->orWhereDate('end_date', '>=', $start);
            });
        }

        if ($q->exists()) {
            throw ValidationException::withMessages([
                'bulkFormData.start_date' => tr('This schedule overlaps with an existing schedule period for this employee.'),
            ]);
        }
    }


    private function parseToDate(Carbon|string|null $value): Carbon
    {
        $v = trim((string) $value);
        $v = str_replace('/', '-', $v);

        $d = Carbon::parse($v)->startOfDay();

        if ($d->year < 1900) {
            throw ValidationException::withMessages([
                'bulkFormData.start_date' => tr('Please choose a Gregorian date (YYYY-MM-DD).'),
            ]);
        }

        return $d;
    }

    private function normalizeDateInputToGregorianYmd($value): ?string
    {
        if ($value === null) return null;

        $raw = trim((string) $value);
        if ($raw === '') return null;

        $raw = str_replace('/', '-', $raw);

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return null;
        }

        [$y, $m, $d] = array_map('intval', explode('-', $raw));

        if ($y <= 1700) {
            return $this->hijriToGregorianYmd($y, $m, $d);
        }

        return sprintf('%04d-%02d-%02d', $y, $m, $d);
    }

    private function hijriToGregorianYmd(int $hy, int $hm, int $hd): ?string
    {
        if (!class_exists(\IntlCalendar::class)) {
            return null;
        }

        foreach (['en_US@calendar=islamic-umalqura', 'en_US@calendar=islamic'] as $locale) {
            try {
                $cal = \IntlCalendar::createInstance('UTC', $locale);
                $cal->clear();
                $cal->set($hy, $hm - 1, $hd, 0, 0, 0);
                $ms = $cal->getTime();
                $ts = (int) floor($ms / 1000);

                return gmdate('Y-m-d', $ts);
            } catch (\Throwable $e) {
                // try next locale
            }
        }

        return null;
    }


    public function openSchedulePreviewModal(int $employeeId): void
{
    $this->resetErrorBag();

    $this->previewEmployeeId = (int) $employeeId;

    $companyId = $this->getCompanyId();

        $empQ = Employee::forCompany($companyId)->whereKey($employeeId);

        $locationCol = $this->resolveEmployeeLocationColumn();
        if ($locationCol && !empty($this->allowedLocationIds)) {
            $empQ->whereIn($locationCol, $this->allowedLocationIds);
        }

        $emp = $empQ->first(['id', 'name_ar', 'name_en', 'employee_no']);

    if (!$emp) {
        $this->dispatch('toast', ['type' => 'danger', 'message' => tr('Employee not found')]);
        return;
    }

    $this->previewEmployee = $emp->toArray();

    $from = now()->startOfDay();
    $to   = now()->copy()->addDays(7)->startOfDay();

    $this->previewForm['from'] = $from->toDateString();
    $this->previewForm['to']   = $to->toDateString();

    $this->showSchedulePreviewModal = true;

    $this->generateSchedulePreview();
}

public function closeSchedulePreviewModal(): void
{
    $this->showSchedulePreviewModal = false;
    $this->previewEmployeeId = null;
    $this->previewEmployee = null;
    $this->previewRows = [];
    $this->previewMeta = [];
}

public function generateSchedulePreview(): void
{
    if (!$this->previewEmployeeId) {
        return;
    }

    $this->validate([
        'previewForm.from' => 'required|date',
        'previewForm.to'   => 'required|date|after_or_equal:previewForm.from',
    ]);

    $from = Carbon::parse($this->previewForm['from'])->startOfDay();
    $to   = Carbon::parse($this->previewForm['to'])->startOfDay();

    if ($from->diffInDays($to) > 31) {
        throw ValidationException::withMessages([
            'previewForm.to' => tr('Maximum preview range is 31 days.'),
        ]);
    }

    $companyId = $this->getCompanyId();

    $data = $this->buildSchedulePreview($this->previewEmployeeId, $companyId, $from, $to);

    $this->previewRows = $data['rows'];
    $this->previewMeta = $data['meta'];
}

private function buildSchedulePreview(int $employeeId, int $companyId, Carbon $from, Carbon $to): array
{
    $assignments = EmployeeWorkSchedule::query()
        ->where('employee_id', $employeeId)
        ->where('saas_company_id', $companyId)
        ->whereDate('start_date', '<=', $to->toDateString())
        ->where(function ($q) use ($from) {
            $q->whereNull('end_date')->orWhereDate('end_date', '>=', $from->toDateString());
        })
        ->orderBy('start_date', 'desc')
        ->orderBy('id', 'desc')
        ->get();

    $rotations = EmployeeShiftRotation::query()
        ->where('employee_id', $employeeId)
        ->where('saas_company_id', $companyId)
        ->whereDate('start_date', '<=', $to->toDateString())
        ->where(function ($q) use ($from) {
            $q->whereNull('end_date')->orWhereDate('end_date', '>=', $from->toDateString());
        })
        ->orderBy('start_date', 'desc')
        ->orderBy('id', 'desc')
        ->get();

    $scheduleIds = [];

    foreach ($assignments as $a) {
        if (!empty($a->work_schedule_id)) $scheduleIds[] = (int) $a->work_schedule_id;
    }

    foreach ($rotations as $r) {
        if (!empty($r->work_schedule_id_a)) $scheduleIds[] = (int) $r->work_schedule_id_a;
        if (!empty($r->work_schedule_id_b)) $scheduleIds[] = (int) $r->work_schedule_id_b;
    }

    $scheduleIds = array_values(array_unique(array_filter($scheduleIds)));

    $schedules = WorkSchedule::query()
        ->whereIn('id', $scheduleIds)
        ->get()
        ->keyBy('id');

    $periodsBySchedule = [];

    if (!empty($scheduleIds)) {
        $rows = DB::table('work_schedule_periods')
            ->whereIn('work_schedule_id', $scheduleIds)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['work_schedule_id', 'start_time', 'end_time']);

        foreach ($rows as $row) {
            $s = substr((string) $row->start_time, 0, 5);
            $e = substr((string) $row->end_time, 0, 5);
            if (!$s || !$e) continue;

            $periodsBySchedule[(int) $row->work_schedule_id][] = $s . ' - ' . $e;
        }
    }

    $pickAssignmentForDay = function (Carbon $day) use ($assignments) {
        foreach ($assignments as $a) {
            $start = Carbon::parse($a->start_date)->startOfDay();
            $end   = $a->end_date ? Carbon::parse($a->end_date)->startOfDay() : null;

            if ($day->lt($start)) continue;
            if ($end && $day->gt($end)) continue;

            return $a;
        }
        return null;
    };

    $pickRotationForDay = function (Carbon $day) use ($rotations) {
        foreach ($rotations as $r) {
            $start = Carbon::parse($r->start_date)->startOfDay();
            $end   = $r->end_date ? Carbon::parse($r->end_date)->startOfDay() : null;

            if ($day->lt($start)) continue;
            if ($end && $day->gt($end)) continue;

            return $r;
        }
        return null;
    };

    $rowsOut = [];
    $cursor = $from->copy();

    while ($cursor->lte($to)) {
        $day = $cursor->copy()->startOfDay();

        $type = 'none';
        $scheduleId = null;
        $scheduleName = null;
        $scheduleDisabled = false;
        $periods = [];
        $source = null;

        $rot = $pickRotationForDay($day);

        if ($rot) {
            $type = 'rotation';

            $rotStart = Carbon::parse($rot->start_date)->startOfDay();
            $rotationDays = max(1, (int) ($rot->rotation_days ?? 7));
            $diffDays = $rotStart->diffInDays($day);

            $cycleIndex = intdiv($diffDays, $rotationDays);
            $isA = ($cycleIndex % 2) === 0;

            $scheduleId = $isA ? (int) $rot->work_schedule_id_a : (int) $rot->work_schedule_id_b;
            $source = $isA ? 'A' : 'B';
        } else {
            $a = $pickAssignmentForDay($day);
            if ($a) {
                $type = 'single';
                $scheduleId = (int) $a->work_schedule_id;
                $source = 'single';
            }
        }

        if ($scheduleId) {
            $sch = $schedules[$scheduleId] ?? null;
            $scheduleName = $sch?->name ?? ('#' . $scheduleId);
            $scheduleDisabled = (bool) ($sch?->is_active === false);
            $periods = $periodsBySchedule[$scheduleId] ?? [];
        }

        $rowsOut[] = [
            'date' => $day->toDateString(),
            'day'  => $day->translatedFormat('D'),
            'type' => $type,
            'source' => $source,
            'schedule' => $scheduleName,
            'disabled' => $scheduleDisabled,
            'periods' => $periods,
        ];

        $cursor->addDay();
    }

    $meta = [
        'from' => $from->toDateString(),
        'to' => $to->toDateString(),
        'days' => $from->diffInDays($to) + 1,
        'assignments_count' => $assignments->count(),
        'rotations_count' => $rotations->count(),
    ];

    return ['rows' => $rowsOut, 'meta' => $meta];
}

}


