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
        Schema::create('attendance_mission_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('employee_id');
            
            $table->string('type')->default('full_day'); // full_day, partial
            $table->date('start_date');
            $table->date('end_date')->nullable();
            
            $table->time('from_time')->nullable();
            $table->time('to_time')->nullable();
            
            $table->string('destination')->nullable();
            $table->text('reason')->nullable();
            
            $table->string('status')->default('pending'); // pending, approved, rejected
            
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->timestamp('requested_at')->nullable();
            
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            
            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->text('reject_reason')->nullable();
            
            $table->timestamps();

            // Foreign keys if necessary or just indexes
            $table->index(['company_id', 'employee_id']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_mission_requests');
    }
};
