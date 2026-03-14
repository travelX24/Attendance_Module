<?php

namespace Athka\Attendance;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class AttendanceServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // 1. Load Views
        $this->loadViewsFrom(__DIR__ . '/Resources/views', 'attendance');

        // 2. Load Migrations
        $this->loadMigrationsFrom(__DIR__ . '/Database/Migrations');

        $this->registerWebRoutes();
        $this->registerApiRoutes();
    }

    protected function registerWebRoutes(): void
    {
        Route::middleware([
            'web',
            'auth',
            \Athka\Saas\Http\Middleware\EnsureCompanyAdmin::class,
            \Athka\Saas\Http\Middleware\ForceCompanyDomain::class,
            'company.domain',
            \Athka\Saas\Http\Middleware\SetCompanyTimezone::class,
        ])
            ->prefix('attendance')
            ->name('company-admin.attendance.')
            ->group(__DIR__ . '/Routes/web.php');
    }

    protected function registerApiRoutes(): void
    {
        if (file_exists(__DIR__ . '/Routes/api.php')) {
            Route::middleware(['api', 'auth:sanctum'])
                ->prefix('api/offline-attendance')
                ->group(__DIR__ . '/Routes/api.php');
        }
    }

        // 3. Register Livewire Components
        Livewire::component('attendance.work-schedules.index', \Athka\Attendance\Http\Livewire\WorkSchedules\Index::class);
        Livewire::component('attendance.daily-attendance.index', \Athka\Attendance\Http\Livewire\DailyAttendance\Index::class);
        Livewire::component('attendance.daily-penalties.index', \Athka\Attendance\Http\Livewire\DailyPenalties\Index::class);
        Livewire::component('attendance.leaves-permissions.index', \Athka\Attendance\Http\Livewire\LeavesPermissions\Index::class);

        // 4. Register Observers
        if (class_exists(\Athka\Employees\Models\Employee::class)) {
            \Athka\Employees\Models\Employee::observe(\Athka\Attendance\Observers\EmployeeObserver::class);
        }
        
        if (class_exists(\Athka\Attendance\Models\AttendanceMissionRequest::class)) {
            \Athka\Attendance\Models\AttendanceMissionRequest::observe(\Athka\Attendance\Observers\AttendanceMissionRequestObserver::class);
        }
    }

    public function register(): void
    {
        //
    }
}


