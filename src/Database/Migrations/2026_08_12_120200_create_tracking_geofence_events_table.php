<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_geofence_events', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('tracking_session_id');
            $table->unsignedBigInteger('saas_company_id');
            $table->unsignedBigInteger('employee_id');

            $table->string('status', 24)->default('open');
            $table->string('classification', 32)->default('work_exit');
            $table->boolean('is_counted')->default(true);
            $table->string('exclusion_reason', 50)->nullable();

            $table->unsignedBigInteger('exit_location_id')->nullable();
            $table->unsignedBigInteger('return_location_id')->nullable();

            $table->dateTime('exited_at', 3);
            $table->dateTime('returned_at', 3)->nullable();

            $table->decimal('exit_lat', 10, 7);
            $table->decimal('exit_lng', 10, 7);
            $table->decimal('return_lat', 10, 7)->nullable();
            $table->decimal('return_lng', 10, 7)->nullable();

            $table->decimal('exit_distance_to_boundary_meters', 12, 2)->nullable();
            $table->decimal('maximum_distance_to_boundary_meters', 12, 2)->default(0);
            $table->decimal('outside_route_distance_meters', 14, 2)->default(0);

            $table->unsignedInteger('outside_seconds')->default(0);
            $table->unsignedInteger('excluded_seconds')->default(0);
            $table->unsignedInteger('counted_outside_seconds')->default(0);

            $table->unsignedSmallInteger('exit_confirmation_points')->default(0);
            $table->unsignedSmallInteger('return_confirmation_points')->default(0);

            $table->dateTime('exit_notification_sent_at', 3)->nullable();
            $table->dateTime('return_notification_sent_at', 3)->nullable();

            $table->json('meta')->nullable();
            $table->timestamps(3);

            $table->index(
                ['saas_company_id', 'employee_id', 'exited_at'],
                'tracking_events_company_employee_exit_idx'
            );
            $table->index(
                ['tracking_session_id', 'status'],
                'tracking_events_session_status_idx'
            );
            $table->index(
                ['saas_company_id', 'status', 'exited_at'],
                'tracking_events_company_status_exit_idx'
            );
            $table->index(
                ['employee_id', 'classification', 'exited_at'],
                'tracking_events_employee_class_exit_idx'
            );

            $table->foreign('tracking_session_id')
                ->references('id')
                ->on('tracking_sessions')
                ->cascadeOnDelete();

            $table->foreign('saas_company_id')
                ->references('id')
                ->on('saas_companies')
                ->cascadeOnDelete();

            $table->foreign('employee_id')
                ->references('id')
                ->on('employees')
                ->cascadeOnDelete();

            $table->foreign('exit_location_id')
                ->references('id')
                ->on('attendance_gps_locations')
                ->nullOnDelete();

            $table->foreign('return_location_id')
                ->references('id')
                ->on('attendance_gps_locations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_geofence_events');
    }
};
