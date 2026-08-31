<?php

require_once dirname(__DIR__, 3) . '/HrWithModules/vendor/autoload.php';
require_once __DIR__ . '/../../src/Models/AttendanceDailyLog.php';

use Athka\Attendance\Models\AttendanceDailyLog;

function makeLog(array $attributes, array $periods = []): AttendanceDailyLog
{
    $log = new AttendanceDailyLog();
    $log->setDateFormat('Y-m-d H:i:s');
    $log->setRawAttributes($attributes, true);
    $log->setRelation('details', collect());

    if ($periods) {
        $log->tempMetrics = ['periods' => $periods];
    }

    return $log;
}

function check(string $label, bool $passed, $actual): void
{
    echo ($passed ? 'PASS' : 'FAIL')
        . " - {$label} [actual={$actual}]"
        . PHP_EOL;

    if (! $passed) {
        exit(1);
    }
}

/* absent */
$log = makeLog([
    'attendance_status' => 'absent',
    'scheduled_hours' => 8,
]);

$log->calculateCompliance();
check('absent = 0%', (float)$log->compliance_percentage === 0.0, $log->compliance_percentage);

/* day off */
$log = makeLog([
    'attendance_status' => 'day_off',
    'scheduled_hours' => 0,
]);

$log->calculateCompliance();
check('day off = 0%', (float)$log->compliance_percentage === 0.0, $log->compliance_percentage);

/* no punches */
$log = makeLog([
    'attendance_status' => 'present',
    'scheduled_hours' => 8,
]);

$log->calculateCompliance();
check('no punches = 0%', (float)$log->compliance_percentage === 0.0, $log->compliance_percentage);

/* single period: 20 minutes late from 11-hour schedule */
$log = makeLog([
    'attendance_status' => 'present',
    'attendance_date' => '2026-08-01',
    'scheduled_hours' => 11,
    'check_in_time' => '09:20:00',
    'check_out_time' => '20:00:00',
], [
    [
        'id' => 1,
        'start_time' => '09:00',
        'end_time' => '20:00',
        'is_night_shift' => false,
    ],
]);

$log->calculateCompliance();

check(
    '20 minutes late = 96.97%',
    abs((float)$log->compliance_percentage - 96.97) < 0.02,
    $log->compliance_percentage
);

/*
 * Multi-period:
 * 09-13 = 4h
 * 16-20 = 4h
 * actual 12-20 overlaps scheduled periods for 5h only.
 * 5 / 8 = 62.5%
 */
$log = makeLog([
    'attendance_status' => 'present',
    'attendance_date' => '2026-08-01',
    'scheduled_hours' => 8,
    'check_in_time' => '12:00:00',
    'check_out_time' => '20:00:00',
], [
    [
        'id' => 1,
        'start_time' => '09:00',
        'end_time' => '13:00',
        'is_night_shift' => false,
    ],
    [
        'id' => 2,
        'start_time' => '16:00',
        'end_time' => '20:00',
        'is_night_shift' => false,
    ],
]);

$log->calculateCompliance();

check(
    'multi-period break excluded = 62.5%',
    abs((float)$log->compliance_percentage - 62.5) < 0.01,
    $log->compliance_percentage
);

/* Exact attendance */
$log = makeLog([
    'attendance_status' => 'present',
    'attendance_date' => '2026-08-01',
    'scheduled_hours' => 8,
    'check_in_time' => '09:00:00',
    'check_out_time' => '20:00:00',
], [
    [
        'start_time' => '09:00',
        'end_time' => '13:00',
        'is_night_shift' => false,
    ],
    [
        'start_time' => '16:00',
        'end_time' => '20:00',
        'is_night_shift' => false,
    ],
]);

$log->calculateCompliance();

check(
    'full scheduled periods = 100%',
    abs((float)$log->compliance_percentage - 100) < 0.01,
    $log->compliance_percentage
);

/* actual_hours must never be negative */
$log = makeLog([
    'check_in_time' => '12:18:09',
    'check_out_time' => '13:00:00',
]);

$log->calculateActualHours();

check(
    'actual hours positive = 0.70',
    abs((float)$log->actual_hours - 0.70) < 0.01,
    $log->actual_hours
);

echo "ATTENDANCE COMPLIANCE CALCULATION REGRESSION: PASS" . PHP_EOL;
