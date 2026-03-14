<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('employee_shift_rotations', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('saas_company_id');
            $table->unsignedBigInteger('employee_id');

            // schedule A and schedule B
            $table->unsignedBigInteger('work_schedule_id_a');
            $table->unsignedBigInteger('work_schedule_id_b');

            $table->date('start_date');
            $table->date('end_date')->nullable(); // rotation overall end (optional)
            $table->unsignedSmallInteger('rotation_days'); // days per cycle
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['saas_company_id', 'employee_id', 'is_active'], 'idx_esr_comp_emp_act');
            $table->index(['saas_company_id', 'is_active'], 'idx_esr_comp_act');
            $table->index(['start_date', 'end_date'], 'idx_esr_dates');


            // FKs (adjust if your table names differ)
            $table->foreign('employee_id')->references('id')->on('employees')->cascadeOnDelete();
            $table->foreign('work_schedule_id_a')->references('id')->on('work_schedules')->cascadeOnDelete();
            $table->foreign('work_schedule_id_b')->references('id')->on('work_schedules')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_shift_rotations');
    }
};
