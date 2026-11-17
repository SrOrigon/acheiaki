<?php
/**
 * Healthcheck para Railway / balanceadores de carga.
 */
require_once __DIR__ . '/db_pdo.php';

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

echo json_encode([
    'status' => 'ok',
    'service' => 'AcheIAki',
    'php' => PHP_VERSION,
    'sql_enabled' => $dbEnabled,
    'sql_connected' => $dbOk,
    'time' => date('c'),
], JSON_UNESCAPED_UNICODE);
