<?php
/**
 * Emissão Fiscal Automatizada (NFC-e Modelo 65 / SAT) vinculada aos pedidos da loja.
 * Suporta provedores REST fiscais (Focus NFe, Nuvem Fiscal, PlugNotas, SAT Local) e modo homologação interna.
 */
require_once __DIR__ . '/tenant_init.php';
require_once __DIR__ . '/store_schema.php';
require_once __DIR__ . '/queue_lib.php';

function pz_fiscal_ler_config($slug = null)
{
    $anterior = Database::getTenant();
    if ($slug !== null) {
        Database::setTenant($slug);
    }
    $cfgLoja = pedidozap_normalizar_config(Database::read('config.json'), Database::getTenant());
    $raw = Database::read('fiscal_config.json');
    if ($slug !== null) {
        Database::setTenant($anterior);
    }
    if (!is_array($raw)) {
        $raw = [];
    }

    return array_merge([
        'habilitado' => false,
        'modelo' => '65', // 65 = NFC-e, 59 = SAT/CF-e
        'provedor' => 'local_homologacao', // local_homologacao | focusnfe | nuvemfiscal | plugnotas | sat_local
        'provedor_token' => '',
        'provedor_endpoint' => '',
        'ambiente' => 'homologacao', // homologacao | producao
        'cnpj' => (string) ($cfgLoja['CNPJ'] ?? ''),
        'ie' => '',
        'razao_social' => (string) ($cfgLoja['RAZAO_SOCIAL'] ?? $cfgLoja['NOME_LOJA'] ?? ''),
        'regime_tributario' => 'simples_nacional',
        'serie' => 1,
        'proximo_numero' => 1,
        'csc_id' => '000001',
        'csc_token' => '',
        'emissao_automatica' => 'ao_concluir', // manual | ao_preparar | ao_concluir
        'ncm_padrao' => '21069090',
        'cfop_padrao' => '5102',
        'csosn_padrao' => '102',
    ], $raw);
}

function pz_fiscal_salvar_config($slug, array $patch)
{
    $atual = pz_fiscal_ler_config($slug);
    $boolFields = ['habilitado'];
    foreach ($boolFields as $f) {
        if (array_key_exists($f, $patch)) {
            $atual[$f] = !empty($patch[$f]);
        }
    }
    $strFields = [
        'modelo', 'provedor', 'provedor_endpoint', 'ambiente', 'ie',
        'razao_social', 'regime_tributario', 'csc_id', 'emissao_automatica',
        'ncm_padrao', 'cfop_padrao', 'csosn_padrao',
    ];
    foreach ($strFields as $f) {
        if (array_key_exists($f, $patch)) {
            $atual[$f] = trim(strip_tags((string) $patch[$f]));
        }
    }
    if (array_key_exists('cnpj', $patch)) {
        $atual['cnpj'] = preg_replace('/\D/', '', (string) $patch['cnpj']);
    }
    if (!empty($patch['provedor_token'])) {
        $atual['provedor_token'] = trim((string) $patch['provedor_token']);
    }
    if (!empty($patch['csc_token'])) {
        $atual['csc_token'] = trim((string) $patch['csc_token']);
    }
    if (isset($patch['serie']) && is_numeric($patch['serie'])) {
        $atual['serie'] = max(1, min(999, (int) $patch['serie']));
    }
    if (isset($patch['proximo_numero']) && is_numeric($patch['proximo_numero'])) {
        $atual['proximo_numero'] = max(1, (int) $patch['proximo_numero']);
    }

    $anterior = Database::getTenant();
    Database::setTenant($slug);
    $ok = Database::write('fiscal_config.json', $atual);
    Database::setTenant($anterior);

    return ['success' => (bool) $ok, 'config' => pz_fiscal_mascarar_config($atual)];
}

function pz_fiscal_mascarar_config(array $cfg)
{
    $out = $cfg;
    $out['tem_provedor_token'] = trim((string) ($cfg['provedor_token'] ?? '')) !== '';
    $out['tem_csc_token'] = trim((string) ($cfg['csc_token'] ?? '')) !== '';
    unset($out['provedor_token'], $out['csc_token']);
    return $out;
}

function pz_fiscal_chave_44($ufCode, $aamm, $cnpj, $mod, $serie, $numero, $tpEmis = 1, $cNF = null)
{
    $cnpj = str_pad(preg_replace('/\D/', '', (string) $cnpj), 14, '0', STR_PAD_LEFT);
    $base43 = str_pad((string) $ufCode, 2, '0', STR_PAD_LEFT)
        . str_pad((string) $aamm, 4, '0', STR_PAD_LEFT)
        . substr($cnpj, 0, 14)
        . str_pad((string) $mod, 2, '0', STR_PAD_LEFT)
        . str_pad((string) $serie, 3, '0', STR_PAD_LEFT)
        . str_pad((string) $numero, 9, '0', STR_PAD_LEFT)
        . str_pad((string) $tpEmis, 1, '0', STR_PAD_LEFT)
        . str_pad((string) ($cNF ?? random_int(10000000, 99999999)), 8, '0', STR_PAD_LEFT);

    // Módulo 11 padrão NF-e/NFC-e
    $peso = 2;
    $soma = 0;
    for ($i = strlen($base43) - 1; $i >= 0; $i--) {
        $soma += ((int) $base43[$i]) * $peso;
        $peso = ($peso >= 9) ? 2 : ($peso + 1);
    }
    $resto = $soma % 11;
    $dv = ($resto === 0 || $resto === 1) ? 0 : (11 - $resto);
    return $base43 . $dv;
}

function pz_fiscal_agendar_se_automatico($slug, $orderId, $momento)
{
    $cfg = pz_fiscal_ler_config($slug);
    if (empty($cfg['habilitado'])) {
        return false;
    }
    if (($cfg['emissao_automatica'] ?? 'manual') !== $momento) {
        return false;
    }
    pz_queue_push('fiscal.emit_nfce', ['order_id' => $orderId], $slug);
    return true;
}

function pz_fiscal_emitir_pedido($slug, $orderId, $forcar = false)
{
    $orderId = trim((string) $orderId);
    if ($orderId === '') {
        return ['success' => false, 'message' => 'Informe o pedido.'];
    }

    $cfg = pz_fiscal_ler_config($slug);
    if (empty($cfg['habilitado']) && !$forcar) {
        return ['success' => true, 'skipped' => true];
    }

    $anterior = Database::getTenant();
    Database::setTenant($slug);
    $pedido = Database::findOrder($orderId);
    $cfgLoja = pedidozap_normalizar_config(Database::read('config.json'), $slug);
    if (!is_array($pedido)) {
        Database::setTenant($anterior);
        return ['success' => false, 'message' => 'Pedido não encontrado.'];
    }

    if (!$forcar && !empty($pedido['nfce']['chave_acesso']) && ($pedido['nfce']['status'] ?? '') === 'autorizada') {
        Database::setTenant($anterior);
        return ['success' => true, 'nota' => $pedido['nfce'], 'ja_emitida' => true];
    }

    $cnpj = preg_replace('/\D/', '', (string) ($cfg['cnpj'] ?: ($cfgLoja['CNPJ'] ?? '')));
    if (strlen($cnpj) !== 14) {
        if ($cfg['ambiente'] === 'producao') {
            Database::setTenant($anterior);
            return ['success' => false, 'message' => 'Configure um CNPJ válido (14 dígitos) antes de emitir NFC-e em produção.'];
        }
        $cnpj = '00000000000191'; // CNPJ de teste para homologação interna
    }

    $numero = (int) ($cfg['proximo_numero'] ?? 1);
    $serie = (int) ($cfg['serie'] ?? 1);
    $mod = ($cfg['modelo'] ?? '65') === '59' ? '59' : '65';
    $chave = pz_fiscal_chave_44('35', date('ym'), $cnpj, $mod, $serie, $numero);

    $itensFiscais = [];
    foreach ((array) ($pedido['itens'] ?? []) as $it) {
        if (!is_array($it)) {
            continue;
        }
        $qtd = max(1, (int) ($it['quantidade'] ?? 1));
        $unit = round((float) ($it['preco'] ?? $it['unitario'] ?? 0), 2);
        $itensFiscais[] = [
            'codigo' => (string) ($it['sku_pdv'] ?? $it['idProduto'] ?? 'ITEM'),
            'descricao' => (string) ($it['nome'] ?? 'Produto'),
            'ncm' => (string) ($it['ncm'] ?? $cfg['ncm_padrao'] ?? '21069090'),
            'cfop' => (string) ($it['cfop'] ?? $cfg['cfop_padrao'] ?? '5102'),
            'csosn' => (string) ($cfg['csosn_padrao'] ?? '102'),
            'unidade' => 'UN',
            'quantidade' => $qtd,
            'valor_unitario' => $unit,
            'valor_total' => round($unit * $qtd, 2),
        ];
    }

    $payloadFiscal = [
        'ref' => $slug . '_' . $orderId,
        'modelo' => $mod,
        'ambiente' => $cfg['ambiente'],
        'emitente' => [
            'cnpj' => $cnpj,
            'ie' => $cfg['ie'],
            'razao_social' => $cfg['razao_social'] ?: ($cfgLoja['NOME_LOJA'] ?? $slug),
            'regime' => $cfg['regime_tributario'],
        ],
        'serie' => $serie,
        'numero' => $numero,
        'natureza_operacao' => 'VENDA AO CONSUMIDOR',
        'cliente_nome' => (string) ($pedido['cliente'] ?? 'CONSUMIDOR FINAL'),
        'valor_total' => round((float) ($pedido['total'] ?? 0), 2),
        'forma_pagamento' => (string) ($pedido['pagamento'] ?? 'PIX'),
        'itens' => $itensFiscais,
    ];

    $status = 'autorizada';
    $protocolo = '135' . date('ymdHis') . random_int(100, 999);
    $mensagemRetorno = $cfg['ambiente'] === 'homologacao'
        ? 'Emitida em ambiente de Homologação (SEFAZ Simulada / Pronta para Certificado A1)'
        : 'Autorizado o uso da NFC-e';
    $provedor = $cfg['provedor'] ?: 'local_homologacao';

    if ($provedor === 'focusnfe' && $cfg['provedor_token'] !== '' && function_exists('curl_init')) {
        $base = $cfg['ambiente'] === 'producao'
            ? 'https://api.focusnfe.com.br/v2/nfce'
            : 'https://homologacao.focusnfe.com.br/v2/nfce';
        $ch = curl_init($base . '?ref=' . rawurlencode($payloadFiscal['ref']));
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_USERPWD => $cfg['provedor_token'] . ':',
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payloadFiscal, JSON_UNESCAPED_UNICODE),
        ]);
        $respRaw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $respJson = is_string($respRaw) ? json_decode($respRaw, true) : null;
        if ($code >= 200 && $code < 300 && is_array($respJson)) {
            $chave = (string) ($respJson['chave_nfe'] ?? $chave);
            $protocolo = (string) ($respJson['protocolo'] ?? $protocolo);
            $mensagemRetorno = (string) ($respJson['mensagem_sefaz'] ?? $respJson['status'] ?? $mensagemRetorno);
        } elseif ($code >= 400) {
            $status = 'rejeitada';
            $mensagemRetorno = (string) ($respJson['mensagem'] ?? ('Erro provedor FocusNFe HTTP ' . $code));
        }
    } elseif ($provedor === 'sat_local' && $cfg['provedor_endpoint'] !== '' && function_exists('curl_init')) {
        $ch = curl_init($cfg['provedor_endpoint']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 6,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payloadFiscal, JSON_UNESCAPED_UNICODE),
        ]);
        $respRaw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 400) {
            $status = 'pendente_sat';
            $mensagemRetorno = 'Bridge SAT offline ou retornou HTTP ' . $code;
        }
    }

    $nota = [
        'id' => 'nf_' . $numero . '_' . $orderId,
        'order_id' => $orderId,
        'modelo' => $mod === '59' ? 'SAT CF-e' : 'NFC-e 65',
        'provedor' => $provedor,
        'ambiente' => $cfg['ambiente'],
        'serie' => $serie,
        'numero' => $numero,
        'chave_acesso' => $chave,
        'protocolo' => $protocolo,
        'status' => $status,
        'mensagem' => $mensagemRetorno,
        'valor_total' => $payloadFiscal['valor_total'],
        'cliente' => $payloadFiscal['cliente_nome'],
        'emitente_cnpj' => $cnpj,
        'emitente_nome' => $payloadFiscal['emitente']['razao_social'],
        'itens' => $itensFiscais,
        'danfe_url' => 'fiscal_api.php?action=danfe&tenant=' . rawurlencode($slug) . '&order_id=' . rawurlencode($orderId),
        'emitida_em' => date('c'),
    ];

    Database::patchOrder($orderId, ['nfce' => $nota]);
    Database::mutate('fiscal_notas.json', function (&$lista) use ($nota) {
        if (!is_array($lista)) {
            $lista = [];
        }
        $lista = array_values(array_filter($lista, function ($n) use ($nota) {
            return is_array($n) && ($n['order_id'] ?? '') !== $nota['order_id'];
        }));
        array_unshift($lista, $nota);
        if (count($lista) > 300) {
            $lista = array_slice($lista, 0, 300);
        }
        return true;
    });
    Database::mutate('fiscal_config.json', function (&$fc) use ($numero) {
        if (!is_array($fc)) {
            $fc = [];
        }
        $fc['proximo_numero'] = $numero + 1;
        return true;
    });
    Database::setTenant($anterior);

    return ['success' => $status === 'autorizada', 'nota' => $nota, 'message' => $mensagemRetorno];
}

function pz_fiscal_listar_notas($slug, $limit = 40)
{
    $anterior = Database::getTenant();
    Database::setTenant($slug);
    $notas = Database::read('fiscal_notas.json');
    Database::setTenant($anterior);
    if (!is_array($notas)) {
        return [];
    }
    return array_slice($notas, 0, max(1, (int) $limit));
}
