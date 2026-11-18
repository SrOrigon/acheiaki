<?php
/**
 * Open Delivery PDV API — Integração aberta para Saipos, Consumer, Linx, Totvs, Stone e ERPs.
 * Autenticação: Header Authorization: Bearer <api_key> ou X-Api-Key: <api_key>.
 */
require_once __DIR__ . '/tenant_init.php';
require_once __DIR__ . '/pdv_lib.php';
require_once __DIR__ . '/gestao_lib.php';
require_once __DIR__ . '/fiscal_lib.php';
require_once __DIR__ . '/push_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Api-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$acao = strtolower(trim((string) ($_GET['action'] ?? 'ping')));
if ($acao === 'spec' || $acao === 'openapi') {
    $specFile = __DIR__ . '/openapi-pdv.json';
    echo is_file($specFile) ? @file_get_contents($specFile) : '{}';
    exit;
}
if ($acao === 'mobile_guide') {
    header('Content-Type: text/plain; charset=utf-8');
    $mdFile = __DIR__ . '/mobile-capacitor/README-APPS-NATIVOS.md';
    echo is_file($mdFile) ? @file_get_contents($mdFile) : 'Guia Capacitor não encontrado.';
    exit;
}

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) {
    $body = [];
}

pedidozap_bootstrap_tenant($body ?: null);
$tenant = Database::getTenant();
$pdvCfg = pz_pdv_ler_config($tenant);

$authHeader = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$apiKeyHeader = (string) ($_SERVER['HTTP_X_API_KEY'] ?? '');
$tokenInformado = '';
if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
    $tokenInformado = trim($m[1]);
} elseif ($apiKeyHeader !== '') {
    $tokenInformado = trim($apiKeyHeader);
} elseif (!empty($_GET['api_key'])) {
    $tokenInformado = trim((string) $_GET['api_key']);
}

if (empty($pdvCfg['habilitado'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Conector PDV não habilitado nesta loja. Ative em Admin -> Integrações PDV & Fiscal.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($tokenInformado === '' || $pdvCfg['api_key'] === '' || !hash_equals($pdvCfg['api_key'], $tokenInformado)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'API Key do PDV ausente ou inválida.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'ping') {
    echo json_encode([
        'success' => true,
        'service' => 'AcheIAki Open Delivery API',
        'version' => '1.0',
        'tenant' => $tenant,
        'driver' => $pdvCfg['driver'],
        'server_time' => date('c'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'orders_polling') {
    $cfgLoja = pedidozap_normalizar_config(Database::read('config.json'), $tenant);
    $pedidos = Database::read('pedidos.json');
    $acked = is_array($pdvCfg['pedidos_confirmados_pdv'] ?? null) ? $pdvCfg['pedidos_confirmados_pdv'] : [];
    $incluirAcked = ($_GET['all'] ?? '') === '1';

    $out = [];
    foreach ((array) $pedidos as $p) {
        if (!is_array($p) || empty($p['id'])) {
            continue;
        }
        $pid = (string) $p['id'];
        if (!$incluirAcked && in_array($pid, $acked, true)) {
            continue;
        }
        if (($p['status'] ?? 'novo') === 'arquivado' && !$incluirAcked) {
            continue;
        }
        $out[] = pz_pdv_mapear_pedido_open_delivery($p, $tenant, $cfgLoja);
    }

    Database::mutate('pdv_config.json', function (&$c) {
        if (!is_array($c)) {
            $c = [];
        }
        $c['ultimo_polling_em'] = date('c');
        return true;
    });

    echo json_encode([
        'success' => true,
        'tenant' => $tenant,
        'count' => count($out),
        'orders' => $out,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'ack_orders') {
    $ids = array_values(array_unique(array_map('strval', (array) ($body['orderIds'] ?? $body['ids'] ?? []))));
    Database::mutate('pdv_config.json', function (&$c) use ($ids) {
        if (!is_array($c)) {
            $c = [];
        }
        $atuais = is_array($c['pedidos_confirmados_pdv'] ?? null) ? $c['pedidos_confirmados_pdv'] : [];
        $merged = array_values(array_unique(array_merge($atuais, $ids)));
        if (count($merged) > 400) {
            $merged = array_slice($merged, -400);
        }
        $c['pedidos_confirmados_pdv'] = $merged;
        return true;
    });
    echo json_encode(['success' => true, 'acked' => count($ids)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'order_status') {
    $orderId = trim((string) ($body['orderId'] ?? $body['id'] ?? ''));
    $rawStatus = strtoupper(trim((string) ($body['status'] ?? '')));
    $mapa = [
        'PLACED' => 'novo',
        'CONFIRMED' => 'preparo',
        'IN_PREPARATION' => 'preparo',
        'READY_FOR_PICKUP' => 'em-rota',
        'DISPATCHED' => 'em-rota',
        'CONCLUDED' => 'concluido',
        'CANCELLED' => 'arquivado',
        'NOVO' => 'novo',
        'PREPARO' => 'preparo',
        'EM-ROTA' => 'em-rota',
        'CONCLUIDO' => 'concluido',
        'ARQUIVADO' => 'arquivado',
    ];
    if ($orderId === '' || !isset($mapa[$rawStatus])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Informe orderId e status Open Delivery válido (CONFIRMED, IN_PREPARATION, DISPATCHED, CONCLUDED, CANCELLED).'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $novoStatus = $mapa[$rawStatus];
    $ator = 'PDV (' . $pdvCfg['driver'] . ')';
    if ($rawStatus === 'CANCELLED') {
        Database::patchOrder($orderId, ['cancelado' => true, 'motivo_cancelamento' => (string) ($body['reason'] ?? 'Cancelado pelo PDV')]);
        pz_estornar_estoque_pedido($orderId, $ator);
        pz_estornar_venda_financeiro($orderId, $ator);
    }

    if (!Database::updateOrderStatus($orderId, $novoStatus)) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Pedido não encontrado.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($novoStatus === 'preparo') {
        Database::garantirComandaCozinha($orderId);
    }
    if ($rawStatus !== 'CANCELLED' && in_array($novoStatus, ['preparo', 'em-rota', 'concluido'], true)) {
        pz_baixar_estoque_pedido($orderId, $ator);
    }
    if ($novoStatus === 'concluido') {
        pz_registrar_venda_financeiro($orderId, $ator);
        pz_fiscal_agendar_se_automatico($tenant, $orderId, 'ao_concluir');
    } elseif ($novoStatus === 'preparo') {
        pz_fiscal_agendar_se_automatico($tenant, $orderId, 'ao_preparar');
    }

    $ped = Database::findOrder($orderId);
    if (is_array($ped)) {
        pz_push_notificar_status_pedido($tenant, $ped, $novoStatus);
    }
    pz_realtime_publicar($tenant, 'pedido_status', ['id' => $orderId, 'status' => $novoStatus, 'origem' => 'pdv']);

    echo json_encode(['success' => true, 'orderId' => $orderId, 'status' => $novoStatus], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'menu') {
    $cardapio = Database::read('cardapio.json');
    echo json_encode(['success' => true, 'tenant' => $tenant, 'catalog' => $cardapio], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'sync_catalog') {
    $updates = is_array($body['items'] ?? null) ? $body['items'] : [];
    if (empty($updates)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Envie items:[{id|sku_pdv, preco, ativo, ncm, cfop}]'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $porChave = [];
    foreach ($updates as $u) {
        if (!is_array($u)) {
            continue;
        }
        $k = trim((string) ($u['id'] ?? $u['externalCode'] ?? $u['sku_pdv'] ?? ''));
        if ($k !== '') {
            $porChave[$k] = $u;
        }
    }

    $alterados = 0;
    Database::mutate('cardapio.json', function (&$cat) use ($porChave, &$alterados) {
        if (!is_array($cat)) {
            return false;
        }
        foreach ($cat as $grupo => $lista) {
            if (!is_array($lista)) {
                continue;
            }
            foreach ($lista as $idx => $item) {
                if (!is_array($item)) {
                    continue;
                }
                $idItem = (string) ($item['id'] ?? '');
                $skuItem = (string) ($item['sku_pdv'] ?? '');
                $patch = $porChave[$skuItem] ?? ($porChave[$idItem] ?? null);
                if (!$patch) {
                    continue;
                }
                if (isset($patch['preco']) && is_numeric($patch['preco'])) {
                    $cat[$grupo][$idx]['preco'] = round((float) $patch['preco'], 2);
                }
                if (array_key_exists('ativo', $patch)) {
                    $cat[$grupo][$idx]['ativo'] = (bool) $patch['ativo'];
                }
                if (isset($patch['sku_pdv']) || isset($patch['externalCode'])) {
                    $cat[$grupo][$idx]['sku_pdv'] = (string) ($patch['sku_pdv'] ?? $patch['externalCode']);
                }
                if (isset($patch['ncm'])) {
                    $cat[$grupo][$idx]['ncm'] = preg_replace('/\D/', '', (string) $patch['ncm']);
                }
                if (isset($patch['cfop'])) {
                    $cat[$grupo][$idx]['cfop'] = preg_replace('/\D/', '', (string) $patch['cfop']);
                }
                $alterados++;
            }
        }
        return $alterados > 0;
    });

    if ($alterados > 0) {
        Database::mutate('config.json', function (&$cfg) {
            if (!is_array($cfg)) {
                $cfg = [];
            }
            $cfg['VERSION'] = time();
            return true;
        });
        pz_realtime_publicar($tenant, 'cardapio_atualizado', ['alterados' => $alterados, 'origem' => 'pdv']);
    }

    echo json_encode(['success' => true, 'updated' => $alterados], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Ação PDV inválida. Consulte pdv_api.php?action=spec'], JSON_UNESCAPED_UNICODE);
