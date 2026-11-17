<?php
/**
 * Proxy de geocodificação (Nominatim) para o checkout.
 * Evita bloqueio de CORS e envia um User-Agent identificável.
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/store_schema.php';

$q = isset($_GET['q']) ? trim((string) $_GET['q']) : '';
$geo = pedidozap_geocode_query($q);

if (!$geo) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Endereço não localizado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['success' => true, 'lat' => $geo['lat'], 'lng' => $geo['lng'], 'label' => $geo['label']], JSON_UNESCAPED_UNICODE);
