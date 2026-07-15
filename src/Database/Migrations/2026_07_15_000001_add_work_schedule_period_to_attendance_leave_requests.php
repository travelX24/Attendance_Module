<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('attendance_leave_requests')) {
            return;
        }

        Schema::table('attendance_leave_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('attendance_leave_requests', 'work_schedule_period_id')) {
                $table->unsignedBigInteger('work_schedule_period_id')->nullable()->after('minutes')->index();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('attendance_leave_requests')) {
            return;
        }

        Schema::table('attendance_leave_requests', function (Blueprint $table) {
            if (Schema::hasColumn('attendance_leave_requests', 'work_schedule_period_id')) {
                $table->dropColumn('work_schedule_period_id');
            }
        });
    }
};
