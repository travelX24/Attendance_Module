<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_daily_details', function (Blueprint $table) {
            $table->unsignedBigInteger('work_schedule_period_id')->nullable()->after('daily_log_id');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_daily_details', function (Blueprint $table) {
            $table->dropColumn('work_schedule_period_id');
        });
    }
};
