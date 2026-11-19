<?php
/**
 * Gera robots.txt dinâmico apontando o Sitemap para o domínio atual da requisição.
 */
require_once __DIR__ . '/tenant_init.php';
require_once __DIR__ . '/saas_lib.php';

$rawHost = $_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? '';
if (strpos($rawHost, ',') !== false) {
    $rawHost = trim(explode(',', $rawHost)[0]);
}
$host = preg_replace('/[^a-zA-Z0-9.\-:]/', '', trim((string) $rawHost));
if ($host === '') {
    $saas = pedidozap_saas_config();
    $host = !empty($saas['dominio_base']) ? $saas['dominio_base'] : 'acheiaki.com.br';
}

$isLocal = preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/i', $host) === 1;
$https = (!$isLocal)
    || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
    || (($_SERVER['SERVER_PORT'] ?? '') === '443');

$base = ($https ? 'https' : 'http') . '://' . $host;

header('Content-Type: text/plain; charset=utf-8');
echo "User-agent: *\n";
echo "Allow: /\n";
echo "Allow: /loja/\n";
echo "Disallow: /admin.html\n";
echo "Disallow: /superadmin.html\n";
echo "Disallow: /painel.html\n";
echo "Disallow: /cozinha.html\n";
echo "Disallow: /motoboy.html\n";
echo "Disallow: /rastreio.html\n";
echo "Disallow: /data/\n";
echo "Disallow: /login.php\n";
echo "Disallow: /save_order.php\n";
echo "Disallow: /save_data.php\n";
echo "Disallow: /get_orders.php\n";
echo "Disallow: /get_active_orders.php\n";
echo "Disallow: /get_order_status.php\n";
echo "Disallow: /update_order_status.php\n";
echo "Disallow: /update_location.php\n";
echo "Disallow: /delete_archived_orders.php\n";
echo "Disallow: /auth_config.php\n";
echo "Disallow: /saas_admin.php\n";
echo "Disallow: /gestao_api.php\n";
echo "Disallow: /agente_api.php\n";
echo "Disallow: /comprador_api.php\n";
echo "Disallow: /pdv_api.php\n";
echo "Disallow: /fiscal_api.php\n";
echo "Disallow: /push_api.php\n";
echo "Disallow: /realtime_events.php\n";
echo "Disallow: /mp_webhook.php\n";
echo "Disallow: /verificar_sistema.php\n";
echo "Disallow: /go_live_smoke.php\n";
echo "Disallow: /worker.php\n";
echo "Disallow: /scripts/\n";
echo "\n";
echo "Sitemap: {$base}/sitemap.xml\n";
