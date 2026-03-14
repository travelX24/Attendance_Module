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
        Schema::table('attendance_leave_requests', function (Blueprint $table) {
            if (!Schema::hasColumn('attendance_leave_requests', 'is_exception')) {
                $table->boolean('is_exception')->default(false)->after('status');
            }
            if (!Schema::hasColumn('attendance_leave_requests', 'exception_status')) {
                $table->string('exception_status')->nullable()->after('is_exception');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('attendance_leave_requests', function (Blueprint $table) {
            if (Schema::hasColumn('attendance_leave_requests', 'is_exception')) {
                $table->dropColumn('is_exception');
            }
            if (Schema::hasColumn('attendance_leave_requests', 'exception_status')) {
                $table->dropColumn('exception_status');
            }
        });
    }
};
