<?php
/**
 * Healthcheck para Railway / balanceadores de carga.
 */
require_once __DIR__ . '/db_pdo.php';
require_once __DIR__ . '/saas_lib.php';
require_once __DIR__ . '/cache_lib.php';
require_once __DIR__ . '/queue_lib.php';
require_once __DIR__ . '/media_cdn_lib.php';
require_once __DIR__ . '/realtime_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$dbCfg = pz_db_ler_config();
$dbEnabled = !empty($dbCfg['enabled']);
$dbOk = false;

if ($dbEnabled) {
    try {
        $pdo = pz_pdo(false);
        $dbOk = $pdo instanceof PDO;
    } catch (Throwable $e) {
        $dbOk = false;
    }
}

$dataDir = __DIR__ . '/data';
$dataGravavel = is_dir($dataDir) && is_writable($dataDir);
$masterOk = pedidozap_ler_master() !== null;
$appSecretLen = strlen(pedidozap_app_secret());
$iconOk = is_file(__DIR__ . '/assets/images/acheiaki-icon.jpg')
    || is_file(__DIR__ . '/assets/images/acheiaki-icon.png')
    || is_file(__DIR__ . '/assets/images/acheiaki-icon.webp');
$warnings = [];
if (!$masterOk) {
    $warnings[] = 'master_nao_configurado';
}
if ($dbEnabled && !$dbOk) {
    $warnings[] = 'sql_desconectado';
}
if (!$iconOk) {
    $warnings[] = 'icone_marca_ausente';
}
$critical = !$dataGravavel;
$ready = $dataGravavel && $masterOk && $appSecretLen >= 32;

if ($critical) {
    http_response_code(503);
}

$persistencia = 'json_local';
if ($dbOk) {
    $persistencia = 'postgresql';
    try {
        $pdo = pz_pdo(false);
        if ($pdo instanceof PDO && $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            $persistencia = 'mysql';
        }
    } catch (Throwable $e) {
        $persistencia = 'sql';
    }
}

$workerHbFile = __DIR__ . '/data/cache/worker_heartbeat.json';
$workerHbRaw = is_file($workerHbFile) ? @json_decode((string) @file_get_contents($workerHbFile), true) : null;
$workerTs = is_array($workerHbRaw) ? (int) ($workerHbRaw['ts'] ?? 0) : 0;
$workerAge = $workerTs > 0 ? max(0, time() - $workerTs) : null;
$workerInfo = [
    'ativo' => $workerAge !== null && $workerAge <= 30,
    'pid' => is_array($workerHbRaw) ? (int) ($workerHbRaw['pid'] ?? 0) : 0,
    'ultimo_batimento_seg' => $workerAge,
    'jobs_ciclo' => is_array($workerHbRaw) ? (int) ($workerHbRaw['jobs'] ?? 0) : 0,
    'mem_mb' => is_array($workerHbRaw) ? (float) ($workerHbRaw['mem_mb'] ?? 0) : 0,
];

echo json_encode([
    'status' => $critical ? 'critical' : ($warnings ? 'degraded' : 'ok'),
    'ready_for_traffic' => $ready,
    'service' => 'AcheIAki',
    'php' => PHP_VERSION,
    'persistencia_oficial' => $persistencia,
    'sql_enabled' => $dbEnabled,
    'sql_connected' => $dbOk,
    'data_writable' => $dataGravavel,
    'master_configured' => $masterOk,
    'brand_icon_present' => $iconOk,
    'cache' => pz_cache_backend_info(),
    'queue' => pz_queue_stats(),
    'worker' => $workerInfo,
    'media' => pz_media_cdn_publico(),
    'realtime' => pz_realtime_ws_publico(),
    'warnings' => $warnings,
    'time' => date('c'),
], JSON_UNESCAPED_UNICODE);
