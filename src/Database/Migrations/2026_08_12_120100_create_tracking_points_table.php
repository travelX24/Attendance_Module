<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_points', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('tracking_session_id');
            $table->unsignedBigInteger('saas_company_id');
            $table->unsignedBigInteger('employee_id');

            $table->string('client_point_uuid', 100);
            $table->unsignedInteger('sequence_number')->nullable();

            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->decimal('accuracy_meters', 8, 2);
            $table->decimal('speed_mps', 9, 3)->nullable();
            $table->decimal('heading_degrees', 6, 2)->nullable();
            $table->decimal('altitude_meters', 10, 2)->nullable();

            $table->dateTime('recorded_at', 3);
            $table->dateTime('received_at', 3);

            $table->boolean('is_mocked')->default(false);
            $table->string('provider', 30)->nullable();
            $table->unsignedTinyInteger('battery_level')->nullable();

            $table->boolean('is_accepted')->default(false);
            $table->boolean('is_counted_for_distance')->default(false);
            $table->string('rejection_reason', 64)->nullable();
            $table->string('work_state', 32)->default('unknown');

            $table->decimal('distance_from_previous_meters', 12, 2)->nullable();

            $table->unsignedBigInteger('matched_location_id')->nullable();
            $table->boolean('inside_allowed_geofence')->nullable();
            $table->decimal('distance_to_boundary_meters', 12, 2)->nullable();

            $table->json('meta')->nullable();
            $table->timestamps(3);

            $table->unique(
                ['tracking_session_id', 'client_point_uuid'],
                'tracking_points_session_client_unique'
            );

            $table->index(
                ['tracking_session_id', 'recorded_at'],
                'tracking_points_session_recorded_idx'
            );
            $table->index(
                ['saas_company_id', 'employee_id', 'recorded_at'],
                'tracking_points_company_employee_recorded_idx'
            );
            $table->index(
                ['tracking_session_id', 'is_accepted'],
                'tracking_points_session_accepted_idx'
            );
            $table->index(
                ['employee_id', 'recorded_at'],
                'tracking_points_employee_recorded_idx'
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

            $table->foreign('matched_location_id')
                ->references('id')
                ->on('attendance_gps_locations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_points');
    }
};
