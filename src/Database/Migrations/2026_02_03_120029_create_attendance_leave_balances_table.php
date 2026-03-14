<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('attendance_permission_requests')) return;

        Schema::create('attendance_permission_requests', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('employee_id')->index();

            $table->date('permission_date')->index();
            $table->time('from_time')->nullable();
            $table->time('to_time')->nullable();

            $table->unsignedInteger('minutes')->default(0);
            $table->text('reason')->nullable();

            $table->string('source', 16)->default('hr')->index(); // hr|app
            $table->string('status', 24)->default('pending')->index();
            // pending|approved|rejected|cancelled

            $table->unsignedBigInteger('requested_by')->nullable()->index();
            $table->timestamp('requested_at')->nullable();

            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->timestamp('approved_at')->nullable();

            $table->unsignedBigInteger('rejected_by')->nullable()->index();
            $table->timestamp('rejected_at')->nullable();
            $table->text('reject_reason')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'employee_id', 'permission_date'], 'apr_company_emp_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_permission_requests');
    }
};
