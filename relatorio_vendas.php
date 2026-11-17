<?php
require_once __DIR__ . '/tenant_init.php';
require_once __DIR__ . '/auth_lib.php';
require_once __DIR__ . '/saas_lib.php';

header('Content-Type: application/json; charset=utf-8');

pedidozap_bootstrap_tenant();

$user = (string) ($_GET['user'] ?? '');
$pass = (string) ($_GET['pass'] ?? '');

if (!pedidozap_autorizado($user, $pass)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Não autorizado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$mesRef = (string) ($_GET['mes'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $mesRef)) {
    $mesRef = date('Y-m');
}

$inicio = $mesRef . '-01 00:00:00';
$fim = date('Y-m-t 23:59:59', strtotime($mesRef . '-01'));

$mapa = [];

foreach (Database::read('pedidos.json') as $pedido) {
    if (!is_array($pedido)) {
        continue;
    }
    $status = (string) ($pedido['status'] ?? 'novo');
    if ($status === 'arquivado') {
        continue;
    }

    $quando = (string) ($pedido['timestamp'] ?? $pedido['data'] ?? '');
    if ($quando === '') {
        continue;
    }
    $ts = strtotime(str_replace('/', '-', $quando));
    if ($ts === false) {
        $ts = strtotime($quando);
    }
    if ($ts === false) {
        continue;
    }
    $momento = date('Y-m-d H:i:s', $ts);
    if ($momento < $inicio || $momento > $fim) {
        continue;
    }

    foreach ((array) ($pedido['itens'] ?? []) as $item) {
        if (!is_array($item)) {
            continue;
        }
        $nomeBase = trim((string) ($item['nome'] ?? 'Item'));
        $tamanho = trim((string) ($item['tamanho'] ?? ''));
        $nome = $tamanho !== '' ? $nomeBase . ' (' . $tamanho . ')' : $nomeBase;
        $id = (string) ($item['idProduto'] ?? $item['id'] ?? $nome);
        $chave = $id . '|' . $nome;

        $qtd = max(0, (int) ($item['quantidade'] ?? 1));
        $unit = (float) ($item['precoTotal'] ?? 0);
        $valor = $unit * $qtd;

        if (!isset($mapa[$chave])) {
            $mapa[$chave] = [
                'id' => $id,
                'nome' => $nome,
                'quantidade' => 0,
                'faturamento' => 0.0,
            ];
        }
        $mapa[$chave]['quantidade'] += $qtd;
        $mapa[$chave]['faturamento'] += $valor;
    }
}

$lista = array_values($mapa);
usort($lista, function ($a, $b) {
    return $b['quantidade'] <=> $a['quantidade'];
});

foreach ($lista as &$row) {
    $row['faturamento'] = round($row['faturamento'], 2);
}
unset($row);

$mais = array_slice($lista, 0, min(10, count($lista)));
$menos = [];
if ($lista) {
    $asc = $lista;
    usort($asc, function ($a, $b) {
        return $a['quantidade'] <=> $b['quantidade'];
    });
    $menos = array_slice($asc, 0, min(10, count($asc)));
}

$totalPedidos = 0;
$totalItens = 0;
$faturamento = 0.0;
foreach (Database::read('pedidos.json') as $pedido) {
    if (!is_array($pedido) || ($pedido['status'] ?? '') === 'arquivado') {
        continue;
    }
    $quando = (string) ($pedido['timestamp'] ?? $pedido['data'] ?? '');
    $ts = strtotime($quando);
    if ($ts === false) {
        continue;
    }
    $momento = date('Y-m-d H:i:s', $ts);
    if ($momento < $inicio || $momento > $fim) {
        continue;
    }
    $totalPedidos++;
    $faturamento += (float) ($pedido['total'] ?? 0);
    foreach ((array) ($pedido['itens'] ?? []) as $item) {
        $totalItens += max(0, (int) ($item['quantidade'] ?? 1));
    }
}

echo json_encode([
    'success' => true,
    'mes' => $mesRef,
    'resumo' => [
        'pedidos' => $totalPedidos,
        'itens' => $totalItens,
        'faturamento' => round($faturamento, 2),
    ],
    'itens' => $lista,
    'mais_vendidos' => $mais,
    'menos_vendidos' => $menos,
], JSON_UNESCAPED_UNICODE);
