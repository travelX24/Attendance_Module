<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_work_schedule_exceptions', function (Blueprint $box) {
            $box->id();

            $box->foreignId('saas_company_id');
            $box->foreignId('employee_id');

            // Snapshot of assignment/schedule at time of creation (optional but helpful)
            $box->foreignId('employee_work_schedule_id')->nullable();
            $box->foreignId('work_schedule_id')->nullable();

            $box->date('exception_date');

            // time_override | day_off | work_day
            $box->string('exception_type');

            // Only for time_override
            $box->time('start_time')->nullable();
            $box->time('end_time')->nullable();

            // Future use (breaks UI can be added later)
            $box->json('breaks_json')->nullable();

            $box->text('notes')->nullable();
            $box->foreignId('created_by')->nullable();

            $box->timestamps();

            // One exception per employee per date (simplifies rules)
            $box->unique(['saas_company_id', 'employee_id', 'exception_date'], 'ux_emp_exc_company_emp_date');

            $box->index(['employee_id', 'exception_date'], 'idx_emp_exc_emp_date');
            $box->index(['saas_company_id', 'exception_date'], 'idx_emp_exc_company_date');

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_work_schedule_exceptions');
    }
};
