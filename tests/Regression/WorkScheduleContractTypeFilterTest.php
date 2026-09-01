<?php

$index = file_get_contents(
    __DIR__ . '/../../src/Http/Livewire/WorkSchedules/Index.php'
);

$requiredContractTypes = [
    'permanent',
    'temporary',
    'probation',
    'contractor',
    'freelancer',
];

$allSystemTypesPresent = true;

foreach ($requiredContractTypes as $type) {
    if (! str_contains($index, "'{$type}'")) {
        $allSystemTypesPresent = false;
        break;
    }
}

$checks = [
    'official contract types are always available'
        => str_contains($index, '$systemContractTypes = [')
            && $allSystemTypesPresent,

    'existing company contract types are preserved'
        => str_contains(
            $index,
            '$existingContractTypes = Employee::withoutGlobalScope(\'active_only\')'
        )
            && str_contains($index, '->forCompany($companyId)'),

    'system and existing contract types are merged'
        => str_contains(
            $index,
            '$contractFilterTypes = array_values(array_unique(array_merge('
        )
            && str_contains($index, '$systemContractTypes,')
            && str_contains($index, '$existingContractTypes'),

    'filter options are no longer employee-only'
        => ! str_contains(
            $index,
            '$contractFilterTypes = Employee::withoutGlobalScope(\'active_only\')'
        ),

    'selected contract type still filters employees'
        => str_contains(
            $index,
            "->when(\$this->contract_type !== 'all', fn(\$q) => \$q->where('contract_type', (string)\$this->contract_type))"
        ),
];

foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . " - {$label}" . PHP_EOL;

    if (! $passed) {
        exit(1);
    }
}

echo "WORK SCHEDULE CONTRACT TYPE FILTER REGRESSION: PASS" . PHP_EOL;