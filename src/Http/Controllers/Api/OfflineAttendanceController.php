<?php

namespace Athka\Attendance\Http\Controllers\Api;

use Illuminate\Routing\Controller;
use Athka\\Attendance\Models\AttendanceDailyLog;
use Athka\\Attendance\Models\OfflineAttendanceQueue;
use Athka\Employees\Models\Employee;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class OfflineAttendanceController extends Controller
{
    /**
     * Submit offline attendance records (batch sync).
     * Called by the client when internet is restored.
     */
    public function sync(Request $request): JsonResponse
    {
        $request->validate([
            'records'                          => ['required', 'array', 'min:1', 'max:50'],
            'records.*.employee_id'            => ['required', 'integer'],
            'records.*.action_type'            => ['required', 'in:check_in,check_out,check_in_out,full_day'],
            'records.*.attendance_date'        => ['required', 'date'],
            'records.*.check_in_time'          => ['nullable', 'date_format:H:i'],
            'records.*.check_out_time'         => ['nullable', 'date_format:H:i'],
            'records.*.device_captured_at'     => ['nullable', 'date'],
            'records.*.device_timezone'        => ['nullable', 'string', 'max:64'],
            'records.*.latitude'               => ['nullable', 'numeric'],
            'records.*.longitude'              => ['nullable', 'numeric'],
            'records.*.gps_accuracy'           => ['nullable', 'string'],
            'records.*.device_id'              => ['nullable', 'string', 'max:128'],
            'records.*.device_platform'        => ['nullable', 'string', 'max:32'],
            'records.*.integrity_hash'         => ['nullable', 'string', 'max:64'],
            'records.*.reason'                 => ['nullable', 'string', 'max:500'],
        ]);

        $user      = $request->user();
        $companyId = (int) ($user->saas_company_id ?? $user->company_id ?? 0);

        if (!$companyId) {
            return response()->json(['ok' => false, 'message' => 'Company not found.'], 422);
        }

        $results = [];

        foreach ($request->records as $rec) {
            try {
                $result = $this->processRecord($rec, $user, $companyId);
                $results[] = $result;
            } catch (\Throwable $e) {
                Log::error('[OfflineAttendance] Error processing record', [
                    'rec'   => $rec,
                    'error' => $e->getMessage(),
                ]);
                $results[] = [
                    'ok'           => false,
                    'employee_id'  => $rec['employee_id'] ?? null,
                    'date'         => $rec['attendance_date'] ?? null,
                    'message'      => tr('An error occurred while processing this record.'),
                    'queue_id'     => null,
                ];
            }
        }

        $synced  = count(array_filter($results, fn($r) => $r['ok']));
        $failed  = count($results) - $synced;

        return response()->json([
            'ok'      => true,
            'synced'  => $synced,
            'failed'  => $failed,
            'results' => $results,
        ]);
    }

    /**
     * Get essential data for the mobile app to function offline.
     * Returns: current schedule, allowed locations, and attendance settings.
     */
    public function getPrepData(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user->employee) {
            return response()->json(['ok' => false, 'message' => 'User is not an employee.'], 403);
        }

        $employee  = $user->employee;
        $companyId = $employee->saas_company_id;
        $date      = now();

        // 1. Get Schedule
        $assignment = \Athka\\Attendance\Models\EmployeeWorkSchedule::where('saas_company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->where('is_active', true)
            ->where('start_date', '<=', $date)
            ->where(function($q) use ($date) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $date);
            })
            ->with(['workSchedule.periods'])
            ->first();

        $schedule = null;
        if ($assignment && $assignment->workSchedule) {
            $ws = $assignment->workSchedule;
            $schedule = [
                'id' => $ws->id,
                'name' => $ws->name,
                'work_days' => $ws->work_days,
                'periods' => $ws->periods->map(fn($p) => [
                    'start' => $p->start_time,
                    'end' => $p->end_time,
                ]),
            ];
        }

        // 2. Get Allowed Locations (if any)
        $locations = [];
        if (Schema::hasTable('branches')) {
            $locations = DB::table('branches')
                ->where('id', $employee->branch_id)
                ->select('id', 'name_ar', 'name_en', 'latitude', 'longitude', 'radius')
                ->get();
        }

        return response()->json([
            'ok' => true,
            'data' => [
                'employee_id' => $employee->id,
                'company_id' => $companyId,
                'date' => $date->toDateString(),
                'server_time' => $date->toDateTimeString(),
                'schedule' => $schedule,
                'locations' => $locations,
                'settings' => [
                    'gps_required' => config('attendance.gps_required', true),
                    'gps_radius' => (int) config('attendance.default_radius', 100),
                    'allow_offline' => true,
                ]
            ]
        ]);
    }

    /**
     * Get pending (not yet synced) queue items for the current user's company.
     */
    public function pending(Request $request): JsonResponse
    {
        $user      = $request->user();
        $companyId = (int) ($user->saas_company_id ?? $user->company_id ?? 0);

        $rows = OfflineAttendanceQueue::forCompany($companyId)
            ->pending()
            ->with(['employee:id,name_ar,name_en'])
            ->latest()
            ->paginate(30);

        $items = $rows->getCollection()->map(fn($r) => [
            'id'               => $r->id,
            'employee_id'      => $r->employee_id,
            'employee_name'    => $r->employee?->name_ar ?? $r->employee?->name_en ?? '#' . $r->employee_id,
            'action_type'      => $r->action_type,
            'attendance_date'  => $r->attendance_date?->toDateString(),
            'check_in_time'    => $r->check_in_time,
            'check_out_time'   => $r->check_out_time,
            'device_captured_at' => $r->device_captured_at?->toDateTimeString(),
            'is_suspicious'    => $r->is_suspicious,
            'suspicion_reason' => $r->suspicion_reason,
            'sync_status'      => $r->sync_status,
            'created_at'       => $r->created_at?->toDateTimeString(),
        ]);

        return response()->json([
            'ok'   => true,
            'data' => $items,
            'meta' => [
                'total'        => $rows->total(),
                'current_page' => $rows->currentPage(),
                'last_page'    => $rows->lastPage(),
            ],
        ]);
    }

    /**
     * Manually trigger sync for a specific queued item (admin action).
     */
    public function syncOne(Request $request, int $id): JsonResponse
    {
        $user      = $request->user();
        $companyId = (int) ($user->saas_company_id ?? $user->company_id ?? 0);

        $item = OfflineAttendanceQueue::forCompany($companyId)->findOrFail($id);

        if ($item->sync_status === 'synced') {
            return response()->json(['ok' => false, 'message' => tr('Already synced.')]);
        }

        $result = $this->applyToAttendanceLog($item, $companyId);

        if ($result['ok']) {
            return response()->json(['ok' => true, 'message' => tr('Synced successfully.')]);
        }

        return response()->json(['ok' => false, 'message' => $result['message']], 422);
    }

    // =========================================================
    // Private Helpers
    // =========================================================

    private function processRecord(array $rec, $user, int $companyId): array
    {
        $employeeId = (int) ($rec['employee_id'] ?? 0);

        // Verify employee belongs to same company
        $employee = Employee::where('saas_company_id', $companyId)
            ->find($employeeId);

        if (!$employee) {
            return [
                'ok'          => false,
                'employee_id' => $employeeId,
                'date'        => $rec['attendance_date'] ?? null,
                'message'     => tr('Employee not found or does not belong to your company.'),
                'queue_id'    => null,
            ];
        }

        // Build payload for queue
        $payload = [
            'employee_id'        => $employeeId,
            'saas_company_id'    => $companyId,
            'submitted_by_user_id' => $user->id,
            'action_type'        => $rec['action_type'],
            'attendance_date'    => $rec['attendance_date'],
            'check_in_time'      => $rec['check_in_time'] ?? null,
            'check_out_time'     => $rec['check_out_time'] ?? null,
            'device_captured_at' => isset($rec['device_captured_at']) ? Carbon::parse($rec['device_captured_at']) : null,
            'device_timezone'    => $rec['device_timezone'] ?? null,
            'latitude'           => $rec['latitude'] ?? null,
            'longitude'          => $rec['longitude'] ?? null,
            'gps_accuracy'       => $rec['gps_accuracy'] ?? null,
            'device_id'          => $rec['device_id'] ?? null,
            'device_platform'    => $rec['device_platform'] ?? 'web',
            'user_agent'         => request()->userAgent(),
            'integrity_hash'     => $rec['integrity_hash'] ?? null,
            'reason'             => $rec['reason'] ?? null,
            'sync_status'        => 'pending',
        ];

        $queueItem = OfflineAttendanceQueue::create($payload);

        // Run tamper detection
        $queueItem->detectTampering();

        // Verify integrity hash if provided
        if (!empty($rec['integrity_hash'])) {
            if (!$queueItem->verifyIntegrity($rec)) {
                $queueItem->is_suspicious = true;
                $queueItem->suspicion_reason = ($queueItem->suspicion_reason ? $queueItem->suspicion_reason . ' | ' : '')
                    . 'Integrity hash mismatch.';
            }
        }

        $queueItem->save();

        // Immediately try to apply to attendance log
        $applyResult = $this->applyToAttendanceLog($queueItem, $companyId);

        return array_merge($applyResult, ['queue_id' => $queueItem->id]);
    }

    private function applyToAttendanceLog(OfflineAttendanceQueue $item, int $companyId): array
    {
        try {
            return DB::transaction(function () use ($item, $companyId) {

                $date       = $item->attendance_date->toDateString();
                $employeeId = (int) $item->employee_id;

                // Check for existing log
                $log = AttendanceDailyLog::forCompany($companyId)
                    ->forEmployee($employeeId)
                    ->whereDate('attendance_date', $date)
                    ->first();

                $actionType = $item->action_type;

                if ($log) {
                    // Update existing
                    if (in_array($actionType, ['check_in', 'full_day', 'check_in_out'])) {
                        if (!$log->check_in_time) {
                            $log->check_in_time = $item->device_captured_at ?? ($item->check_in_time ? Carbon::parse($date . ' ' . $item->check_in_time) : null);
                        }
                    }

                    if (in_array($actionType, ['check_out', 'full_day', 'check_in_out'])) {
                        // For checkout, we usually take the latest captured time
                        $log->check_out_time = $item->device_captured_at ?? ($item->check_out_time ? Carbon::parse($date . ' ' . $item->check_out_time) : null);
                    }

                    $log->source     = 'offline_sync';
                    $log->meta_data  = array_merge((array) ($log->meta_data ?? []), [
                        'offline_queue_id'   => $item->id,
                        'device_captured_at' => $item->device_captured_at?->toDateTimeString(),
                        'latitude'           => $item->latitude,
                        'longitude'          => $item->longitude,
                        'is_suspicious'      => $item->is_suspicious,
                        'synced_at'          => now()->toDateTimeString(),
                    ]);

                    $log->save();

                } else {
                    // Create new log
                    $log = AttendanceDailyLog::create([
                        'saas_company_id'    => $companyId,
                        'employee_id'        => $employeeId,
                        'attendance_date'    => $date,
                        'check_in_time'      => $item->device_captured_at ?? ($item->check_in_time ? Carbon::parse($date . ' ' . $item->check_in_time) : null),
                        'check_out_time'     => ($actionType === 'check_out') ? ($item->device_captured_at ?? ($item->check_out_time ? Carbon::parse($date . ' ' . $item->check_out_time) : null)) : null,
                        'attendance_status'  => 'present',
                        'approval_status'    => 'pending',
                        'source'             => 'offline_sync',
                        'is_edited'          => false,
                        'meta_data'          => [
                            'offline_queue_id'   => $item->id,
                            'device_captured_at' => $item->device_captured_at?->toDateTimeString(),
                            'latitude'           => $item->latitude,
                            'longitude'          => $item->longitude,
                            'device_platform'    => $item->device_platform,
                            'is_suspicious'      => $item->is_suspicious,
                            'synced_at'          => now()->toDateTimeString(),
                        ],
                    ]);
                }

                // Mark queue item as synced
                $item->update([
                    'sync_status'             => 'synced',
                    'synced_at'               => now(),
                    'synced_attendance_log_id' => $log->id,
                ]);

                return [
                    'ok'          => true,
                    'employee_id' => $item->employee_id,
                    'date'        => $date,
                    'message'     => tr('Attendance synced successfully.'),
                    'log_id'      => $log->id,
                ];
            });
        } catch (\Throwable $e) {
            Log::error('[OfflineAttendance] applyToAttendanceLog failed', [
                'queue_id' => $item->id,
                'error'    => $e->getMessage(),
            ]);

            $item->increment('retry_count');
            $item->update([
                'sync_status' => 'failed',
                'sync_error'  => $e->getMessage(),
            ]);

            return [
                'ok'          => false,
                'employee_id' => $item->employee_id,
                'date'        => $item->attendance_date?->toDateString(),
                'message'     => tr('Failed to apply attendance record.'),
            ];
        }
    }
}
