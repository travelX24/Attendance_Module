<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();

            $table->unsignedBigInteger('saas_company_id');
            $table->unsignedBigInteger('employee_id');
            $table->unsignedBigInteger('attendance_daily_log_id')->nullable();
            $table->unsignedBigInteger('attendance_daily_detail_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('started_by_user_id')->nullable();

            $table->string('client_session_uuid', 100)->nullable();

            $table->string('status', 24)->default('active');
            $table->string('geofence_state', 24)->default('unknown');
            $table->dateTime('state_changed_at', 3)->nullable();

            $table->dateTime('started_at', 3);
            $table->dateTime('ended_at', 3)->nullable();

            $table->decimal('start_lat', 10, 7)->nullable();
            $table->decimal('start_lng', 10, 7)->nullable();
            $table->decimal('start_accuracy_meters', 8, 2)->nullable();

            $table->decimal('last_lat', 10, 7)->nullable();
            $table->decimal('last_lng', 10, 7)->nullable();
            $table->decimal('last_accuracy_meters', 8, 2)->nullable();
            $table->dateTime('last_recorded_at', 3)->nullable();

            $table->decimal('end_lat', 10, 7)->nullable();
            $table->decimal('end_lng', 10, 7)->nullable();
            $table->decimal('end_accuracy_meters', 8, 2)->nullable();

            $table->unsignedBigInteger('current_location_id')->nullable();

            $table->decimal('total_distance_meters', 14, 2)->default(0);
            $table->decimal('outside_distance_meters', 14, 2)->default(0);

            $table->unsignedInteger('accepted_points_count')->default(0);
            $table->unsignedInteger('rejected_points_count')->default(0);
            $table->unsignedSmallInteger('consecutive_outside_points')->default(0);
            $table->unsignedSmallInteger('consecutive_inside_points')->default(0);

            $table->string('device_uuid', 150)->nullable();
            $table->string('close_reason', 50)->nullable();
            $table->json('meta')->nullable();

            $table->timestamps(3);

            $table->index(
                ['saas_company_id', 'employee_id', 'status'],
                'tracking_sessions_company_employee_status_idx'
            );
            $table->index(
                ['employee_id', 'started_at'],
                'tracking_sessions_employee_started_idx'
            );
            $table->index(
                ['saas_company_id', 'last_recorded_at'],
                'tracking_sessions_company_last_recorded_idx'
            );
            $table->index(
                'attendance_daily_detail_id',
                'tracking_sessions_attendance_detail_idx'
            );

            $table->unique(
                ['saas_company_id', 'employee_id', 'client_session_uuid'],
                'tracking_sessions_client_session_unique'
            );

            $table->foreign('saas_company_id')
                ->references('id')
                ->on('saas_companies')
                ->cascadeOnDelete();

            $table->foreign('employee_id')
                ->references('id')
                ->on('employees')
                ->cascadeOnDelete();

            $table->foreign('attendance_daily_log_id')
                ->references('id')
                ->on('attendance_daily_logs')
                ->nullOnDelete();

            $table->foreign('attendance_daily_detail_id')
                ->references('id')
                ->on('attendance_daily_details')
                ->nullOnDelete();

            $table->foreign('branch_id')
                ->references('id')
                ->on('branches')
                ->nullOnDelete();

            $table->foreign('started_by_user_id')
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->foreign('current_location_id')
                ->references('id')
                ->on('attendance_gps_locations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_sessions');
    }
};
