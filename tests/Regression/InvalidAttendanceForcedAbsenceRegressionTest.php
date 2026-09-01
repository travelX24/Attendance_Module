<?php

$serviceFile = dirname(__DIR__, 2)
    . '/src/Services/PenaltyService.php';

require_once $serviceFile;

function check(string $label, bool $passed): void
{
    echo ($passed ? 'PASS' : 'FAIL')
        . " - {$label}"
        . PHP_EOL;

    if (! $passed) {
        exit(1);
    }
}

$class = \Athka\Attendance\Services\PenaltyService::class;

$service = new $class();

$method = new ReflectionMethod(
    $class,
    'resolveForcedAbsenceReason'
);


$date = '2026-08-30';

$scheduledEnd = new DateTimeImmutable(
    "{$date} 20:00:00"
);

/*
|--------------------------------------------------------------------------
| Exact QA scenario
|--------------------------------------------------------------------------
| Schedule: 09:00 -> 20:00
| Check-in: 21:18
| Check-out: 21:00
|--------------------------------------------------------------------------
*/

$result = $method->invoke(
    $service,
    new DateTimeImmutable("{$date} 21:18:00"),
    new DateTimeImmutable("{$date} 21:00:00"),
    $scheduledEnd,
    60
);

check(
    'QA scenario: 21:18 check-in after 21:00 check-out forces absence',
    $result === 'invalid_attendance_order'
);

/*
|--------------------------------------------------------------------------
| Correct order, but 78 minutes after schedule end
|--------------------------------------------------------------------------
*/

$result = $method->invoke(
    $service,
    new DateTimeImmutable("{$date} 21:18:00"),
    new DateTimeImmutable("{$date} 21:30:00"),
    $scheduledEnd,
    60
);

check(
    '21:18 exceeds 20:00 schedule end plus 60-minute threshold',
    $result === 'attendance_after_schedule_end'
);

/*
|--------------------------------------------------------------------------
| Boundary tests
|--------------------------------------------------------------------------
*/

$result = $method->invoke(
    $service,
    new DateTimeImmutable("{$date} 20:59:00"),
    new DateTimeImmutable("{$date} 21:30:00"),
    $scheduledEnd,
    60
);

check(
    '59 minutes after schedule end does not force absence',
    $result === null
);

$result = $method->invoke(
    $service,
    new DateTimeImmutable("{$date} 21:00:00"),
    new DateTimeImmutable("{$date} 21:30:00"),
    $scheduledEnd,
    60
);

check(
    'exactly 60 minutes does not exceed the threshold',
    $result === null
);

$result = $method->invoke(
    $service,
    new DateTimeImmutable("{$date} 21:01:00"),
    new DateTimeImmutable("{$date} 21:30:00"),
    $scheduledEnd,
    60
);

check(
    '61 minutes after schedule end forces absence',
    $result === 'attendance_after_schedule_end'
);

/*
|--------------------------------------------------------------------------
| No configured threshold
|--------------------------------------------------------------------------
*/

$result = $method->invoke(
    $service,
    new DateTimeImmutable("{$date} 21:18:00"),
    new DateTimeImmutable("{$date} 21:30:00"),
    $scheduledEnd,
    0
);

check(
    'zero threshold does not invent an absence rule',
    $result === null
);

echo "INVALID ATTENDANCE FUNCTIONAL REGRESSION: PASS"
    . PHP_EOL;