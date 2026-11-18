<?php
/**
 * Worker CLI de Alta Concorrência — AcheIAki
 * Execução contínua supervisionada (Dockerfile): PZ_WORKER_DAEMON=1 php worker.php
 * Execução única:                              php worker.php --once
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'Somente via CLI.';
    exit(1);
}

@ini_set('memory_limit', '128M');
require_once __DIR__ . '/tenant_init.php';
require_once __DIR__ . '/queue_lib.php';

$once = in_array('--once', $argv ?? [], true);
$maxJobs = (int) (getenv('PZ_WORKER_MAX_JOBS') ?: 400);
$maxSeg = (int) (getenv('PZ_WORKER_MAX_SEC') ?: 1800);
$maxMemBytes = (int) (getenv('PZ_WORKER_MAX_MEM_MB') ?: 96) * 1024 * 1024;
$inicio = time();
$totalProcessados = 0;

echo '[' . date('c') . '] Worker AcheIAki iniciado (' . ($once ? 'once' : 'daemon') . ")\n";

do {
    pz_cache_l1_clear();
    $res = pz_queue_run_batch(20, 8);
    $okQtd = (int) ($res['processados'] ?? 0);
    $errQtd = (int) ($res['falhas'] ?? 0);
    $totalProcessados += ($okQtd + $errQtd);

    if ($okQtd > 0 || $errQtd > 0) {
        echo '[' . date('c') . '] Batch ok=' . $okQtd . ' err=' . $errQtd . ' mem=' . round(memory_get_usage(true) / 1048576, 1) . "MB\n";
    }

    if (function_exists('gc_collect_cycles')) {
        gc_collect_cycles();
    }

    if ($once) {
        break;
    }

    // Reciclagem graciosa (supervisor no Dockerfile reinicia instantaneamente com memória zerada)
    if ($totalProcessados >= $maxJobs || (time() - $inicio) >= $maxSeg || memory_get_usage(true) >= $maxMemBytes) {
        echo '[' . date('c') . "] Reciclagem preventiva concluída ({$totalProcessados} jobs).\n";
        break;
    }

    usleep($okQtd > 0 ? 150000 : 1200000);
} while (true);

exit(0);
