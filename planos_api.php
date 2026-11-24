<?php
/**
 * API pública somente leitura com a tabela vigente de planos e preços do AcheIAki.
 */
require_once __DIR__ . '/tenant_init.php';
require_once __DIR__ . '/saas_lib.php';

header('Content-Type: application/json; charset=utf-8');
pz_cors_same_origin('GET, OPTIONS', 'Content-Type');
header('Cache-Control: public, max-age=60');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$saas = pedidozap_saas_config();
echo json_encode([
    'success' => true,
    'nome_plataforma' => $saas['nome_plataforma'] ?? 'AcheIAki',
    'dias_teste_gratis' => max(1, min(7, (int) ($saas['dias_teste_gratis'] ?? 7))),
    'desconto_anual_pct' => (int) ($saas['desconto_anual_pct'] ?? 20),
    'planos' => array_values(pz_planos_catalogo($saas)),
], JSON_UNESCAPED_UNICODE);
