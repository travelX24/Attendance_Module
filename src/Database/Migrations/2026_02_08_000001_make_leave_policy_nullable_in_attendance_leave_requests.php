<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('attendance_leave_requests')) return;

        // Drop foreign keys safely (default Laravel names) then make columns nullable then re-add.
        try { DB::statement('ALTER TABLE `attendance_leave_requests` DROP FOREIGN KEY `attendance_leave_requests_leave_policy_id_foreign`'); } catch (\Throwable $e) {}
        try { DB::statement('ALTER TABLE `attendance_leave_requests` DROP FOREIGN KEY `attendance_leave_requests_policy_year_id_foreign`'); } catch (\Throwable $e) {}

        Schema::table('attendance_leave_requests', function (Blueprint $table) {
            if (Schema::hasColumn('attendance_leave_requests', 'leave_policy_id')) {
                $table->unsignedBigInteger('leave_policy_id')->nullable()->change();
            }
            if (Schema::hasColumn('attendance_leave_requests', 'policy_year_id')) {
                $table->unsignedBigInteger('policy_year_id')->nullable()->change();
            }
        });

        // Re-add foreign keys (NULL on delete)
        Schema::table('attendance_leave_requests', function (Blueprint $table) {
            if (Schema::hasColumn('attendance_leave_requests', 'leave_policy_id')) {
                try {
                    $table->foreign('leave_policy_id')->references('id')->on('leave_policies')->nullOnDelete();
                } catch (\Throwable $e) {}
            }
            if (Schema::hasColumn('attendance_leave_requests', 'policy_year_id')) {
                try {
                    $table->foreign('policy_year_id')->references('id')->on('leave_policy_years')->nullOnDelete();
                } catch (\Throwable $e) {}
            }
        });
    }

    public function down(): void
    {
        // رجوع اختياري (غالباً لن تحتاجه)
    }
};
