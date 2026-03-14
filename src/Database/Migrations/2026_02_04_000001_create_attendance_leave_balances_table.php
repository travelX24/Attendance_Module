<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('attendance_leave_balances')) return;

        Schema::create('attendance_leave_balances', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('employee_id')->index();

            $table->unsignedBigInteger('leave_policy_id')->index();
            $table->unsignedBigInteger('policy_year_id')->index();

            $table->decimal('entitled_days', 6, 2)->default(0);
            $table->decimal('taken_days', 6, 2)->default(0);
            $table->decimal('remaining_days', 6, 2)->default(0);

            $table->timestamp('last_recalculated_at')->nullable();

            $table->timestamps();

            // one balance per employee/policy/year/company
            $table->unique(
                ['company_id', 'employee_id', 'leave_policy_id', 'policy_year_id'],
                'ux_leave_bal_company_emp_policy_year'
            );

            $table->index(['company_id', 'policy_year_id'], 'idx_leave_bal_company_year');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_leave_balances');
    }
};
