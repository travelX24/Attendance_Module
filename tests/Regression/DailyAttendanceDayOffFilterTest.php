<?php

$blade = file_get_contents(
    __DIR__ . '/../../src/Resources/views/livewire/daily-attendance/index.blade.php'
);

$filters = file_get_contents(
    __DIR__ . '/../../src/Http/Livewire/DailyAttendance/Traits/WithAttendanceFilters.php'
);

$checks = [
    'day off exists in status filter'
        => str_contains(
            $blade,
            "['value' => 'day_off', 'label' => tr('Day Off')]"
        ),

    'day off badge remains supported'
        => str_contains(
            $blade,
            "'day_off' => ['type' => 'default', 'label' => tr('Day Off')]"
        ),

    'attendance status backend filter remains generic'
        => str_contains(
            $filters,
            "where('attendance_status', \$this->attendance_status_filter)"
        ),
];

foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . " - {$label}" . PHP_EOL;

    if (! $passed) {
        exit(1);
    }
}

echo "DAILY ATTENDANCE DAY OFF FILTER REGRESSION: PASS" . PHP_EOL;
