<?php
/**
 * Controle comercial do Micro SaaS: registro das lojas, plano e chave mestre.
 * A senha do lojista permanece só com hash. O master entra por impersonação.
 */
require_once __DIR__ . '/auth_lib.php';

function pedidozap_nicho_presets()
{
    $cat = function ($slug, $nome, $emoji, $cor, $sub) {
        return [
            'slug' => $slug,
            'nome' => $nome,
            'subtitulo' => $sub,
            'emoji' => $emoji,
            'icone' => 'fa-utensils',
            'cor' => $cor,
            'ativo' => true,
        ];
    };

    return [
        'lanchonete' => [
            'rotulo' => 'Lanchonete',
            'categorias' => [
                $cat('lanches', 'Lanches', '🍔', '#f97136', 'Os mais pedidos'),
                $cat('porcoes', 'Porções', '🍟', '#f59e0b', 'Para acompanhar'),
                $cat('bebidas', 'Bebidas', '🥤', '#2563eb', 'Geladas'),
                $cat('sobremesas', 'Sobremesas', '🍰', '#db2777', 'Para fechar'),
            ],
        ],
        'pensao' => [
            'rotulo' => 'Pensão / comida caseira',
            'categorias' => [
                $cat('pratos', 'Pratos feitos', '🍽️', '#b45309', 'Almoço do dia'),
                $cat('marmitas', 'Marmitas', '🍱', '#ea580c', 'P, M e G'),
                $cat('guarnicoes', 'Guarnições', '🥗', '#16a34a', 'Acompanhamentos'),
                $cat('sopas', 'Sopas e caldos', '🥣', '#0369a1', 'Quentinhas'),
                $cat('bebidas', 'Bebidas', '🥤', '#2563eb', 'Geladas'),
            ],
        ],
        'marmitaria' => [
            'rotulo' => 'Marmitaria',
            'categorias' => [
                $cat('marmitas', 'Marmitas', '🍱', '#ea580c', 'Monte a sua'),
                $cat('executivos', 'Executivos', '🥗', '#15803d', 'Pratos leves'),
                $cat('bebidas', 'Bebidas', '🥤', '#2563eb', 'Geladas'),
            ],
        ],
        'pizzaria' => [
            'rotulo' => 'Pizzaria',
            'categorias' => [
                $cat('pizzas', 'Pizzas', '🍕', '#dc2626', 'Tradicionais e especiais'),
                $cat('bordas', 'Bordas e adicionais', '🧀', '#d97706', 'Para incrementar'),
                $cat('bebidas', 'Bebidas', '🥤', '#2563eb', 'Geladas'),
            ],
        ],
        'acai' => [
            'rotulo' => 'Açaí',
            'categorias' => [
                $cat('acai', 'Açaí', '🫐', '#7e22ce', 'Tigelas'),
                $cat('complementos', 'Complementos', '🍓', '#db2777', 'Monte do seu jeito'),
                $cat('bebidas', 'Bebidas', '🥤', '#2563eb', 'Geladas'),
            ],
        ],
        'confeitaria' => [
            'rotulo' => 'Doceria / confeitaria',
            'categorias' => [
                $cat('bolos', 'Bolos e fatias', '🎂', '#db2777', 'Encomendas'),
                $cat('doces', 'Doces', '🍬', '#f59e0b', 'Para presentear'),
                $cat('bebidas', 'Bebidas', '☕', '#78350f', 'Para acompanhar'),
            ],
        ],
    ];
}

function pedidozap_saas_config()
{
    $anterior = Database::getTenant();
    Database::setTenant('default');
    $data = Database::readStrict('saas_config.json');
    Database::setTenant($anterior);

    if (!is_array($data) || empty($data)) {
        $path = __DIR__ . '/data/saas_config.json';
        if (is_file($path)) {
            $fileData = json_decode(@file_get_contents($path) ?: '', true);
            if (is_array($fileData)) {
                $data = $fileData;
            }
        }
    }
    if (!is_array($data)) {
        $data = [];
    }

    $cfg = array_merge([
        'nome_plataforma' => 'AcheIAki',
        'slogan_plataforma' => 'O Marketplace Inteligente da sua Região',
        'suporte_whatsapp' => '',
        'dpo_email' => 'privacidade@acheiaki.com.br',
        'cnpj_plataforma' => '',
        'dominio_base' => '',
        'google_site_verification' => '',
        'mensalidade_valor' => 0,
        'mp_access_token' => '',
    ], $data);

    $envMp = function_exists('pz_db_env_val') ? pz_db_env_val('ACHEIAKI_MP_ACCESS_TOKEN') : '';
    if ($envMp !== '') {
        $cfg['mp_access_token'] = $envMp;
    }
    $envDpo = function_exists('pz_db_env_val') ? pz_db_env_val('ACHEIAKI_DPO_EMAIL') : '';
    if ($envDpo !== '') {
        $cfg['dpo_email'] = $envDpo;
    }
    $envGsc = function_exists('pz_db_env_val') ? pz_db_env_val('ACHEIAKI_GSC_TOKEN') : '';
    if ($envGsc !== '') {
        $cfg['google_site_verification'] = $envGsc;
    }
    return $cfg;
}

/** Versão da configuração que pode ir para o navegador do master: nunca devolve o token. */
function pedidozap_saas_config_publica()
{
    $saas = pedidozap_saas_config();
    $token = (string) ($saas['mp_access_token'] ?? '');
    unset($saas['mp_access_token']);
    $saas['mp_configurado'] = $token !== '';
    $saas['mp_token_final'] = $token !== '' ? substr($token, -4) : '';
    $saas['mp_modo_teste'] = strpos($token, 'TEST-') === 0;
    $saas['webhook_url'] = pedidozap_base_url() . '/mp_webhook.php';
    return $saas;
}

function pedidozap_salvar_saas_config(array $config)
{
    $atual = pedidozap_saas_config();
    $atual['nome_plataforma'] = trim((string) ($config['nome_plataforma'] ?? $atual['nome_plataforma']));
    $atual['suporte_whatsapp'] = preg_replace('/\D/', '', (string) ($config['suporte_whatsapp'] ?? $atual['suporte_whatsapp'] ?? ''));
    if (array_key_exists('dpo_email', $config)) {
        $atual['dpo_email'] = trim((string) $config['dpo_email']);
    }
    if (array_key_exists('cnpj_plataforma', $config)) {
        $atual['cnpj_plataforma'] = trim((string) $config['cnpj_plataforma']);
    }
    if (array_key_exists('dominio_base', $config)) {
        $dominio = strtolower(trim((string) $config['dominio_base']));
        $dominio = preg_replace('#^https?://#', '', $dominio);
        $dominio = preg_replace('#/.*$#', '', $dominio);
        $atual['dominio_base'] = preg_replace('/^www\./', '', $dominio);
    }
    if (array_key_exists('google_site_verification', $config)) {
        $gsc = trim((string) $config['google_site_verification']);
        if (preg_match('/content\s*=\s*["\']([^"\']+)["\']/i', $gsc, $m)) {
            $gsc = trim($m[1]);
        }
        $atual['google_site_verification'] = preg_replace('/[^a-zA-Z0-9_\-]/', '', $gsc);
    }
    if (array_key_exists('mensalidade_valor', $config)) {
        $valor = (float) str_replace(',', '.', (string) $config['mensalidade_valor']);
        $atual['mensalidade_valor'] = $valor > 0 ? round($valor, 2) : 0;
    }
    $token = trim((string) ($config['mp_access_token'] ?? ''));
    if ($token !== '') {
        $atual['mp_access_token'] = $token;
    }
    if (!empty($config['mp_remover_token'])) {
        $atual['mp_access_token'] = '';
    }
    $anterior = Database::getTenant();
    Database::setTenant('default');
    $ok = Database::write('saas_config.json', $atual);
    Database::setTenant($anterior);
    return $ok;
}

function pedidozap_ler_registro()
{
    $anterior = Database::getTenant();
    Database::setTenant('default');
    $registro = Database::read('tenants_registry.json');
    Database::setTenant($anterior);
    return is_array($registro) ? $registro : [];
}

function pedidozap_salvar_registro_loja($slug, array $meta)
{
    $slug = strtolower(trim((string) $slug));
    if ($slug === '' || !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $slug)) {
        return false;
    }

    $anterior = Database::getTenant();
    Database::setTenant('default');
    $ok = Database::mutate('tenants_registry.json', function (&$registro) use ($slug, $meta) {
        if (!is_array($registro)) {
            $registro = [];
        }
        $atual = is_array($registro[$slug] ?? null) ? $registro[$slug] : [];
        $registro[$slug] = array_merge($atual, $meta, [
            'slug' => $slug,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        if (empty($registro[$slug]['criado_em'])) {
            $registro[$slug]['criado_em'] = date('Y-m-d H:i:s');
        }
        return true;
    });
    Database::setTenant($anterior);
    return $ok;
}

function pedidozap_assinatura_publica($slug)
{
    $registro = pedidozap_ler_registro();
    $loja = is_array($registro[$slug] ?? null) ? $registro[$slug] : [];
    $saas = pedidozap_saas_config();
    $efetivo = pedidozap_status_efetivo($loja);
    return [
        'status' => $efetivo['status'],
        'status_cadastro' => $loja['status'] ?? 'ativo',
        'motivo' => $efetivo['motivo'],
        'em_teste' => !empty($loja['em_teste']),
        'plano' => $loja['plano'] ?? 'basico',
        'vencimento' => $loja['vencimento'] ?? '',
        'nicho' => $loja['nicho'] ?? ($loja['NICHO'] ?? ''),
        'suporte_whatsapp' => $saas['suporte_whatsapp'] ?? '',
        'nome_plataforma' => $saas['nome_plataforma'] ?? 'AcheIAki',
        'mensalidade_valor' => (float) ($saas['mensalidade_valor'] ?? 0),
        'pagamento_online' => !empty($saas['mp_access_token']) && (float) ($saas['mensalidade_valor'] ?? 0) > 0,
        'assinatura_status' => $loja['assinatura_status'] ?? '',
        'ultimo_pagamento' => $loja['ultimo_pagamento'] ?? '',
    ];
}

function pedidozap_base_url()
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $saas = pedidozap_saas_config();
    $host = !empty($saas['dominio_base']) ? $saas['dominio_base'] : ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    if (!empty($saas['dominio_base'])) {
        $https = true;
    }
    return ($https ? 'https' : 'http') . '://' . $host . $dir;
}

/**
 * Chamada à API do Mercado Pago com o token guardado só no servidor.
 * Retorna [codigoHttp, respostaDecodificada].
 */
function pedidozap_mp_request($metodo, $caminho, $corpo = null)
{
    $token = (string) (pedidozap_saas_config()['mp_access_token'] ?? '');
    if ($token === '') {
        return [0, ['message' => 'Mercado Pago não configurado.']];
    }
    if (!function_exists('curl_init')) {
        return [0, ['message' => 'Extensão cURL indisponível no servidor.']];
    }
    $ch = curl_init('https://api.mercadopago.com' . $caminho);
    $headers = [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
    ];
    if ($metodo === 'POST') {
        $headers[] = 'X-Idempotency-Key: ' . bin2hex(random_bytes(16));
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $metodo,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($corpo !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($corpo, JSON_UNESCAPED_UNICODE));
    }
    $resposta = curl_exec($ch);
    $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $dados = json_decode((string) $resposta, true);
    return [$codigo, is_array($dados) ? $dados : []];
}

function pedidozap_mp_criar_assinatura($slug, $email)
{
    $saas = pedidozap_saas_config();
    $valor = (float) ($saas['mensalidade_valor'] ?? 0);
    if ($valor <= 0 || empty($saas['mp_access_token'])) {
        return ['success' => false, 'message' => 'O pagamento online ainda não foi ativado pela plataforma. Fale com o suporte.'];
    }
    $registro = pedidozap_ler_registro();
    $loja = is_array($registro[$slug] ?? null) ? $registro[$slug] : [];
    $nome = $loja['nome_empresa'] ?? $slug;

    $retorno = pedidozap_base_url() . '/admin.html?assinatura=retorno' . ($slug !== 'default' ? '&tenant=' . rawurlencode($slug) : '');
    [$codigo, $dados] = pedidozap_mp_request('POST', '/preapproval', [
        'reason' => ($saas['nome_plataforma'] ?: 'AcheIAki') . ' - mensalidade ' . $nome,
        'external_reference' => $slug,
        'payer_email' => $email,
        'back_url' => $retorno,
        'auto_recurring' => [
            'frequency' => 1,
            'frequency_type' => 'months',
            'transaction_amount' => $valor,
            'currency_id' => 'BRL',
        ],
        'status' => 'pending',
    ]);
    if ($codigo < 200 || $codigo >= 300 || empty($dados['init_point'])) {
        $msg = $dados['message'] ?? 'O Mercado Pago recusou a criação da assinatura.';
        return ['success' => false, 'message' => 'Mercado Pago: ' . $msg];
    }
    pedidozap_salvar_registro_loja($slug, [
        'assinatura_id' => (string) ($dados['id'] ?? ''),
        'assinatura_status' => (string) ($dados['status'] ?? 'pending'),
        'assinatura_email' => $email,
    ]);
    return ['success' => true, 'url' => $dados['init_point']];
}

/**
 * Consulta a assinatura no Mercado Pago e atualiza vencimento/status da loja.
 * Nunca confia no corpo do webhook: sempre relê da API.
 */
function pedidozap_mp_sincronizar_assinatura($preapprovalId, $slugEsperado = null)
{
    $preapprovalId = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $preapprovalId);
    if ($preapprovalId === '') {
        return ['success' => false, 'message' => 'Assinatura não encontrada.'];
    }
    [$codigo, $pre] = pedidozap_mp_request('GET', '/preapproval/' . $preapprovalId);
    if ($codigo !== 200 || empty($pre['id'])) {
        return ['success' => false, 'message' => 'Não foi possível consultar a assinatura no Mercado Pago.'];
    }
    $slug = strtolower((string) ($pre['external_reference'] ?? ''));
    if ($slug === '' || ($slugEsperado !== null && $slug !== $slugEsperado)) {
        return ['success' => false, 'message' => 'Assinatura de outra loja.'];
    }
    $registro = pedidozap_ler_registro();
    if (!isset($registro[$slug]) && !Database::tenantDataExists($slug)) {
        return ['success' => false, 'message' => 'Loja desconhecida.'];
    }
    $loja = is_array($registro[$slug] ?? null) ? $registro[$slug] : [];

    $status = (string) ($pre['status'] ?? '');
    $meta = [
        'assinatura_id' => (string) $pre['id'],
        'assinatura_status' => $status,
    ];

    $ultimaCobranca = (string) ($pre['summarized']['last_charged_date'] ?? '');
    $cobrancas = (int) ($pre['summarized']['charged_quantity'] ?? 0);
    if ($status === 'authorized' && $cobrancas > 0 && $ultimaCobranca !== '') {
        $pago = strtotime($ultimaCobranca);
        $candidatos = [];
        if ($pago) {
            $candidatos[] = strtotime('+1 month', $pago);
            $meta['ultimo_pagamento'] = date('Y-m-d', $pago);
        }
        $proxima = strtotime((string) ($pre['next_payment_date'] ?? ''));
        if ($proxima) {
            $candidatos[] = $proxima;
        }
        if ($candidatos) {
            // 3 dias de tolerância para a cobrança automática do mês seguinte cair.
            $novo = strtotime('+3 days', max($candidatos));
            $atual = strtotime((string) ($loja['vencimento'] ?? ''));
            $meta['vencimento'] = date('Y-m-d', ($atual && $atual > $novo) ? $atual : $novo);
        }
        $meta['status'] = 'ativo';
        $meta['em_teste'] = false;
        $meta['plano'] = 'mensal';
    }

    pedidozap_salvar_registro_loja($slug, $meta);
    return ['success' => true, 'slug' => $slug, 'status' => $status, 'pago' => isset($meta['vencimento'])];
}

function pedidozap_status_efetivo(array $loja)
{
    $status = $loja['status'] ?? 'ativo';
    if (!in_array($status, ['ativo', 'bloqueado', 'pendente', 'teste'], true)) {
        $status = 'ativo';
    }
    if ($status === 'bloqueado') {
        return ['status' => 'bloqueado', 'motivo' => 'manual'];
    }
    $vencimento = trim((string) ($loja['vencimento'] ?? ''));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $vencimento) && strtotime($vencimento . ' 23:59:59') < time()) {
        return ['status' => 'bloqueado', 'motivo' => 'vencido'];
    }
    return ['status' => $status, 'motivo' => ''];
}

function pedidozap_recusar_loja_bloqueada($user, $pass)
{
    if (pedidozap_autorizado($user, $pass) === 'master') {
        return;
    }
    if (!pedidozap_loja_bloqueada(Database::getTenant())) {
        return;
    }
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'A mensalidade desta loja venceu ou está bloqueada. Regularize para voltar a receber pedidos.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function pedidozap_slugify($nome)
{
    $bruto = trim((string) $nome);
    $texto = function_exists('mb_strtolower') ? mb_strtolower($bruto, 'UTF-8') : strtolower($bruto);
    $texto = strtr($texto, [
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
        'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n',
    ]);
    $texto = preg_replace('/[^a-z0-9]+/', '-', $texto);
    $texto = trim((string) $texto, '-');
    $texto = substr($texto, 0, 40);
    $reservados = ['default', 'www', 'loja', 'cadastro', 'admin', 'superadmin', 'app', 'mail', 'api'];
    if ($texto === '' || in_array($texto, $reservados, true)) {
        $texto = 'minha-loja';
    }
    $registro = pedidozap_ler_registro();
    $base = $texto;
    $n = 2;
    while (Database::tenantDataExists($texto) || isset($registro[$texto])) {
        $texto = $base . '-' . $n;
        $n++;
        if ($n > 200) {
            $texto = $base . '-' . substr(uniqid(), -4);
            break;
        }
    }
    return $texto;
}

function pedidozap_criar_loja(array $dados)
{
    require_once __DIR__ . '/store_schema.php';
    require_once __DIR__ . '/auth_lib.php';
    require_once __DIR__ . '/integracoes_lib.php';

    $nome = trim((string) ($dados['nome_empresa'] ?? ''));
    $login = trim((string) ($dados['login'] ?? 'admin'));
    $senha = (string) ($dados['senha'] ?? '');
    $nicho = (string) ($dados['nicho'] ?? 'lanchonete');
    $perfil = is_array($dados['perfil'] ?? null) ? $dados['perfil'] : null;

    $cnpj = preg_replace('/\D/', '', (string) ($dados['cnpj'] ?? ($perfil['cnpj'] ?? '')));
    $razaoSocial = trim(strip_tags((string) ($dados['razao_social'] ?? ($perfil['razao_social'] ?? ''))));
    $whatsapp = preg_replace('/\D/', '', (string) ($dados['whatsapp'] ?? ($perfil['telefone'] ?? '')));
    $cep = preg_replace('/\D/', '', (string) ($dados['cep'] ?? ($perfil['endereco']['cep'] ?? '')));
    $rua = trim(strip_tags((string) ($dados['rua'] ?? ($perfil['endereco']['rua'] ?? ''))));
    $numero = trim(strip_tags((string) ($dados['numero'] ?? ($perfil['endereco']['numero'] ?? ''))));
    $bairro = trim(strip_tags((string) ($dados['bairro'] ?? ($perfil['endereco']['bairro'] ?? ''))));
    $cidade = trim(strip_tags((string) ($dados['cidade'] ?? ($perfil['endereco']['cidade'] ?? ''))));
    $estado = strtoupper(trim(strip_tags((string) ($dados['estado'] ?? ($perfil['endereco']['estado'] ?? '')))));
    $raioKm = isset($dados['raio_entrega_km']) && is_numeric($dados['raio_entrega_km']) ? (float) $dados['raio_entrega_km'] : 10.0;
    $tempoMin = isset($dados['tempo_medio_min']) && is_numeric($dados['tempo_medio_min']) ? (int) $dados['tempo_medio_min'] : 35;
    $taxaBase = isset($dados['taxa_entrega_base']) && is_numeric($dados['taxa_entrega_base']) ? max(0.0, (float) $dados['taxa_entrega_base']) : 0.0;

    $exigirCompleto = !empty($dados['exigir_cadastro_completo']);
    if ($exigirCompleto) {
        if (mb_strlen($nome, 'UTF-8') < 3) {
            return ['success' => false, 'message' => 'Informe o nome completo do estabelecimento (mínimo 3 caracteres).'];
        }
        if (!pedidozap_validar_cnpj($cnpj)) {
            return ['success' => false, 'message' => 'CNPJ obrigatório inválido. Confira os 14 dígitos do CNPJ da empresa.'];
        }
        if (strlen($whatsapp) < 10) {
            return ['success' => false, 'message' => 'WhatsApp da loja obrigatório com DDD (mínimo 10 dígitos).'];
        }
        if (strlen($cep) !== 8 || mb_strlen($rua, 'UTF-8') < 3 || $numero === '' || mb_strlen($bairro, 'UTF-8') < 2 || mb_strlen($cidade, 'UTF-8') < 2 || strlen($estado) !== 2) {
            return ['success' => false, 'message' => 'Preencha o endereço completo obrigatório da empresa (CEP com 8 dígitos, Rua, Número, Bairro, Cidade e UF).'];
        }
        if ($raioKm < 1 || $raioKm > 300) {
            return ['success' => false, 'message' => 'Informe o raio máximo de entrega entre 1 km e 300 km (ex.: 10 km ou 100 km).'];
        }
        if ($tempoMin < 10 || $tempoMin > 180) {
            return ['success' => false, 'message' => 'Informe o tempo estimado de entrega entre 10 e 180 minutos.'];
        }
    }

    $slug = strtolower(trim((string) ($dados['slug'] ?? '')));
    if ($slug === '') {
        $slug = pedidozap_slugify($nome);
    }
    if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $slug) || $slug === 'default') {
        return ['success' => false, 'message' => 'O endereço da loja precisa ter só letras minúsculas, números e hífen.'];
    }
    $tamanhoNome = function_exists('mb_strlen') ? mb_strlen($nome) : strlen($nome);
    $tamanhoSenha = function_exists('mb_strlen') ? mb_strlen($senha) : strlen($senha);
    if ($tamanhoNome < 3 || $senha === '' || $tamanhoSenha < 6) {
        return ['success' => false, 'message' => 'Informe o nome da loja e uma senha com pelo menos 6 caracteres.'];
    }
    if ($login === '') {
        $login = 'admin';
    }
    if (Database::tenantDataExists($slug)) {
        return ['success' => false, 'message' => 'Já existe uma loja com esse endereço.'];
    }

    // Evita duplicidade de CNPJ em lojas ativas
    if ($cnpj !== '') {
        $regExist = pedidozap_ler_registro();
        foreach ($regExist as $mExist) {
            if (is_array($mExist) && preg_replace('/\D/', '', (string) ($mExist['cnpj'] ?? '')) === $cnpj) {
                return ['success' => false, 'message' => 'Este CNPJ já está cadastrado no sistema. Entre no painel da sua loja ou fale com o suporte.'];
            }
        }
    }

    $presets = pedidozap_nicho_presets();
    $preset = $presets[$nicho] ?? $presets['lanchonete'];
    $nicho = isset($presets[$nicho]) ? $nicho : 'lanchonete';
    if ($perfil && !empty($perfil['nicho_sugerido']) && isset($presets[$perfil['nicho_sugerido']]) && empty($dados['nicho'])) {
        $nicho = $perfil['nicho_sugerido'];
        $preset = $presets[$nicho];
    }

    $pagamentos = is_array($dados['pagamentos'] ?? null) ? $dados['pagamentos'] : [];
    $pagFinal = [
        'pix' => array_key_exists('pix', $pagamentos) ? !empty($pagamentos['pix']) : true,
        'debito' => array_key_exists('debito', $pagamentos) ? !empty($pagamentos['debito']) : true,
        'credito' => array_key_exists('credito', $pagamentos) ? !empty($pagamentos['credito']) : true,
        'dinheiro' => array_key_exists('dinheiro', $pagamentos) ? !empty($pagamentos['dinheiro']) : true,
        'chave_pix' => trim(strip_tags((string) ($pagamentos['chave_pix'] ?? ''))),
    ];
    if ($exigirCompleto && !$pagFinal['pix'] && !$pagFinal['debito'] && !$pagFinal['credito'] && !$pagFinal['dinheiro']) {
        return ['success' => false, 'message' => 'Selecione pelo menos 1 forma de pagamento aceita pela empresa.'];
    }

    $config = pedidozap_normalizar_config([
        'NOME_LOJA' => $nome,
        'CNPJ' => $cnpj,
        'RAZAO_SOCIAL' => $razaoSocial,
        'NICHO' => $nicho,
        'REDES' => ['whatsapp' => $whatsapp],
        'WHATSAPP_NUMBER' => $whatsapp,
        'TEMPO_ENTREGA_MIN' => max(10, min(180, $tempoMin)),
        'TEMPO_ESPERA' => max(10, min(180, $tempoMin)) . ' min',
        'PAGAMENTOS' => $pagFinal,
        'FRETE' => [
            'modo' => $taxaBase > 0 ? 'fixo' : 'gratis',
            'taxa_fixa' => round($taxaBase, 2),
            'taxa_base' => round($taxaBase, 2),
            'raio_max_km' => max(1.0, min(300.0, round($raioKm, 1))),
        ],
    ], $slug);
    $config['CATEGORIAS'] = [];
    $cardapio = [];
    foreach ($preset['categorias'] as $categoria) {
        $chave = $categoria['slug'];
        unset($categoria['slug']);
        $config['CATEGORIAS'][$chave] = $categoria;
        $config['TEXTOS']['PAGINAS'][$chave] = [
            'titulo' => $categoria['nome'],
            'subtitulo' => $categoria['subtitulo'],
            'hero' => '',
        ];
        $cardapio[$chave] = [];
    }

    if ($perfil) {
        require_once __DIR__ . '/perfil_qualificado_lib.php';
        $config = pedidozap_aplicar_melhorias_config($config, $perfil, ['nome_loja', 'whatsapp', 'endereco', 'frete_entrega']);
        $config['PERFIL_QUALIFICADO'] = array_merge($perfil, ['melhorias_aplicadas_em' => date('c')]);
    }

    if ($rua !== '') {
        $config['ENDERECO']['rua'] = $rua;
    }
    if ($numero !== '') {
        $config['ENDERECO']['numero'] = $numero;
    }
    if ($cidade !== '') {
        $config['ENDERECO']['cidade'] = $cidade;
    }
    if ($bairro !== '') {
        $config['ENDERECO']['bairro'] = $bairro;
    }
    if ($estado !== '') {
        $config['ENDERECO']['estado'] = substr($estado, 0, 2);
    }
    if ($cep !== '') {
        $config['ENDERECO']['cep'] = $cep;
    }
    if (!empty($dados['lat']) && !empty($dados['lng']) && is_numeric($dados['lat']) && is_numeric($dados['lng'])) {
        $config['ENDERECO']['lat'] = round((float) $dados['lat'], 7);
        $config['ENDERECO']['lng'] = round((float) $dados['lng'], 7);
    } elseif (empty($config['ENDERECO']['lat']) && ($config['ENDERECO']['cidade'] !== '' || $config['ENDERECO']['cep'] !== '')) {
        pedidozap_tentar_geocode_loja($config);
    }

    if ($exigirCompleto && (empty($config['ENDERECO']['lat']) || empty($config['ENDERECO']['lng']))) {
        return ['success' => false, 'message' => 'Não foi possível localizar as coordenadas GPS do endereço. Clique em "Capturar GPS da loja" ou confira o CEP e a cidade antes de concluir.'];
    }

    if (!empty($dados['consentimento_lgpd']) && is_array($dados['consentimento_lgpd'])) {
        $config['CONSENTIMENTO_LGPD'] = $dados['consentimento_lgpd'];
    }

    $anterior = Database::getTenant();
    Database::setTenant($slug);
    $okConfig = Database::write('config.json', $config);
    $okMenu = Database::write('cardapio.json', $cardapio);
    $okAuth = pedidozap_salvar_credenciais($login, $senha);
    Database::write('pedidos.json', []);
    Database::setTenant($anterior);

    $vencimento = trim((string) ($dados['vencimento'] ?? ''));
    $emTeste = !empty($dados['em_teste']);
    if ($emTeste && $vencimento === '') {
        $vencimento = date('Y-m-d', strtotime('+7 days'));
    }
    $okRegistro = pedidozap_salvar_registro_loja($slug, [
        'nome_empresa' => $nome,
        'razao_social' => $razaoSocial,
        'cnpj' => $cnpj,
        'google_place_id' => $perfil ? (string) ($perfil['google_place_id'] ?? '') : '',
        'nicho' => $nicho,
        'login' => $login,
        'status' => $dados['status'] ?? ($emTeste ? 'teste' : 'ativo'),
        'plano' => $dados['plano'] ?? ($emTeste ? 'teste' : 'basico'),
        'vencimento' => $vencimento,
        'whatsapp' => $whatsapp,
        'cidade' => $config['ENDERECO']['cidade'] ?? '',
        'bairro' => $config['ENDERECO']['bairro'] ?? '',
        'estado' => $config['ENDERECO']['estado'] ?? '',
        'cep' => $config['ENDERECO']['cep'] ?? '',
        'lat' => $config['ENDERECO']['lat'] ?? 0,
        'lng' => $config['ENDERECO']['lng'] ?? 0,
        'em_teste' => $emTeste,
        'criado_em' => date('Y-m-d H:i:s'),
    ]);

    return [
        'success' => (bool) ($okConfig && $okMenu && $okAuth && $okRegistro),
        'message' => ($okConfig && $okMenu && $okAuth && $okRegistro) ? '' : 'Não foi possível criar a loja.',
        'slug' => $slug,
        'vencimento' => $vencimento,
        'login' => $login,
    ];
}

function pedidozap_loja_bloqueada($slug)
{
    return pedidozap_assinatura_publica($slug)['status'] === 'bloqueado';
}

function pedidozap_master_file()
{
    return __DIR__ . '/data/master_auth.json';
}

function pedidozap_ler_master()
{
    $envUser = function_exists('pz_db_env_val') ? pz_db_env_val('ACHEIAKI_MASTER_USER') : '';
    $envPass = function_exists('pz_db_env_val') ? pz_db_env_val('ACHEIAKI_MASTER_PASS') : '';
    if ($envUser !== '' && $envPass !== '') {
        return [
            'user' => $envUser,
            'pass_plain_env' => $envPass,
            'pass_hash' => hash('sha256', $envUser . '|' . $envPass),
        ];
    }

    $anterior = Database::getTenant();
    Database::setTenant('default');
    $data = Database::readStrict('master_auth.json');
    Database::setTenant($anterior);
    if (is_array($data) && !empty($data['user']) && !empty($data['pass_hash'])) {
        return $data;
    }

    $path = pedidozap_master_file();
    if (is_file($path)) {
        $fileData = json_decode(@file_get_contents($path) ?: '', true);
        if (is_array($fileData) && !empty($fileData['user']) && !empty($fileData['pass_hash'])) {
            return $fileData;
        }
    }
    return null;
}

function pedidozap_verificar_master($user, $pass)
{
    $data = pedidozap_ler_master();
    if (!is_array($data) || empty($data['user'])) {
        return false;
    }
    if (!hash_equals((string) $data['user'], (string) $user)) {
        return false;
    }
    if (isset($data['pass_plain_env'])) {
        return hash_equals((string) $data['pass_plain_env'], (string) $pass);
    }
    if (empty($data['pass_hash'])) {
        return false;
    }
    return password_verify((string) $pass, $data['pass_hash']);
}

function pedidozap_salvar_master($user, $pass)
{
    $user = trim((string) $user);
    $pass = (string) $pass;
    if ($user === '' || $pass === '') {
        return false;
    }
    $payload = [
        'user' => $user,
        'pass_hash' => password_hash($pass, PASSWORD_DEFAULT),
        'updated_at' => date('c'),
    ];
    $anterior = Database::getTenant();
    Database::setTenant('default');
    $ok = Database::write('master_auth.json', $payload);
    Database::setTenant($anterior);
    return $ok;
}

function pedidozap_perfis_permissoes_padrao($funcao = 'atendente')
{
    $funcao = strtolower(trim((string) $funcao));
    $todas = [
        'visao_geral' => true,
        'pedidos' => true,
        'cardapio' => true,
        'entrega' => true,
        'clientes' => true,
        'estoque' => true,
        'financeiro' => true,
        'relatorios' => true,
        'cozinha' => true,
        'equipe' => true,
        'config' => true,
        'ia_financeiro' => true,
        'ia_estoque' => true,
        'ia_operacao' => true,
    ];
    if ($funcao === 'administrador') {
        return $todas;
    }
    if ($funcao === 'gerente') {
        $p = $todas;
        $p['equipe'] = false;
        return $p;
    }
    if ($funcao === 'cozinha') {
        return array_merge(array_fill_keys(array_keys($todas), false), [
            'cozinha' => true,
            'pedidos' => true,
            'estoque' => true,
            'ia_operacao' => true,
            'ia_estoque' => true,
        ]);
    }
    if ($funcao === 'entregador') {
        return array_merge(array_fill_keys(array_keys($todas), false), [
            'entrega' => true,
            'pedidos' => true,
            'ia_operacao' => true,
        ]);
    }
    // atendente padrão
    return array_merge(array_fill_keys(array_keys($todas), false), [
        'visao_geral' => true,
        'pedidos' => true,
        'cardapio' => true,
        'clientes' => true,
        'entrega' => true,
        'cozinha' => true,
        'ia_operacao' => true,
    ]);
}

function pedidozap_buscar_funcionario($user)
{
    $user = trim((string) $user);
    if ($user === '') {
        return null;
    }
    $lista = Database::readStrict('funcionarios.json');
    if (!is_array($lista)) {
        return null;
    }
    foreach ($lista as $func) {
        if (!is_array($func) || empty($func['login'])) {
            continue;
        }
        if (strcasecmp((string) $func['login'], $user) === 0) {
            return $func;
        }
    }
    return null;
}

function pedidozap_verificar_funcionario($user, $pass)
{
    $func = pedidozap_buscar_funcionario($user);
    if (!$func || empty($func['ativo']) || empty($func['pass_hash'])) {
        return null;
    }
    if (!password_verify((string) $pass, (string) $func['pass_hash'])) {
        return null;
    }
    return $func;
}

function pedidozap_autorizado($user, $pass)
{
    $pass = (string) $pass;
    if (strpos($pass, 'pzt.') === 0) {
        return pedidozap_validar_token($user, $pass);
    }
    if (pedidozap_verificar_master($user, $pass)) {
        return 'master';
    }
    if (pedidozap_verificar_credenciais($user, $pass)) {
        return 'lojista';
    }
    if (pedidozap_verificar_funcionario($user, $pass)) {
        return 'funcionario';
    }
    return false;
}

function pedidozap_sessao_info($user, $pass)
{
    $pass = (string) $pass;
    if (strpos($pass, 'pzt.') === 0) {
        $partes = explode('.', $pass);
        if (count($partes) === 3 && $partes[0] === 'pzt' && pedidozap_validar_token($user, $pass)) {
            $payload = json_decode(pedidozap_b64url_decode($partes[1]) ?: '', true);
            if (is_array($payload)) {
                $role = $payload['r'] ?? 'lojista';
                if ($role === 'funcionario') {
                    $func = pedidozap_buscar_funcionario($payload['u'] ?? '');
                    $perms = is_array($func['permissoes'] ?? null)
                        ? $func['permissoes']
                        : pedidozap_perfis_permissoes_padrao($func['funcao'] ?? 'atendente');
                    return [
                        'role' => 'funcionario',
                        'user' => (string) ($payload['u'] ?? $user),
                        'nome' => (string) ($func['nome'] ?? ($payload['u'] ?? $user)),
                        'funcao' => (string) ($func['funcao'] ?? 'atendente'),
                        'permissoes' => $perms,
                    ];
                }
                return [
                    'role' => $role,
                    'user' => (string) ($payload['u'] ?? $user),
                    'nome' => (string) ($payload['u'] ?? $user),
                    'funcao' => $role === 'master' ? 'master' : 'proprietario',
                    'permissoes' => pedidozap_perfis_permissoes_padrao('administrador'),
                ];
            }
        }
        return null;
    }

    $role = pedidozap_autorizado($user, $pass);
    if (!$role) {
        return null;
    }
    if ($role === 'funcionario') {
        $func = pedidozap_buscar_funcionario($user);
        $perms = is_array($func['permissoes'] ?? null)
            ? $func['permissoes']
            : pedidozap_perfis_permissoes_padrao($func['funcao'] ?? 'atendente');
        return [
            'role' => 'funcionario',
            'user' => (string) ($func['login'] ?? $user),
            'nome' => (string) ($func['nome'] ?? $user),
            'funcao' => (string) ($func['funcao'] ?? 'atendente'),
            'permissoes' => $perms,
        ];
    }
    return [
        'role' => $role,
        'user' => (string) $user,
        'nome' => (string) $user,
        'funcao' => $role === 'master' ? 'master' : 'proprietario',
        'permissoes' => pedidozap_perfis_permissoes_padrao('administrador'),
    ];
}

function pedidozap_tem_permissao(?array $sessao, $permissao)
{
    if (!$sessao) {
        return false;
    }
    if (($sessao['role'] ?? '') === 'master' || ($sessao['role'] ?? '') === 'lojista') {
        return true;
    }
    $perms = $sessao['permissoes'] ?? [];
    return !empty($perms[$permissao]);
}

function pedidozap_app_secret()
{
    $envSecret = function_exists('pz_db_env_val') ? pz_db_env_val('ACHEIAKI_APP_SECRET') : '';
    if (strlen($envSecret) >= 32) {
        return $envSecret;
    }
    $anterior = Database::getTenant();
    Database::setTenant('default');
    $data = Database::readStrict('app_secret.json');
    if (is_array($data) && !empty($data['secret']) && strlen($data['secret']) >= 32) {
        Database::setTenant($anterior);
        return $data['secret'];
    }
    $path = __DIR__ . '/data/app_secret.json';
    if (is_file($path)) {
        $fileData = json_decode(@file_get_contents($path) ?: '', true);
        if (is_array($fileData) && !empty($fileData['secret']) && strlen($fileData['secret']) >= 32) {
            Database::write('app_secret.json', $fileData);
            Database::setTenant($anterior);
            return $fileData['secret'];
        }
    }
    $secret = bin2hex(random_bytes(32));
    Database::write('app_secret.json', ['secret' => $secret, 'criado_em' => date('c')]);
    Database::setTenant($anterior);
    return $secret;
}

function pedidozap_b64url($bin)
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function pedidozap_b64url_decode($txt)
{
    $txt = strtr((string) $txt, '-_', '+/');
    $pad = strlen($txt) % 4;
    if ($pad) {
        $txt .= str_repeat('=', 4 - $pad);
    }
    return base64_decode($txt, true);
}

/**
 * Impressão digital do hash da senha: trocar a senha invalida todos os tokens emitidos antes.
 */
function pedidozap_digital_credencial($role, $user = '')
{
    if ($role === 'master') {
        $data = pedidozap_ler_master();
        $hash = is_array($data) ? (string) ($data['pass_hash'] ?? '') : '';
    } elseif ($role === 'funcionario') {
        $func = pedidozap_buscar_funcionario($user);
        $hash = is_array($func) && !empty($func['ativo']) ? (string) ($func['pass_hash'] ?? '') : 'inativo';
    } else {
        $auth = pedidozap_ler_auth();
        $hash = is_array($auth) ? (string) ($auth['pass_hash'] ?? '') : '';
    }
    return substr(hash('sha256', $hash), 0, 16);
}

function pedidozap_emitir_token($user, $role, $ttlSegundos = 259200)
{
    $roleNorm = in_array($role, ['master', 'funcionario', 'lojista'], true) ? $role : 'lojista';
    $payload = [
        'u' => (string) $user,
        'r' => $roleNorm,
        't' => Database::getTenant(),
        'e' => time() + (int) $ttlSegundos,
        'd' => pedidozap_digital_credencial($roleNorm, $user),
    ];
    $corpo = pedidozap_b64url(json_encode($payload));
    $assinatura = pedidozap_b64url(hash_hmac('sha256', $corpo, pedidozap_app_secret(), true));
    return 'pzt.' . $corpo . '.' . $assinatura;
}

function pedidozap_validar_token($user, $token)
{
    $partes = explode('.', (string) $token);
    if (count($partes) !== 3 || $partes[0] !== 'pzt') {
        return false;
    }
    $esperada = pedidozap_b64url(hash_hmac('sha256', $partes[1], pedidozap_app_secret(), true));
    if (!hash_equals($esperada, $partes[2])) {
        return false;
    }
    $payload = json_decode(pedidozap_b64url_decode($partes[1]) ?: '', true);
    if (!is_array($payload) || (int) ($payload['e'] ?? 0) < time()) {
        return false;
    }
    if ((string) $user !== '' && strcasecmp((string) $payload['u'], (string) $user) !== 0) {
        return false;
    }
    $role = $payload['r'] ?? '';
    if ($role === 'master') {
        return hash_equals(pedidozap_digital_credencial('master'), (string) ($payload['d'] ?? '')) ? 'master' : false;
    }
    if ($role === 'funcionario') {
        if (($payload['t'] ?? '') !== Database::getTenant()) {
            return false;
        }
        return hash_equals(pedidozap_digital_credencial('funcionario', (string) ($payload['u'] ?? '')), (string) ($payload['d'] ?? '')) ? 'funcionario' : false;
    }
    if ($role !== 'lojista' || ($payload['t'] ?? '') !== Database::getTenant()) {
        return false;
    }
    return hash_equals(pedidozap_digital_credencial('lojista'), (string) ($payload['d'] ?? '')) ? 'lojista' : false;
}

/**
 * Limite genérico por IP + escopo (protege APIs públicas contra abuso).
 * Retorna false quando o limite foi atingido.
 */
function pedidozap_limite_requisicao($escopo, $maximo = 80, $janela = 60, $registrar = true)
{
    $escopo = preg_replace('/[^a-z0-9._-]/i', '_', (string) $escopo);
    if ($escopo === '') {
        $escopo = 'api';
    }
    $dir = __DIR__ . '/data/ratelimit';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'desconhecido';
    $tenant = class_exists('Database') ? Database::getTenant() : 'default';
    $path = $dir . '/' . sha1($ip . '|' . $escopo . '|' . $tenant) . '.json';
    $agora = time();

    $data = is_file($path) ? json_decode(file_get_contents($path) ?: '', true) : null;
    if (!is_array($data) || ($data['inicio'] ?? 0) < $agora - $janela) {
        $data = ['inicio' => $agora, 'hits' => 0];
    }
    if ($registrar) {
        $data['hits']++;
        file_put_contents($path, json_encode($data), LOCK_EX);
    }
    return (int) ($data['hits'] ?? 0) <= (int) $maximo;
}

function pedidozap_limite_login($registrarFalha = false, $limpar = false)
{
    $dir = __DIR__ . '/data/ratelimit';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'desconhecido';
    $path = $dir . '/' . sha1($ip . '|' . Database::getTenant()) . '.json';
    $janela = 900;
    $maximo = 8;

    if ($limpar) {
        @unlink($path);
        return true;
    }

    $data = is_file($path) ? json_decode(file_get_contents($path) ?: '', true) : null;
    if (!is_array($data) || ($data['inicio'] ?? 0) < time() - $janela) {
        $data = ['inicio' => time(), 'falhas' => 0];
    }
    if ($registrarFalha) {
        $data['falhas']++;
        file_put_contents($path, json_encode($data), LOCK_EX);
    }
    return $data['falhas'] < $maximo;
}

function pedidozap_listar_lojas()
{
    return pedidozap_resumo_lojas();
}

function pedidozap_resumo_lojas()
{
    $registro = pedidozap_ler_registro();
    $lista = [];
    foreach ($registro as $slug => $meta) {
        if (!is_array($meta)) {
            continue;
        }
        $lista[] = [
            'slug' => $slug,
            'nome_empresa' => $meta['nome_empresa'] ?? $meta['nome_loja'] ?? $slug,
            'razao_social' => $meta['razao_social'] ?? '',
            'nicho' => $meta['nicho'] ?? '',
            'login' => $meta['login'] ?? '',
            'status' => $meta['status'] ?? 'ativo',
            'status_efetivo' => pedidozap_status_efetivo($meta)['status'],
            'motivo' => pedidozap_status_efetivo($meta)['motivo'],
            'em_teste' => !empty($meta['em_teste']),
            'plano' => $meta['plano'] ?? 'basico',
            'vencimento' => $meta['vencimento'] ?? '',
            'criado_em' => $meta['criado_em'] ?? '',
            'whatsapp' => $meta['whatsapp'] ?? '',
            'assinatura_status' => $meta['assinatura_status'] ?? '',
            'ultimo_pagamento' => $meta['ultimo_pagamento'] ?? '',
            'destaque' => !empty($meta['destaque']),
            'cidade' => $meta['cidade'] ?? '',
            'bairro' => $meta['bairro'] ?? '',
        ];
    }
    usort($lista, function ($a, $b) {
        return strcmp($b['criado_em'], $a['criado_em']);
    });
    return $lista;
}
