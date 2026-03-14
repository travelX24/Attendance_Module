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
        Schema::create('attendance_daily_logs', function (Blueprint $table) {
            $table->id();
            
            // Company & Employee
            $table->unsignedBigInteger('saas_company_id');
            $table->unsignedBigInteger('employee_id');
            $table->date('attendance_date');
            
            // Work Schedule Info
            $table->unsignedBigInteger('work_schedule_id')->nullable();
            $table->decimal('scheduled_hours', 5, 2)->nullable(); //ساعات العمل المجدولة
            
            // Actual Check In/Out Times
            $table->time('check_in_time')->nullable(); // وقت الدخول الفعلي
            $table->time('check_out_time')->nullable(); // وقت الخروج الفعلي
            $table->decimal('actual_hours', 5, 2)->nullable(); // ساعات العمل الفعلية
            
            // Scheduled Times (من الجدول)
            $table->time('scheduled_check_in')->nullable();
            $table->time('scheduled_check_out')->nullable();
            
            // Status & Compliance
            $table->enum('attendance_status', [
                'present',           // حاضر
                'late',              // متأخر
                'early_departure',   // انصراف مبكر
                'absent',            // غائب
                'on_leave',          // في إجازة
                'auto_checkout',     // انصراف تلقائي
                'day_off',           // يوم راحة
            ])->default('absent');
            
            // Approval Status
            $table->enum('approval_status', [
                'pending',           // قيد الانتظار
                'approved',          // معتمد للرواتب
                'rejected',          // مرفوض
            ])->default('pending');
            
            // Compliance Percentage (نسبة الالتزام)
            $table->decimal('compliance_percentage', 5, 2)->nullable();
            
            // Edit History
            $table->boolean('is_edited')->default(false);
            $table->unsignedBigInteger('edited_by')->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->string('edit_reason')->nullable();
            $table->text('edit_notes')->nullable();
            
            // Approval History
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('approval_notes')->nullable();
            
            // Source of Data
            $table->enum('source', [
                'automatic',         // تلقائي من الأجهزة
                'manual',            // يدوي
                'imported',          // مستورد
            ])->default('automatic');
            
            // Additional Info
            $table->json('check_attempts')->nullable(); // محاولات التحضير الخام
            $table->json('meta_data')->nullable(); // بيانات إضافية
            
            $table->timestamps();
            
            // Indexes
            $table->index('saas_company_id');
            $table->index('employee_id');
            $table->index('attendance_date');
            $table->index(['saas_company_id', 'attendance_date']);
            $table->index(['employee_id', 'attendance_date']);
            $table->index('attendance_status');
            $table->index('approval_status');
            
            // Unique constraint: one record per employee per day
            $table->unique(['saas_company_id', 'employee_id', 'attendance_date'], 'unique_employee_daily_attendance');
            
            // Foreign Keys
            $table->foreign('saas_company_id')
                ->references('id')
                ->on('saas_companies')
                ->onDelete('cascade');
                
            $table->foreign('employee_id')
                ->references('id')
                ->on('employees')
                ->onDelete('cascade');
                
            $table->foreign('work_schedule_id')
                ->references('id')
                ->on('work_schedules')
                ->onDelete('set null');
                
            $table->foreign('edited_by')
                ->references('id')
                ->on('users')
                ->onDelete('set null');
                
            $table->foreign('approved_by')
                ->references('id')
                ->on('users')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_daily_logs');
    }
};
