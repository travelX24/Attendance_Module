<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('attendance_daily_penalties')) {
            Schema::table('attendance_daily_penalties', function (Blueprint $table) {
                // Add unique composite constraint if not present
                $table->unique(
                    ['saas_company_id', 'employee_id', 'attendance_date', 'violation_type'],
                    'unique_daily_penalty_record'
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('attendance_daily_penalties')) {
            Schema::table('attendance_daily_penalties', function (Blueprint $table) {
                $table->dropUnique('unique_daily_penalty_record');
            });
        }
    }
};
