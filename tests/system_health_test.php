<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../includes/system_health.php';

$checks = [
    systemHealthItem('A', 'ok', 'Ready'),
    systemHealthItem('B', 'warning', 'Review'),
    systemHealthItem('C', 'error', 'Broken'),
];
$summary = systemHealthSummary($checks);
if ($summary['ok'] !== 1 || $summary['warning'] !== 1 || $summary['error'] !== 1 || $summary['overall'] !== 'error') {
    fwrite(STDERR, "[FAIL] System health summary is incorrect.\n");
    exit(1);
}

$warningOnly = systemHealthSummary([systemHealthItem('A', 'warning', 'Review')]);
if ($warningOnly['overall'] !== 'warning') {
    fwrite(STDERR, "[FAIL] Warning status is incorrect.\n");
    exit(1);
}

$healthy = systemHealthSummary([systemHealthItem('A', 'ok', 'Ready')]);
if ($healthy['overall'] !== 'ok') {
    fwrite(STDERR, "[FAIL] Healthy status is incorrect.\n");
    exit(1);
}

if (systemHealthFormatBytes(1073741824) !== '1,00 GB') {
    fwrite(STDERR, "[FAIL] Storage byte formatting is incorrect.\n");
    exit(1);
}

echo "[PASS] System health status aggregation and storage formatting\n4 tests, 0 failures.\n";
