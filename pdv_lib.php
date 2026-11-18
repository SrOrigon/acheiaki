<?php
/**
 * Conector PDV / ERP estilo Open Delivery (Saipos, Consumer, Linx, Totvs, Stone).
 * Gerencia chaves de API por loja, mapeamento canônico de pedidos, webhooks assinados e sync de catálogo.
 */
require_once __DIR__ . '/tenant_init.php';
require_once __DIR__ . '/store_schema.php';
require_once __DIR__ . '/queue_lib.php';
require_once __DIR__ . '/realtime_lib.php';

function pz_pdv_drivers_suportados()
{
    return [
        'open_delivery' => 'Open Delivery Padrão (Webhook / Polling)',
        'saipos' => 'Saipos PDV',
        'consumer' => 'Consumer / MenuDino',
        'linx' => 'Linx Degust / Microvix',
        'totvs' => 'TOTVS Chef / Bematech',
        'stone' => 'Stone / Ton PDV',
    ];
}

function pz_pdv_ler_config($slug = null)
{
    $anterior = Database::getTenant();
    if ($slug !== null) {
        Database::setTenant($slug);
    }
    $raw = Database::read('pdv_config.json');
    if ($slug !== null) {
        Database::setTenant($anterior);
    }
    if (!is_array($raw)) {
        $raw = [];
    }
    $drivers = pz_pdv_drivers_suportados();
    $driver = strtolower(trim((string) ($raw['driver'] ?? 'open_delivery')));
    if (!isset($drivers[$driver])) {
        $driver = 'open_delivery';
    }

    return array_merge([
        'habilitado' => false,
        'driver' => $driver,
        'api_key' => '',
        'webhook_url' => '',
        'webhook_secret' => '',
        'auto_aceitar_pdv' => false,
        'ultimo_webhook_em' => '',
        'ultimo_webhook_status' => '',
        'ultimo_polling_em' => '',
        'pedidos_confirmados_pdv' => [],
    ], $raw);
}

function pz_pdv_salvar_config($slug, array $patch)
{
    $atual = pz_pdv_ler_config($slug);
    if (array_key_exists('habilitado', $patch)) {
        $atual['habilitado'] = !empty($patch['habilitado']);
    }
    if (array_key_exists('driver', $patch)) {
        $drivers = pz_pdv_drivers_suportados();
        $d = strtolower(trim((string) $patch['driver']));
        if (isset($drivers[$d])) {
            $atual['driver'] = $d;
        }
    }
    if (array_key_exists('webhook_url', $patch)) {
        $u = trim((string) $patch['webhook_url']);
        $atual['webhook_url'] = ($u === '' || preg_match('#^https?://#i', $u)) ? $u : $atual['webhook_url'];
    }
    if (array_key_exists('auto_aceitar_pdv', $patch)) {
        $atual['auto_aceitar_pdv'] = !empty($patch['auto_aceitar_pdv']);
    }
    if (!empty($patch['gerar_nova_chave']) || $atual['api_key'] === '') {
        $atual['api_key'] = 'pzk_' . bin2hex(random_bytes(20));
    }
    if (!empty($patch['gerar_novo_secret']) || $atual['webhook_secret'] === '') {
        $atual['webhook_secret'] = 'whsec_' . bin2hex(random_bytes(18));
    }

    $anterior = Database::getTenant();
    Database::setTenant($slug);
    $ok = Database::write('pdv_config.json', $atual);
    Database::setTenant($anterior);
    return ['success' => (bool) $ok, 'config' => pz_pdv_mascarar_config($atual)];
}

function pz_pdv_mascarar_config(array $cfg)
{
    $out = $cfg;
    unset($out['pedidos_confirmados_pdv']);
    $out['drivers_suportados'] = pz_pdv_drivers_suportados();
    $out['acked_count'] = is_array($cfg['pedidos_confirmados_pdv'] ?? null) ? count($cfg['pedidos_confirmados_pdv']) : 0;
    return $out;
}

function pz_pdv_mapear_pedido_open_delivery(array $pedido, $slug, array $configLoja = [])
{
    $statusMap = [
        'novo' => 'PLACED',
        'preparo' => 'IN_PREPARATION',
        'em-rota' => 'DISPATCHED',
        'concluido' => 'CONCLUDED',
        'arquivado' => !empty($pedido['cancelado']) ? 'CANCELLED' : 'CONCLUDED',
    ];
    $stRaw = (string) ($pedido['status'] ?? 'novo');
    $end = is_array($pedido['endereco'] ?? null) ? $pedido['endereco'] : [];
    $itens = [];
    foreach ((array) ($pedido['itens'] ?? []) as $idx => $it) {
        if (!is_array($it)) {
            continue;
        }
        $ops = [];
        foreach ((array) ($it['opcionais'] ?? []) as $op) {
            if (!is_array($op)) {
                continue;
            }
            $ops[] = [
                'name' => (string) ($op['nome'] ?? ''),
                'unitPrice' => round((float) ($op['preco'] ?? 0), 2),
                'quantity' => 1,
            ];
        }
        $itens[] = [
            'index' => $idx + 1,
            'id' => (string) ($it['idProduto'] ?? ''),
            'externalCode' => (string) ($it['sku_pdv'] ?? $it['idProduto'] ?? ''),
            'ncm' => (string) ($it['ncm'] ?? '21069090'),
            'cfop' => (string) ($it['cfop'] ?? '5102'),
            'name' => (string) ($it['nome'] ?? 'Item'),
            'variation' => (string) ($it['tamanho'] ?? ''),
            'quantity' => max(1, (int) ($it['quantidade'] ?? 1)),
            'unitPrice' => round((float) ($it['preco'] ?? $it['unitario'] ?? 0), 2),
            'totalPrice' => round((float) ($it['total'] ?? ($it['preco'] ?? 0) * max(1, (int) ($it['quantidade'] ?? 1))), 2),
            'options' => $ops,
            'observations' => (string) ($it['obs'] ?? ''),
        ];
    }

    return [
        'id' => (string) ($pedido['id'] ?? ''),
        'displayId' => (string) ($pedido['comanda_id'] ?? $pedido['id'] ?? ''),
        'createdAt' => (string) ($pedido['timestamp'] ?? $pedido['data'] ?? date('c')),
        'orderType' => (($pedido['entrega'] ?? '') === 'retirada') ? 'TAKEOUT' : 'DELIVERY',
        'status' => $statusMap[$stRaw] ?? 'PLACED',
        'merchant' => [
            'id' => $slug,
            'name' => (string) ($configLoja['NOME_LOJA'] ?? $slug),
            'cnpj' => (string) ($configLoja['CNPJ'] ?? ''),
        ],
        'customer' => [
            'name' => (string) ($pedido['cliente'] ?? 'Cliente'),
            'phone' => (string) ($pedido['celular'] ?? ''),
        ],
        'delivery' => [
            'address' => [
                'streetName' => (string) ($end['rua'] ?? ''),
                'streetNumber' => (string) ($end['numero'] ?? ''),
                'neighborhood' => (string) ($end['bairro'] ?? ''),
                'city' => (string) ($end['cidade'] ?? ''),
                'state' => (string) ($end['estado'] ?? ''),
                'postalCode' => (string) ($end['cep'] ?? ''),
                'complement' => (string) ($end['complemento'] ?? ''),
                'reference' => (string) ($end['referencia'] ?? ''),
            ],
        ],
        'items' => $itens,
        'total' => [
            'itemsPrice' => round((float) ($pedido['subtotal'] ?? $pedido['total'] ?? 0), 2),
            'deliveryFee' => round((float) ($pedido['frete'] ?? 0), 2),
            'discount' => round((float) ($pedido['desconto_cupom'] ?? 0), 2),
            'orderAmount' => round((float) ($pedido['total'] ?? 0), 2),
        ],
        'payments' => [[
            'method' => (string) ($pedido['pagamento'] ?? 'PIX'),
            'changeFor' => (string) ($pedido['troco'] ?? ''),
            'value' => round((float) ($pedido['total'] ?? 0), 2),
        ]],
        'observations' => (string) ($pedido['observacao'] ?? ''),
        'nfce' => $pedido['nfce'] ?? null,
    ];
}

function pz_pdv_enfileirar_evento($slug, $eventName, array $pedido)
{
    $cfg = pz_pdv_ler_config($slug);
    if (empty($cfg['habilitado']) || empty($cfg['webhook_url'])) {
        return false;
    }
    $anterior = Database::getTenant();
    Database::setTenant($slug);
    $cfgLoja = pedidozap_normalizar_config(Database::read('config.json'), $slug);
    Database::setTenant($anterior);

    $mapped = pz_pdv_mapear_pedido_open_delivery($pedido, $slug, $cfgLoja);
    pz_queue_push('pdv.webhook', [
        'event' => $eventName,
        'data' => $mapped,
    ], $slug);
    return true;
}

function pz_pdv_entregar_webhook($slug, $eventName, array $orderMapped)
{
    $cfg = pz_pdv_ler_config($slug);
    $url = trim((string) ($cfg['webhook_url'] ?? ''));
    if ($url === '' || !function_exists('curl_init')) {
        return ['success' => true, 'skipped' => true];
    }

    $envelope = [
        'eventId' => 'ev_' . bin2hex(random_bytes(6)),
        'eventType' => $eventName,
        'driver' => $cfg['driver'],
        'merchantId' => $slug,
        'sentAt' => date('c'),
        'order' => $orderMapped,
    ];
    $json = json_encode($envelope, JSON_UNESCAPED_UNICODE);
    $sig = hash_hmac('sha256', $json, (string) ($cfg['webhook_secret'] ?? ''));

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-AcheIAki-Driver: ' . $cfg['driver'],
            'X-AcheIAki-Event: ' . $eventName,
            'X-AcheIAki-Signature: sha256=' . $sig,
        ],
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    $ok = $code >= 200 && $code < 300;
    $anterior = Database::getTenant();
    Database::setTenant($slug);
    Database::mutate('pdv_config.json', function (&$c) use ($code, $ok, $err) {
        if (!is_array($c)) {
            $c = [];
        }
        $c['ultimo_webhook_em'] = date('c');
        $c['ultimo_webhook_status'] = $ok ? ('HTTP ' . $code . ' OK') : ('HTTP ' . $code . ' ' . $err);
        return true;
    });
    Database::setTenant($anterior);

    return [
        'success' => $ok,
        'http_code' => $code,
        'message' => $ok ? 'Webhook entregue ao PDV.' : ('Falha HTTP ' . $code . ': ' . ($err ?: substr((string) $resp, 0, 120))),
    ];
}
