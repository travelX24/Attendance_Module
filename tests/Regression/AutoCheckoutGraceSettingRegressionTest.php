<?php

$service = file_get_contents(
    __DIR__ . '/../../src/Services/PenaltyService.php'
);

$checks = [
    'auto checkout uses company grace resolver'
        => str_contains(
            $service,
            '$grace = $this->resolveCompanyGraceSetting('
        ),

    'company grace resolver exists'
        => str_contains(
            $service,
            'private function resolveCompanyGraceSetting(int $companyId): ?AttendanceGraceSetting'
        ),

    'company grace setting is preferred'
        => str_contains(
            $service,
            "->where('saas_company_id', \$companyId)"
        ),

    'global grace setting is fallback'
        => str_contains(
            $service,
            'AttendanceGraceSetting::globalDefault()->first()'
        ),

    'auto checkout penalty setting remains authoritative'
        => str_contains(
            $service,
            '$grace->auto_checkout_penalty_enabled'
        ),
];

foreach ($checks as $label => $passed) {
    echo ($passed ? 'PASS' : 'FAIL') . " - {$label}" . PHP_EOL;

    if (! $passed) {
        throw new RuntimeException("Regression check failed: {$label}");
    }
}

echo "AUTO CHECKOUT GRACE SETTING REGRESSION: PASS" . PHP_EOL;