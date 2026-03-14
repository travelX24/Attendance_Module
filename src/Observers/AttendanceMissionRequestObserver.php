<?php

namespace Athka\Attendance\Observers;

use Athka\Attendance\Models\AttendanceMissionRequest;
use Athka\Attendance\Models\AttendanceDailyLog;
use Athka\Attendance\Models\AttendanceDailyDetail;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AttendanceMissionRequestObserver
{
    /**
     * Handle the AttendanceMissionRequest "updated" event.
     */
    public function updated(AttendanceMissionRequest $request): void
    {
        // If the request was just approved, trigger auto-attendance
        if ($request->isDirty('status') && $request->status === 'approved') {
            $this->processAutoAttendance($request);
        }
    }

    /**
     * Process auto-attendance for approved mission
     */
    private function processAutoAttendance(AttendanceMissionRequest $request): void
    {
        $startDate = Carbon::parse($request->start_date);
        $endDate = $request->end_date ? Carbon::parse($request->end_date) : $startDate;

        $cursor = $startDate->copy();
        while ($cursor->lte($endDate)) {
            $dateStr = $cursor->toDateString();
            $this->syncAttendanceForMission($request, $dateStr);
            $cursor->addDay();
        }
    }

    private function syncAttendanceForMission(AttendanceMissionRequest $request, string $dateStr): void
    {
        // Find or create the daily log
        $log = AttendanceDailyLog::firstOrCreate([
            'saas_company_id' => $request->company_id,
            'employee_id'     => $request->employee_id,
            'attendance_date' => $dateStr,
        ]);

        // If it's a full day mission, use scheduled times or request times
        if ($request->type === 'full_day') {
            // If scheduled times are not set, sync with schedule first
            if (!$log->scheduled_check_in || !$log->scheduled_check_out) {
                $log->syncWithSchedule();
            }

            // Fill check-in/out with scheduled times (or fall back to mission times if provided)
            $log->check_in_time  = $log->scheduled_check_in ?: '08:00:00';
            $log->check_out_time = $log->scheduled_check_out ?: '17:00:00';
            $log->attendance_status = 'work_mission';
            $log->source = 'manual';
            $log->save();

            // Create a detail record for the mission
            AttendanceDailyDetail::updateOrCreate([
                'daily_log_id' => $log->id,
            ], [
                'check_in_time'  => $log->check_in_time,
                'check_out_time' => $log->check_out_time,
                'attendance_status' => 'present',
                'meta_data' => json_encode(['mission_id' => $request->id, 'note' => 'Auto-created from Work Mission']),
            ]);
        } else {
            // Partial mission (hours)
            if ($request->from_time && $request->to_time) {
                // If it's partial, we just add the detail record
                AttendanceDailyDetail::create([
                    'daily_log_id' => $log->id,
                    'check_in_time'  => $request->from_time,
                    'check_out_time' => $request->to_time,
                    'attendance_status' => 'present',
                    'meta_data' => json_encode(['mission_id' => $request->id, 'note' => 'Partial Work Mission']),
                ]);

                // Update main log to show working/present if it was absent
                if ($log->attendance_status === 'absent' || empty($log->attendance_status)) {
                    $log->attendance_status = 'work_mission';
                }
                
                // Set first check-in if empty
                if (!$log->check_in_time) {
                    $log->check_in_time = $request->from_time;
                }
                // Set last check-out
                $log->check_out_time = $request->to_time;
                
                $log->source = 'manual';
                $log->save();
            }
        }
    }
}


