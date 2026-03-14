<?php

namespace Athka\Attendance\Console\Commands;

use Illuminate\Console\Command;
use Athka\Attendance\Models\EmployeeShiftRotation;
use Athka\Attendance\Models\EmployeeWorkSchedule;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ApplyShiftRotations extends Command
{
    protected $signature = 'attendance:apply-shift-rotations';
    protected $description = 'Apply active employee shift rotations and switch schedules automatically';

    public function handle(): int
    {
        $today = now()->startOfDay();

        $rotations = EmployeeShiftRotation::query()
            ->where('is_active', true)
            ->whereDate('start_date', '<=', $today->toDateString())
            ->where(function ($q) use ($today) {
                $q->whereNull('end_date')
                  ->orWhereDate('end_date', '>=', $today->toDateString());
            })
            ->get();

        foreach ($rotations as $rot) {
            $start = Carbon::parse($rot->start_date)->startOfDay();
            $diffDays = $start->diffInDays($today);
            $cycle = intdiv($diffDays, max(1, (int) $rot->rotation_days));
            $targetScheduleId = ($cycle % 2 === 0) ? (int) $rot->work_schedule_id_a : (int) $rot->work_schedule_id_b;

            $cycleStart = $start->copy()->addDays($cycle * (int) $rot->rotation_days);
            $cycleEnd = $cycleStart->copy()->addDays(((int) $rot->rotation_days) - 1);

            if (!empty($rot->end_date) && $cycleEnd->gt($rot->end_date)) {
                $cycleEnd = Carbon::parse($rot->end_date)->endOfDay();
            }

            DB::transaction(function () use ($rot, $targetScheduleId, $cycleStart, $cycleEnd) {
                $current = EmployeeWorkSchedule::query()
                    ->where('saas_company_id', $rot->saas_company_id)
                    ->where('employee_id', $rot->employee_id)
                    ->where('is_active', true)
                    ->latest('id')
                    ->first();

                if ($current && (int) $current->work_schedule_id === (int) $targetScheduleId) {
                    // update current cycle end_date (optional)
                    if ($current->end_date !== $cycleEnd->toDateString()) {
                        $current->update(['end_date' => $cycleEnd->toDateString()]);
                    }
                    return;
                }

                EmployeeWorkSchedule::where('saas_company_id', $rot->saas_company_id)
                    ->where('employee_id', $rot->employee_id)
                    ->update(['is_active' => false]);

                EmployeeWorkSchedule::create([
                    'employee_id'      => $rot->employee_id,
                    'work_schedule_id' => $targetScheduleId,
                    'start_date'       => $cycleStart->toDateString(),
                    'end_date'         => $cycleEnd->toDateString(),
                    'is_active'        => true,
                    'assignment_type'  => 'rotation',
                    'saas_company_id'  => $rot->saas_company_id,
                ]);
            });
        }

        $this->info('Shift rotations applied: ' . $rotations->count());
        return self::SUCCESS;
    }
}


