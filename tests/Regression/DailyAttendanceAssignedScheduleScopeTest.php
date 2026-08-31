<?php

$filters = file_get_contents(
    __DIR__ . '/../../src/Http/Livewire/DailyAttendance/Traits/WithAttendanceFilters.php'
);

$index = file_get_contents(
    __DIR__ . '/../../src/Http/Livewire/DailyAttendance/Index.php'
);

$checks = [
    'summary requires assigned schedule'
        => str_contains($filters, "whereColumn('scheduled_ews.employee_id', 'employees.id')"),

    'summary schedule overlaps selected range'
        => str_contains($filters, "whereDate('scheduled_ews.start_date', '<=', \$summaryScheduleEnd)")
        && str_contains($filters, "orWhereDate('scheduled_ews.end_date', '>=', \$summaryScheduleStart)"),

    'daily log requires schedule for its own date'
        => str_contains($filters, "whereColumn('scheduled_ews.start_date', '<=', 'attendance_daily_logs.attendance_date')")
        && str_contains($filters, "orWhereColumn('scheduled_ews.end_date', '>=', 'attendance_daily_logs.attendance_date')"),

    'stats require assigned schedule'
        => substr_count(
            $index,
            "whereColumn('scheduled_ews.employee_id', 'attendance_daily_logs.employee_id')"
        ) >= 2,

    'historical assignments are not incorrectly restricted by is_active'
        => ! str_contains(
            $filters,
            "scheduled_ews.is_active"
        )
        && ! str_contains(
            $index,
            "scheduled_ews.is_active"
        ),
];

foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . " - {$label}" . PHP_EOL;

    if (! $passed) {
        exit(1);
    }
}

echo "DAILY ATTENDANCE ASSIGNED SCHEDULE SCOPE REGRESSION: PASS" . PHP_EOL;
