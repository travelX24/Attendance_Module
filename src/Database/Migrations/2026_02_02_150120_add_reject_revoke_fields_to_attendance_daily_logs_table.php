<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_daily_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('attendance_daily_logs', 'rejected_by')) {
                $table->unsignedBigInteger('rejected_by')->nullable()->index()->after('approved_by');
                $table->timestamp('rejected_at')->nullable()->after('approved_at');
                $table->text('rejection_notes')->nullable()->after('approval_notes');

                $table->unsignedBigInteger('revoked_by')->nullable()->index()->after('rejected_by');
                $table->timestamp('revoked_at')->nullable()->after('rejected_at');
                $table->text('revoke_reason')->nullable()->after('rejection_notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('attendance_daily_logs', function (Blueprint $table) {
            foreach (['rejected_by','rejected_at','rejection_notes','revoked_by','revoked_at','revoke_reason'] as $col) {
                if (Schema::hasColumn('attendance_daily_logs', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
