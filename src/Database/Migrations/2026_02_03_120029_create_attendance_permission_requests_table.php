<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('attendance_request_actions')) return;

        Schema::create('attendance_request_actions', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('company_id')->index();
            $table->unsignedBigInteger('actor_user_id')->nullable()->index();
            $table->unsignedBigInteger('employee_id')->nullable()->index();

            $table->string('subject_type', 24)->index(); // leave|permission
            $table->unsignedBigInteger('subject_id')->index();

            $table->string('action', 32)->index(); // created|approved|rejected|cancelled|...
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['company_id', 'subject_type', 'subject_id'], 'ara_company_subject_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_request_actions');
    }
};
