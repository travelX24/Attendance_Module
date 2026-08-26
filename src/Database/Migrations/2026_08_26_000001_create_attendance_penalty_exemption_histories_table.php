<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_penalty_exemption_histories', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('saas_company_id');
            $table->unsignedBigInteger('attendance_daily_penalty_id');
            $table->unsignedBigInteger('employee_id');
            $table->date('attendance_date');
            $table->string('violation_type')->nullable();
            $table->string('action')->default('applied');
            $table->string('status')->default('active');
            $table->string('exemption_type')->nullable();
            $table->decimal('exemption_amount', 12, 2)->default(0);
            $table->decimal('net_amount', 12, 2)->default(0);
            $table->text('reason')->nullable();
            $table->string('attachment_path')->nullable();
            $table->unsignedBigInteger('applied_by')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['saas_company_id', 'attendance_date'], 'pen_ex_hist_company_date_idx');
            $table->index(['attendance_daily_penalty_id', 'status'], 'pen_ex_hist_penalty_status_idx');
            $table->index(['employee_id', 'attendance_date'], 'pen_ex_hist_emp_date_idx');
            $table->index('action', 'pen_ex_hist_action_idx');

            $table->foreign('saas_company_id', 'pen_ex_hist_company_fk')
                ->references('id')
                ->on('saas_companies')
                ->onDelete('cascade');

            $table->foreign('attendance_daily_penalty_id', 'pen_ex_hist_penalty_fk')
                ->references('id')
                ->on('attendance_daily_penalties')
                ->onDelete('cascade');

            $table->foreign('employee_id', 'pen_ex_hist_emp_fk')
                ->references('id')
                ->on('employees')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_penalty_exemption_histories');
    }
};
