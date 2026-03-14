<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('attendance_leave_requests')) return;

        Schema::table('attendance_leave_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('attendance_leave_requests', 'duration_unit')) {
                $table->string('duration_unit', 16)->nullable()->after('end_date'); // full_day|half_day|hours
            }
            if (!Schema::hasColumn('attendance_leave_requests', 'half_day_part')) {
                $table->string('half_day_part', 16)->nullable()->after('duration_unit'); // first_half|second_half
            }
            if (!Schema::hasColumn('attendance_leave_requests', 'from_time')) {
                $table->string('from_time', 8)->nullable()->after('half_day_part'); // HH:ii
            }
            if (!Schema::hasColumn('attendance_leave_requests', 'to_time')) {
                $table->string('to_time', 8)->nullable()->after('from_time'); // HH:ii
            }
            if (!Schema::hasColumn('attendance_leave_requests', 'minutes')) {
                $table->unsignedInteger('minutes')->nullable()->after('to_time');
            }
            if (!Schema::hasColumn('attendance_leave_requests', 'attachment_path')) {
                $table->string('attachment_path', 1024)->nullable()->after('reason');
            }
            if (!Schema::hasColumn('attendance_leave_requests', 'attachment_name')) {
                $table->string('attachment_name', 255)->nullable()->after('attachment_path');
            }
            if (!Schema::hasColumn('attendance_leave_requests', 'note_ack')) {
                $table->boolean('note_ack')->default(false)->after('attachment_name');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('attendance_leave_requests')) return;

        Schema::table('attendance_leave_requests', function (Blueprint $table) {
            foreach ([
                'duration_unit','half_day_part','from_time','to_time','minutes',
                'attachment_path','attachment_name','note_ack'
            ] as $col) {
                if (Schema::hasColumn('attendance_leave_requests', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
