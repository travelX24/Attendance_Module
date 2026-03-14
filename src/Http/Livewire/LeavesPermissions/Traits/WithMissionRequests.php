<?php

namespace Athka\Attendance\Http\Livewire\LeavesPermissions\Traits;

use Athka\Attendance\Models\AttendanceMissionRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

trait WithMissionRequests
{
    public bool $createMissionOpen = false;

    // Form fields
    public ?int $mission_employee_id = null;
    public string $mission_type = 'full_day';
    public string $mission_start_date = '';
    public string $mission_end_date = '';
    public string $mission_from_time = '';
    public string $mission_to_time = '';
    public string $mission_destination = '';
    public string $mission_reason = '';

    public function openCreateMission(): void
    {
        $this->resetValidation();
        $this->mission_employee_id = null;
        $this->mission_type = 'full_day';
        $this->mission_start_date = '';
        $this->mission_end_date = '';
        $this->mission_from_time = '';
        $this->mission_to_time = '';
        $this->mission_destination = '';
        $this->mission_reason = '';
        $this->createMissionOpen = true;
    }

    public function closeCreateMission(): void
    {
        $this->createMissionOpen = false;
    }

    public function saveMission(): void
    {
        $this->ensureCanManage();

        $rules = [
            'mission_employee_id' => ['required', 'integer', 'min:1'],
            'mission_type' => ['required', 'in:full_day,partial'],
            'mission_start_date' => ['required', 'date'],
            'mission_end_date' => ['required_if:mission_type,full_day', 'nullable', 'date'],
            'mission_from_time' => ['required_if:mission_type,partial', 'nullable', 'string'],
            'mission_to_time' => ['required_if:mission_type,partial', 'nullable', 'string'],
            'mission_destination' => ['nullable', 'string', 'max:500'],
            'mission_reason' => ['nullable', 'string', 'max:2000'],
        ];

        $data = $this->validate($rules);

        $table = (new AttendanceMissionRequest())->getTable();
        $coCol = $this->detectCompanyColumn($table);

        $payload = [
            'employee_id' => $data['mission_employee_id'],
            'type' => $data['mission_type'],
            'start_date' => $data['mission_start_date'],
            'end_date' => $data['mission_type'] === 'full_day' ? ($data['mission_end_date'] ?: $data['mission_start_date']) : $data['mission_start_date'],
            'from_time' => $data['mission_type'] === 'partial' ? $data['mission_from_time'] : null,
            'to_time' => $data['mission_type'] === 'partial' ? $data['mission_to_time'] : null,
            'destination' => $data['mission_destination'],
            'reason' => $data['mission_reason'],
            'status' => 'pending', 
        ];

        if ($coCol && Schema::hasColumn($table, $coCol)) {
            $payload[$coCol] = $this->companyId;
        }

        $mission = AttendanceMissionRequest::create($payload);

        // âœ… Trigger task generation
        try {
            $controller = app(\Athka\SystemSettings\Http\Controllers\Api\Employee\ApprovalInboxController::class);
            $method = new \ReflectionMethod(get_class($controller), 'requestSource');
            $method->setAccessible(true);
            $src = $method->invoke($controller, 'missions');
            if ($src) {
                $controller->ensureTasksForRequest($src, $mission->id);
            }
        } catch (\Throwable $e) {
            // ignore
        }

        session()->flash('success', tr('Saved successfully'));
        $this->closeCreateMission();
        $this->resetPage('missionPage');
    }

    public function getPendingMissionRequestsProperty()
    {
        $table = (new AttendanceMissionRequest())->getTable();
        $coCol = $this->detectCompanyColumn($table);

        $q = AttendanceMissionRequest::query()
            ->with(['employee'])
            ->when($coCol, fn ($qq) => $qq->where($coCol, $this->companyId))
            ->where('status', 'pending');

        // âœ… Data scoping
        $q = $this->applyDataScoping($q, 'attendance.leaves.view', 'attendance.leaves.view-subordinates');

        $this->applySelectedYearDateRangeFilter($q, 'start_date');
        $this->applyDateRangeBetweenFilter($q, 'start_date');
        $this->applyEmployeeFilters($q);
        $this->applyApprovalTaskFilter($q, 'missions');

        return $q->orderByDesc('id')->paginate($this->perPage, ['*'], 'missionPage');
    }

    public function getPreviousMissionRequestsProperty()
    {
        $table = (new AttendanceMissionRequest())->getTable();
        $coCol = $this->detectCompanyColumn($table);

        $q = AttendanceMissionRequest::query()
            ->with(['employee'])
            ->when($coCol, fn ($qq) => $qq->where($coCol, $this->companyId))
            ->where('status', '!=', 'pending');

        // âœ… Data scoping
        $q = $this->applyDataScoping($q, 'attendance.leaves.view', 'attendance.leaves.view-subordinates');

        if ($this->historyStatus !== '') {
            $q->where('status', $this->historyStatus);
        }

        $this->applySelectedYearDateRangeFilter($q, 'start_date');
        $this->applyDateRangeBetweenFilter($q, 'start_date');
        $this->applyEmployeeFilters($q);

        return $q->orderByDesc('id')->paginate($this->perPage, ['*'], 'historyMissionPage');
    }
}


