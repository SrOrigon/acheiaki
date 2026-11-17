<?php
/**
 * Endpoint público do Algoritmo Estilo TikTok / iFood.
 * - registrar_evento: grava sinais comportamentais (views, dwell time, buscas, cliques, carrinho, pedidos)
 * - recomendar: exige localização ativa (GPS ou Cidade/CEP) e devolve Feed "Para Você" + Lojas ranqueadas
 */
require_once __DIR__ . '/tenant_init.php';
require_once __DIR__ . '/algoritmo_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$raw = file_get_contents('php://input');
$body = json_decode($raw ?: '', true);
if (!is_array($body)) {
    $body = [];
}

$acao = (string) ($_GET['action'] ?? $body['action'] ?? 'recomendar');

if ($acao === 'registrar_evento') {
    if (array_key_exists('consentimento_ia', $body) && empty($body['consentimento_ia'])) {
        echo json_encode(['success' => true, 'consentimento_recusado' => true], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $res = pz_alg_registrar_evento($body);
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'excluir_perfil_visitante') {
    $vid = (string) ($body['visitor_id'] ?? $_GET['visitor_id'] ?? '');
    $res = pz_alg_excluir_perfil($vid);
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'recomendar') {
    $ctx = [
        'visitor_id' => $_GET['visitor_id'] ?? $body['visitor_id'] ?? '',
        'telefone' => $_GET['telefone'] ?? $body['telefone'] ?? '',
        'lat' => $_GET['lat'] ?? $body['lat'] ?? 0,
        'lng' => $_GET['lng'] ?? $body['lng'] ?? 0,
        'cidade' => $_GET['cidade'] ?? $body['cidade'] ?? '',
        'bairro' => $_GET['bairro'] ?? $body['bairro'] ?? '',
        'nicho' => $_GET['nicho'] ?? $body['nicho'] ?? '',
    ];
    $res = pz_alg_recomendar($ctx);
    if (empty($res['success'])) {
        http_response_code(412);
    }
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Ação desconhecida no algoritmo.'], JSON_UNESCAPED_UNICODE);
