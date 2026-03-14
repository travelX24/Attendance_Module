<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_audit_logs', function (Blueprint $box) {
            $box->id();

            $box->foreignId('saas_company_id')->index('aal_company_idx');
            $box->foreignId('actor_user_id')->nullable()->index('aal_actor_idx');
            $box->foreignId('employee_id')->nullable()->index('aal_emp_idx');

            // e.g. work_schedule.assigned | work_schedule.changed | exception.created | exception.updated | exception.deleted
            $box->string('action', 80)->index('aal_action_idx');

            // entity tracking
            $box->string('entity_type', 80)->nullable();
            $box->unsignedBigInteger('entity_id')->nullable();
            $box->index(['entity_type', 'entity_id'], 'aal_entity_idx');

            // snapshots
            $box->json('before_json')->nullable();
            $box->json('after_json')->nullable();
            $box->json('meta_json')->nullable();

            // request context (optional)
            $box->string('ip', 45)->nullable();
            $box->string('user_agent', 512)->nullable();

            $box->timestamps();
            $box->index(['saas_company_id', 'created_at'], 'aal_company_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_audit_logs');
    }
};
