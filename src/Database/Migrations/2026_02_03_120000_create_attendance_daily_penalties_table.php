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
        Schema::create('attendance_daily_penalties', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('saas_company_id');
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('attendance_daily_log_id')->nullable();
            $table->date('attendance_date');
            
            // Violation Info
            $table->enum('violation_type', ['delay', 'early_departure', 'absent', 'auto_checkout', 'other']);
            $table->integer('violation_minutes')->default(0);
            
            // Calculation Policy
            $table->unsignedBigInteger('penalty_policy_id')->nullable();
            
            // Amounts
            $table->decimal('calculated_amount', 10, 2)->default(0.00);
            $table->decimal('exemption_amount', 10, 2)->default(0.00);
            $table->decimal('net_amount', 10, 2)->default(0.00);
            
            // Exemption Details
            $table->enum('exemption_type', ['none', 'partial', 'full'])->default('none');
            $table->enum('exemption_status', ['none', 'pending', 'approved', 'rejected'])->default('none');
            $table->string('exemption_reason')->nullable();
            $table->unsignedBigInteger('exempted_by')->nullable();
            $table->timestamp('exempted_at')->nullable();
            
            // Status & Confirmation
            $table->enum('status', ['pending', 'confirmed', 'waived'])->default('pending');
            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            
            $table->text('notes')->nullable();
            
            $table->timestamps();
            
            // Indexes
            $table->index('saas_company_id');
            $table->index('employee_id');
            $table->index('attendance_date');
            $table->index('status');
            
            // Foreign Keys
            $table->foreign('saas_company_id')
                ->references('id')
                ->on('saas_companies')
                ->onDelete('cascade');
                
            $table->foreign('employee_id')
                ->references('id')
                ->on('employees')
                ->onDelete('cascade');
                
            $table->foreign('attendance_daily_log_id')
                ->references('id')
                ->on('attendance_daily_logs')
                ->onDelete('set null');
                
            $table->foreign('penalty_policy_id')
                ->references('id')
                ->on('attendance_penalty_policies')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_daily_penalties');
    }
};
