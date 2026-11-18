<?php
/**
 * Worker CLI de Alta Concorrência — AcheIAki
 * Execução contínua: php worker.php
 * Execução única:    php worker.php --once
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'Somente via CLI.';
    exit(1);
}

require_once __DIR__ . '/tenant_init.php';
require_once __DIR__ . '/queue_lib.php';

$once = in_array('--once', $argv ?? [], true);
echo '[' . date('c') . '] Worker AcheIAki iniciado (' . ($once ? 'once' : 'daemon') . ")\n";

do {
    $res = pz_queue_run_batch(25, 10);
    if (($res['processados'] ?? 0) > 0 || ($res['falhas'] ?? 0) > 0) {
        echo '[' . date('c') . '] Batch ok=' . $res['processados'] . ' err=' . $res['falhas'] . "\n";
    }
    if ($once) {
        break;
    }
    usleep(($res['processados'] ?? 0) > 0 ? 150000 : 1200000);
} while (true);
