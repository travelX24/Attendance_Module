<?php

namespace Athka\Attendance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceDailyDetail extends Model
{
    protected $table = 'attendance_daily_details';

    protected $fillable = [
        'daily_log_id',
        'check_in_time',
        'check_out_time',
        'attendance_status',
        'meta_data',
    ];

    protected $casts = [
        'meta_data' => 'array',
    ];

    public function dailyLog(): BelongsTo
    {
        return $this->belongsTo(AttendanceDailyLog::class, 'daily_log_id');
    }
}


