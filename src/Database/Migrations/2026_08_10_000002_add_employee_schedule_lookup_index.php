<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_work_schedules') || $this->indexExists('emp_work_sched_emp_company_start_idx')) {
            return;
        }

        Schema::table('employee_work_schedules', function (Blueprint $table) {
            $table->index(['employee_id', 'saas_company_id', 'start_date', 'id'], 'emp_work_sched_emp_company_start_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('employee_work_schedules') || ! $this->indexExists('emp_work_sched_emp_company_start_idx')) {
            return;
        }

        Schema::table('employee_work_schedules', function (Blueprint $table) {
            $table->dropIndex('emp_work_sched_emp_company_start_idx');
        });
    }

    private function indexExists(string $index): bool
    {
        return collect(DB::select('SHOW INDEX FROM `employee_work_schedules` WHERE Key_name = ?', [$index]))->isNotEmpty();
    }
};
