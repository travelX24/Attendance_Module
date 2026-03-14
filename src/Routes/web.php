<?php

use Illuminate\Support\Facades\Route;
use Athka\Attendance\Http\Livewire\WorkSchedules\Index as WorkSchedulesIndex;
use Athka\Attendance\Http\Livewire\DailyAttendance\Index as DailyAttendanceIndex;
use Athka\Attendance\Http\Livewire\DailyPenalties\Index as DailyPenaltiesIndex;
use Athka\Attendance\Http\Livewire\LeavesPermissions\Index as LeavesPermissionsIndex;

Route::get('/work-schedules', WorkSchedulesIndex::class)->name('work-schedules.index');
Route::get('/daily-attendance', DailyAttendanceIndex::class)->name('daily-attendance.index');
Route::get('/daily-penalties', DailyPenaltiesIndex::class)->name('daily-penalties.index');
Route::get('/leaves-permissions', LeavesPermissionsIndex::class)->name('leaves-permissions.index');



