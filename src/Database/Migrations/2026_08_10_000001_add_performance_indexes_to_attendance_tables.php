<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndexIfMissing('attendance_daily_penalties', 'att_penalties_company_date_status_idx', ['saas_company_id', 'attendance_date', 'status']);
        $this->addIndexIfMissing('attendance_leave_requests', 'att_leave_company_status_id_idx', ['company_id', 'status', 'id']);
        $this->addIndexIfMissing('attendance_permission_requests', 'att_perm_company_status_id_idx', ['company_id', 'status', 'id']);
        $this->addIndexIfMissing('attendance_mission_requests', 'att_mission_company_status_id_idx', ['company_id', 'status', 'id']);
        $this->addIndexIfMissing('attendance_leave_cut_requests', 'att_cut_company_status_id_idx', ['company_id', 'status', 'id']);
        $this->addIndexIfMissing('attendance_request_actions', 'att_actions_company_id_idx', ['company_id', 'id']);
        $this->addIndexIfMissing('employee_work_schedules', 'emp_work_sched_company_emp_dates_idx', ['saas_company_id', 'employee_id', 'start_date', 'end_date', 'id']);
    }

    public function down(): void
    {
        $this->dropIndexIfExists('employee_work_schedules', 'emp_work_sched_company_emp_dates_idx');
        $this->dropIndexIfExists('attendance_request_actions', 'att_actions_company_id_idx');
        $this->dropIndexIfExists('attendance_leave_cut_requests', 'att_cut_company_status_id_idx');
        $this->dropIndexIfExists('attendance_mission_requests', 'att_mission_company_status_id_idx');
        $this->dropIndexIfExists('attendance_permission_requests', 'att_perm_company_status_id_idx');
        $this->dropIndexIfExists('attendance_leave_requests', 'att_leave_company_status_id_idx');
        $this->dropIndexIfExists('attendance_daily_penalties', 'att_penalties_company_date_status_idx');
    }

    private function addIndexIfMissing(string $table, string $index, array $columns): void
    {
        if (! Schema::hasTable($table) || $this->indexExists($table, $index)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($columns, $index) {
            $blueprint->index($columns, $index);
        });
    }

    private function dropIndexIfExists(string $table, string $index): void
    {
        if (! Schema::hasTable($table) || ! $this->indexExists($table, $index)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) use ($index) {
            $blueprint->dropIndex($index);
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        return collect(DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$index]))->isNotEmpty();
    }
};
