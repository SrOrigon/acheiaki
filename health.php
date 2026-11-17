<?php
/**
 * Healthcheck para Railway / balanceadores de carga.
 */
require_once __DIR__ . '/db_pdo.php';
require_once __DIR__ . '/saas_lib.php';

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
    'warnings' => $warnings,
    'time' => date('c'),
], JSON_UNESCAPED_UNICODE);
