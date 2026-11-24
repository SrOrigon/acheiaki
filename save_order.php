<?php
require_once 'tenant_init.php';
require_once 'auth_lib.php';
require_once 'saas_lib.php';
require_once 'gestao_lib.php';
require_once 'algoritmo_lib.php';
require_once 'realtime_lib.php';
require_once 'push_lib.php';
require_once 'pdv_lib.php';

header('Content-Type: application/json; charset=utf-8');
pz_cors_same_origin('POST, OPTIONS', 'Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function pz_texto($valor, $max = 300)
{
    $txt = trim(strip_tags((string) $valor));
    $txt = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $txt) ?? '';
    return function_exists('mb_substr') ? mb_substr($txt, 0, $max) : substr($txt, 0, $max);
}

function pz_produtos_por_id(array $cardapio)
{
    $mapa = [];
    foreach ($cardapio as $lista) {
        if (!is_array($lista)) {
            continue;
        }
        foreach ($lista as $produto) {
            if (is_array($produto) && isset($produto['id'])) {
                $mapa[(string) $produto['id']] = $produto;
            }
        }
    }
    return $mapa;
}

function pz_loja_aberta_backend(array $cfg)
{
    if (!empty($cfg['PAUSADA_ATE']) && (int) $cfg['PAUSADA_ATE'] > time()) {
        return ['aberta' => false, 'motivo' => 'Esta loja pausou pedidos temporariamente por alta demanda.'];
    }
    $regras = $cfg['HORARIOS'] ?? null;
    if (!is_array($regras) || empty($regras)) {
        return ['aberta' => true];
    }
    $diaSemana = (int) date('w');
    $fechados = array_map('intval', (array) ($regras['FECHADO'] ?? []));
    if (in_array($diaSemana, $fechados, true)) {
        return ['aberta' => false, 'motivo' => 'Estabelecimento fechado hoje. Escolha um horário agendado.'];
    }
    $ehFds = ($diaSemana === 0 || $diaSemana === 5 || $diaSemana === 6);
    $janela = $ehFds ? ($regras['FIM_DE_SEMANA'] ?? ($regras['SEMANA'] ?? null)) : ($regras['SEMANA'] ?? ($regras['FIM_DE_SEMANA'] ?? null));
    if (!is_array($janela)) {
        return ['aberta' => true];
    }
    $parseMin = static function ($v) {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_numeric($v)) {
            return (int) $v * 60;
        }
        $p = explode(':', (string) $v);
        return ((int) ($p[0] ?? 0)) * 60 + ((int) ($p[1] ?? 0));
    };
    $ini = $parseMin($janela['inicio'] ?? null);
    $fim = $parseMin($janela['fim'] ?? null);
    if ($ini === null || $fim === null || $ini === $fim) {
        return ['aberta' => true];
    }
    $agoraMin = ((int) date('G')) * 60 + (int) date('i');
    $dentro = ($ini < $fim) ? ($agoraMin >= $ini && $agoraMin < $fim) : ($agoraMin >= $ini || $agoraMin < $fim);
    return $dentro
        ? ['aberta' => true]
        : ['aberta' => false, 'motivo' => 'Estabelecimento fechado no momento (' . ($janela['inicio'] ?? '') . ' às ' . ($janela['fim'] ?? '') . ').'];
}

/**
 * Recalcula o subtotal e valida esgotados com os preços do cardápio salvo no servidor.
 */
function pz_conferir_itens(array &$itens, array $cardapio)
{
    $produtos = pz_produtos_por_id($cardapio);
    $subtotal = 0.0;
    $problemas = [];
    $esgotados = [];

    foreach ($itens as $idx => &$item) {
        $qtd = max(1, (int) ($item['quantidade'] ?? 1));
        $produto = $produtos[(string) ($item['idProduto'] ?? '')] ?? null;
        if (!$produto) {
            $problemas[] = 'Item fora do cardápio: ' . ($item['nome'] ?? '?');
            $subtotal += round((float) ($item['precoTotal'] ?? $item['precoBase'] ?? 0), 2) * $qtd;
            continue;
        }
        if (!empty($produto['esgotado']) || (isset($produto['ativo']) && !$produto['ativo'])) {
            $esgotados[] = (string) ($produto['nome'] ?? $item['nome'] ?? 'Produto');
        }

        $unitario = (float) ($produto['preco'] ?? 0);
        $tamanho = (string) ($item['tamanho'] ?? '');
        if ($tamanho !== '') {
            $achou = false;
            foreach ((array) ($produto['tamanhos'] ?? []) as $t) {
                if (is_array($t) && strcasecmp((string) ($t['nome'] ?? ''), $tamanho) === 0) {
                    $unitario = (float) ($t['preco'] ?? 0);
                    $achou = true;
                    break;
                }
            }
            if (!$achou) {
                $problemas[] = 'Tamanho desconhecido em ' . ($produto['nome'] ?? '?');
            }
        }

        $mapaOpc = [];
        foreach ((array) ($produto['opcionais'] ?? []) as $cad) {
            if (is_array($cad) && !empty($cad['nome'])) {
                $mapaOpc[strtolower(trim((string) $cad['nome']))] = (float) ($cad['preco'] ?? 0);
            }
        }
        foreach ((array) ($produto['grupos'] ?? []) as $grp) {
            if (!is_array($grp)) {
                continue;
            }
            foreach ((array) ($grp['itens'] ?? []) as $cadG) {
                if (is_array($cadG) && !empty($cadG['nome'])) {
                    $mapaOpc[strtolower(trim((string) $cadG['nome']))] = (float) ($cadG['preco'] ?? 0);
                }
            }
        }

        foreach ((array) ($item['opcionais'] ?? []) as $opIdx => $op) {
            $nomeOp = strtolower(trim((string) ($op['nome'] ?? '')));
            if (!array_key_exists($nomeOp, $mapaOpc)) {
                $problemas[] = 'Adicional desconhecido: ' . ($op['nome'] ?? '');
                continue;
            }
            $item['opcionais'][$opIdx]['preco'] = round($mapaOpc[$nomeOp], 2);
            $unitario += $mapaOpc[$nomeOp];
        }

        $item['precoTotal'] = round($unitario, 2);
        $subtotal += round($unitario, 2) * $qtd;
    }
    unset($item);

    return [round($subtotal, 2), $problemas, $esgotados];
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!pedidozap_limite_requisicao('save_order', 30, 60)) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'message' => 'Muitos pedidos em sequência. Aguarde um minuto e tente novamente.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$bruto = file_get_contents('php://input');
if (strlen((string) $bruto) > 200000) {
    http_response_code(413);
    echo json_encode(['success' => false, 'message' => 'Pedido grande demais.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pedido = json_decode($bruto, true);
pedidozap_bootstrap_tenant(is_array($pedido) ? $pedido : null);

if (pedidozap_loja_bloqueada(Database::getTenant())) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Este estabelecimento está temporariamente indisponível.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_array($pedido) || empty($pedido['itens']) || !is_array($pedido['itens'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Dados do pedido inválidos.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$id = preg_replace('/\D/', '', (string) ($pedido['id'] ?? ''));
if ($id === '' || strlen($id) > 18) {
    $id = (string) random_int(100000, 999999);
}

$codigo = (string) ($pedido['codigo'] ?? '');
if (!preg_match('/^[A-Za-z0-9]{12,40}$/', $codigo)) {
    $codigo = bin2hex(random_bytes(9));
}

$itens = [];
foreach (array_slice($pedido['itens'], 0, 100) as $item) {
    if (!is_array($item)) {
        continue;
    }
    $opcionais = [];
    foreach (array_slice((array) ($item['opcionais'] ?? []), 0, 40) as $op) {
        if (is_array($op)) {
            $opcionais[] = ['nome' => pz_texto($op['nome'] ?? '', 80), 'preco' => round((float) ($op['preco'] ?? 0), 2)];
        }
    }
    $itens[] = [
        'id' => pz_texto($item['id'] ?? '', 30),
        'idProduto' => pz_texto($item['idProduto'] ?? '', 40),
        'nome' => pz_texto($item['nome'] ?? '', 120),
        'imagem' => pz_texto($item['imagem'] ?? '', 500),
        'tamanho' => pz_texto($item['tamanho'] ?? '', 60),
        'precoBase' => round((float) ($item['precoBase'] ?? 0), 2),
        'precoTotal' => round((float) ($item['precoTotal'] ?? 0), 2),
        'quantidade' => max(1, min(99, (int) ($item['quantidade'] ?? 1))),
        'opcionais' => $opcionais,
    ];
}

if (!$itens) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'O pedido não tem itens.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$endereco = [];
foreach ((array) ($pedido['endereco'] ?? []) as $campo => $valor) {
    if (is_scalar($valor) || $valor === null) {
        $endereco[pz_texto($campo, 30)] = pz_texto($valor, 200);
    }
}

$frete = max(0, round((float) ($pedido['frete'] ?? 0), 2));
$totalCliente = round((float) ($pedido['total'] ?? 0), 2);
$cupomCodigo = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($pedido['cupom'] ?? '')));
$celularLimpo = preg_replace('/\D/', '', (string) ($pedido['celular'] ?? ''));
$clienteNome = pz_texto($pedido['cliente'] ?? '', 80);
$recebimentoModo = strtolower(pz_texto($pedido['recebimento'] ?? '', 30));
$canalPedido = strtolower(pz_texto($pedido['canal'] ?? '', 40));

if (mb_strlen($clienteNome) < 2) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Informe seu nome completo para finalizar o pedido.'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (strlen($celularLimpo) < 10 && strpos($canalPedido, 'balcao') === false && strpos($canalPedido, 'mesa') === false) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Informe um WhatsApp válido com DDD para acompanhar o pedido.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$ehRetiradaOuLocal = in_array($recebimentoModo, ['retirada', 'balcao', 'mesa', 'local'], true) || strpos($canalPedido, 'balcao') !== false;
if (!$ehRetiradaOuLocal) {
    $cepCheck = preg_replace('/\D/', '', (string) ($endereco['cep'] ?? ''));
    $ruaCheck = trim((string) ($endereco['rua'] ?? ''));
    $bairroCheck = trim((string) ($endereco['bairro'] ?? ''));
    $cidadeCheck = trim((string) ($endereco['cidade'] ?? ''));
    if ($ruaCheck === '' || $bairroCheck === '' || $cidadeCheck === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Informe rua, bairro e cidade para entrega.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$cfgLojaSave = Database::readStrict('config.json');
if (Database::getTenant() === 'default' && empty($cfgLojaSave)) {
    $cfgLojaSave = Database::read('config.json');
}
require_once 'store_schema.php';
$cfgLojaSave = pedidozap_normalizar_config(is_array($cfgLojaSave) ? $cfgLojaSave : [], Database::getTenant());

$userAuthOrd = $_GET['user'] ?? $pedido['auth']['user'] ?? '';
$passAuthOrd = $_GET['pass'] ?? $pedido['auth']['pass'] ?? '';
$equipeAuthOk = ($userAuthOrd !== '' || $passAuthOrd !== '') && (pedidozap_autorizado($userAuthOrd, $passAuthOrd) !== false);

$ehAgendado = stripos((string) ($pedido['entrega'] ?? ''), 'AGENDADO') !== false;
$emModoAnalise = $equipeAuthOk && !empty($pedido['modo_analise']);
$ehAtendenteAuth = $equipeAuthOk && strpos($canalPedido, 'atendente') !== false;
if (!$ehAtendenteAuth && !$ehAgendado && !$emModoAnalise) {
    $abertaCheck = pz_loja_aberta_backend($cfgLojaSave);
    if (empty($abertaCheck['aberta'])) {
        http_response_code(409);
        echo json_encode([
            'success' => false,
            'codigo_erro' => 'loja_fechada_ou_pausada',
            'message' => $abertaCheck['motivo'] ?? 'Estabelecimento fechado ou pausado no momento.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$checkoutToken = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($pedido['checkout_token'] ?? ''));

$cardapioServidor = Database::readStrict('cardapio.json');
if (Database::getTenant() === 'default' && empty($cardapioServidor)) {
    $cardapioServidor = Database::read('cardapio.json');
}
list($subtotalServidor, $problemas, $esgotados) = pz_conferir_itens($itens, is_array($cardapioServidor) ? $cardapioServidor : []);
if (!empty($esgotados)) {
    http_response_code(409);
    echo json_encode([
        'success' => false,
        'codigo_erro' => 'item_esgotado',
        'message' => 'Item esgotado agora: ' . implode(', ', array_slice($esgotados, 0, 3)) . '. Atualize o carrinho.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
if (!empty($problemas)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'codigo_erro' => 'itens_divergentes',
        'message' => 'Alguns itens ou adicionais do carrinho foram atualizados pela loja. Revise seu pedido.',
        'detalhes' => array_slice($problemas, 0, 5),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$descontoAplicado = 0.0;
$cupomInfo = null;
if ($cupomCodigo !== '') {
    $validacaoCupom = pz_validar_cupom($cupomCodigo, $subtotalServidor, $frete, $celularLimpo);
    if (!empty($validacaoCupom['valido'])) {
        $descontoAplicado = round((float) ($validacaoCupom['desconto'] ?? 0), 2);
        $cupomInfo = [
            'codigo' => $cupomCodigo,
            'tipo' => $validacaoCupom['tipo'] ?? '',
            'desconto' => $descontoAplicado,
        ];
    } elseif (isset($pedido['desconto']) && is_numeric($pedido['desconto']) && (float) $pedido['desconto'] > 0) {
        $problemas[] = 'Cupom ' . $cupomCodigo . ': ' . ($validacaoCupom['message'] ?? 'inválido.');
    }
}

$totalServidor = max(0.0, round($subtotalServidor + $frete - $descontoAplicado, 2));
$divergente = $problemas || abs($totalServidor - $totalCliente) > 0.05;
$totalAutoritativo = empty($problemas) ? $totalServidor : max($totalServidor, $totalCliente);

$registro = [
    'id' => $id,
    'codigo' => $codigo,
    'checkout_token' => $checkoutToken,
    'tenant' => Database::getTenant(),
    'data' => pz_texto($pedido['data'] ?? '', 40),
    'timestamp' => date('Y-m-d H:i:s'),
    'created_at' => date('c'),
    'updated_at' => date('c'),
    'updated_ms' => (int) round(microtime(true) * 1000),
    'cliente' => pz_texto($pedido['cliente'] ?? '', 80),
    'celular' => $celularLimpo,
    'itens' => $itens,
    'subtotal' => round($subtotalServidor, 2),
    'desconto' => $descontoAplicado,
    'total' => $totalAutoritativo,
    'frete' => $frete,
    'distancia_km' => isset($pedido['distancia_km']) && is_numeric($pedido['distancia_km']) ? round((float) $pedido['distancia_km'], 2) : null,
    'recebimento' => pz_texto($pedido['recebimento'] ?? '', 30),
    'frete_detalhe' => pz_texto($pedido['frete_detalhe'] ?? '', 160),
    'entrega' => pz_texto($pedido['entrega'] ?? '', 120),
    'endereco' => $endereco,
    'pagamento' => pz_texto($pedido['pagamento'] ?? '', 40),
    'troco' => pz_texto($pedido['troco'] ?? '', 20),
    'observacao' => pz_texto($pedido['observacao'] ?? '', 500),
    'status' => 'novo',
    'etapa_detalhada' => 'pendente',
    'canal' => pz_texto($pedido['canal'] ?? '', 40),
    'location' => null,
    'conferencia' => [
        'total_servidor' => $totalServidor,
        'total_cliente' => $totalCliente,
        'divergente' => (bool) $divergente,
        'problemas' => array_slice($problemas, 0, 10),
    ],
];

foreach (['tipo_entrega', 'retirada', 'cupom'] as $extra) {
    if (isset($pedido[$extra]) && is_scalar($pedido[$extra])) {
        $registro[$extra] = pz_texto($pedido[$extra], 80);
    }
}
if ($cupomInfo) {
    $registro['cupom'] = $cupomInfo['codigo'];
    $registro['cupom_detalhe'] = $cupomInfo;
}

$saveTx = Database::saveOrderAtomic($registro, $checkoutToken);
if (!empty($saveTx['deduplicated']) && is_array($saveTx['order'])) {
    $dup = $saveTx['order'];
    echo json_encode([
        'success' => true,
        'deduplicated' => true,
        'id' => $dup['id'] ?? $id,
        'codigo' => $dup['codigo'] ?? $codigo,
        'desconto' => $dup['desconto'] ?? 0,
        'total_servidor' => $dup['total'] ?? $totalServidor,
        'message' => 'Pedido já registrado (proteção contra duplicidade).',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!empty($saveTx['success'])) {
    if ($cupomInfo) {
        pz_incrementar_uso_cupom($cupomInfo['codigo']);
    }
    pz_sincronizar_cliente_pedido($registro);
    $sessaoCli = pz_texto($pedido['sessao_cliente'] ?? '', 80);
    pz_marcar_carrinho_recuperado($sessaoCli !== '' ? $sessaoCli : $celularLimpo, 'convertido');
    if (function_exists('pz_alg_registrar_evento')) {
        $consentIa = !array_key_exists('consentimento_ia', $pedido) || !empty($pedido['consentimento_ia']);
        $vid = pz_texto($pedido['visitor_id'] ?? $sessaoCli, 64);
        if ($consentIa && $vid !== '') {
            $cfgLojaAlg = Database::readStrict('config.json');
            $nichoLojaAlg = is_array($cfgLojaAlg) ? (string) ($cfgLojaAlg['NICHO'] ?? '') : '';
            $nomesItens = implode(' | ', array_filter(array_map(function ($i) {
                return trim((string) ($i['nome'] ?? ''));
            }, $itens)));
            pz_alg_registrar_evento([
                'visitor_id' => $vid,
                'evento' => 'pedido_concluido',
                'tenant' => Database::getTenant(),
                'nicho' => $nichoLojaAlg,
                'produto_nome' => $nomesItens,
                'preco' => $totalAutoritativo,
            ]);
        }
    }
    $slugSalvo = Database::getTenant();
    pz_realtime_publicar($slugSalvo, 'pedido_criado', [
        'id' => $id,
        'cliente' => $registro['cliente'],
        'total' => $totalAutoritativo,
        'status' => 'novo',
    ]);
    pz_pdv_enfileirar_evento($slugSalvo, 'order.created', $registro);
    pz_push_notificar_status_pedido($slugSalvo, $registro, 'novo');

    echo json_encode([
        'success' => true,
        'id' => $id,
        'codigo' => $codigo,
        'desconto' => $descontoAplicado,
        'total_servidor' => $totalServidor,
        'message' => 'Pedido salvo com sucesso.',
    ], JSON_UNESCAPED_UNICODE);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erro ao processar o pedido no servidor.'], JSON_UNESCAPED_UNICODE);
}
