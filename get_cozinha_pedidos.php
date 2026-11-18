<?php
require_once __DIR__ . '/tenant_init.php';
require_once __DIR__ . '/pedido_acesso.php';
require_once __DIR__ . '/store_schema.php';

header('Content-Type: application/json; charset=utf-8');

pedidozap_bootstrap_tenant();

$chave = (string) ($_GET['k'] ?? '');
$admin = pz_admin_ok();
if (!pz_cozinha_ok($chave) && !$admin) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'Abra a tela da cozinha pelo link enviado pelo painel da loja.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$incluirNovos = ($_GET['incluir_novos'] ?? '') === '1';
$statusOk = $incluirNovos ? ['novo', 'preparo'] : ['preparo'];
$sinceParam = trim((string) ($_GET['since'] ?? ''));
$verAtual = Database::getOrdersVersion() . '|' . ($incluirNovos ? '1' : '0');
$etagCoz = '"' . sha1($verAtual) . '"';
header('ETag: ' . $etagCoz);
header('Cache-Control: no-cache');

if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etagCoz) {
    http_response_code(304);
    exit;
}

$ordersVer = Database::getOrdersVersion();
if ($sinceParam !== '' && $sinceParam === $ordersVer) {
    echo json_encode([
        'success' => true,
        'changed' => false,
        'updated_at' => $ordersVer,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$lista = array_filter(Database::read('pedidos.json'), function ($p) use ($statusOk) {
    return is_array($p) && in_array($p['status'] ?? 'novo', $statusOk, true);
});

usort($lista, function ($a, $b) {
    $ta = (string) ($a['cozinha_desde'] ?? $a['timestamp'] ?? $a['data'] ?? '');
    $tb = (string) ($b['cozinha_desde'] ?? $b['timestamp'] ?? $b['data'] ?? '');
    return strcmp($tb, $ta);
});

$saida = [];
foreach ($lista as $p) {
    $id = $p['id'] ?? '';
    if (($p['status'] ?? '') === 'preparo' && empty($p['comanda_id']) && $id !== '') {
        Database::garantirComandaCozinha($id);
        $atualizado = Database::findOrder($id);
        if (is_array($atualizado)) {
            $p = $atualizado;
        }
    }
    $saida[] = [
        'id' => $p['id'] ?? '',
        'status' => $p['status'] ?? 'novo',
        'canal' => $p['canal'] ?? '',
        'cliente' => $p['cliente'] ?? '',
        'celular' => $p['celular'] ?? '',
        'entrega' => $p['entrega'] ?? '',
        'endereco' => $p['endereco'] ?? null,
        'itens' => $p['itens'] ?? [],
        'total' => $p['total'] ?? 0,
        'frete' => $p['frete'] ?? 0,
        'pagamento' => $p['pagamento'] ?? '',
        'troco' => $p['troco'] ?? '',
        'observacao' => $p['observacao'] ?? '',
        'timestamp' => $p['timestamp'] ?? $p['data'] ?? '',
        'cozinha_desde' => $p['cozinha_desde'] ?? '',
        'comanda_id' => $p['comanda_id'] ?? '',
        'comanda_impressa_id' => $p['comanda_impressa_id'] ?? '',
    ];
}

$config = pedidozap_normalizar_config(Database::read('config.json'), Database::getTenant());

echo json_encode([
    'success' => true,
    'changed' => true,
    'updated_at' => $ordersVer,
    'nome_loja' => $config['NOME_LOJA'] ?? '',
    'comanda' => $config['COMANDA'] ?? [],
    'pedidos' => $saida,
], JSON_UNESCAPED_UNICODE);
