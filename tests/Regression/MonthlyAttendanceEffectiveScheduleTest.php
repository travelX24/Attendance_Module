<?php

$root = dirname(__DIR__, 2);

$php = $root . '/src/Http/Livewire/DailyAttendance/Traits/WithAttendanceEdits.php';
$view = $root . '/src/Resources/views/livewire/daily-attendance/modals/monthly-edit-modal.blade.php';

$failures = [];

function check(bool $condition, string $label): void
{
    global $failures;

    if ($condition) {
        echo "PASS: {$label}" . PHP_EOL;
        return;
    }

    echo "FAIL: {$label}" . PHP_EOL;
    $failures[] = $label;
}

$source = file_get_contents($php);
$blade = file_get_contents($view);

check(
    ! str_contains($source, '$isWeekend'),
    'NO HARDCODED WEEKEND VARIABLE'
);

check(
    ! preg_match('/Carbon::FRIDAY.*Carbon::SATURDAY/s', $source),
    'NO FIXED FRIDAY SATURDAY RULE'
);

check(
    str_contains($source, 'getEffectiveSchedule('),
    'USES EMPLOYEE EFFECTIVE SCHEDULE'
);

check(
    str_contains($source, 'getEmployeeRequests('),
    'USES APPROVED EMPLOYEE REQUESTS'
);

check(
    str_contains($source, 'getMetricsForDate('),
    'USES WORK SCHEDULE METRICS'
);

check(
    str_contains($source, "\$effectiveStatus === 'off'"),
    'DAY OFF COMES FROM EFFECTIVE STATUS'
);

check(
    str_contains($source, "'on_leave'"),
    'LEAVE STATUS SUPPORTED'
);

check(
    str_contains($source, "'mission'"),
    'MISSION STATUS SUPPORTED'
);

check(
    str_contains($source, "'holiday'"),
    'HOLIDAY STATUS SUPPORTED'
);

check(
    str_contains($source, "'permissions'") ||
    str_contains($source, 'getEmployeeRequests('),
    'PERMISSIONS FLOW SUPPORTED'
);

check(
    ! str_contains($source, "'is_weekend'"),
    'OLD WEEKEND PAYLOAD REMOVED'
);

check(
    str_contains($source, "'is_day_off' => \$isScheduleDayOff"),
    'MONTHLY PAYLOAD USES SCHEDULE DAY OFF'
);

check(
    ! str_contains($blade, 'is_weekend'),
    'MONTHLY VIEW DOES NOT USE WEEKDAY WEEKEND FLAG'
);

check(
    str_contains($blade, 'is_day_off'),
    'MONTHLY VIEW USES EFFECTIVE DAY OFF FLAG'
);

if ($failures !== []) {
    echo PHP_EOL . 'FAILED: ' . count($failures) . PHP_EOL;
    exit(1);
}

echo PHP_EOL . 'MONTHLY ATTENDANCE EFFECTIVE SCHEDULE REGRESSION: PASS' . PHP_EOL;
