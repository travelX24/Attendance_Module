<?php

$service = file_get_contents(
    __DIR__ . '/../../src/Services/PenaltyService.php'
);

$checks = [
    'employee group cache property exists'
        => str_contains(
            $service,
            'private array $employeeGroupCache = [];'
        ),

    'cache resets for every range calculation'
        => str_contains(
            $service,
            '$this->employeeGroupCache = [];'
        ),

    'penalty calculation resolves employee group through cache'
        => str_contains(
            $service,
            '$group = $this->resolveEmployeeGroup((int) $employee->id);'
        ),

    'resolver caches null and non-null results'
        => str_contains(
            $service,
            'array_key_exists($employeeId, $this->employeeGroupCache)'
        ),

    'original employee group lookup remains unchanged'
        => str_contains(
            $service,
            "DB::table('employee_group_members')"
        )
            && str_contains(
                $service,
                "->join('employee_groups', 'employee_group_members.group_id', '=', 'employee_groups.id')"
            )
            && str_contains(
                $service,
                "'employee_groups.applied_policy_id'"
            )
            && str_contains(
                $service,
                "'employee_groups.grace_source'"
            )
            && str_contains(
                $service,
                "'employee_groups.grace_setting_id'"
            ),
];

foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . " - {$label}" . PHP_EOL;

    if (! $passed) {
        throw new RuntimeException("Regression check failed: {$label}");
    }
}

echo "PENALTY EMPLOYEE GROUP CACHE REGRESSION: PASS" . PHP_EOL;