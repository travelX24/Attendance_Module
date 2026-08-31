<?php

$assignments = file_get_contents(
    __DIR__ . '/../../src/Http/Livewire/WorkSchedules/Traits/WithScheduleAssignments.php'
);

$exceptions = file_get_contents(
    __DIR__ . '/../../src/Http/Livewire/WorkSchedules/Traits/WithScheduleExceptions.php'
);

$checks = [
    'history can read ended employee'
        => str_contains(
            $exceptions,
            "Employee::withoutGlobalScope('active_only')->forCompany(\$companyId)->findOrFail(\$employeeId)"
        ),

    'history legacy active-only lookup removed'
        => ! str_contains(
            $exceptions,
            'Employee::forCompany($companyId)->findOrFail($employeeId)'
        ),

    'preview modal can read ended employee'
        => str_contains(
            $assignments,
            "Employee::withoutGlobalScope('active_only')->forCompany(\$companyId)->whereKey(\$employeeId)"
        ),

    'preview builder can read ended employee'
        => str_contains(
            $assignments,
            "Employee::withoutGlobalScope('active_only')->forCompany(\$companyId)->find(\$employeeId)"
        ),

    'expired-contract write protections remain'
        => substr_count(
            $assignments,
            'selectedExpiredContractEmployeeIds'
        ) >= 3,
];

foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . " - {$label}" . PHP_EOL;

    if (! $passed) {
        exit(1);
    }
}

echo "ENDED EMPLOYEE WORK SCHEDULE READ-ONLY REGRESSION: PASS" . PHP_EOL;
