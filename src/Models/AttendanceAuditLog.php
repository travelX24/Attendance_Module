<?php

namespace Athka\Attendance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\User;

class AttendanceAuditLog extends Model
{
    protected $table = 'attendance_audit_logs';

    protected $fillable = [
        'saas_company_id',
        'actor_user_id',
        'employee_id',
        'action',
        'entity_type',
        'entity_id',
        'before_json',
        'after_json',
        'meta_json',
        'ip',
        'user_agent',
    ];

    protected $casts = [
        'before_json' => 'array',
        'after_json' => 'array',
        'meta_json' => 'array',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}


