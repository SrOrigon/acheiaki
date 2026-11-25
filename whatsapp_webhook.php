<?php
/**
 * Webhook Oficial de WhatsApp Ao Vivo (Evolution API, Meta Cloud API, Z-API)
 * Recebe mensagens dos clientes em tempo real, identifica a loja correta,
 * registra na caixa ao vivo e responde com a IA da loja automaticamente.
 */
require_once __DIR__ . '/tenant_init.php';
require_once __DIR__ . '/saas_lib.php';
require_once __DIR__ . '/integracoes_lib.php';
require_once __DIR__ . '/whatsapp_lib.php';

function pz_wpp_resolver_tenant_webhook($instanceHint = '', $phoneIdHint = '', $slugQuery = '')
{
    $sQuery = preg_replace('/[^a-z0-9_-]/i', '', (string) $slugQuery);
    if ($sQuery !== '' && ($sQuery === 'default' || Database::tenantDataExists($sQuery))) {
        return strtolower($sQuery);
    }

    $inst = trim((string) $instanceHint);
    $pid = trim((string) $phoneIdHint);

    if ($inst !== '' && stripos($inst, 'acheiaki-') === 0) {
        $candidato = preg_replace('/[^a-z0-9_-]/i', '', substr($inst, 9));
        if ($candidato !== '' && ($candidato === 'default' || Database::tenantDataExists($candidato))) {
            return strtolower($candidato);
        }
    }

    $lojas = function_exists('pedidozap_ler_lojas') ? pedidozap_ler_lojas() : [];
    foreach ($lojas as $l) {
        $slug = strtolower((string) ($l['slug'] ?? ''));
        if ($slug === '') {
            continue;
        }
        $cfg = pz_wpp_ler_config($slug);
        if ($inst !== '' && ($cfg['instance'] ?? '') === $inst) {
            return $slug;
        }
        if ($pid !== '' && ($cfg['phone_id'] ?? '') === $pid) {
            return $slug;
        }
    }

    return Database::getTenant();
}

function pz_wpp_verificar_segredo_webhook($slugHint = '')
{
    $int = pedidozap_integracoes_config();
    $segredoPlat = trim((string) ($int['wpp_webhook_secret'] ?? ''));
    $segredoLoja = '';
    if ($slugHint !== '') {
        $lojaCfg = pz_wpp_ler_config($slugHint);
        $segredoLoja = trim((string) ($lojaCfg['webhook_secret'] ?? ''));
    }
    if ($segredoPlat === '' && $segredoLoja === '') {
        $appEnvWpp = strtolower(trim((string) (function_exists('pz_db_env_val') ? pz_db_env_val('APP_ENV') : getenv('APP_ENV'))));
        return $appEnvWpp !== 'production';
    }
    $recebido = trim((string) (
        $_GET['secret']
        ?? $_GET['hub_verify_token']
        ?? $_GET['hub.verify_token']
        ?? $_SERVER['HTTP_X_WEBHOOK_SECRET']
        ?? $_SERVER['HTTP_APIKEY']
        ?? ''
    ));
    if ($recebido === '') {
        return false;
    }
    if ($segredoPlat !== '' && hash_equals($segredoPlat, $recebido)) {
        return true;
    }
    if ($segredoLoja !== '' && hash_equals($segredoLoja, $recebido)) {
        return true;
    }
    return false;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// 1. Verificação de desafio GET (Meta Cloud API ou checagem de saúde)
if ($method === 'GET') {
    $hubMode = (string) ($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '');
    $challenge = (string) ($_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '');
    $verifyToken = (string) ($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '');
    $slugGet = (string) ($_GET['tenant'] ?? '');

    if ($hubMode === 'subscribe' && $challenge !== '') {
        $int = pedidozap_integracoes_config();
        $segredoPlat = trim((string) ($int['wpp_webhook_secret'] ?? ''));
        $segredoLoja = $slugGet !== '' ? trim((string) (pz_wpp_ler_config($slugGet)['webhook_secret'] ?? '')) : '';
        $okToken = ($segredoPlat === '' && $segredoLoja === '')
            || ($segredoPlat !== '' && hash_equals($segredoPlat, $verifyToken))
            || ($segredoLoja !== '' && hash_equals($segredoLoja, $verifyToken));
        if (!$okToken) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Verify token inválido.';
            exit;
        }
        http_response_code(200);
        header('Content-Type: text/plain; charset=utf-8');
        echo $challenge;
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => true,
        'endpoint' => 'whatsapp_webhook',
        'status' => 'online',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$body = json_decode((string) $raw, true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'JSON inválido.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$slugQuery = (string) ($_GET['tenant'] ?? '');
$instHintPre = (string) ($body['instance'] ?? ($body['data']['instance'] ?? $body['instanceId'] ?? ''));
$phoneIdPre = (string) ($body['entry'][0]['changes'][0]['value']['metadata']['phone_number_id'] ?? '');
$slugPreResolvido = pz_wpp_resolver_tenant_webhook($instHintPre, $phoneIdPre, $slugQuery);

if (!pz_wpp_verificar_segredo_webhook($slugPreResolvido)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Segredo do webhook inválido.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$eventosProcessados = 0;
$respostasEnviadas = 0;

// 2A. Meta Cloud API (WhatsApp Business Platform)
if (($body['object'] ?? '') === 'whatsapp_business_account' && !empty($body['entry']) && is_array($body['entry'])) {
    foreach ($body['entry'] as $entry) {
        foreach ((array) ($entry['changes'] ?? []) as $change) {
            $val = $change['value'] ?? [];
            if (!is_array($val) || empty($val['messages'])) {
                continue;
            }
            $phoneId = (string) ($val['metadata']['phone_number_id'] ?? '');
            $slug = pz_wpp_resolver_tenant_webhook('', $phoneId, $slugQuery);
            $contatos = is_array($val['contacts'] ?? null) ? $val['contacts'] : [];
            $nomeContato = (string) ($contatos[0]['profile']['name'] ?? '');

            foreach ($val['messages'] as $msg) {
                if (!is_array($msg)) {
                    continue;
                }
                $from = (string) ($msg['from'] ?? '');
                $msgId = (string) ($msg['id'] ?? '');
                $texto = (string) (
                    $msg['text']['body']
                    ?? $msg['button']['text']
                    ?? $msg['interactive']['button_reply']['title']
                    ?? $msg['interactive']['list_reply']['title']
                    ?? ''
                );
                if ($from !== '' && $texto !== '') {
                    $r = pz_wpp_processar_entrada_cliente($slug, $from, $texto, [
                        'nome' => $nomeContato,
                        'msg_id' => $msgId,
                    ]);
                    $eventosProcessados++;
                    if (!empty($r['auto_respondido'])) {
                        $respostasEnviadas++;
                    }
                }
            }
        }
    }
    echo json_encode(['success' => true, 'provider' => 'meta', 'processados' => $eventosProcessados, 'respostas' => $respostasEnviadas], JSON_UNESCAPED_UNICODE);
    exit;
}

// 2B. Evolution API (v1/v2)
$eventName = strtolower((string) ($body['event'] ?? ''));
$instanceEvo = (string) ($body['instance'] ?? ($body['data']['instance'] ?? ''));
if ($eventName !== '' || isset($body['data']['key'])) {
    $slug = pz_wpp_resolver_tenant_webhook($instanceEvo, '', $slugQuery);

    if (strpos($eventName, 'connection') !== false) {
        $state = strtolower((string) ($body['data']['state'] ?? $body['state'] ?? ''));
        if ($state !== '') {
            pz_wpp_salvar_config($slug, ['conectado' => ($state === 'open' || $state === 'connected'), 'ultimo_check' => date('c')]);
        }
        echo json_encode(['success' => true, 'provider' => 'evolution', 'event' => $eventName], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (strpos($eventName, 'message') !== false || isset($body['data']['key'])) {
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $key = is_array($data['key'] ?? null) ? $data['key'] : [];
        $fromMe = !empty($key['fromMe']);
        $remoteJid = (string) ($key['remoteJid'] ?? '');

        // Ignora mensagens próprias e grupos (@g.us) / status@broadcast
        if (!$fromMe && $remoteJid !== '' && strpos($remoteJid, '@g.us') === false && strpos($remoteJid, 'status@') === false) {
            $telefone = preg_replace('/@.*/', '', $remoteJid);
            $msgObj = is_array($data['message'] ?? null) ? $data['message'] : [];
            $texto = (string) (
                $msgObj['conversation']
                ?? $msgObj['extendedTextMessage']['text']
                ?? $msgObj['imageMessage']['caption']
                ?? $msgObj['videoMessage']['caption']
                ?? $msgObj['buttonsResponseMessage']['selectedDisplayText']
                ?? $msgObj['listResponseMessage']['title']
                ?? ''
            );
            $nome = (string) ($data['pushName'] ?? '');
            $msgId = (string) ($key['id'] ?? '');

            if ($telefone !== '' && trim($texto) !== '') {
                $r = pz_wpp_processar_entrada_cliente($slug, $telefone, $texto, [
                    'nome' => $nome,
                    'msg_id' => $msgId,
                ]);
                $eventosProcessados++;
                if (!empty($r['auto_respondido'])) {
                    $respostasEnviadas++;
                }
            }
        }
    }

    echo json_encode(['success' => true, 'provider' => 'evolution', 'processados' => $eventosProcessados, 'respostas' => $respostasEnviadas], JSON_UNESCAPED_UNICODE);
    exit;
}

// 2C. Z-API (ReceivedCallback)
if (isset($body['phone']) && (isset($body['text']['message']) || isset($body['hydratedTemplate']) || isset($body['buttonsResponseMessage']))) {
    $fromMe = !empty($body['fromMe']);
    $isGroup = !empty($body['isGroup']);
    if (!$fromMe && !$isGroup) {
        $slug = pz_wpp_resolver_tenant_webhook((string) ($body['instanceId'] ?? ''), '', $slugQuery);
        $texto = (string) ($body['text']['message'] ?? $body['buttonsResponseMessage']['message'] ?? '');
        $telefone = (string) ($body['phone'] ?? '');
        $nome = (string) ($body['senderName'] ?? $body['chatName'] ?? '');
        $msgId = (string) ($body['messageId'] ?? '');
        if ($telefone !== '' && trim($texto) !== '') {
            $r = pz_wpp_processar_entrada_cliente($slug, $telefone, $texto, [
                'nome' => $nome,
                'msg_id' => $msgId,
            ]);
            $eventosProcessados++;
            if (!empty($r['auto_respondido'])) {
                $respostasEnviadas++;
            }
        }
    }
    echo json_encode(['success' => true, 'provider' => 'zapi', 'processados' => $eventosProcessados, 'respostas' => $respostasEnviadas], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['success' => true, 'ignored' => true], JSON_UNESCAPED_UNICODE);
