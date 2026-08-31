<?php

$source = file_get_contents(__DIR__ . '/../../src/Services/PenaltyService.php');

$start = strpos($source, 'private function hasApprovedPermissionForViolation');

if ($start === false) {
    echo "FAIL - method not found" . PHP_EOL;
    exit(1);
}

$method = substr($source, $start, 1800);

$checks = [
    'converted absence is not waived again by permission'
        => str_contains($method, 'if ($violationType === \'absent\')'),

    'old conditional absence bypass is removed'
        => ! str_contains(
            $method,
            'if ($violationType === \'absent\' && ! $this->hasAnyActualAttendance($log))'
        ),

    'remaining delay is still punishable'
        => str_contains(
            $method,
            'if ($violationType === \'delay\' && $this->getLateMinutes($log) > 0)'
        ),

    'remaining early departure is still punishable'
        => str_contains(
            $method,
            'if ($violationType === \'early_departure\' && $this->getEarlyDepartureMinutes($log) > 0)'
        ),

    'approved permission lookup remains available'
        => str_contains($method, "->where('status', 'approved')"),
];

foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . " - {$label}" . PHP_EOL;

    if (! $passed) {
        exit(1);
    }
}

echo "ALL TESTS PASSED" . PHP_EOL;
