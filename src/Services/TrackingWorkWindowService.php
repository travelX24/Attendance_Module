<?php

namespace Athka\Attendance\Services;

use Athka\Attendance\Models\EmployeeWorkScheduleException;
use Athka\Attendance\Support\TrackingWorkWindowResult;
use Athka\Employees\Models\Employee;
use Athka\SystemSettings\Services\WorkScheduleService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class TrackingWorkWindowService
{
    public const STATE_WORKING = 'working';
    public const STATE_BREAK = 'break';
    public const STATE_PERMISSION = 'permission';
    public const STATE_MISSION = 'mission';
    public const STATE_LEAVE = 'leave';
    public const STATE_HOLIDAY = 'holiday';
    public const STATE_OUTSIDE_WORK_WINDOW = 'outside_work_window';
    public const STATE_NO_SCHEDULE = 'no_schedule';

    public function __construct(
        private readonly WorkScheduleService $workScheduleService,
    ) {
    }

    /**
     * Resolve the employee's state at the original GPS capture time.
     *
     * The previous calendar date is evaluated too, so a night shift that
     * crosses midnight remains attached to its original workday.
     */
    public function resolve(Employee $employee, CarbonInterface $capturedAt): TrackingWorkWindowResult
    {
        $at = CarbonImmutable::instance($capturedAt);
        $companyId = (int) $employee->saas_company_id;

        foreach ([$at->toDateString(), $at->subDay()->toDateString()] as $anchorDate) {
            $context = $this->buildContext($employee, $companyId, $anchorDate);
            $result = $this->classifyContext($context, $at);

            if ($result !== null) {
                return $result;
            }
        }

        return new TrackingWorkWindowResult(
            state: self::STATE_OUTSIDE_WORK_WINDOW,
            shouldTrack: false,
            shouldCountOutside: false,
            source: 'outside_work_window',
        );
    }

    /**
     * Pure classifier kept public for deterministic unit tests and offline replay.
     */
    public function classifyContext(array $context, CarbonInterface $capturedAt): ?TrackingWorkWindowResult
    {
        $at = CarbonImmutable::instance($capturedAt);
        $anchorDate = (string) ($context['anchor_date'] ?? $at->toDateString());

        $fullDayMission = $this->firstFullDayMission($context['missions'] ?? [], $anchorDate);
        if ($fullDayMission !== null) {
            return new TrackingWorkWindowResult(
                state: self::STATE_MISSION,
                shouldTrack: true,
                shouldCountOutside: false,
                source: 'approved_full_day_mission',
                meta: ['mission_id' => (int) $fullDayMission->id],
            );
        }

        $fullDayLeave = $this->firstFullDayLeave($context['leaves'] ?? [], $anchorDate);
        if ($fullDayLeave !== null) {
            return new TrackingWorkWindowResult(
                state: self::STATE_LEAVE,
                shouldTrack: false,
                shouldCountOutside: false,
                source: 'approved_full_day_leave',
                meta: ['leave_id' => (int) $fullDayLeave->id],
            );
        }

        foreach ($this->permissionWindows($context['permissions'] ?? [], $anchorDate) as $window) {
            if ($this->contains($window['start'], $window['end'], $at)) {
                return new TrackingWorkWindowResult(
                    state: self::STATE_PERMISSION,
                    shouldTrack: true,
                    shouldCountOutside: false,
                    source: 'approved_permission',
                    windowStart: $window['start'],
                    windowEnd: $window['end'],
                    meta: ['permission_id' => $window['id']],
                );
            }
        }

        foreach ($this->partialMissionWindows($context['missions'] ?? [], $anchorDate) as $window) {
            if ($this->contains($window['start'], $window['end'], $at)) {
                return new TrackingWorkWindowResult(
                    state: self::STATE_MISSION,
                    shouldTrack: true,
                    shouldCountOutside: false,
                    source: 'approved_partial_mission',
                    windowStart: $window['start'],
                    windowEnd: $window['end'],
                    meta: ['mission_id' => $window['id']],
                );
            }
        }

        foreach ($this->partialLeaveWindows($context['leaves'] ?? [], $anchorDate) as $window) {
            if ($this->contains($window['start'], $window['end'], $at)) {
                return new TrackingWorkWindowResult(
                    state: self::STATE_LEAVE,
                    shouldTrack: false,
                    shouldCountOutside: false,
                    source: 'approved_partial_leave',
                    windowStart: $window['start'],
                    windowEnd: $window['end'],
                    periodId: $window['period_id'],
                    meta: ['leave_id' => $window['id']],
                );
            }
        }

        foreach ($this->explicitBreakWindows($context['breaks'] ?? [], $anchorDate) as $window) {
            if ($this->contains($window['start'], $window['end'], $at)) {
                return new TrackingWorkWindowResult(
                    state: self::STATE_BREAK,
                    shouldTrack: true,
                    shouldCountOutside: false,
                    source: 'employee_exception_break',
                    windowStart: $window['start'],
                    windowEnd: $window['end'],
                );
            }
        }

        $status = (string) ($context['metrics']['status'] ?? '');

        if ($status === 'holiday') {
            return new TrackingWorkWindowResult(
                state: self::STATE_HOLIDAY,
                shouldTrack: false,
                shouldCountOutside: false,
                source: 'schedule_holiday',
            );
        }

        if ($status === 'no_schedule') {
            return new TrackingWorkWindowResult(
                state: self::STATE_NO_SCHEDULE,
                shouldTrack: false,
                shouldCountOutside: false,
                source: 'no_schedule',
            );
        }

        $periodWindows = $this->periodWindows(
            $context['metrics']['periods'] ?? [],
            $anchorDate,
        );

        foreach ($periodWindows as $window) {
            if (! $this->contains($window['start'], $window['end'], $at)) {
                continue;
            }

            if ($window['is_leave']) {
                return new TrackingWorkWindowResult(
                    state: self::STATE_LEAVE,
                    shouldTrack: false,
                    shouldCountOutside: false,
                    source: 'schedule_period_leave',
                    windowStart: $window['start'],
                    windowEnd: $window['end'],
                    periodId: $window['period_id'],
                );
            }

            return new TrackingWorkWindowResult(
                state: self::STATE_WORKING,
                shouldTrack: true,
                shouldCountOutside: true,
                source: 'scheduled_period',
                windowStart: $window['start'],
                windowEnd: $window['end'],
                periodId: $window['period_id'],
            );
        }

        for ($i = 0; $i < count($periodWindows) - 1; $i++) {
            $breakStart = $periodWindows[$i]['end'];
            $breakEnd = $periodWindows[$i + 1]['start'];

            if (
                $breakEnd->greaterThan($breakStart)
                && $this->contains($breakStart, $breakEnd, $at, false)
            ) {
                return new TrackingWorkWindowResult(
                    state: self::STATE_BREAK,
                    shouldTrack: true,
                    shouldCountOutside: false,
                    source: 'gap_between_work_periods',
                    windowStart: $breakStart,
                    windowEnd: $breakEnd,
                );
            }
        }

        if ($status === 'mission') {
            return new TrackingWorkWindowResult(
                state: self::STATE_MISSION,
                shouldTrack: true,
                shouldCountOutside: false,
                source: 'approved_mission',
            );
        }

        if ($status === 'on_leave') {
            return new TrackingWorkWindowResult(
                state: self::STATE_LEAVE,
                shouldTrack: false,
                shouldCountOutside: false,
                source: 'approved_leave',
            );
        }

        // Let resolve() inspect the previous anchor date for overnight shifts.
        return null;
    }

    private function buildContext(Employee $employee, int $companyId, string $anchorDate): array
    {
        $schedule = $this->workScheduleService->getEffectiveSchedule(
            $companyId,
            $employee,
            $anchorDate,
        );

        $holidays = $this->workScheduleService->getHolidays(
            $companyId,
            $anchorDate,
            $anchorDate,
        );

        $requests = $this->workScheduleService->getEmployeeRequests(
            (int) $employee->id,
            $anchorDate,
            $anchorDate,
        );

        $metrics = $this->workScheduleService->getMetricsForDate(
            $anchorDate,
            $schedule,
            $holidays,
            $employee,
            $requests,
        );

        $exception = EmployeeWorkScheduleException::query()
            ->where('saas_company_id', $companyId)
            ->where('employee_id', (int) $employee->id)
            ->whereDate('exception_date', $anchorDate)
            ->first();

        return [
            'anchor_date' => $anchorDate,
            'metrics' => $metrics,
            'leaves' => $requests['leaves'] ?? [],
            'missions' => $requests['missions'] ?? [],
            'permissions' => $requests['permissions'] ?? [],
            'breaks' => $exception?->breaks_json ?? [],
        ];
    }

    private function periodWindows(iterable $periods, string $anchorDate): array
    {
        $windows = [];

        foreach ($periods as $period) {
            $period = is_array($period) ? $period : (array) $period;

            $startTime = $period['start_time'] ?? null;
            $endTime = $period['end_time'] ?? null;

            if (! $startTime || ! $endTime) {
                continue;
            }

            [$start, $end] = $this->dateTimeWindow(
                $anchorDate,
                (string) $startTime,
                (string) $endTime,
                (bool) ($period['is_night_shift'] ?? false),
            );

            $windows[] = [
                'start' => $start,
                'end' => $end,
                'period_id' => isset($period['id']) ? (int) $period['id'] : null,
                'is_leave' => (bool) ($period['is_leave'] ?? false),
            ];
        }

        usort($windows, fn (array $a, array $b) => $a['start'] <=> $b['start']);

        return $windows;
    }

    private function permissionWindows(iterable $permissions, string $anchorDate): array
    {
        $windows = [];

        foreach ($permissions as $permission) {
            if (! $this->sameDate($permission->permission_date ?? null, $anchorDate)) {
                continue;
            }

            if (empty($permission->from_time) || empty($permission->to_time)) {
                continue;
            }

            [$start, $end] = $this->dateTimeWindow(
                $anchorDate,
                (string) $permission->from_time,
                (string) $permission->to_time,
            );

            $windows[] = [
                'id' => (int) $permission->id,
                'start' => $start,
                'end' => $end,
            ];
        }

        return $windows;
    }

    private function partialMissionWindows(iterable $missions, string $anchorDate): array
    {
        $windows = [];

        foreach ($missions as $mission) {
            if ((string) ($mission->type ?? '') !== 'partial') {
                continue;
            }

            if (! $this->dateFallsWithin(
                $anchorDate,
                $mission->start_date ?? null,
                $mission->end_date ?? ($mission->start_date ?? null),
            )) {
                continue;
            }

            if (empty($mission->from_time) || empty($mission->to_time)) {
                continue;
            }

            [$start, $end] = $this->dateTimeWindow(
                $anchorDate,
                (string) $mission->from_time,
                (string) $mission->to_time,
            );

            $windows[] = [
                'id' => (int) $mission->id,
                'start' => $start,
                'end' => $end,
            ];
        }

        return $windows;
    }

    private function partialLeaveWindows(iterable $leaves, string $anchorDate): array
    {
        $windows = [];

        foreach ($leaves as $leave) {
            $duration = (string) ($leave->duration_unit ?? 'full_day');

            if (! in_array($duration, ['half_day', 'hours'], true)) {
                continue;
            }

            if (! $this->dateFallsWithin(
                $anchorDate,
                $leave->start_date ?? null,
                $leave->end_date ?? ($leave->start_date ?? null),
            )) {
                continue;
            }

            if (empty($leave->from_time) || empty($leave->to_time)) {
                continue;
            }

            [$start, $end] = $this->dateTimeWindow(
                $anchorDate,
                (string) $leave->from_time,
                (string) $leave->to_time,
            );

            $windows[] = [
                'id' => (int) $leave->id,
                'start' => $start,
                'end' => $end,
                'period_id' => ! empty($leave->work_schedule_period_id)
                    ? (int) $leave->work_schedule_period_id
                    : null,
            ];
        }

        return $windows;
    }

    private function explicitBreakWindows(array $breaks, string $anchorDate): array
    {
        $windows = [];

        foreach ($breaks as $break) {
            if (! is_array($break)) {
                continue;
            }

            $from = $break['from_time']
                ?? $break['start_time']
                ?? $break['from']
                ?? $break['start']
                ?? null;

            $to = $break['to_time']
                ?? $break['end_time']
                ?? $break['to']
                ?? $break['end']
                ?? null;

            if (! $from || ! $to) {
                continue;
            }

            [$start, $end] = $this->dateTimeWindow(
                $anchorDate,
                (string) $from,
                (string) $to,
            );

            $windows[] = [
                'start' => $start,
                'end' => $end,
            ];
        }

        return $windows;
    }

    private function firstFullDayMission(iterable $missions, string $anchorDate): ?object
    {
        foreach ($missions as $mission) {
            if ((string) ($mission->type ?? 'full_day') === 'partial') {
                continue;
            }

            if ($this->dateFallsWithin(
                $anchorDate,
                $mission->start_date ?? null,
                $mission->end_date ?? ($mission->start_date ?? null),
            )) {
                return $mission;
            }
        }

        return null;
    }

    private function firstFullDayLeave(iterable $leaves, string $anchorDate): ?object
    {
        foreach ($leaves as $leave) {
            $duration = (string) ($leave->duration_unit ?? 'full_day');

            if (in_array($duration, ['half_day', 'hours'], true)) {
                continue;
            }

            if ($this->dateFallsWithin(
                $anchorDate,
                $leave->start_date ?? null,
                $leave->end_date ?? ($leave->start_date ?? null),
            )) {
                return $leave;
            }
        }

        return null;
    }

    private function dateTimeWindow(
        string $anchorDate,
        string $startTime,
        string $endTime,
        bool $nightShift = false,
    ): array {
        $start = CarbonImmutable::parse(
            $anchorDate . ' ' . substr($startTime, 0, 8)
        );

        $end = CarbonImmutable::parse(
            $anchorDate . ' ' . substr($endTime, 0, 8)
        );

        if ($nightShift || $end->lessThanOrEqualTo($start)) {
            $end = $end->addDay();
        }

        return [$start, $end];
    }

    private function contains(
        CarbonImmutable $start,
        CarbonImmutable $end,
        CarbonImmutable $at,
        bool $includeEnd = true,
    ): bool {
        if ($includeEnd) {
            return $at->greaterThanOrEqualTo($start)
                && $at->lessThanOrEqualTo($end);
        }

        return $at->greaterThanOrEqualTo($start)
            && $at->lessThan($end);
    }

    private function sameDate(mixed $value, string $date): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        return CarbonImmutable::parse($value)->toDateString() === $date;
    }

    private function dateFallsWithin(
        string $date,
        mixed $startDate,
        mixed $endDate,
    ): bool {
        if ($startDate === null || $startDate === '') {
            return false;
        }

        $target = CarbonImmutable::parse($date)->startOfDay();
        $start = CarbonImmutable::parse($startDate)->startOfDay();
        $end = CarbonImmutable::parse($endDate ?: $startDate)->startOfDay();

        return $target->betweenIncluded($start, $end);
    }
}
