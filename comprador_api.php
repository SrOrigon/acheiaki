<?php
/**
 * API de Conta Única do Comprador (Marketplace estilo iFood).
 * Permite cadastro, login por telefone/senha, endereços salvos e favoritos
 * funcionando de forma compatível em JSON e PDO MySQL/PostgreSQL.
 */
require_once __DIR__ . '/tenant_init.php';
require_once __DIR__ . '/auth_lib.php';
require_once __DIR__ . '/saas_lib.php';
require_once __DIR__ . '/store_schema.php';
require_once __DIR__ . '/integracoes_lib.php';

header('Content-Type: application/json; charset=utf-8');
pz_cors_same_origin('GET, POST, OPTIONS', 'Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function pz_comprador_chave_hmac()
{
    if (function_exists('pz_db_env_val')) {
        $envSecret = pz_db_env_val('ACHEIAKI_APP_SECRET');
        if (strlen($envSecret) >= 32) {
            return $envSecret;
        }
    }
    $anterior = Database::getTenant();
    Database::setTenant('default');
    $sqlData = Database::readStrict('comprador_secret.json');
    if (is_array($sqlData) && !empty($sqlData['secret'])) {
        Database::setTenant($anterior);
        return (string) $sqlData['secret'];
    }
    $secretFile = __DIR__ . '/data/.comprador_secret';
    if (is_file($secretFile)) {
        $v = trim((string) @file_get_contents($secretFile));
        if ($v !== '') {
            Database::write('comprador_secret.json', ['secret' => $v]);
            Database::setTenant($anterior);
            return $v;
        }
    }
    $novo = bin2hex(random_bytes(24));
    Database::write('comprador_secret.json', ['secret' => $novo, 'criado_em' => date('c')]);
    @file_put_contents($secretFile, $novo, LOCK_EX);
    Database::setTenant($anterior);
    return $novo;
}

function pz_comprador_gerar_token($telefone)
{
    $exp = time() + (86400 * 30);
    $payload = $telefone . '|' . $exp;
    $sig = hash_hmac('sha256', $payload, pz_comprador_chave_hmac());
    return base64_encode($payload . '|' . $sig);
}

function pz_comprador_validar_token($token)
{
    $raw = base64_decode((string) $token, true);
    if ($raw === false) {
        return null;
    }
    $partes = explode('|', $raw);
    if (count($partes) !== 3) {
        return null;
    }
    [$tel, $exp, $sig] = $partes;
    if ((int) $exp < time()) {
        return null;
    }
    $esperado = hash_hmac('sha256', $tel . '|' . $exp, pz_comprador_chave_hmac());
    if (!hash_equals($esperado, $sig)) {
        return null;
    }
    return $tel;
}

function pz_comprador_normalizar_tel($tel)
{
    $digitos = preg_replace('/\D/', '', (string) $tel);
    if (strlen($digitos) >= 12 && strpos($digitos, '55') === 0) {
        $digitos = substr($digitos, 2);
    }
    return $digitos;
}

function pz_comprador_validar_cpf($cpf)
{
    $cpf = preg_replace('/\D/', '', (string) $cpf);
    if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
        return false;
    }
    for ($t = 9; $t < 11; $t++) {
        $d = 0;
        for ($c = 0; $c < $t; $c++) {
            $d += ((int) $cpf[$c]) * (($t + 1) - $c);
        }
        $d = ((10 * $d) % 11) % 10;
        if ((int) $cpf[$c] !== $d) {
            return false;
        }
    }
    return true;
}

function pz_comprador_normalizar_endereco(array $end)
{
    $cep = preg_replace('/\D/', '', (string) ($end['cep'] ?? ''));
    $estado = strtoupper(trim(strip_tags((string) ($end['estado'] ?? $end['uf'] ?? ''))));
    return [
        'rotulo' => trim(strip_tags((string) ($end['rotulo'] ?? 'Principal'))) ?: 'Principal',
        'cep' => $cep,
        'rua' => trim(strip_tags((string) ($end['rua'] ?? ''))),
        'numero' => trim(strip_tags((string) ($end['numero'] ?? ''))),
        'complemento' => trim(strip_tags((string) ($end['complemento'] ?? ''))),
        'bairro' => trim(strip_tags((string) ($end['bairro'] ?? ''))),
        'cidade' => trim(strip_tags((string) ($end['cidade'] ?? ''))),
        'estado' => substr($estado, 0, 2),
        'lat' => is_numeric($end['lat'] ?? null) ? round((float) $end['lat'], 7) : 0.0,
        'lng' => is_numeric($end['lng'] ?? null) ? round((float) $end['lng'], 7) : 0.0,
    ];
}

function pz_comprador_validar_endereco(array $end, $resolverGeo = true)
{
    $n = pz_comprador_normalizar_endereco($end);
    if (strlen($n['cep']) !== 8) {
        return ['ok' => false, 'message' => 'Informe um CEP válido com 8 dígitos.'];
    }
    if (mb_strlen($n['rua'], 'UTF-8') < 3 || $n['numero'] === '') {
        return ['ok' => false, 'message' => 'Informe a rua e o número do endereço.'];
    }
    if (mb_strlen($n['bairro'], 'UTF-8') < 2 || mb_strlen($n['cidade'], 'UTF-8') < 2 || strlen($n['estado']) !== 2) {
        return ['ok' => false, 'message' => 'Informe bairro, cidade e estado (UF com 2 letras).'];
    }
    if (($n['lat'] === 0.0 || $n['lng'] === 0.0) && $resolverGeo && function_exists('pedidozap_geocode_query')) {
        $q1 = implode(', ', array_filter([$n['rua'], $n['numero'], $n['bairro'], $n['cidade'], $n['estado'], $n['cep'], 'Brasil']));
        $geo = pedidozap_geocode_query($q1);
        if (!$geo) {
            $geo = pedidozap_geocode_query($n['cidade'] . ', ' . $n['estado'] . ', Brasil');
        }
        if ($geo) {
            $n['lat'] = round((float) $geo['lat'], 7);
            $n['lng'] = round((float) $geo['lng'], 7);
        }
    }
    if ($n['lat'] === 0.0 || $n['lng'] === 0.0) {
        return ['ok' => false, 'message' => 'Não foi possível validar a localização GPS deste endereço. Clique em "Ativar GPS" ou confira CEP, Rua e Cidade.'];
    }
    return ['ok' => true, 'endereco' => $n];
}

function pz_comprador_pendencias(array $c)
{
    $pend = [];
    if (strlen(pz_comprador_normalizar_tel($c['telefone'] ?? '')) < 10) {
        $pend[] = 'WhatsApp com DDD (obrigatório para contato e pedidos)';
    }
    if (!filter_var((string) ($c['email'] ?? ''), FILTER_VALIDATE_EMAIL)) {
        $pend[] = 'Conta Google / E-mail válido';
    }
    return $pend;
}

function pz_comprador_nome_padrao($nome, $email)
{
    $n = trim(strip_tags((string) $nome));
    if ($n !== '') {
        return $n;
    }
    $parte = explode('@', (string) $email)[0] ?? '';
    $parte = preg_replace('/[._-]+/', ' ', $parte);
    $parte = ucwords(trim($parte));
    return $parte !== '' ? $parte : 'Cliente AcheIAki';
}

function pz_comprador_verificar_google_jwt($idToken, $clientIdEsperado = '')
{
    $idToken = trim((string) $idToken);
    if ($idToken === '' || substr_count($idToken, '.') !== 2) {
        return null;
    }
    $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($idToken);
    $ctx = stream_context_create(['http' => ['timeout' => 6, 'ignore_errors' => true]]);
    $resp = @file_get_contents($url, false, $ctx);
    if (is_string($resp) && $resp !== '') {
        $info = json_decode($resp, true);
        $emailVerificado = !empty($info['email_verified']) && ($info['email_verified'] === true || $info['email_verified'] === 'true');
        if (is_array($info) && $emailVerificado && !empty($info['email']) && filter_var($info['email'], FILTER_VALIDATE_EMAIL)) {
            if ($clientIdEsperado !== '' && (($info['aud'] ?? '') !== $clientIdEsperado)) {
                return null;
            }
            return [
                'email' => strtolower(trim((string) $info['email'])),
                'nome' => trim((string) ($info['name'] ?? '')),
                'google_sub' => trim((string) ($info['sub'] ?? '')),
                'foto' => trim((string) ($info['picture'] ?? '')),
            ];
        }
    }
    return null;
}

function pz_comprador_publico(array $c)
{
    unset($c['senha_hash']);
    $pendencias = pz_comprador_pendencias($c);
    $c['pendencias'] = $pendencias;
    $c['perfil_completo'] = count($pendencias) === 0;
    return $c;
}

function pz_comprador_sync_sql(array $c)
{
    $pdo = function_exists('pz_pdo') ? pz_pdo() : null;
    if (!$pdo) {
        return;
    }
    $completo = count(pz_comprador_pendencias($c)) === 0 ? 1 : 0;
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $params = [
        (string) ($c['telefone'] ?? ''),
        (string) ($c['nome'] ?? ''),
        (string) ($c['cpf'] ?? ''),
        (string) ($c['email'] ?? ''),
        (string) ($c['senha_hash'] ?? ''),
        json_encode($c['enderecos'] ?? [], JSON_UNESCAPED_UNICODE),
        json_encode($c['favoritos'] ?? [], JSON_UNESCAPED_UNICODE),
        $completo,
        (string) ($c['criado_em'] ?? date('Y-m-d H:i:s')),
        (string) ($c['atualizado_em'] ?? date('Y-m-d H:i:s')),
    ];
    try {
        if ($driver === 'pgsql') {
            $sql = 'INSERT INTO pz_compradores (telefone, nome, cpf, email, senha_hash, enderecos_json, favoritos_json, perfil_completo, criado_em, atualizado_em)
                    VALUES (?,?,?,?,?,?,?,?,?,?)
                    ON CONFLICT (telefone) DO UPDATE SET
                        nome=EXCLUDED.nome, cpf=EXCLUDED.cpf, email=EXCLUDED.email,
                        enderecos_json=EXCLUDED.enderecos_json, favoritos_json=EXCLUDED.favoritos_json,
                        perfil_completo=EXCLUDED.perfil_completo, atualizado_em=EXCLUDED.atualizado_em';
        } else {
            $sql = 'INSERT INTO pz_compradores (telefone, nome, cpf, email, senha_hash, enderecos_json, favoritos_json, perfil_completo, criado_em, atualizado_em)
                    VALUES (?,?,?,?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE
                        nome=VALUES(nome), cpf=VALUES(cpf), email=VALUES(email),
                        enderecos_json=VALUES(enderecos_json), favoritos_json=VALUES(favoritos_json),
                        perfil_completo=VALUES(perfil_completo), atualizado_em=VALUES(atualizado_em)';
        }
        $pdo->prepare($sql)->execute($params);
    } catch (Throwable $e) {
        // espelho SQL não bloqueia fluxo JSON/KV
    }
}

$raw = file_get_contents('php://input');
$body = json_decode($raw ?: '', true);
if (!is_array($body)) {
    $body = [];
}

$acao = (string) ($_GET['action'] ?? $body['action'] ?? 'perfil');

Database::setTenant('default');

if ($acao === 'config_login') {
    $pub = function_exists('pedidozap_integracoes_publicas') ? pedidozap_integracoes_publicas() : [];
    echo json_encode([
        'success' => true,
        'google_oauth_client_id' => $pub['google_oauth_client_id'] ?? '',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'entrar_google') {
    $pub = function_exists('pedidozap_integracoes_publicas') ? pedidozap_integracoes_publicas() : [];
    $clientId = $pub['google_oauth_client_id'] ?? '';
    $credential = trim((string) ($body['credential'] ?? ''));

    $email = strtolower(trim(strip_tags((string) ($body['email'] ?? ''))));
    $nome = trim(strip_tags((string) ($body['nome'] ?? '')));
    $googleSub = trim((string) ($body['google_sub'] ?? ''));
    $tel = pz_comprador_normalizar_tel($body['telefone'] ?? '');

    $verificadoGoogle = false;
    if ($credential !== '') {
        $ver = pz_comprador_verificar_google_jwt($credential, $clientId);
        if (!$ver) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Não foi possível validar o token da sua conta Google.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $verificadoGoogle = true;
        $email = $ver['email'];
        if ($ver['nome'] !== '') {
            $nome = $ver['nome'];
        }
        $googleSub = $ver['google_sub'];
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Informe um e-mail válido da sua conta Google.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($tel !== '' && (strlen($tel) < 10 || strlen($tel) > 11)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Informe um WhatsApp válido com DDD (10 ou 11 dígitos).'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $listaAtual = Database::read('compradores_mkt.json');
    $encontrado = null;
    foreach ($listaAtual as $c) {
        if (!is_array($c)) {
            continue;
        }
        if (strtolower((string) ($c['email'] ?? '')) === $email || ($tel !== '' && ($c['telefone'] ?? '') === $tel)) {
            $encontrado = $c;
            break;
        }
    }

    if ($encontrado) {
        // Sem token JWT verificado pelo Google, nunca permite entrar em conta já existente (previne sequestro de conta)
        if (!$verificadoGoogle) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'message' => 'Este e-mail ou WhatsApp já tem cadastro. Entre com sua senha na aba "Já tenho conta" ou use o botão oficial do Google.',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $telFinal = strlen((string) ($encontrado['telefone'] ?? '')) >= 10 ? $encontrado['telefone'] : $tel;
        if (strlen($telFinal) < 10) {
            echo json_encode([
                'success' => true,
                'precisa_whatsapp' => true,
                'google_perfil' => ['email' => $email, 'nome' => $encontrado['nome'] ?: $nome, 'google_sub' => $googleSub],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $atualizado = null;
        Database::mutate('compradores_mkt.json', function (&$lista) use ($encontrado, $email, $nome, $googleSub, $telFinal, &$atualizado) {
            foreach ($lista as &$c) {
                if (!is_array($c) || (int) ($c['id'] ?? 0) !== (int) ($encontrado['id'] ?? -1)) {
                    continue;
                }
                if ($c['email'] === '' || $c['email'] === $email) {
                    $c['email'] = $email;
                }
                if ($nome !== '' && (empty($c['nome']) || $c['nome'] === 'Cliente PedidoZap' || $c['nome'] === 'Cliente AcheIAki')) {
                    $c['nome'] = $nome;
                }
                if ($googleSub !== '') {
                    $c['google_sub'] = $googleSub;
                }
                if (empty($c['telefone'])) {
                    $c['telefone'] = $telFinal;
                }
                $c['perfil_completo'] = count(pz_comprador_pendencias($c)) === 0;
                $c['atualizado_em'] = date('Y-m-d H:i:s');
                $atualizado = $c;
                return true;
            }
            unset($c);
            return false;
        });
        $resComp = $atualizado ?: $encontrado;
        pz_comprador_sync_sql($resComp);
        echo json_encode([
            'success' => true,
            'token' => pz_comprador_gerar_token($resComp['telefone']),
            'comprador' => pz_comprador_publico($resComp),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (strlen($tel) < 10) {
        echo json_encode([
            'success' => true,
            'precisa_whatsapp' => true,
            'google_perfil' => ['email' => $email, 'nome' => pz_comprador_nome_padrao($nome, $email), 'google_sub' => $googleSub],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $cpfOpt = preg_replace('/\D/', '', (string) ($body['cpf'] ?? ''));
    if ($cpfOpt !== '' && !pz_comprador_validar_cpf($cpfOpt)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'O CPF informado é inválido (deixe em branco se preferir).'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $endsOpt = [];
    $endRaw = is_array($body['endereco'] ?? null) ? $body['endereco'] : [];
    if (!empty($endRaw['cep']) || !empty($endRaw['rua']) || !empty($endRaw['cidade'])) {
        $nEnd = pz_comprador_normalizar_endereco($endRaw);
        $endsOpt[] = $nEnd;
    }

    $novo = [
        'id' => time(),
        'telefone' => $tel,
        'cpf' => $cpfOpt,
        'nome' => pz_comprador_nome_padrao($nome, $email),
        'email' => $email,
        'google_sub' => $googleSub,
        'senha_hash' => '',
        'enderecos' => $endsOpt,
        'favoritos' => [],
        'perfil_completo' => true,
        'consentimento_lgpd' => [
            'termos_aceitos' => true,
            'versao_termos' => '2026-11',
            'ia_personalizacao' => !empty($body['ia_personalizacao']),
            'aceito_em' => date('c'),
            'ip_hash' => hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? '')),
        ],
        'criado_em' => date('Y-m-d H:i:s'),
        'atualizado_em' => date('Y-m-d H:i:s'),
    ];

    Database::mutate('compradores_mkt.json', function (&$lista) use (&$novo) {
        if (!is_array($lista)) {
            $lista = [];
        }
        $maxId = 0;
        foreach ($lista as $c) {
            $maxId = max($maxId, (int) ($c['id'] ?? 0));
        }
        $novo['id'] = $maxId + 1;
        $lista[] = $novo;
        return true;
    });

    pz_comprador_sync_sql($novo);
    echo json_encode([
        'success' => true,
        'token' => pz_comprador_gerar_token($tel),
        'comprador' => pz_comprador_publico($novo),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'cadastrar') {
    $nomeRaw = trim(strip_tags((string) ($body['nome'] ?? '')));
    $cpf = preg_replace('/\D/', '', (string) ($body['cpf'] ?? ''));
    $tel = pz_comprador_normalizar_tel($body['telefone'] ?? '');
    $email = strtolower(trim(strip_tags((string) ($body['email'] ?? ''))));
    $senha = (string) ($body['senha'] ?? '');
    $endRaw = is_array($body['endereco'] ?? null)
        ? $body['endereco']
        : (is_array($body['enderecos'][0] ?? null) ? $body['enderecos'][0] : []);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Informe um e-mail da sua conta Google válido.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (strlen($tel) < 10 || strlen($tel) > 11) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Informe um WhatsApp válido com DDD (10 ou 11 números).'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($cpf !== '' && !pz_comprador_validar_cpf($cpf)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'CPF inválido (ou deixe em branco, pois é opcional para compradores).'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($senha !== '' && strlen($senha) < 6) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Se quiser criar senha, use pelo menos 6 caracteres.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $endsOpt = [];
    if (!empty($endRaw['cep']) || !empty($endRaw['rua']) || !empty($endRaw['cidade'])) {
        $nEnd = pz_comprador_normalizar_endereco($endRaw);
        if (($nEnd['lat'] === 0.0 || $nEnd['lng'] === 0.0) && !empty($nEnd['cidade']) && function_exists('pedidozap_geocode_query')) {
            $geo = pedidozap_geocode_query(implode(', ', array_filter([$nEnd['rua'], $nEnd['numero'], $nEnd['bairro'], $nEnd['cidade'], $nEnd['estado'], 'Brasil'])));
            if ($geo) {
                $nEnd['lat'] = round((float) $geo['lat'], 7);
                $nEnd['lng'] = round((float) $geo['lng'], 7);
            }
        }
        $endsOpt[] = $nEnd;
    }

    $hash = $senha !== '' ? password_hash($senha, PASSWORD_DEFAULT) : '';
    $novo = [
        'id' => time(),
        'telefone' => $tel,
        'cpf' => $cpf,
        'nome' => pz_comprador_nome_padrao($nomeRaw, $email),
        'email' => $email,
        'senha_hash' => $hash,
        'enderecos' => $endsOpt,
        'favoritos' => [],
        'perfil_completo' => true,
        'consentimento_lgpd' => [
            'termos_aceitos' => true,
            'versao_termos' => '2026-11',
            'ia_personalizacao' => !empty($body['ia_personalizacao']),
            'aceito_em' => date('c'),
            'ip_hash' => hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? '')),
        ],
        'criado_em' => date('Y-m-d H:i:s'),
        'atualizado_em' => date('Y-m-d H:i:s'),
    ];

    $motivoDuplicado = '';
    $ok = Database::mutate('compradores_mkt.json', function (&$lista) use (&$novo, &$motivoDuplicado, $tel, $cpf) {
        if (!is_array($lista)) {
            $lista = [];
        }
        $maxId = 0;
        foreach ($lista as $c) {
            if (!is_array($c)) {
                continue;
            }
            $maxId = max($maxId, (int) ($c['id'] ?? 0));
            if (($c['telefone'] ?? '') === $tel) {
                $motivoDuplicado = 'Este WhatsApp já tem cadastro. Faça login para continuar.';
                return false;
            }
            if ($cpf !== '' && preg_replace('/\D/', '', (string) ($c['cpf'] ?? '')) === $cpf) {
                $motivoDuplicado = 'Este CPF já possui uma conta cadastrada. Faça login para continuar.';
                return false;
            }
        }
        $novo['id'] = $maxId + 1;
        $lista[] = $novo;
        return true;
    });

    if ($motivoDuplicado !== '') {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => $motivoDuplicado], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!$ok) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erro ao salvar cadastro.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    pz_comprador_sync_sql($novo);

    echo json_encode([
        'success' => true,
        'token' => pz_comprador_gerar_token($tel),
        'comprador' => pz_comprador_publico($novo),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'vincular_rapido') {
    if (function_exists('pedidozap_limite_requisicao') && !pedidozap_limite_requisicao('vincular_rapido', 25, 60)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'Muitas tentativas em sequência. Aguarde um instante.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $tel = pz_comprador_normalizar_tel($body['telefone'] ?? ($_GET['telefone'] ?? ''));
    $tenantSlug = preg_replace('/[^a-z0-9_-]/i', '', (string) ($body['tenant'] ?? ($_GET['tenant'] ?? 'default'))) ?: 'default';
    if (strlen($tel) < 10) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Informe um WhatsApp válido com DDD.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $tokRapido = (string) ($body['token'] ?? $_GET['token'] ?? '');
    $telAuthRapido = $tokRapido !== '' ? (string) (pz_comprador_validar_token($tokRapido) ?? '') : '';
    $autorizadoProprio = ($telAuthRapido !== '' && $telAuthRapido === $tel);

    $existe = false;
    $perfil = null;
    foreach (Database::read('compradores_mkt.json') as $c) {
        if (is_array($c) && pz_comprador_normalizar_tel($c['telefone'] ?? '') === $tel) {
            $existe = true;
            if ($autorizadoProprio) {
                $ends = is_array($c['enderecos'] ?? null) ? $c['enderecos'] : [];
                $perfil = [
                    'nome' => (string) ($c['nome'] ?? ''),
                    'telefone' => $tel,
                    'endereco' => $ends[0] ?? null,
                ];
            }
            break;
        }
    }

    if (!$perfil && $autorizadoProprio) {
        $antT = Database::getTenant();
        Database::setTenant($tenantSlug);
        $clientesLoja = Database::read('clientes.json');
        Database::setTenant($antT);
        if (is_array($clientesLoja)) {
            foreach ($clientesLoja as $cl) {
                if (is_array($cl) && substr(pz_comprador_normalizar_tel($cl['celular'] ?? $cl['telefone'] ?? ''), -8) === substr($tel, -8)) {
                    $existe = true;
                    $perfil = [
                        'nome' => (string) ($cl['nome'] ?? ''),
                        'telefone' => $tel,
                        'endereco' => is_array($cl['endereco'] ?? null) ? $cl['endereco'] : null,
                    ];
                    break;
                }
            }
        }
    }

    echo json_encode([
        'success' => true,
        'encontrado' => $perfil !== null,
        'requer_login' => ($existe && !$autorizadoProprio),
        'perfil' => $perfil,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'login') {
    $ident = trim((string) ($body['telefone'] ?? $body['email'] ?? ''));
    $tel = pz_comprador_normalizar_tel($ident);
    $emailLogin = strtolower($ident);
    $senha = (string) ($body['senha'] ?? '');
    $achou = null;
    foreach (Database::read('compradores_mkt.json') as $c) {
        if (!is_array($c)) {
            continue;
        }
        $matchTel = $tel !== '' && ($c['telefone'] ?? '') === $tel;
        $matchEmail = filter_var($emailLogin, FILTER_VALIDATE_EMAIL) && strtolower((string) ($c['email'] ?? '')) === $emailLogin;
        if ($matchTel || $matchEmail) {
            $achou = $c;
            break;
        }
    }
    $hashAtual = (string) ($achou['senha_hash'] ?? '');
    $okSenha = $achou && $hashAtual !== '' && password_verify($senha, $hashAtual);
    if (!$okSenha) {
        http_response_code(401);
        $msg = ($achou && $hashAtual === '')
            ? 'Esta conta foi criada via Google/WhatsApp sem senha. Clique em "Continuar com Google" acima.'
            : 'WhatsApp/E-mail ou senha inválidos.';
        echo json_encode(['success' => false, 'message' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'success' => true,
        'token' => pz_comprador_gerar_token($achou['telefone']),
        'comprador' => pz_comprador_publico($achou),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$token = (string) ($body['token'] ?? $_GET['token'] ?? '');
$telAuth = pz_comprador_validar_token($token);
if (!$telAuth) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sessão do comprador expirada ou ausente.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'perfil') {
    foreach (Database::read('compradores_mkt.json') as $c) {
        if (is_array($c) && ($c['telefone'] ?? '') === $telAuth) {
            echo json_encode(['success' => true, 'comprador' => pz_comprador_publico($c)], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Comprador não encontrado.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (in_array($acao, ['atualizar_perfil', 'salvar_endereco', 'remover_endereco', 'definir_endereco_ativo', 'alternar_favorito'], true)) {
    $endValidado = null;
    if ($acao === 'salvar_endereco') {
        $endRaw = is_array($body['endereco'] ?? null) ? $body['endereco'] : [];
        $val = pz_comprador_validar_endereco($endRaw, true);
        if (empty($val['ok'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $val['message']], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $endValidado = $val['endereco'];
    } elseif ($acao === 'atualizar_perfil') {
        $nomeUp = trim(strip_tags((string) ($body['nome'] ?? '')));
        $cpfUp = preg_replace('/\D/', '', (string) ($body['cpf'] ?? ''));
        $emailUp = strtolower(trim(strip_tags((string) ($body['email'] ?? ''))));
        if ($cpfUp !== '' && !pz_comprador_validar_cpf($cpfUp)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'CPF inválido (ou deixe em branco).'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!filter_var($emailUp, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Informe um e-mail da conta Google válido.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (is_array($body['endereco'] ?? null)) {
            $nEnd = pz_comprador_normalizar_endereco($body['endereco']);
            if ($nEnd['rua'] !== '' || $nEnd['cidade'] !== '' || $nEnd['cep'] !== '') {
                $endValidado = $nEnd;
            }
        }
    }

    $atualizado = null;
    $erroRegra = '';
    Database::mutate('compradores_mkt.json', function (&$lista) use ($telAuth, $acao, $body, $endValidado, &$atualizado, &$erroRegra) {
        if (!is_array($lista)) {
            return false;
        }
        foreach ($lista as &$c) {
            if (!is_array($c) || ($c['telefone'] ?? '') !== $telAuth) {
                continue;
            }
            if ($acao === 'atualizar_perfil') {
                $emailNovo = strtolower(trim(strip_tags((string) ($body['email'] ?? $c['email'] ?? ''))));
                $c['email'] = $emailNovo;
                $c['nome'] = pz_comprador_nome_padrao($body['nome'] ?? $c['nome'], $emailNovo);
                $c['cpf'] = preg_replace('/\D/', '', (string) ($body['cpf'] ?? $c['cpf'] ?? ''));
                if ($endValidado) {
                    $ends = is_array($c['enderecos'] ?? null) ? $c['enderecos'] : [];
                    array_unshift($ends, $endValidado);
                    $c['enderecos'] = array_slice($ends, 0, 5);
                }
            } elseif ($acao === 'salvar_endereco' && $endValidado) {
                $listaEnd = is_array($c['enderecos'] ?? null) ? $c['enderecos'] : [];
                array_unshift($listaEnd, $endValidado);
                $c['enderecos'] = array_slice($listaEnd, 0, 5);
            } elseif ($acao === 'remover_endereco') {
                $idx = (int) ($body['index'] ?? -1);
                $listaEnd = array_values(is_array($c['enderecos'] ?? null) ? $c['enderecos'] : []);
                if (isset($listaEnd[$idx])) {
                    array_splice($listaEnd, $idx, 1);
                    $c['enderecos'] = $listaEnd;
                }
            } elseif ($acao === 'definir_endereco_ativo') {
                $idx = (int) ($body['index'] ?? 0);
                $listaEnd = array_values(is_array($c['enderecos'] ?? null) ? $c['enderecos'] : []);
                if (isset($listaEnd[$idx]) && $idx > 0) {
                    $escolhido = $listaEnd[$idx];
                    array_splice($listaEnd, $idx, 1);
                    array_unshift($listaEnd, $escolhido);
                    $c['enderecos'] = $listaEnd;
                }
            } elseif ($acao === 'alternar_favorito') {
                $slug = trim((string) ($body['slug'] ?? ''));
                $favs = is_array($c['favoritos'] ?? null) ? $c['favoritos'] : [];
                if ($slug !== '') {
                    if (in_array($slug, $favs, true)) {
                        $favs = array_values(array_diff($favs, [$slug]));
                    } else {
                        $favs[] = $slug;
                    }
                    $c['favoritos'] = $favs;
                }
            }
            $c['perfil_completo'] = count(pz_comprador_pendencias($c)) === 0;
            $c['atualizado_em'] = date('Y-m-d H:i:s');
            $atualizado = $c;
            return true;
        }
        unset($c);
        return false;
    });

    if ($erroRegra !== '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => $erroRegra], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!$atualizado) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Perfil não encontrado.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    pz_comprador_sync_sql($atualizado);
    echo json_encode(['success' => true, 'comprador' => pz_comprador_publico($atualizado)], JSON_UNESCAPED_UNICODE);
    exit;
}

// =========================================================================
// DIREITOS DO TITULAR (LGPD - Lei 13.709/2018, Art. 18)
// =========================================================================
if ($acao === 'atualizar_consentimento_lgpd') {
    $iaOptIn = !empty($body['ia_personalizacao']);
    $atualizado = null;
    Database::mutate('compradores_mkt.json', function (&$lista) use ($telAuth, $iaOptIn, &$atualizado) {
        if (!is_array($lista)) {
            return false;
        }
        foreach ($lista as &$c) {
            if (!is_array($c) || ($c['telefone'] ?? '') !== $telAuth) {
                continue;
            }
            $lgpd = is_array($c['consentimento_lgpd'] ?? null) ? $c['consentimento_lgpd'] : [];
            $lgpd['termos_aceitos'] = true;
            $lgpd['versao_termos'] = '2026-11';
            $lgpd['ia_personalizacao'] = $iaOptIn;
            $lgpd['atualizado_em'] = date('c');
            $c['consentimento_lgpd'] = $lgpd;
            $c['atualizado_em'] = date('Y-m-d H:i:s');
            $atualizado = $c;
            return true;
        }
        unset($c);
        return false;
    });
    if (!$atualizado) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Conta não encontrada.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    pz_comprador_sync_sql($atualizado);
    echo json_encode(['success' => true, 'comprador' => pz_comprador_publico($atualizado)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'exportar_dados_lgpd') {
    $perfil = null;
    foreach (Database::read('compradores_mkt.json') as $c) {
        if (is_array($c) && ($c['telefone'] ?? '') === $telAuth) {
            $perfil = pz_comprador_publico($c);
            break;
        }
    }
    if (!$perfil) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Titular não encontrado.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode([
        'success' => true,
        'portabilidade_lgpd' => [
            'plataforma' => 'AcheIAki',
            'exportado_em' => date('c'),
            'base_legal' => 'Art. 18, incisos II e V da Lei nº 13.709/2018 (LGPD)',
            'titular' => $perfil,
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if ($acao === 'excluir_conta_lgpd') {
    $removido = false;
    Database::mutate('compradores_mkt.json', function (&$lista) use ($telAuth, &$removido) {
        if (!is_array($lista)) {
            return false;
        }
        $antes = count($lista);
        $lista = array_values(array_filter($lista, function ($c) use ($telAuth) {
            return !is_array($c) || ($c['telefone'] ?? '') !== $telAuth;
        }));
        $removido = count($lista) < $antes;
        return $removido;
    });
    $pdo = function_exists('pz_pdo') ? pz_pdo() : null;
    if ($pdo) {
        try {
            $pdo->prepare('DELETE FROM pz_compradores WHERE telefone = ?')->execute([$telAuth]);
        } catch (Throwable $e) { /* segue */ }
    }
    echo json_encode([
        'success' => $removido,
        'message' => $removido ? 'Sua conta e dados pessoais foram excluídos conforme a LGPD.' : 'Conta não encontrada.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Ação desconhecida.'], JSON_UNESCAPED_UNICODE);
