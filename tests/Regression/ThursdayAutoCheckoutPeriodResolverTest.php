<?php

declare(strict_types=1);

/**
 * Regression test:
 *
 * A weekly/daily schedule exception has its own database ID,
 * while an attendance detail may still reference the original
 * work_schedule_period_id.
 *
 * Auto checkout must resolve the effective period for that day
 * instead of assuming both IDs are identical.
 */

$autoloadCandidates = array_filter([
    getenv('ATHKA_TEST_AUTOLOAD') ?: null,
    dirname(__DIR__, 2) . '/vendor/autoload.php',
    dirname(__DIR__, 3) . '/HrWithModules/vendor/autoload.php',
]);

$autoload = null;

foreach ($autoloadCandidates as $candidate) {
    if (is_file($candidate)) {
        $autoload = $candidate;
        break;
    }
}

if (! $autoload) {
    fwrite(
        STDERR,
        "Unable to locate Composer autoload. " .
        "Set ATHKA_TEST_AUTOLOAD to the application vendor/autoload.php." .
        PHP_EOL
    );

    exit(2);
}

require $autoload;

$sourceFile = dirname(__DIR__, 2)
    . '/src/Models/AttendanceDailyLog.php';

/*
 * When the host application's Composer autoloader already resolved
 * another installed copy of the package, explicitly load this repo's
 * source for this isolated regression test.
 */
if (! class_exists(
    \Athka\Attendance\Models\AttendanceDailyLog::class,
    false
)) {
    require $sourceFile;
}

use Athka\Attendance\Models\AttendanceDailyLog;

$model = new AttendanceDailyLog();

$method = new ReflectionMethod(
    AttendanceDailyLog::class,
    'resolveMetricPeriodForDetail'
);

$failures = 0;

function assertPeriodResolution(
    string $name,
    AttendanceDailyLog $model,
    ReflectionMethod $method,
    array $periods,
    object $detail,
    ?int $expectedId,
    ?string $expectedEnd,
    ?int $expectedIndex
): void {
    global $failures;

    [$period, $index] = $method->invoke(
        $model,
        collect($periods),
        $detail,
        '2026-08-27'
    );

    $actualId = isset($period->id)
        ? (int) $period->id
        : null;

    $actualEnd = $period->end_time ?? null;

    $passed =
        $actualId === $expectedId
        && $actualEnd === $expectedEnd
        && $index === $expectedIndex;

    echo PHP_EOL;
    echo $name . PHP_EOL;
    echo str_repeat('-', strlen($name)) . PHP_EOL;

    echo 'Expected ID    : '
        . var_export($expectedId, true)
        . PHP_EOL;

    echo 'Actual ID      : '
        . var_export($actualId, true)
        . PHP_EOL;

    echo 'Expected End   : '
        . var_export($expectedEnd, true)
        . PHP_EOL;

    echo 'Actual End     : '
        . var_export($actualEnd, true)
        . PHP_EOL;

    echo 'Expected Index : '
        . var_export($expectedIndex, true)
        . PHP_EOL;

    echo 'Actual Index   : '
        . var_export($index, true)
        . PHP_EOL;

    echo $passed
        ? 'RESULT         : PASS' . PHP_EOL
        : 'RESULT         : FAIL' . PHP_EOL;

    if (! $passed) {
        $failures++;
    }
}

/*
|--------------------------------------------------------------------------
| 1. Normal schedule day
|--------------------------------------------------------------------------
|
| Stored work_schedule_period_id matches the effective schedule period.
|
*/
assertPeriodResolution(
    'NORMAL DAY',
    $model,
    $method,
    [
        [
            'id' => 7,
            'start_time' => '09:00',
            'end_time' => '17:00',
            'is_night_shift' => false,
        ],
    ],
    (object) [
        'work_schedule_period_id' => 7,
        'check_in_time' => '09:10:00',
    ],
    7,
    '17:00',
    0
);

/*
|--------------------------------------------------------------------------
| 2. Thursday weekly exception
|--------------------------------------------------------------------------
|
| Original period ID = 7.
| Thursday exception ID = 12.
|
| The exception must still be selected and its 15:00 end time used.
|
*/
assertPeriodResolution(
    'THURSDAY SINGLE EXCEPTION',
    $model,
    $method,
    [
        [
            'id' => 12,
            'start_time' => '09:00',
            'end_time' => '15:00',
            'is_night_shift' => false,
        ],
    ],
    (object) [
        'work_schedule_period_id' => 7,
        'check_in_time' => '09:10:00',
    ],
    12,
    '15:00',
    0
);

/*
|--------------------------------------------------------------------------
| 3. Multi-period exception
|--------------------------------------------------------------------------
|
| If IDs do not match, resolve the effective exception period using the
| actual check-in time.
|
*/
assertPeriodResolution(
    'MULTI PERIOD EXCEPTION',
    $model,
    $method,
    [
        [
            'id' => 12,
            'start_time' => '09:00',
            'end_time' => '12:00',
            'is_night_shift' => false,
        ],
        [
            'id' => 13,
            'start_time' => '13:00',
            'end_time' => '15:00',
            'is_night_shift' => false,
        ],
    ],
    (object) [
        'work_schedule_period_id' => 7,
        'check_in_time' => '13:30:00',
    ],
    13,
    '15:00',
    1
);

/*
|--------------------------------------------------------------------------
| 4. Do not restore ID-minus-one fallback
|--------------------------------------------------------------------------
|
| Database IDs must never be treated as array indexes.
|
*/
assertPeriodResolution(
    'NO FALSE INDEX FALLBACK',
    $model,
    $method,
    [
        [
            'id' => 20,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'is_night_shift' => false,
        ],
        [
            'id' => 21,
            'start_time' => '13:00',
            'end_time' => '15:00',
            'is_night_shift' => false,
        ],
    ],
    (object) [
        'work_schedule_period_id' => 99,
        'check_in_time' => '12:00:00',
    ],
    null,
    null,
    null
);

echo PHP_EOL;
echo str_repeat('=', 45) . PHP_EOL;

if ($failures > 0) {
    echo "FAILED TESTS: {$failures}" . PHP_EOL;
    exit(1);
}

echo "ALL THURSDAY AUTO-CHECKOUT TESTS PASSED" . PHP_EOL;

exit(0);
