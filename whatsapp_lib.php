<?php
/**
 * Gateway Unificado de WhatsApp + IA Ao Vivo (AcheIAki)
 * - Suporta Evolution API (open-source / QR Code), Meta Cloud API Oficial e Z-API
 * - Funciona com gateway central da plataforma (SuperAdmin) OU gateway próprio da loja
 * - Inclui Caixa de Entrada Ao Vivo (wpp_conversas.json) + Resposta Automática via IA do Cardápio
 * - Avisos automáticos de status de pedido na fila assíncrona sem travar o checkout
 */
require_once __DIR__ . '/tenant_init.php';
require_once __DIR__ . '/integracoes_lib.php';
require_once __DIR__ . '/store_schema.php';
require_once __DIR__ . '/gestao_lib.php';
require_once __DIR__ . '/algoritmo_lib.php';
require_once __DIR__ . '/saas_lib.php';

function pz_wpp_normalizar_slug($slug)
{
    $s = preg_replace('/[^a-z0-9_-]/i', '', (string) $slug);
    return $s !== '' ? strtolower($s) : 'default';
}

function pz_wpp_normalizar_telefone($telefone)
{
    $d = preg_replace('/\D/', '', (string) $telefone);
    if ($d === '') {
        return '';
    }
    if (strlen($d) === 10 || strlen($d) === 11) {
        return '55' . $d;
    }
    return $d;
}

function pz_wpp_chave_chat($telefone)
{
    $d = pz_wpp_normalizar_telefone($telefone);
    if (strlen($d) >= 10 && substr($d, 0, 2) === '55') {
        $ddd = substr($d, 2, 2);
        $ultimos8 = substr($d, -8);
        return '55' . $ddd . $ultimos8;
    }
    return $d;
}

function pz_wpp_validar_url($url)
{
    $u = trim((string) $url);
    if ($u === '') {
        return '';
    }
    $u = rtrim($u, '/');
    if (!preg_match('#^https?://[a-zA-Z0-9._:-]+(?:/.*)?$#', $u)) {
        return '';
    }
    return $u;
}

function pz_wpp_ler_config($slug = null)
{
    $slug = pz_wpp_normalizar_slug($slug ?: Database::getTenant());
    $anterior = Database::getTenant();
    Database::setTenant($slug);
    $salvo = Database::readStrict('wpp_config.json');
    Database::setTenant($anterior);
    if (!is_array($salvo)) {
        $salvo = [];
    }

    $instPadrao = 'acheiaki-' . $slug;
    return array_merge([
        'provider' => 'plataforma', // plataforma | evolution | meta | zapi
        'api_url' => '',
        'api_key' => '',
        'instance' => $instPadrao,
        'phone_id' => '',
        'ia_auto_responder' => true,
        'notificar_pedidos' => true,
        'saudacao_personalizada' => '',
        'apenas_horario_loja' => false,
        'conectado' => false,
        'numero_conectado' => '',
        'ultimo_check' => '',
    ], $salvo);
}

function pz_wpp_resolver_gateway($slug = null)
{
    $slug = pz_wpp_normalizar_slug($slug ?: Database::getTenant());
    $lojaCfg = pz_wpp_ler_config($slug);
    $plat = pedidozap_integracoes_config();

    $providerLoja = strtolower(trim((string) ($lojaCfg['provider'] ?? 'plataforma')));
    if ($providerLoja === 'plataforma' || $providerLoja === '') {
        $prov = strtolower(trim((string) ($plat['wpp_provider'] ?? 'evolution')));
        $url = pz_wpp_validar_url($plat['wpp_api_url'] ?? '');
        $key = trim((string) ($plat['wpp_api_key'] ?? ''));
        $instance = trim((string) ($lojaCfg['instance'] ?? '')) ?: ('acheiaki-' . $slug);
        $phoneId = trim((string) ($lojaCfg['phone_id'] ?? ($plat['wpp_phone_id'] ?? '')));
        $origem = 'plataforma';
    } else {
        $prov = in_array($providerLoja, ['evolution', 'meta', 'zapi'], true) ? $providerLoja : 'evolution';
        $url = pz_wpp_validar_url($lojaCfg['api_url'] ?? '');
        $key = trim((string) ($lojaCfg['api_key'] ?? ''));
        $instance = trim((string) ($lojaCfg['instance'] ?? '')) ?: ('acheiaki-' . $slug);
        $phoneId = trim((string) ($lojaCfg['phone_id'] ?? ''));
        $origem = 'loja';
    }

    $gatewayPronto = false;
    if ($prov === 'evolution') {
        $gatewayPronto = ($url !== '' && $key !== '' && $instance !== '');
    } elseif ($prov === 'meta') {
        $gatewayPronto = ($key !== '' && $phoneId !== '');
    } elseif ($prov === 'zapi') {
        $gatewayPronto = ($instance !== '' && $key !== '');
    }

    return [
        'slug' => $slug,
        'origem' => $origem,
        'provider' => $prov,
        'api_url' => $url !== '' ? $url : ($prov === 'zapi' ? 'https://api.z-api.io' : 'https://graph.facebook.com/v21.0'),
        'api_key' => $key,
        'instance' => $instance,
        'phone_id' => $phoneId,
        'gateway_pronto' => $gatewayPronto,
        'plataforma_pronta' => pz_wpp_validar_url($plat['wpp_api_url'] ?? '') !== '' && trim((string) ($plat['wpp_api_key'] ?? '')) !== '',
        'loja_cfg' => $lojaCfg,
    ];
}

function pz_wpp_salvar_config($slug, array $patch)
{
    $slug = pz_wpp_normalizar_slug($slug);
    $atual = pz_wpp_ler_config($slug);

    if (isset($patch['provider'])) {
        $p = strtolower(trim((string) $patch['provider']));
        if (in_array($p, ['plataforma', 'evolution', 'meta', 'zapi'], true)) {
            $atual['provider'] = $p;
        }
    }
    if (array_key_exists('api_url', $patch)) {
        $u = trim((string) $patch['api_url']);
        $atual['api_url'] = $u === '' ? '' : pz_wpp_validar_url($u);
    }
    if (array_key_exists('api_key', $patch)) {
        $k = trim((string) $patch['api_key']);
        if ($k !== '') {
            $atual['api_key'] = $k;
        }
    }
    if (!empty($patch['remover_api_key'])) {
        $atual['api_key'] = '';
    }
    if (array_key_exists('instance', $patch)) {
        $inst = preg_replace('/[^a-zA-Z0-9._-]/', '', trim((string) $patch['instance']));
        $atual['instance'] = $inst !== '' ? $inst : ('acheiaki-' . $slug);
    }
    if (array_key_exists('phone_id', $patch)) {
        $atual['phone_id'] = preg_replace('/[^a-zA-Z0-9._-]/', '', trim((string) $patch['phone_id']));
    }
    foreach (['ia_auto_responder', 'notificar_pedidos', 'apenas_horario_loja', 'conectado'] as $boolKey) {
        if (array_key_exists($boolKey, $patch)) {
            $atual[$boolKey] = !empty($patch[$boolKey]);
        }
    }
    if (array_key_exists('saudacao_personalizada', $patch)) {
        $atual['saudacao_personalizada'] = mb_substr(trim((string) $patch['saudacao_personalizada']), 0, 400, 'UTF-8');
    }
    if (array_key_exists('numero_conectado', $patch)) {
        $atual['numero_conectado'] = pz_wpp_normalizar_telefone($patch['numero_conectado']);
    }
    if (array_key_exists('ultimo_check', $patch)) {
        $atual['ultimo_check'] = (string) $patch['ultimo_check'];
    }

    $anterior = Database::getTenant();
    Database::setTenant($slug);
    $ok = Database::write('wpp_config.json', $atual);
    Database::setTenant($anterior);
    return $ok ? $atual : false;
}

function pz_wpp_config_publica($slug = null)
{
    $slug = pz_wpp_normalizar_slug($slug ?: Database::getTenant());
    $gw = pz_wpp_resolver_gateway($slug);
    $cfg = $gw['loja_cfg'];
    $base = function_exists('pedidozap_base_url') ? pedidozap_base_url() : '';

    return [
        'provider' => $cfg['provider'] ?? 'plataforma',
        'gateway_efetivo' => $gw['provider'],
        'origem_gateway' => $gw['origem'],
        'gateway_pronto' => $gw['gateway_pronto'],
        'plataforma_pronta' => $gw['plataforma_pronta'],
        'api_url' => (string) ($cfg['api_url'] ?? ''),
        'api_key_configurada' => trim((string) ($cfg['api_key'] ?? '')) !== '',
        'instance' => (string) ($cfg['instance'] ?? ('acheiaki-' . $slug)),
        'phone_id' => (string) ($cfg['phone_id'] ?? ''),
        'ia_auto_responder' => !empty($cfg['ia_auto_responder']),
        'notificar_pedidos' => !empty($cfg['notificar_pedidos']),
        'apenas_horario_loja' => !empty($cfg['apenas_horario_loja']),
        'saudacao_personalizada' => (string) ($cfg['saudacao_personalizada'] ?? ''),
        'conectado' => !empty($cfg['conectado']),
        'numero_conectado' => (string) ($cfg['numero_conectado'] ?? ''),
        'webhook_url' => ($base !== '' ? $base : '') . '/whatsapp_webhook.php?tenant=' . rawurlencode($slug),
    ];
}

function pz_wpp_http_request($method, $url, array $headers = [], $body = null, $timeout = 10)
{
    $method = strtoupper($method);
    $payloadStr = is_array($body) ? json_encode($body, JSON_UNESCAPED_UNICODE) : (is_string($body) ? $body : '');

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $hdrs = array_merge(['Accept: application/json', 'User-Agent: AcheIAki-WhatsApp/1.0'], $headers);
        if ($payloadStr !== '' && $method !== 'GET') {
            $hdrs[] = 'Content-Type: application/json';
        }
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $hdrs,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => min(5, $timeout),
        ];
        if ($payloadStr !== '' && $method !== 'GET') {
            $opts[CURLOPT_POSTFIELDS] = $payloadStr;
        }
        curl_setopt_array($ch, $opts);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        $json = is_string($raw) ? json_decode($raw, true) : null;
        return [
            'ok' => $code >= 200 && $code < 300,
            'status' => $code,
            'json' => is_array($json) ? $json : null,
            'raw' => is_string($raw) ? $raw : '',
            'error' => $err ?: ($code === 0 ? 'Sem resposta do servidor WhatsApp' : ''),
        ];
    }

    $hdrLines = array_merge(['Accept: application/json', 'User-Agent: AcheIAki-WhatsApp/1.0'], $headers);
    if ($payloadStr !== '' && $method !== 'GET') {
        $hdrLines[] = 'Content-Type: application/json';
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => $method,
            'timeout' => $timeout,
            'ignore_errors' => true,
            'header' => implode("\r\n", $hdrLines) . "\r\n",
            'content' => ($method !== 'GET' ? $payloadStr : ''),
        ],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    $code = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $h) {
            if (preg_match('/HTTP\/\S+\s+(\d{3})/', $h, $m)) {
                $code = (int) $m[1];
                break;
            }
        }
    }
    $json = is_string($raw) ? json_decode($raw, true) : null;
    return [
        'ok' => $code >= 200 && $code < 300,
        'status' => $code,
        'json' => is_array($json) ? $json : null,
        'raw' => is_string($raw) ? $raw : '',
        'error' => $raw === false ? 'Falha de conexão HTTP com API do WhatsApp' : '',
    ];
}

function pz_wpp_status_conexao($slug = null)
{
    $gw = pz_wpp_resolver_gateway($slug);
    $cfg = $gw['loja_cfg'];

    if (!$gw['gateway_pronto']) {
        return [
            'ok' => true,
            'estado' => 'modo_teste',
            'conectado' => false,
            'rotulo' => 'Simulador IA Ao Vivo (sem token de WhatsApp ainda)',
            'detalhe' => 'Configure a API do WhatsApp no Painel Master (ou cole sua URL/Token abaixo) para enviar mensagens reais.',
        ];
    }

    $prov = $gw['provider'];
    if ($prov === 'evolution') {
        $res = pz_wpp_http_request(
            'GET',
            $gw['api_url'] . '/instance/connectionState/' . rawurlencode($gw['instance']),
            ['apikey: ' . $gw['api_key']],
            null,
            6
        );
        $state = strtolower((string) ($res['json']['instance']['state'] ?? $res['json']['state'] ?? ''));
        $conectado = ($state === 'open' || $state === 'connected');
        pz_wpp_salvar_config($gw['slug'], ['conectado' => $conectado, 'ultimo_check' => date('c')]);
        return [
            'ok' => true,
            'estado' => $conectado ? 'conectado' : ($res['status'] === 404 ? 'nao_criada' : 'aguardando_qr'),
            'conectado' => $conectado,
            'rotulo' => $conectado ? 'WhatsApp conectado ao vivo (Evolution)' : 'Aguardando leitura do QR Code',
            'detalhe' => $res['error'] ?: ($state !== '' ? 'Estado da instância: ' . $state : 'Instância pronta para parear.'),
        ];
    }

    if ($prov === 'meta') {
        $res = pz_wpp_http_request(
            'GET',
            $gw['api_url'] . '/' . rawurlencode($gw['phone_id']) . '?fields=display_phone_number,verified_name',
            ['Authorization: Bearer ' . $gw['api_key']],
            null,
            6
        );
        $conectado = !empty($res['ok']);
        $numMeta = (string) ($res['json']['display_phone_number'] ?? '');
        pz_wpp_salvar_config($gw['slug'], array_filter([
            'conectado' => $conectado,
            'numero_conectado' => $numMeta !== '' ? $numMeta : ($cfg['numero_conectado'] ?? ''),
            'ultimo_check' => date('c'),
        ], function ($v) { return $v !== ''; }));
        return [
            'ok' => $conectado,
            'estado' => $conectado ? 'conectado' : 'erro_credencial',
            'conectado' => $conectado,
            'rotulo' => $conectado ? ('Meta Oficial conectada' . ($numMeta ? ' (' . $numMeta . ')' : '')) : 'Token ou Phone ID Meta inválido',
            'detalhe' => $res['json']['verified_name'] ?? ($res['json']['error']['message'] ?? $res['error']),
        ];
    }

    if ($prov === 'zapi') {
        $res = pz_wpp_http_request(
            'GET',
            $gw['api_url'] . '/instances/' . rawurlencode($gw['instance']) . '/token/' . rawurlencode($gw['api_key']) . '/status',
            [],
            null,
            6
        );
        $conectado = !empty($res['json']['connected']);
        pz_wpp_salvar_config($gw['slug'], ['conectado' => $conectado, 'ultimo_check' => date('c')]);
        return [
            'ok' => true,
            'estado' => $conectado ? 'conectado' : 'aguardando_qr',
            'conectado' => $conectado,
            'rotulo' => $conectado ? 'Z-API conectado ao vivo' : 'Z-API aguardando QR Code',
            'detalhe' => (string) ($res['json']['error'] ?? $res['error'] ?? ''),
        ];
    }

    return ['ok' => false, 'estado' => 'desconhecido', 'conectado' => false, 'rotulo' => 'Provedor desconhecido'];
}

function pz_wpp_qr_ou_conectar($slug = null)
{
    $gw = pz_wpp_resolver_gateway($slug);
    if (!$gw['gateway_pronto']) {
        return [
            'success' => false,
            'message' => 'Configure primeiro a URL/Token do WhatsApp (Evolution API ou Z-API) na plataforma ou nesta loja.',
        ];
    }

    $baseUrl = function_exists('pedidozap_base_url') ? pedidozap_base_url() : '';
    $webhookUrl = ($baseUrl !== '' ? $baseUrl : '') . '/whatsapp_webhook.php?tenant=' . rawurlencode($gw['slug']);

    if ($gw['provider'] === 'evolution') {
        $hdr = ['apikey: ' . $gw['api_key']];
        $conn = pz_wpp_http_request('GET', $gw['api_url'] . '/instance/connect/' . rawurlencode($gw['instance']), $hdr, null, 10);
        if (!$conn['ok'] || $conn['status'] === 404) {
            pz_wpp_http_request('POST', $gw['api_url'] . '/instance/create', $hdr, [
                'instanceName' => $gw['instance'],
                'qrcode' => true,
                'integration' => 'WHATSAPP-BAILEYS',
                'webhook' => [
                    'url' => $webhookUrl,
                    'byEvents' => false,
                    'base64' => false,
                    'events' => ['MESSAGES_UPSERT', 'CONNECTION_UPDATE'],
                ],
            ], 12);
            $conn = pz_wpp_http_request('GET', $gw['api_url'] . '/instance/connect/' . rawurlencode($gw['instance']), $hdr, null, 10);
        } else {
            pz_wpp_http_request('POST', $gw['api_url'] . '/webhook/set/' . rawurlencode($gw['instance']), $hdr, [
                'webhook' => [
                    'enabled' => true,
                    'url' => $webhookUrl,
                    'webhookByEvents' => false,
                    'events' => ['MESSAGES_UPSERT', 'CONNECTION_UPDATE'],
                ],
            ], 6);
        }

        $qrBase64 = (string) ($conn['json']['base64'] ?? $conn['json']['qrcode']['base64'] ?? '');
        $pairingCode = (string) ($conn['json']['pairingCode'] ?? $conn['json']['code'] ?? '');
        $jaConectado = (($conn['json']['instance']['state'] ?? '') === 'open');
        if ($jaConectado) {
            pz_wpp_salvar_config($gw['slug'], ['conectado' => true]);
        }
        return [
            'success' => $qrBase64 !== '' || $pairingCode !== '' || $jaConectado,
            'conectado' => $jaConectado,
            'qrcode_base64' => $qrBase64,
            'pairing_code' => $pairingCode,
            'message' => $jaConectado
                ? 'WhatsApp já está conectado nesta instância!'
                : ($qrBase64 !== '' ? 'Aponte a câmera do WhatsApp da loja (Dispositivos Conectados) para este QR Code.' : ($conn['error'] ?: 'Não foi possível gerar QR Code nesta instância.')),
        ];
    }

    if ($gw['provider'] === 'zapi') {
        $conn = pz_wpp_http_request(
            'GET',
            $gw['api_url'] . '/instances/' . rawurlencode($gw['instance']) . '/token/' . rawurlencode($gw['api_key']) . '/qr-code/image',
            [],
            null,
            10
        );
        $qrBase64 = (string) ($conn['json']['value'] ?? '');
        $jaConectado = !empty($conn['json']['connected']);
        return [
            'success' => $qrBase64 !== '' || $jaConectado,
            'conectado' => $jaConectado,
            'qrcode_base64' => $qrBase64,
            'message' => $jaConectado ? 'WhatsApp já está conectado na Z-API!' : ($qrBase64 !== '' ? 'Escaneie o QR Code com o WhatsApp da loja.' : ($conn['error'] ?: 'Falha ao obter QR Code da Z-API.')),
        ];
    }

    return [
        'success' => false,
        'message' => 'A Meta Cloud API Oficial não usa QR Code: configure o Webhook na Meta usando a URL exibida e o Token/Phone ID.',
    ];
}

function pz_wpp_registrar_mensagem($slug, $telefone, $direcao, $texto, array $extra = [])
{
    $slug = pz_wpp_normalizar_slug($slug);
    $telNorm = pz_wpp_normalizar_telefone($telefone);
    $chave = pz_wpp_chave_chat($telNorm);
    if ($chave === '' || trim((string) $texto) === '') {
        return false;
    }

    $anterior = Database::getTenant();
    Database::setTenant($slug);
    $threadAtual = null;

    Database::mutate('wpp_conversas.json', function (&$dados) use ($chave, $telNorm, $direcao, $texto, $extra, &$threadAtual) {
        if (!is_array($dados)) {
            $dados = [];
        }
        $idx = -1;
        foreach ($dados as $i => $c) {
            if (is_array($c) && ($c['chave'] ?? '') === $chave) {
                $idx = $i;
                break;
            }
        }
        if ($idx === -1) {
            $thread = [
                'chave' => $chave,
                'telefone' => $telNorm,
                'nome' => trim((string) ($extra['nome'] ?? '')) ?: ('Cliente ' . substr($telNorm, -4)),
                'ia_pausada' => false,
                'nao_lidas' => 0,
                'atualizado_em' => date('c'),
                'mensagens' => [],
            ];
        } else {
            $thread = $dados[$idx];
            array_splice($dados, $idx, 1);
            if (!empty($extra['nome']) && (($thread['nome'] ?? '') === '' || strpos((string) $thread['nome'], 'Cliente ') === 0)) {
                $thread['nome'] = trim((string) $extra['nome']);
            }
        }

        $msgId = trim((string) ($extra['msg_id'] ?? ''));
        if ($msgId !== '' && !empty($thread['mensagens'])) {
            foreach ($thread['mensagens'] as $mExist) {
                if (($mExist['msg_id'] ?? '') === $msgId) {
                    $threadAtual = $thread;
                    array_unshift($dados, $thread);
                    return false; // duplicada
                }
            }
        }

        $msgItem = [
            'id' => 'wm_' . substr(md5($telNorm . microtime(true) . random_int(100, 999)), 0, 10),
            'msg_id' => $msgId,
            'direcao' => $direcao === 'entrada' ? 'entrada' : 'saida',
            'autor' => $extra['autor'] ?? ($direcao === 'entrada' ? 'cliente' : 'ia'), // cliente | ia | humano | sistema
            'texto' => mb_substr(trim((string) $texto), 0, 2000, 'UTF-8'),
            'status_envio' => $extra['status_envio'] ?? 'ok',
            'criado_em' => date('c'),
        ];

        $thread['mensagens'][] = $msgItem;
        if (count($thread['mensagens']) > 30) {
            $thread['mensagens'] = array_slice($thread['mensagens'], -30);
        }
        $thread['ultima_mensagem'] = $msgItem['texto'];
        $thread['ultima_direcao'] = $msgItem['direcao'];
        $thread['atualizado_em'] = $msgItem['criado_em'];
        if ($direcao === 'entrada') {
            $thread['nao_lidas'] = ((int) ($thread['nao_lidas'] ?? 0)) + 1;
        } else {
            $thread['nao_lidas'] = 0;
        }

        $threadAtual = $thread;
        array_unshift($dados, $thread);
        if (count($dados) > 60) {
            $dados = array_slice($dados, 0, 60);
        }
        return true;
    });

    Database::setTenant($anterior);

    if (function_exists('pz_realtime_publicar') && $threadAtual) {
        pz_realtime_publicar($slug, 'wpp_mensagem', [
            'chave' => $chave,
            'telefone' => $telNorm,
            'nome' => $threadAtual['nome'] ?? '',
            'direcao' => $direcao,
            'autor' => $extra['autor'] ?? ($direcao === 'entrada' ? 'cliente' : 'ia'),
            'texto' => mb_substr(trim((string) $texto), 0, 180, 'UTF-8'),
        ]);
    }

    return $threadAtual;
}

function pz_wpp_listar_conversas($slug = null)
{
    $slug = pz_wpp_normalizar_slug($slug ?: Database::getTenant());
    $anterior = Database::getTenant();
    Database::setTenant($slug);
    $dados = Database::readStrict('wpp_conversas.json');
    Database::setTenant($anterior);
    return is_array($dados) ? array_values($dados) : [];
}

function pz_wpp_alternar_ia_conversa($slug, $telefone, $iaAtiva)
{
    $slug = pz_wpp_normalizar_slug($slug);
    $chave = pz_wpp_chave_chat($telefone);
    $anterior = Database::getTenant();
    Database::setTenant($slug);
    $achou = false;
    Database::mutate('wpp_conversas.json', function (&$dados) use ($chave, $iaAtiva, &$achou) {
        if (!is_array($dados)) {
            return false;
        }
        foreach ($dados as $i => $c) {
            if (is_array($c) && ($c['chave'] ?? '') === $chave) {
                $dados[$i]['ia_pausada'] = !$iaAtiva;
                $dados[$i]['nao_lidas'] = 0;
                $achou = true;
                return true;
            }
        }
        return false;
    });
    Database::setTenant($anterior);
    return $achou;
}

function pz_wpp_enviar_texto($slug, $telefone, $mensagem, array $opcoes = [])
{
    $slug = pz_wpp_normalizar_slug($slug);
    $tel = pz_wpp_normalizar_telefone($telefone);
    $texto = trim((string) $mensagem);
    if ($tel === '' || $texto === '') {
        return ['success' => false, 'message' => 'Telefone ou mensagem inválida.'];
    }

    $gw = pz_wpp_resolver_gateway($slug);
    $autor = $opcoes['autor'] ?? 'ia';
    $nome = $opcoes['nome'] ?? '';
    $registrar = !array_key_exists('registrar', $opcoes) || !empty($opcoes['registrar']);

    $enviadoExterno = false;
    $statusEnvio = 'simulado';
    $erroExterno = '';

    if ($gw['gateway_pronto']) {
        $prov = $gw['provider'];
        if ($prov === 'evolution') {
            $res = pz_wpp_http_request(
                'POST',
                $gw['api_url'] . '/message/sendText/' . rawurlencode($gw['instance']),
                ['apikey: ' . $gw['api_key']],
                ['number' => $tel, 'text' => $texto, 'delay' => 600],
                10
            );
            $enviadoExterno = !empty($res['ok']);
            $statusEnvio = $enviadoExterno ? 'enviado_api' : 'falha_api';
            $erroExterno = $enviadoExterno ? '' : ($res['error'] ?: ('HTTP ' . $res['status']));
        } elseif ($prov === 'meta') {
            $res = pz_wpp_http_request(
                'POST',
                $gw['api_url'] . '/' . rawurlencode($gw['phone_id']) . '/messages',
                ['Authorization: Bearer ' . $gw['api_key']],
                [
                    'messaging_product' => 'whatsapp',
                    'to' => $tel,
                    'type' => 'text',
                    'text' => ['preview_url' => true, 'body' => $texto],
                ],
                10
            );
            $enviadoExterno = !empty($res['ok']);
            $statusEnvio = $enviadoExterno ? 'enviado_meta' : 'falha_api';
            $erroExterno = $enviadoExterno ? '' : ($res['json']['error']['message'] ?? $res['error']);
        } elseif ($prov === 'zapi') {
            $res = pz_wpp_http_request(
                'POST',
                $gw['api_url'] . '/instances/' . rawurlencode($gw['instance']) . '/token/' . rawurlencode($gw['api_key']) . '/send-text',
                [],
                ['phone' => $tel, 'message' => $texto],
                10
            );
            $enviadoExterno = !empty($res['ok']);
            $statusEnvio = $enviadoExterno ? 'enviado_zapi' : 'falha_api';
            $erroExterno = $enviadoExterno ? '' : ($res['error'] ?: ('HTTP ' . $res['status']));
        }
    }

    if ($registrar) {
        pz_wpp_registrar_mensagem($slug, $tel, 'saida', $texto, [
            'autor' => $autor,
            'nome' => $nome,
            'status_envio' => $statusEnvio,
        ]);
    }

    return [
        'success' => $enviadoExterno || !$gw['gateway_pronto'],
        'enviado_externo' => $enviadoExterno,
        'status_envio' => $statusEnvio,
        'message' => $enviadoExterno
            ? 'Mensagem enviada no WhatsApp com sucesso!'
            : ($gw['gateway_pronto']
                ? 'Falha ao entregar na API do WhatsApp: ' . $erroExterno
                : 'Mensagem salva no atendimento ao vivo (configure a API do WhatsApp para envio automático externo).'),
    ];
}

function pz_wpp_norm($txt)
{
    if (function_exists('pz_territorio_norm')) {
        return pz_territorio_norm($txt);
    }
    $txt = mb_strtolower(trim((string) $txt), 'UTF-8');
    return strtr($txt, [
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e',
        'í' => 'i', 'ó' => 'o', 'õ' => 'o', 'ô' => 'o', 'ú' => 'u', 'ç' => 'c',
    ]);
}

function pz_wpp_brl($v)
{
    return 'R$ ' . number_format((float) $v, 2, ',', '.');
}

function pz_wpp_buscar_ultimo_pedido_telefone($slug, $telefone)
{
    $ultimos8 = substr(preg_replace('/\D/', '', (string) $telefone), -8);
    if (strlen($ultimos8) < 8) {
        return null;
    }
    $anterior = Database::getTenant();
    Database::setTenant($slug);
    $pedidos = Database::readStrict('pedidos.json');
    Database::setTenant($anterior);
    if (!is_array($pedidos)) {
        return null;
    }
    foreach ($pedidos as $p) {
        if (!is_array($p)) {
            continue;
        }
        $cel = preg_replace('/\D/', '', (string) ($p['celular'] ?? ''));
        if ($cel !== '' && substr($cel, -8) === $ultimos8) {
            return $p;
        }
    }
    return null;
}

function pz_wpp_link_loja($slug)
{
    $base = function_exists('pedidozap_base_url') ? pedidozap_base_url() : '';
    $s = pz_wpp_normalizar_slug($slug);
    if ($s === 'default') {
        return ($base !== '' ? $base : '') . '/index.html';
    }
    return ($base !== '' ? $base : '') . '/loja/' . rawurlencode($s) . '/';
}

function pz_wpp_gemini_polir_resposta($slug, $pergunta, array $fatosReais)
{
    $int = pedidozap_integracoes_config();
    $provider = $int['ia_provider'] ?? 'local';
    $apiKey = trim((string) ($int['ia_api_key'] ?? ''));
    if ($provider !== 'gemini' || $apiKey === '' || trim((string) $pergunta) === '') {
        return '';
    }

    $prompt = "Você é o atendente de WhatsApp simpático e direto da loja abaixo. "
        . "Responda em português do Brasil em no máximo 450 caracteres, usando estilo WhatsApp (*negrito* com 1 asterisco). "
        . "REGRAS OBRIGATÓRIAS: 1) Nunca invente produtos, preços, horários ou links; use APENAS o JSON abaixo; "
        . "2) Quando fizer sentido, inclua o link direto da loja para o cliente pedir rápido sem taxa de app.\n\n"
        . "Dados reais da loja: " . json_encode($fatosReais, JSON_UNESCAPED_UNICODE) . "\n\n"
        . "Mensagem do cliente no WhatsApp: " . $pergunta;

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . rawurlencode($apiKey);
    $res = pz_wpp_http_request('POST', $url, [], [
        'contents' => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
        'generationConfig' => ['temperature' => 0.3, 'maxOutputTokens' => 260],
    ], 7);

    if (!empty($res['ok']) && !empty($res['json']['candidates'][0]['content']['parts'][0]['text'])) {
        return trim((string) $res['json']['candidates'][0]['content']['parts'][0]['text']);
    }
    return '';
}

function pz_wpp_gerar_resposta_ia($slug, $telefone, $textoRecebido, $nomeCliente = '')
{
    $slug = pz_wpp_normalizar_slug($slug);
    $anterior = Database::getTenant();
    Database::setTenant($slug);
    $config = pedidozap_normalizar_config(Database::readStrict('config.json'), $slug);
    $cardapio = Database::readStrict('cardapio.json');
    Database::setTenant($anterior);

    if (!is_array($cardapio)) {
        $cardapio = [];
    }
    $empresa = is_array($config['EMPRESA'] ?? null) ? $config['EMPRESA'] : [];
    $nomeLoja = trim((string) ($config['NOME_LOJA'] ?? $empresa['nome'] ?? 'Nossa Loja')) ?: 'Nossa Loja';
    $linkLoja = pz_wpp_link_loja($slug);
    $wppCfg = pz_wpp_ler_config($slug);
    $primeiroNome = $nomeCliente !== '' ? explode(' ', trim($nomeCliente))[0] : '';
    $oiPrefix = $primeiroNome !== '' ? "Oi, *{$primeiroNome}*! " : 'Oi! ';

    $hor = is_array($config['HORARIOS'] ?? null) ? $config['HORARIOS'] : [];
    $horario = trim((string) ($hor['TEXTO'] ?? ''));
    if ($horario === '' && !empty($hor['SEMANA']['inicio'])) {
        $horario = 'Seg a Qui ' . $hor['SEMANA']['inicio'] . ' às ' . ($hor['SEMANA']['fim'] ?? '23:30');
        if (!empty($hor['FIM_DE_SEMANA']['inicio'])) {
            $horario .= ' · Sex a Dom ' . $hor['FIM_DE_SEMANA']['inicio'] . ' às ' . ($hor['FIM_DE_SEMANA']['fim'] ?? '00:00');
        }
    }
    if ($horario === '') {
        $horario = $empresa['horario_funcionamento'] ?? 'Consulte no link da loja';
    }

    $logistica = is_array($config['LOGISTICA'] ?? null) ? $config['LOGISTICA'] : [];
    $freteCfg = is_array($config['FRETE'] ?? null) ? $config['FRETE'] : [];
    $pagamentos = is_array($config['PAGAMENTOS'] ?? null) ? $config['PAGAMENTOS'] : [];

    $todosProdutos = [];
    foreach ($cardapio as $cat => $lista) {
        if (!is_array($lista)) {
            continue;
        }
        foreach ($lista as $prod) {
            if (!is_array($prod) || empty($prod['nome']) || !empty($prod['esgotado']) || (isset($prod['ativo']) && !$prod['ativo'])) {
                continue;
            }
            $prod['_categoria'] = (string) $cat;
            $prod['_preco'] = (float) ($prod['preco'] ?? ($prod['tamanhos'][0]['preco'] ?? 0));
            $todosProdutos[] = $prod;
        }
    }

    $n = pz_wpp_norm($textoRecebido);

    // 1. Consulta de status de pedido real pelo WhatsApp do cliente
    if (preg_match('/\b(cade|status|andamento|pedido|chegou|saiu|preparo|rastre)\b/', $n)) {
        $ultPedido = pz_wpp_buscar_ultimo_pedido_telefone($slug, $telefone);
        if ($ultPedido) {
            $mapaStatus = [
                'novo' => 'Recebido (aguardando confirmação)',
                'preparo' => 'Em preparo agora',
                'em-rota' => 'Saiu para entrega',
                'concluido' => 'Concluído / Entregue',
                'cancelado' => 'Cancelado',
            ];
            $stKey = (string) ($ultPedido['status'] ?? 'novo');
            $stTxt = $mapaStatus[$stKey] ?? $stKey;
            $base = function_exists('pedidozap_base_url') ? pedidozap_base_url() : '';
            $rastreioUrl = ($base !== '' ? $base : '') . '/rastreio.html?tenant=' . rawurlencode($slug) . '&id=' . rawurlencode((string) ($ultPedido['id'] ?? ''));
            return [
                'intencao' => 'rastreio_pedido',
                'resposta' => $oiPrefix . "Achei seu pedido *#" . ($ultPedido['id'] ?? '') . "* na *{$nomeLoja}*:\n"
                    . "• Status atual: *{$stTxt}*\n"
                    . "• Total: *" . pz_wpp_brl($ultPedido['total'] ?? 0) . "*\n\n"
                    . "Acompanhe ao vivo aqui:\n{$rastreioUrl}",
            ];
        }
    }

    // 2. Falar com atendente humano
    if (preg_match('/\b(atendente|humano|pessoa|falar com alguem|gerente|reclam|problema)\b/', $n)) {
        return [
            'intencao' => 'humano',
            'resposta' => $oiPrefix . "Entendi! Já chamei a equipe da *{$nomeLoja}* aqui na conversa para falar com você.\n"
                . "Se quiser ir adiantando seu pedido no catálogo direto (sem taxa de aplicativo), acesse:\n{$linkLoja}",
        ];
    }

    // 3. Horário de funcionamento
    if (preg_match('/\b(horario|aberto|aberta|abre|fecha|funcionando|hoje)\b/', $n) && strpos($n, 'entrega') === false) {
        return [
            'intencao' => 'horario',
            'resposta' => $oiPrefix . "O horário da *{$nomeLoja}* é:\n*{$horario}*.\n\nVeja o catálogo e peça direto aqui:\n{$linkLoja}",
        ];
    }

    // 4. Entrega / Frete / Retirada
    if (preg_match('/\b(entrega|frete|taxa|retirar|retirada|bairro|demora|tempo)\b/', $n)) {
        $modo = $freteCfg['modo'] ?? '';
        $taxa = (float) ($freteCfg['taxa_fixa'] ?? $freteCfg['taxa_base'] ?? $logistica['taxa_entrega_fixa'] ?? 0);
        $tempo = trim((string) ($config['TEMPO_ESPERA'] ?? $logistica['tempo_estimado'] ?? '30 a 50 min'));
        $freteTxt = $modo === 'gratis'
            ? 'Estamos com *entrega grátis*!'
            : ($taxa > 0 ? 'Taxa a partir de *' . pz_wpp_brl($taxa) . '* (confirmada pelo endereço).' : 'A entrega é calculada direto pelo seu endereço.');
        return [
            'intencao' => 'entrega',
            'resposta' => $oiPrefix . "Tempo estimado da *{$nomeLoja}*: *{$tempo}*.\n{$freteTxt}\nTambém pode escolher retirar no local.\n\nFaça seu pedido sem taxa de app:\n{$linkLoja}",
        ];
    }

    // 5. Formas de pagamento
    if (preg_match('/\b(pagament|pagar|pix|cartao|credito|debito|dinheiro|vale|troco)\b/', $n)) {
        $formas = [];
        if (!isset($pagamentos['pix']) || !empty($pagamentos['pix'])) $formas[] = 'PIX';
        if (!isset($pagamentos['credito']) || !empty($pagamentos['credito'])) $formas[] = 'Cartão';
        if (!isset($pagamentos['dinheiro']) || !empty($pagamentos['dinheiro'])) $formas[] = 'Dinheiro';
        $lista = $formas ? implode(', ', $formas) : 'PIX, Cartão e Dinheiro';
        return [
            'intencao' => 'pagamento',
            'resposta' => $oiPrefix . "Na *{$nomeLoja}* aceitamos: *{$lista}*.\nÉ só escolher na hora de fechar o carrinho aqui:\n{$linkLoja}",
        ];
    }

    // 6. Busca inteligente de produtos + opcional Gemini
    $intencao = function_exists('pz_alg_expandir_intencao') ? pz_alg_expandir_intencao($textoRecebido) : ['tags' => [], 'economia' => false];
    $tokensBrutos = array_values(array_filter(preg_split('/\s+/', preg_replace('/[^a-z0-9\s]/', ' ', $n)) ?: [], function ($t) {
        return strlen($t) >= 3 && !in_array($t, ['tem', 'que', 'qual', 'quanto', 'custa', 'valor', 'preco', 'voce', 'voces', 'para', 'hoje', 'quero', 'gostaria', 'ver', 'cardapio', 'catalogo', 'menu', 'mais', 'bom', 'dia', 'boa', 'tarde', 'noite', 'ola', 'tudo', 'bem'], true);
    }));
    $tokens = array_values(array_unique(array_merge($tokensBrutos, $intencao['tags'] ?? [])));

    $achados = [];
    foreach ($todosProdutos as $prod) {
        $hay = pz_wpp_norm(($prod['nome'] ?? '') . ' ' . ($prod['descricao'] ?? '') . ' ' . ($prod['_categoria'] ?? ''));
        $sc = !empty($prod['promocao']) ? 0.5 : 0.0;
        foreach ($tokensBrutos as $tk) {
            if (strpos($hay, $tk) !== false) $sc += 2.0;
        }
        foreach ($tokens as $tk) {
            if (strpos($hay, $tk) !== false) $sc += 0.8;
        }
        if ($sc > 0 || empty($tokensBrutos)) {
            $prod['_score'] = $sc;
            $achados[] = $prod;
        }
    }
    if (!$achados) {
        $achados = $todosProdutos;
    }

    $economia = !empty($intencao['economia']);
    usort($achados, function ($a, $b) use ($economia) {
        if ($economia) {
            $pa = ($a['_preco'] ?? 0) > 0 ? $a['_preco'] : 999999;
            $pb = ($b['_preco'] ?? 0) > 0 ? $b['_preco'] : 999999;
            if (abs($pa - $pb) > 0.01) return $pa <=> $pb;
        }
        return ($b['_score'] ?? 0) <=> ($a['_score'] ?? 0);
    });

    $top = array_slice($achados, 0, 4);
    $saudacao = trim((string) ($wppCfg['saudacao_personalizada'] ?? ''));

    // Tenta polimento via Gemini se configurado no SuperAdmin
    $geminiResp = pz_wpp_gemini_polir_resposta($slug, $textoRecebido, [
        'loja' => $nomeLoja,
        'link_direto' => $linkLoja,
        'horario' => $horario,
        'tempo_estimado' => $config['TEMPO_ESPERA'] ?? '30-50 min',
        'produtos_encontrados' => array_map(function ($p) {
            return [
                'nome' => $p['nome'],
                'preco' => $p['_preco'] > 0 ? pz_wpp_brl($p['_preco']) : 'ver opções',
                'categoria' => $p['_categoria'],
            ];
        }, $top),
    ]);
    if ($geminiResp !== '') {
        return ['intencao' => 'ia_gemini', 'resposta' => $geminiResp];
    }

    $linhas = array_map(function ($p) {
        $pr = ($p['_preco'] ?? 0) > 0 ? ' — *' . pz_wpp_brl($p['_preco']) . '*' : '';
        return '• ' . $p['nome'] . $pr;
    }, $top);

    $intro = $saudacao !== '' && empty($tokensBrutos)
        ? $saudacao
        : ($oiPrefix . "Aqui é a *IA da {$nomeLoja}* (preço direto da loja, sem taxa de app)!");
    $corpo = $linhas
        ? "\n\nSeparei estas opções pra você:\n" . implode("\n", $linhas)
        : "\n\nConfira todas as opções disponíveis agora no nosso catálogo:";

    return [
        'intencao' => $tokensBrutos ? 'busca_catalogo' : 'boas_vindas',
        'resposta' => $intro . $corpo . "\n\nPeça em segundos pelo link:\n{$linkLoja}",
    ];
}

function pz_wpp_processar_entrada_cliente($slug, $telefone, $texto, array $extra = [])
{
    $slug = pz_wpp_normalizar_slug($slug);
    $telNorm = pz_wpp_normalizar_telefone($telefone);
    $texto = trim((string) $texto);
    if ($telNorm === '' || $texto === '') {
        return ['success' => false, 'message' => 'Dados da mensagem vazios.'];
    }

    $thread = pz_wpp_registrar_mensagem($slug, $telNorm, 'entrada', $texto, [
        'autor' => 'cliente',
        'nome' => $extra['nome'] ?? '',
        'msg_id' => $extra['msg_id'] ?? '',
    ]);

    if (!$thread) {
        return ['success' => true, 'duplicado' => true];
    }

    $cfg = pz_wpp_ler_config($slug);
    $forcarResposta = !empty($extra['forcar_ia']);
    if (!$forcarResposta && (empty($cfg['ia_auto_responder']) || !empty($thread['ia_pausada']))) {
        return [
            'success' => true,
            'auto_respondido' => false,
            'motivo' => !empty($thread['ia_pausada']) ? 'ia_pausada_humano' : 'auto_responder_desligado',
            'conversa' => $thread,
        ];
    }

    $ia = pz_wpp_gerar_resposta_ia($slug, $telNorm, $texto, (string) ($thread['nome'] ?? ($extra['nome'] ?? '')));
    $envio = pz_wpp_enviar_texto($slug, $telNorm, $ia['resposta'], [
        'autor' => 'ia',
        'nome' => $thread['nome'] ?? '',
    ]);
    if (($ia['intencao'] ?? '') === 'humano') {
        pz_wpp_alternar_ia_conversa($slug, $telNorm, false);
    }

    $conversasAtual = pz_wpp_listar_conversas($slug);
    $chaveChat = pz_wpp_chave_chat($telNorm);
    $threadFinal = null;
    foreach ($conversasAtual as $c) {
        if (($c['chave'] ?? '') === $chaveChat) {
            $threadFinal = $c;
            break;
        }
    }

    return [
        'success' => true,
        'auto_respondido' => true,
        'intencao' => $ia['intencao'] ?? 'ia',
        'resposta_ia' => $ia['resposta'],
        'envio' => $envio,
        'conversa' => $threadFinal ?: $thread,
    ];
}

function pz_wpp_notificar_status_pedido($slug, array $pedido, $statusNovo)
{
    $slug = pz_wpp_normalizar_slug($slug);
    $tel = pz_wpp_normalizar_telefone($pedido['celular'] ?? '');
    if (strlen($tel) < 12) {
        return ['success' => false, 'ignorado' => 'sem_telefone'];
    }
    $cfg = pz_wpp_ler_config($slug);
    if (empty($cfg['notificar_pedidos'])) {
        return ['success' => false, 'ignorado' => 'notificacao_desativada'];
    }

    $gw = pz_wpp_resolver_gateway($slug);
    if (!$gw['gateway_pronto']) {
        // Sem API externa conectada ainda: não polui fila nem trava operação
        return ['success' => false, 'ignorado' => 'sem_gateway'];
    }

    $anterior = Database::getTenant();
    Database::setTenant($slug);
    $storeCfg = pedidozap_normalizar_config(Database::readStrict('config.json'), $slug);
    Database::setTenant($anterior);
    $nomeLoja = trim((string) ($storeCfg['NOME_LOJA'] ?? $storeCfg['EMPRESA']['nome'] ?? 'Loja')) ?: 'Loja';

    $nomeCli = trim((string) ($pedido['cliente'] ?? ''));
    $primeiro = $nomeCli !== '' ? explode(' ', $nomeCli)[0] : '';
    $idPed = (string) ($pedido['id'] ?? '');
    $base = function_exists('pedidozap_base_url') ? pedidozap_base_url() : '';
    $rastreio = ($base !== '' ? $base : '') . '/rastreio.html?tenant=' . rawurlencode($slug) . '&id=' . rawurlencode($idPed);

    $mensagens = [
        'novo' => "Oi" . ($primeiro ? ", *{$primeiro}*" : '') . "! Recebemos seu pedido *#{$idPed}* na *{$nomeLoja}* (Total: *" . pz_wpp_brl($pedido['total'] ?? 0) . "*).\nAcompanhe em tempo real: {$rastreio}",
        'preparo' => "Boas notícias" . ($primeiro ? ", *{$primeiro}*" : '') . "! Seu pedido *#{$idPed}* na *{$nomeLoja}* já está *em preparo*.\nAcompanhe: {$rastreio}",
        'em-rota' => "Saiu agora" . ($primeiro ? ", *{$primeiro}*" : '') . "! Seu pedido *#{$idPed}* da *{$nomeLoja}* já *saiu para entrega*.\nVeja ao vivo: {$rastreio}",
        'concluido' => "Pedido *#{$idPed}* da *{$nomeLoja}* *entregue/concluído*! Obrigado por pedir direto com a gente (sem taxa de aplicativo).",
        'cancelado' => "Aviso da *{$nomeLoja}*: seu pedido *#{$idPed}* foi cancelado. Se precisar de ajuda, responda esta mensagem.",
    ];
    $texto = $mensagens[$statusNovo] ?? '';
    if ($texto === '') {
        return ['success' => false, 'ignorado' => 'status_sem_template'];
    }

    return pz_wpp_enviar_texto($slug, $tel, $texto, [
        'autor' => 'sistema',
        'nome' => $nomeCli,
    ]);
}

function pz_wpp_enfileirar_status_pedido($slug, array $pedido, $statusNovo)
{
    $slug = pz_wpp_normalizar_slug($slug);
    $tel = pz_wpp_normalizar_telefone($pedido['celular'] ?? '');
    if (strlen($tel) < 12) {
        return false;
    }
    $cfg = pz_wpp_ler_config($slug);
    if (empty($cfg['notificar_pedidos'])) {
        return false;
    }
    $gw = pz_wpp_resolver_gateway($slug);
    if (!$gw['gateway_pronto']) {
        return false;
    }
    if (function_exists('pz_queue_push')) {
        pz_queue_push('whatsapp.order_status', [
            'pedido' => [
                'id' => $pedido['id'] ?? '',
                'cliente' => $pedido['cliente'] ?? '',
                'celular' => $tel,
                'total' => $pedido['total'] ?? 0,
            ],
            'status' => (string) $statusNovo,
        ], $slug, 0, true);
        return true;
    }
    pz_wpp_notificar_status_pedido($slug, $pedido, $statusNovo);
    return true;
}
