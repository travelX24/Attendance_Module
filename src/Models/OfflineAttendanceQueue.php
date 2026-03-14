<?php

namespace Athka\Attendance\Models;

use Athka\Employees\Models\Employee;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfflineAttendanceQueue extends Model
{
    protected $table = 'offline_attendance_queue';

    protected $fillable = [
        'employee_id',
        'saas_company_id',
        'submitted_by_user_id',
        'action_type',
        'attendance_date',
        'check_in_time',
        'check_out_time',
        'device_captured_at',
        'device_timezone',
        'latitude',
        'longitude',
        'gps_accuracy',
        'device_id',
        'device_platform',
        'user_agent',
        'integrity_hash',
        'reason',
        'sync_status',
        'synced_attendance_log_id',
        'synced_at',
        'sync_error',
        'retry_count',
        'is_suspicious',
        'suspicion_reason',
    ];

    protected $casts = [
        'attendance_date'    => 'date',
        'device_captured_at' => 'datetime',
        'synced_at'          => 'datetime',
        'latitude'           => 'float',
        'longitude'          => 'float',
        'is_suspicious'      => 'boolean',
        'retry_count'        => 'integer',
    ];

    // ====== Relationships ======

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_user_id');
    }

    public function syncedLog(): BelongsTo
    {
        return $this->belongsTo(AttendanceDailyLog::class, 'synced_attendance_log_id');
    }

    // ====== Scopes ======

    public function scopePending($query)
    {
        return $query->where('sync_status', 'pending');
    }

    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('saas_company_id', $companyId);
    }

    public function scopeForEmployee($query, int $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }

    // ====== Integrity Check ======

    /**
     * Generate an HMAC hash to prevent client-side tampering.
     */
    public static function generateIntegrityHash(array $data): string
    {
        $secret = config('app.key');
        $payload = implode('|', [
            $data['employee_id']       ?? '',
            $data['attendance_date']   ?? '',
            $data['check_in_time']     ?? '',
            $data['check_out_time']    ?? '',
            $data['device_captured_at'] ?? '',
        ]);

        return hash_hmac('sha256', $payload, $secret);
    }

    public function verifyIntegrity(array $data): bool
    {
        $expected = self::generateIntegrityHash($data);
        return hash_equals($expected, (string) $this->integrity_hash);
    }

    // ====== Tamper Detection ======

    /**
     * Detect if device_captured_at is suspiciously far from created_at.
     * If more than 30 minutes difference â†’ flag as suspicious.
     */
    public function detectTampering(): void
    {
        if (!$this->device_captured_at) return;

        $diff = abs($this->created_at->diffInMinutes($this->device_captured_at));

        if ($diff > 180) { // more than 3 hours difference
            $this->is_suspicious = true;
            $this->suspicion_reason = "Device time differs from server time by {$diff} minutes.";
        }
    }

    // ====== Status Labels ======

    public function getSyncStatusLabelAttribute(): string
    {
        return match ($this->sync_status) {
            'pending'  => tr('Pending Sync'),
            'synced'   => tr('Synced'),
            'failed'   => tr('Sync Failed'),
            'rejected' => tr('Rejected'),
            default    => $this->sync_status,
        };
    }

    public function getSyncStatusColorAttribute(): string
    {
        return match ($this->sync_status) {
            'pending'  => 'yellow',
            'synced'   => 'green',
            'failed'   => 'red',
            'rejected' => 'red',
            default    => 'gray',
        };
    }
}


