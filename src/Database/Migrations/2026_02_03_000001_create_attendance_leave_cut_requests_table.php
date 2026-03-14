<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('attendance_leave_cut_requests')) return;

        Schema::create('attendance_leave_cut_requests', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('company_id')->index();

            $table->unsignedBigInteger('original_leave_request_id')->index();
            $table->unsignedBigInteger('employee_id')->index();

            $table->unsignedBigInteger('leave_policy_id')->index();
            $table->unsignedBigInteger('policy_year_id')->index();

            $table->date('original_start_date');
            $table->date('original_end_date');

            // آخر يوم يُحسب إجازة بعد القطع
            $table->date('cut_end_date');

            // الجزء اللي صار "مؤجل" (لو فيه)
            $table->date('postponed_start_date')->nullable();
            $table->date('postponed_end_date')->nullable();

            $table->text('reason')->nullable();

            $table->string('status', 24)->default('pending')->index(); // pending|approved|rejected|cancelled

            $table->unsignedBigInteger('requested_by')->nullable()->index();
            $table->timestamp('requested_at')->nullable();

            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->timestamp('approved_at')->nullable();

            $table->unsignedBigInteger('rejected_by')->nullable()->index();
            $table->timestamp('rejected_at')->nullable();
            $table->text('reject_reason')->nullable();

            // لو أنشأنا leave request جديد للجزء المؤجل
            $table->unsignedBigInteger('new_leave_request_id')->nullable()->index();

            $table->timestamps();

            $table->index(['company_id', 'employee_id', 'policy_year_id'], 'alcr_company_emp_year_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_leave_cut_requests');
    }
};
