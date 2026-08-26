<?php

namespace Athka\Attendance\Http\Controllers\Api;

use Illuminate\Routing\Controller;
use Athka\Attendance\Models\AttendanceDailyLog;
use Athka\Attendance\Models\OfflineAttendanceQueue;
use Athka\Employees\Models\Employee;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Athka\SystemSettings\Models\AttendanceGpsLocation;
use Athka\SystemSettings\Models\AttendanceMethod;
use Athka\SystemSettings\Models\EmployeeGroup;
use Athka\SystemSettings\Services\AttendanceLocationGateService;

class OfflineAttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceLocationGateService $locationGateService
    ) {
    }

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
            'records.*.attendance_method'      => ['nullable', 'in:gps,fingerprint,nfc'],
            'records.*.attendance_date'        => ['required', 'date'],
            'records.*.check_in_time'          => ['nullable', 'date_format:H:i'],
            'records.*.check_out_time'         => ['nullable', 'date_format:H:i'],
            'records.*.device_captured_at'     => ['nullable', 'date'],
            'records.*.device_timezone'        => ['nullable', 'string', 'max:64'],
            'records.*.latitude'               => ['nullable', 'numeric', 'between:-90,90'],
            'records.*.longitude'              => ['nullable', 'numeric', 'between:-180,180'],
            'records.*.gps_accuracy'           => ['nullable', 'numeric', 'min:0'],
            'records.*.is_mocked'              => ['nullable', 'boolean'],
            'records.*.local_id'               => ['nullable'],
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
                    'correlation_id' => $request->header('X-Correlation-ID'),
                    'rec'            => class_exists('\App\Support\LogSanitizer') ? \App\Support\LogSanitizer::clean($rec) : $rec,
                    'error'          => $e->getMessage(),
                ]);
                $results[] = [
                    'ok'           => false,
                    'employee_id'  => $rec['employee_id'] ?? null,
                    'date'         => $rec['attendance_date'] ?? null,
                    'code'         => 'offline_sync_processing_error',
                    'message'      => tr('An error occurred while processing this record.'),
                    'queue_id'     => null,
                    'local_id'     => $rec['local_id'] ?? null,
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
        $assignment = \Athka\Attendance\Models\EmployeeWorkSchedule::where('saas_company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->where('is_active', true)
            ->where('start_date', '<=', $date)
            ->where(function($q) use ($date) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', $date);
            })
            ->with(['workSchedule.periods'])
            ->first();

        // 1b. Check for Exceptions (Overrides everything)
        $exception = \Athka\Attendance\Models\EmployeeWorkScheduleException::where('employee_id', $employee->id)
            ->whereDate('exception_date', $date->toDateString())
            ->first();

        $schedule = null;
        if ($exception) {
            $typeLabel = match($exception->exception_type) {
                'off_day', 'day_off' => tr('Off Day'),
                'work_day' => tr('Work Day'),
                'overtime' => tr('Overtime'),
                default => tr('Exception'),
            };

            $periods = [];
            if ($exception->start_time && $exception->end_time) {
                $periods[] = [
                    'start' => substr($exception->start_time, 0, 5),
                    'end' => substr($exception->end_time, 0, 5),
                ];
            }

            $schedule = [
                'id' => $exception->id,
                'name' => $typeLabel,
                'is_exception' => true,
                'type' => $exception->exception_type,
                'periods' => $periods,
            ];
        } elseif ($assignment && $assignment->workSchedule) {
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

        // 2. Get the same effective Circle/Polygon locations used by
        // the online attendance endpoints.
        $locations = $this->locationGateService
            ->allowedLocationsForEmployee((int) $companyId, $employee)
            ->map(fn (AttendanceGpsLocation $location) => [
                'id' => (int) $location->id,
                'name' => (string) $location->name,
                'lat' => (float) $location->lat,
                'lng' => (float) $location->lng,
                'radius_meters' => (int) $location->radius_meters,
                'geofence_type' => (string) (
                    $location->geofence_type
                    ?: AttendanceGpsLocation::GEOFENCE_TYPE_CIRCLE
                ),
                'boundary_geojson' => $location->boundary_geojson,
                'address_text' => (string) ($location->address_text ?? ''),
                'country' => (string) ($location->country ?? ''),
                'city' => (string) ($location->city ?? ''),
                'region' => (string) ($location->region ?? ''),
            ])
            ->values();

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
                    'location_gate' => $this->locationGateService->settings(),
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
        $query = OfflineAttendanceQueue::forCompany($companyId)
            ->pending()
            ->with(['employee:id,name_ar,name_en']);

        if (!$this->canManageOfflineAttendance($user)) {
            $query->where('submitted_by_user_id', $user->id)
                ->where('employee_id', (int) ($user->employee_id ?? 0));
        }

        $rows = $query->latest()->paginate(30);

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
        $itemQuery = OfflineAttendanceQueue::forCompany($companyId);

        if (!$this->canManageOfflineAttendance($user)) {
            $itemQuery->where('submitted_by_user_id', $user->id)
                ->where('employee_id', (int) ($user->employee_id ?? 0));
        }

        $item = $itemQuery->findOrFail($id);

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

    private function canManageOfflineAttendance($user): bool
    {
        if (!$user || !method_exists($user, 'can')) {
            return false;
        }

        foreach (['attendance.daily.manage', 'attendance.logs.sync'] as $permission) {
            if ($user->can($permission)) {
                return true;
            }
        }

        return false;
    }

    private function canSyncOfflineRecordForEmployee($user, int $employeeId): bool
    {
        if ($this->canManageOfflineAttendance($user)) {
            return true;
        }

        return (int) ($user->employee_id ?? 0) === $employeeId;
    }
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
                'code'        => 'employee_not_found',
                'message'     => tr('Employee not found or does not belong to your company.'),
                'queue_id'    => null,
                'local_id'    => $rec['local_id'] ?? null,
            ];
        }

        
        if (!$this->canSyncOfflineRecordForEmployee($user, $employeeId)) {
            return [
                'ok'          => false,
                'employee_id' => $employeeId,
                'date'        => $rec['attendance_date'] ?? null,
                'code'        => 'offline_employee_not_allowed',
                'message'     => tr('You are not allowed to sync attendance for this employee.'),
                'queue_id'    => null,
                'local_id'    => $rec['local_id'] ?? null,
            ];
        }

        $rawClientReference = $rec['local_id'] ?? null;
        $clientReference = is_scalar($rawClientReference)
            ? mb_substr(trim((string) $rawClientReference), 0, 128)
            : '';

        if ($clientReference !== '') {
            $existingQueueItem = OfflineAttendanceQueue::query()
                ->where('saas_company_id', $companyId)
                ->where('client_reference', $clientReference)
                ->first();

            if ($existingQueueItem) {
                return $this->responseForExistingQueueItem(
                    $existingQueueItem,
                    $companyId,
                    $employeeId,
                    $clientReference
                );
            }

            // Check if record was already synced into daily log
            $existingLog = AttendanceDailyLog::where('saas_company_id', $companyId)
                ->where('employee_id', $employeeId)
                ->where('date', $rec['attendance_date'] ?? null)
                ->where('client_reference', $clientReference)
                ->first();

            if ($existingLog) {
                return [
                    'ok'          => true,
                    'code'        => 'already_synced',
                    'employee_id' => $employeeId,
                    'date'        => $rec['attendance_date'] ?? null,
                    'message'     => tr('Record has already been processed into daily logs.'),
                    'queue_id'    => null,
                    'local_id'    => $clientReference,
                ];
            }
        }

        $attendanceMethod = (string) (
            $rec['attendance_method']
            ?? 'gps'
        );

        if (! $this->attendanceMethodAllowed(
            $companyId,
            $employee,
            $attendanceMethod
        )) {
            return [
                'ok' => false,
                'code' => 'method_unavailable',
                'employee_id' => $employeeId,
                'date' => $rec['attendance_date'] ?? null,
                'message' => tr('This attendance method is not available.'),
                'queue_id' => null,
                'local_id' => $clientReference !== ''
                    ? $clientReference
                    : null,
            ];
        }

        $locationDecision = null;

        if ($attendanceMethod === 'gps') {
            $locationDecision = $this->locationGateService->evaluateOffline(
                companyId: $companyId,
                employee: $employee,
                payload: [
                    'lat' => $rec['latitude'] ?? null,
                    'lng' => $rec['longitude'] ?? null,
                    'gps_accuracy' => $rec['gps_accuracy'] ?? null,
                    'is_mocked' => $rec['is_mocked'] ?? false,
                    'location_captured_at' => $rec['device_captured_at'] ?? null,
                ],
            );

            if (! $locationDecision->allowed) {
                return array_merge(
                    $locationDecision->toResponseArray(),
                    [
                        'employee_id' => $employeeId,
                        'date' => $rec['attendance_date'] ?? null,
                        'queue_id' => null,
                        'local_id' => $rec['local_id'] ?? null,
                    ]
                );
            }
        }

        // Build payload for queue
        $payload = [
            'employee_id'        => $employeeId,
            'saas_company_id'    => $companyId,
            'submitted_by_user_id' => $user->id,
            'action_type'        => $rec['action_type'],
            'attendance_method'  => $attendanceMethod,
            'attendance_date'    => $rec['attendance_date'],
            'check_in_time'      => $rec['check_in_time'] ?? null,
            'check_out_time'     => $rec['check_out_time'] ?? null,
            'device_captured_at' => isset($rec['device_captured_at']) ? Carbon::parse($rec['device_captured_at']) : null,
            'device_timezone'    => $rec['device_timezone'] ?? null,
            'latitude'           => $rec['latitude'] ?? null,
            'longitude'          => $rec['longitude'] ?? null,
            'gps_accuracy'       => $rec['gps_accuracy'] ?? null,
            'is_mocked'          => (bool) ($rec['is_mocked'] ?? false),
            'location_gate_result' => $locationDecision?->toArray(),
            'client_reference'   => $clientReference !== ''
                ? $clientReference
                : null,
            'device_id'          => $rec['device_id'] ?? null,
            'device_platform'    => $rec['device_platform'] ?? 'web',
            'user_agent'         => request()->userAgent(),
            'integrity_hash'     => $rec['integrity_hash'] ?? null,
            'reason'             => $rec['reason'] ?? null,
            'sync_status'        => 'pending',
        ];

        try {
            $queueItem = OfflineAttendanceQueue::create($payload);
        } catch (QueryException $error) {
            if ($clientReference === '') {
                throw $error;
            }

            $existingQueueItem = OfflineAttendanceQueue::query()
                ->where('saas_company_id', $companyId)
                ->where('submitted_by_user_id', (int) $user->id)
                ->where('client_reference', $clientReference)
                ->first();

            if (! $existingQueueItem) {
                throw $error;
            }

            return $this->responseForExistingQueueItem(
                $existingQueueItem,
                $companyId,
                $employeeId,
                $clientReference
            );
        }

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

        return array_merge(
            $applyResult,
            [
                'queue_id' => $queueItem->id,
                'local_id' => $clientReference !== ''
                    ? $clientReference
                    : null,
            ]
        );
    }

    private function attendanceMethodAllowed(
        int $companyId,
        Employee $employee,
        string $method,
    ): bool {
        $globalEnabled = AttendanceMethod::query()
            ->where('saas_company_id', $companyId)
            ->where('method', $method)
            ->where('is_enabled', true)
            ->exists();

        if (! $globalEnabled) {
            return false;
        }

        $groupIds = EmployeeGroup::query()
            ->where('saas_company_id', $companyId)
            ->whereHas(
                'employees',
                fn ($query) => $query->where(
                    'employees.id',
                    (int) $employee->id
                )
            )
            ->pluck('id');

        if ($groupIds->isEmpty()) {
            return true;
        }

        return EmployeeGroup::query()
            ->whereIn('id', $groupIds)
            ->whereHas(
                'allowedMethods',
                fn ($query) => $query
                    ->where('method', $method)
                    ->where('is_allowed', true)
            )
            ->exists();
    }

    private function responseForExistingQueueItem(
        OfflineAttendanceQueue $item,
        int $companyId,
        int $employeeId,
        string $clientReference,
    ): array {
        if ($item->sync_status === 'synced') {
            return [
                'ok' => true,
                'code' => 'offline_record_already_synced',
                'employee_id' => $employeeId,
                'date' => $item->attendance_date?->toDateString(),
                'message' => tr('Attendance was already synced.'),
                'queue_id' => $item->id,
                'local_id' => $clientReference,
                'location_gate' => $item->location_gate_result,
            ];
        }

        if ($item->sync_status === 'rejected') {
            $gate = is_array($item->location_gate_result)
                ? $item->location_gate_result
                : [];

            return [
                'ok' => false,
                'code' => (string) (
                    $gate['code']
                    ?? 'offline_record_rejected'
                ),
                'employee_id' => $employeeId,
                'date' => $item->attendance_date?->toDateString(),
                'message' => (string) (
                    $item->sync_error
                    ?: tr('The offline attendance record was rejected.')
                ),
                'queue_id' => $item->id,
                'local_id' => $clientReference,
                'location_gate' => $gate ?: null,
            ];
        }

        $result = $this->applyToAttendanceLog($item, $companyId);

        return array_merge(
            $result,
            [
                'queue_id' => $item->id,
                'local_id' => $clientReference,
            ]
        );
    }

    private function applyToAttendanceLog(OfflineAttendanceQueue $item, int $companyId): array
    {
        $employee = Employee::query()
            ->where('saas_company_id', $companyId)
            ->find((int) $item->employee_id);

        if (! $employee) {
            $item->update([
                'sync_status' => 'rejected',
                'sync_error' => 'Employee not found.',
            ]);

            return [
                'ok' => false,
                'code' => 'employee_not_found',
                'employee_id' => $item->employee_id,
                'date' => $item->attendance_date?->toDateString(),
                'message' => tr('Employee not found.'),
            ];
        }

        $locationDecision = null;

        if (($item->attendance_method ?: 'gps') === 'gps') {
            $locationDecision = $this->locationGateService->evaluateOffline(
                companyId: $companyId,
                employee: $employee,
                payload: [
                    'lat' => $item->latitude,
                    'lng' => $item->longitude,
                    'gps_accuracy' => $item->gps_accuracy,
                    'is_mocked' => $item->is_mocked,
                    'location_captured_at' => $item->device_captured_at,
                ],
            );

            if (! $locationDecision->allowed) {
                $item->update([
                    'sync_status' => 'rejected',
                    'sync_error' => $locationDecision->message,
                    'location_gate_result' => $locationDecision->toArray(),
                ]);

                return array_merge(
                    $locationDecision->toResponseArray(),
                    [
                        'employee_id' => $item->employee_id,
                        'date' => $item->attendance_date?->toDateString(),
                    ]
                );
            }

            $item->update([
                'location_gate_result' => $locationDecision->toArray(),
            ]);
        }

        try {
            return DB::transaction(function () use (
                $item,
                $companyId,
                $locationDecision
            ) {

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
                        'gps_accuracy'        => $item->gps_accuracy,
                        'attendance_method'   => $item->attendance_method,
                        'location_gate'       => $locationDecision?->toArray(),
                        'is_suspicious'       => $item->is_suspicious,
                        'synced_at'          => now()->toDateTimeString(),
                    ]);

                    $log->save();

                } else {
                    if ($actionType === 'check_out') {
                        $item->update([
                            'sync_status' => 'rejected',
                            'sync_error' => tr(
                                'No attendance record found for today. Please register check-in first.'
                            ),
                        ]);

                        return [
                            'ok' => false,
                            'code' => 'no_check_in_record',
                            'employee_id' => $employeeId,
                            'date' => $date,
                            'message' => tr(
                                'No attendance record found for today. Please register check-in first.'
                            ),
                            'location_gate' => $locationDecision?->toArray(),
                        ];
                    }

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
                            'gps_accuracy'        => $item->gps_accuracy,
                            'attendance_method'   => $item->attendance_method,
                            'location_gate'       => $locationDecision?->toArray(),
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
                    'location_gate' => $locationDecision?->toArray(),
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
