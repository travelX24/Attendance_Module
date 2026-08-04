<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('offline_attendance_queue')) {
            return;
        }

        Schema::table('offline_attendance_queue', function (Blueprint $table): void {
            if (! Schema::hasColumn('offline_attendance_queue', 'attendance_method')) {
                $table->string('attendance_method', 20)
                    ->default('gps')
                    ->after('action_type');
            }

            if (! Schema::hasColumn('offline_attendance_queue', 'is_mocked')) {
                $table->boolean('is_mocked')
                    ->default(false)
                    ->after('gps_accuracy');
            }

            if (! Schema::hasColumn('offline_attendance_queue', 'location_gate_result')) {
                $table->json('location_gate_result')
                    ->nullable()
                    ->after('is_mocked');
            }

            if (! Schema::hasColumn('offline_attendance_queue', 'client_reference')) {
                $table->string('client_reference', 128)
                    ->nullable()
                    ->after('location_gate_result');
            }
        });

        Schema::table('offline_attendance_queue', function (Blueprint $table): void {
            $table->unique(
                [
                    'saas_company_id',
                    'submitted_by_user_id',
                    'client_reference',
                ],
                'offline_attendance_client_reference_unique'
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('offline_attendance_queue')) {
            return;
        }

        try {
            Schema::table('offline_attendance_queue', function (Blueprint $table): void {
                $table->dropUnique('offline_attendance_client_reference_unique');
            });
        } catch (\Throwable) {
            // The index may not exist.
        }

        $columns = collect([
            'attendance_method',
            'is_mocked',
            'location_gate_result',
            'client_reference',
        ])->filter(
            fn (string $column): bool => Schema::hasColumn(
                'offline_attendance_queue',
                $column
            )
        )->all();

        if ($columns === []) {
            return;
        }

        Schema::table(
            'offline_attendance_queue',
            fn (Blueprint $table) => $table->dropColumn($columns)
        );
    }
};
