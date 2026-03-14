<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_daily_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('daily_log_id');
            $table->time('check_in_time')->nullable();
            $table->time('check_out_time')->nullable();
            $table->string('attendance_status')->nullable()->default('present');
            $table->json('meta_data')->nullable(); // For source, location, etc.
            $table->timestamps();

            $table->foreign('daily_log_id')
                  ->references('id')
                  ->on('attendance_daily_logs')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_daily_details');
    }
};
