<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('attendance_leave_requests')) return;

        Schema::create('attendance_leave_requests', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('employee_id')->index();

            // From SystemSettings (leave_policies / leave_policy_years)
            $table->unsignedBigInteger('leave_policy_id')->index();
            $table->unsignedBigInteger('policy_year_id')->index();

            $table->date('start_date')->index();
            $table->date('end_date')->index();

            // computed at create time (inclusive days)
            $table->decimal('requested_days', 6, 2)->default(0);

            $table->text('reason')->nullable();
            $table->string('source', 16)->default('hr')->index(); // hr|app

            $table->string('status', 24)->default('pending')->index();
            // pending|approved|rejected|cancelled

            $table->unsignedBigInteger('requested_by')->nullable()->index();
            $table->timestamp('requested_at')->nullable();

            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->timestamp('approved_at')->nullable();

            $table->unsignedBigInteger('rejected_by')->nullable()->index();
            $table->timestamp('rejected_at')->nullable();
            $table->text('reject_reason')->nullable();

            // if salary processed, block cancel (later integration)
            $table->timestamp('salary_processed_at')->nullable()->index();

            $table->timestamps();

            $table->index(['company_id', 'employee_id', 'policy_year_id'], 'alr_company_emp_year_idx');
            $table->index(['company_id', 'leave_policy_id', 'policy_year_id'], 'alr_company_policy_year_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_leave_requests');
    }
};
