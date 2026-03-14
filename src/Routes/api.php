<?php

use Illuminate\Support\Facades\Route;
use Athka\Attendance\Http\Controllers\Api\OfflineAttendanceController;

Route::post('sync', [OfflineAttendanceController::class, 'sync']);
Route::get('prep-data', [OfflineAttendanceController::class, 'getPrepData']);
Route::get('pending', [OfflineAttendanceController::class, 'pending']);
Route::post('{id}/sync-one', [OfflineAttendanceController::class, 'syncOne']);
