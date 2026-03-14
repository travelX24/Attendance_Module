<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('employee_work_schedules', function (Blueprint $box) {
            $box->id();
            $box->foreignId('saas_company_id');
            $box->foreignId('employee_id');
            $box->foreignId('work_schedule_id');
            
            $box->date('start_date');
            $box->date('end_date')->nullable();
            
            $box->boolean('is_active')->default(true);
            $box->string('assignment_type')->default('individual'); // individual, bulk, default
            $box->text('notes')->nullable();
            
            $box->timestamps();

            // Indexing for performance
            $box->index(['employee_id', 'is_active']);
            $box->index(['saas_company_id', 'employee_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_work_schedules');
    }
};
