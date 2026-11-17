<?php
/**
 * Agente IA Integrado Food Gestão (Multi-Tenant + RBAC + Ações Reais + Atendente Vitrine)
 * Prioridade máxima no sistema: opera 100% local sem chave externa obrigatória,
 * com suporte opcional híbrido ao Gemini API configurado no SuperAdmin.
 */
require_once 'tenant_init.php';
require_once 'auth_lib.php';
require_once 'saas_lib.php';
require_once 'integracoes_lib.php';
require_once 'store_schema.php';
require_once 'gestao_lib.php';
require_once 'territorio_lib.php';
require_once 'algoritmo_lib.php';

header('Content-Type: application/json; charset=utf-8');

function pz_agente_req()
{
    $raw = file_get_contents('php://input');
    $json = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    return is_array($json) ? $json : [];
}

function pz_norm($txt)
{
    $txt = mb_strtolower(trim((string) $txt), 'UTF-8');
    $map = [
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
        'é' => 'e', 'ê' => 'e', 'è' => 'e', 'ë' => 'e',
        'í' => 'i', 'î' => 'i', 'ì' => 'i', 'ï' => 'i',
        'ó' => 'o', 'õ' => 'o', 'ô' => 'o', 'ò' => 'o', 'ö' => 'o',
        'ú' => 'u', 'û' => 'u', 'ù' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n',
    ];
    return strtr($txt, $map);
}

function pz_brl($v)
{
    return 'R$ ' . number_format((float) $v, 2, ',', '.');
}

function pz_extrair_valor_monetario($txt)
{
    if (preg_match('/(?:r\$\s*)?(\d+(?:[.,]\d{1,2})?)/i', (string) $txt, $m)) {
        return round((float) str_replace(',', '.', $m[1]), 2);
    }
    return null;
}

function pz_chamar_gemini_hibrido($pergunta, array $contextoSeguro, $nomeAgente, $tom = 'profissional')
{
    $int = pedidozap_integracoes_config();
    $provider = $int['ia_provider'] ?? 'local';
    $apiKey = trim((string) ($int['ia_api_key'] ?? ''));
    if ($provider !== 'gemini' || $apiKey === '') {
        return null;
    }

    $instrucao = "Você é {$nomeAgente}, o Agente de Gestão e Operações integrado ao sistema da lanchonete/restaurante. "
        . "Responda em português do Brasil, de forma objetiva e prática (tom: {$tom}). "
        . "REGRAS CRÍTICAS: 1) Use EXCLUSIVAMENTE os números e fatos do JSON de contexto real abaixo; "
        . "2) NUNCA invente vendas, saldos, produtos ou clientes que não estejam no JSON; "
        . "3) Sempre cite ao final a origem dos dados consultados (ex.: pedidos.json, caixa.json, estoque.json). "
        . "Contexto real da loja: " . json_encode($contextoSeguro, JSON_UNESCAPED_UNICODE);

    $payload = [
        'contents' => [
            [
                'role' => 'user',
                'parts' => [
                    ['text' => $instrucao . "\n\nPergunta do usuário: " . $pergunta],
                ],
            ],
        ],
        'generationConfig' => [
            'temperature' => 0.25,
            'maxOutputTokens' => 550,
        ],
    ];

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . rawurlencode($apiKey);
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $rawResp = false;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);
        $rawResp = curl_exec($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n",
                'content' => $body,
                'timeout' => 12,
            ],
        ]);
        $rawResp = @file_get_contents($url, false, $ctx);
    }

    if (!is_string($rawResp) || $rawResp === '') {
        return null;
    }
    $parsed = json_decode($rawResp, true);
    $texto = $parsed['candidates'][0]['content']['parts'][0]['text'] ?? '';
    return trim((string) $texto) !== '' ? trim((string) $texto) : null;
}

$req = pz_agente_req();
pedidozap_bootstrap_tenant($req);

$action = trim((string) ($_GET['action'] ?? $req['action'] ?? 'perguntar'));
$config = pedidozap_normalizar_config(Database::readStrict('config.json'), Database::getTenant());
$agenteCfg = is_array($config['AGENTE_IA'] ?? null) ? $config['AGENTE_IA'] : [];
$nomeAgente = trim((string) ($agenteCfg['nome'] ?? 'Copiloto Food IA')) ?: 'Copiloto Food IA';
$tomAgente = trim((string) ($agenteCfg['tom'] ?? 'profissional'));

// =====================================================================
// MODO A — ATENDENTE VIRTUAL PÚBLICO NA VITRINE (index.html)
// =====================================================================
if ($action === 'vitrine_responder') {
    if (isset($agenteCfg['vitrine_ativo']) && !$agenteCfg['vitrine_ativo']) {
        echo json_encode([
            'success' => false,
            'ativo' => false,
            'resposta' => 'Atendimento automático temporariamente desativado pela loja.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $pergunta = trim((string) ($req['pergunta'] ?? ''));
    $n = pz_norm($pergunta);
    $consentIA = !empty($req['consentimento_ia']);
    $vid = $consentIA ? pz_alg_limpar_vid($req['visitor_id'] ?? '') : '';
    $cardapio = Database::readStrict('cardapio.json');
    $empresa = $config['EMPRESA'] ?? [];
    $nomeLoja = trim((string) ($config['NOME_LOJA'] ?? $empresa['nome'] ?? 'nossa loja'));
    if ($nomeLoja === '') $nomeLoja = 'nossa loja';

    $hor = $config['HORARIOS'] ?? [];
    $horario = trim((string) ($hor['TEXTO'] ?? ''));
    if ($horario === '' && !empty($hor['SEMANA']['inicio'])) {
        $horario = 'Seg a Sex ' . $hor['SEMANA']['inicio'] . ' às ' . ($hor['SEMANA']['fim'] ?? '23:30');
        if (!empty($hor['FIM_DE_SEMANA']['inicio'])) {
            $horario .= ' · Sáb/Dom ' . $hor['FIM_DE_SEMANA']['inicio'] . ' às ' . ($hor['FIM_DE_SEMANA']['fim'] ?? '00:00');
        }
    }
    if ($horario === '') {
        $horario = $empresa['horario_funcionamento'] ?? ($empresa['horario'] ?? 'Consulte no topo do cardápio');
    }

    $logistica = $config['LOGISTICA'] ?? [];
    $freteCfg = $config['FRETE'] ?? [];
    $pagamentos = $config['PAGAMENTOS'] ?? ($config['PAGAMENTO'] ?? []);

    // Lê perfil local do visitante (apenas com consentimento LGPD) para personalizar recomendações na loja
    $perfilTags = [];
    if ($vid !== '') {
        $perfis = Database::readGlobalStrict('algoritmo_perfis.json');
        if (is_array($perfis) && !empty($perfis[$vid]['tags']) && is_array($perfis[$vid]['tags'])) {
            $perfilTags = $perfis[$vid]['tags'];
            arsort($perfilTags);
            $perfilTags = array_slice(array_keys($perfilTags), 0, 8);
        }
    }

    $todosProdutos = [];
    foreach ($cardapio as $cat => $lista) {
        if (!is_array($lista)) {
            continue;
        }
        foreach ($lista as $prod) {
            if (!is_array($prod) || empty($prod['nome']) || (!empty($prod['esgotado']) || (isset($prod['ativo']) && !$prod['ativo']))) {
                continue;
            }
            $prod['_categoria'] = $cat;
            $prod['_preco_num'] = (float) ($prod['preco'] ?? ($prod['tamanhos'][0]['preco'] ?? 0));
            $todosProdutos[] = $prod;
        }
    }

    // Ordena um subconjunto personalizado usando as tags do perfil do visitante
    $ranquearProdutos = function (array $lista, array $boostTags = [], $ordenarMenorPreco = false) {
        $copia = $lista;
        usort($copia, function ($a, $b) use ($boostTags, $ordenarMenorPreco) {
            if ($ordenarMenorPreco) {
                $pa = $a['_preco_num'] > 0 ? $a['_preco_num'] : 999999;
                $pb = $b['_preco_num'] > 0 ? $b['_preco_num'] : 999999;
                if (abs($pa - $pb) > 0.01) return $pa <=> $pb;
            }
            $sa = (float) ($a['_score'] ?? 0);
            $sb = (float) ($b['_score'] ?? 0);
            if ($boostTags) {
                $ha = pz_norm(($a['nome'] ?? '') . ' ' . ($a['descricao'] ?? '') . ' ' . ($a['_categoria'] ?? ''));
                $hb = pz_norm(($b['nome'] ?? '') . ' ' . ($b['descricao'] ?? '') . ' ' . ($b['_categoria'] ?? ''));
                foreach ($boostTags as $tk) {
                    if (strpos($ha, $tk) !== false) $sa += 0.6;
                    if (strpos($hb, $tk) !== false) $sb += 0.6;
                }
            }
            if (!empty($a['promocao'])) $sa += 0.4;
            if (!empty($b['promocao'])) $sb += 0.4;
            return $sb <=> $sa;
        });
        return $copia;
    };

    if ($pergunta === '' || preg_match('/^(oi|ola|bom dia|boa tarde|boa noite|menu|ajuda|inicio)\b/', $n)) {
        $destaques = array_slice($ranquearProdutos($todosProdutos, $perfilTags, false), 0, 4);
        $listaTxt = implode(', ', array_map(function ($p) {
            $preco = $p['_preco_num'];
            return $p['nome'] . ($preco > 0 ? ' (' . pz_brl($preco) . ')' : '');
        }, $destaques));
        $dicaPerfil = $perfilTags ? "\n✨ Já selecionei opções alinhadas ao que você mais curte!" : '';
        echo json_encode([
            'success' => true,
            'resposta' => "Olá! Sou a **IA AcheIAki** da **{$nomeLoja}**. Posso indicar os melhores pratos para você, consultar preços, entrega, pagamento ou horário!"
                . $dicaPerfil
                . ($listaTxt ? "\n\nSugestões para você agora: {$listaTxt}." : ''),
            'sugestoes' => ['O que você recomenda hoje?', 'Opções mais baratas', 'Qual o horário de funcionamento?', 'Como funciona a entrega?'],
            'produtos' => array_map(function ($p) {
                return [
                    'id' => $p['id'] ?? '',
                    'nome' => $p['nome'] ?? '',
                    'preco' => (float) ($p['_preco_num'] ?? 0),
                    'categoria' => $p['_categoria'] ?? '',
                ];
            }, $destaques),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (strpos($n, 'horario') !== false || strpos($n, 'aberto') !== false || strpos($n, 'fecha') !== false || strpos($n, 'abre') !== false) {
        echo json_encode([
            'success' => true,
            'resposta' => "O horário da **{$nomeLoja}** é: **{$horario}**.",
            'sugestoes' => ['O que você recomenda hoje?', 'Como funciona a entrega?', 'Quais formas de pagamento?'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (strpos($n, 'entrega') !== false || strpos($n, 'frete') !== false || strpos($n, 'taxa') !== false || strpos($n, 'retirar') !== false || strpos($n, 'retirada') !== false) {
        $modoFrete = $freteCfg['modo'] ?? '';
        $freteBase = (float) ($freteCfg['taxa_fixa'] ?? $freteCfg['taxa_base'] ?? $logistica['taxa_entrega_fixa'] ?? $logistica['frete_fixo'] ?? 0);
        $tempo = trim((string) ($config['TEMPO_ESPERA'] ?? $logistica['tempo_estimado'] ?? $empresa['tempo_entrega'] ?? '30 a 50 min'));
        $retirada = (!isset($logistica['aceita_retirada']) || !empty($logistica['aceita_retirada']) || !empty($logistica['permite_retirada']))
            ? 'Também aceitamos retirada no balcão.' : '';
        $freteGratisAcima = (float) ($freteCfg['frete_gratis_acima'] ?? 0);
        if ($modoFrete === 'gratis') {
            $txtFrete = ' Esta loja está com **entrega grátis**!';
        } elseif ($freteBase > 0) {
            $txtFrete = ' Taxa a partir de **' . pz_brl($freteBase) . '** (confirmada pelo endereço no checkout).';
        } else {
            $txtFrete = ' O valor da entrega é calculado automaticamente pelo seu endereço ao finalizar.';
        }
        if ($freteGratisAcima > 0 && $modoFrete !== 'gratis') {
            $txtFrete .= ' Frete grátis em pedidos acima de **' . pz_brl($freteGratisAcima) . '**.';
        }
        echo json_encode([
            'success' => true,
            'resposta' => "Fazemos entregas com tempo estimado de **{$tempo}**." . $txtFrete . ($retirada ? ' ' . $retirada : ''),
            'sugestoes' => ['Quais formas de pagamento?', 'O que você recomenda hoje?'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (strpos($n, 'pagament') !== false || strpos($n, 'pix') !== false || strpos($n, 'cartao') !== false || strpos($n, 'dinheiro') !== false || strpos($n, 'vale') !== false) {
        $formas = [];
        $pix = isset($pagamentos['pix']) ? !empty($pagamentos['pix']) : (!isset($pagamentos['aceita_pix']) || !empty($pagamentos['aceita_pix']));
        $cred = isset($pagamentos['credito']) ? !empty($pagamentos['credito']) : (!isset($pagamentos['aceita_cartao']) || !empty($pagamentos['aceita_cartao']));
        $deb = isset($pagamentos['debito']) ? !empty($pagamentos['debito']) : (!isset($pagamentos['aceita_cartao']) || !empty($pagamentos['aceita_cartao']));
        $din = isset($pagamentos['dinheiro']) ? !empty($pagamentos['dinheiro']) : (!isset($pagamentos['aceita_dinheiro']) || !empty($pagamentos['aceita_dinheiro']));
        if ($pix) $formas[] = 'PIX';
        if ($cred && $deb) $formas[] = 'Cartão (crédito/débito)';
        elseif ($cred) $formas[] = 'Cartão de Crédito';
        elseif ($deb) $formas[] = 'Cartão de Débito';
        if ($din) $formas[] = 'Dinheiro';
        if (!empty($pagamentos['aceita_vale']) || !empty($pagamentos['vale'])) $formas[] = 'Vale-refeição/alimentação';
        $listaFormas = $formas ? implode(', ', $formas) : 'PIX, Cartão e Dinheiro';
        echo json_encode([
            'success' => true,
            'resposta' => "Aceitamos **{$listaFormas}**. No checkout você escolhe como prefere pagar (e se precisar de troco).",
            'sugestoes' => ['O que você recomenda hoje?', 'Como funciona a entrega?'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (strpos($n, 'cupom') !== false || strpos($n, 'desconto') !== false || strpos($n, 'promoc') !== false) {
        $mkt = pz_ler_marketing();
        $ativos = array_values(array_filter($mkt['cupons'], function ($c) {
            return !empty($c['ativo']) && (empty($c['validade']) || $c['validade'] >= date('Y-m-d'));
        }));
        if ($ativos) {
            $c = $ativos[0];
            $descTxt = ($c['tipo'] ?? '') === 'percentual' ? ($c['valor'] . '% OFF') : (($c['tipo'] ?? '') === 'frete_gratis' ? 'Frete Grátis' : pz_brl($c['valor'] ?? 0) . ' OFF');
            $respCupom = "Temos cupom ativo! Experimente usar **{$c['codigo']}** ({$descTxt})" . (!empty($c['minimo_pedido']) ? ' para pedidos a partir de ' . pz_brl($c['minimo_pedido']) : '') . ' no momento de finalizar seu pedido.';
        } else {
            $respCupom = "Se você já tem um código de cupom, pode aplicá-lo direto na tela de finalização do carrinho!";
        }
        echo json_encode([
            'success' => true,
            'resposta' => $respCupom,
            'sugestoes' => ['Opções mais baratas', 'Quais formas de pagamento?'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Busca semântica com expansão de sinônimos culinários e perfil de preferências do usuário
    $intencao = pz_alg_expandir_intencao($pergunta);
    $tokensBrutos = array_values(array_filter(preg_split('/\s+/', preg_replace('/[^a-z0-9\s]/', ' ', $n)), function ($t) {
        return strlen($t) >= 3 && !in_array($t, ['tem', 'que', 'qual', 'quanto', 'custa', 'valor', 'preco', 'voce', 'voces', 'para', 'hoje', 'quero', 'gostaria', 'ver', 'cardapio', 'menu', 'mais', 'recomenda', 'indica'], true);
    }));
    $tokens = array_values(array_unique(array_merge($tokensBrutos, $intencao['tags'] ?? [])));
    $querEconomia = !empty($intencao['economia']);
    $querRecomendacao = strpos($n, 'recomenda') !== false || strpos($n, 'indica') !== false || strpos($n, 'melhor') !== false || strpos($n, 'gosto') !== false || strpos($n, 'fome') !== false;

    $encontrados = [];
    foreach ($todosProdutos as $prod) {
        $hay = pz_norm(($prod['nome'] ?? '') . ' ' . ($prod['descricao'] ?? '') . ' ' . ($prod['_categoria'] ?? ''));
        $score = 0.0;
        foreach ($tokensBrutos as $tk) {
            if (strpos($hay, $tk) !== false) $score += 2.0;
        }
        foreach ($tokens as $tk) {
            if (strpos($hay, $tk) !== false) $score += 0.8;
        }
        if ($score > 0) {
            $prod['_score'] = $score;
            $encontrados[] = $prod;
        }
    }

    if ($encontrados) {
        $exibir = array_slice($ranquearProdutos($encontrados, $perfilTags, $querEconomia), 0, 5);
        $cabecalho = $querEconomia
            ? 'Separei estas opções com ótimo custo-benefício para você:'
            : 'Encontrei estas opções no cardápio para você:';
    } else {
        $exibir = array_slice($ranquearProdutos($todosProdutos, $perfilTags, $querEconomia), 0, 5);
        $cabecalho = ($querRecomendacao && $perfilTags)
            ? 'Com base no que você mais gosta, estas são as melhores pedidas aqui:'
            : ($querEconomia ? 'Confira as opções mais em conta disponíveis agora:' : 'Aqui estão as melhores opções disponíveis agora no cardápio:');
    }

    if ($exibir) {
        $linhas = array_map(function ($p) {
            $preco = (float) ($p['_preco_num'] ?? 0);
            $desc = !empty($p['descricao']) ? ' — ' . mb_substr((string) $p['descricao'], 0, 70, 'UTF-8') : '';
            return '• **' . $p['nome'] . '**' . ($preco > 0 ? ' (' . pz_brl($preco) . ')' : '') . $desc;
        }, $exibir);
        echo json_encode([
            'success' => true,
            'resposta' => $cabecalho . "\n" . implode("\n", $linhas),
            'produtos' => array_map(function ($p) {
                return [
                    'id' => $p['id'] ?? '',
                    'nome' => $p['nome'] ?? '',
                    'preco' => (float) ($p['_preco_num'] ?? 0),
                    'categoria' => $p['_categoria'] ?? '',
                ];
            }, $exibir),
            'sugestoes' => ['Opções mais baratas', 'Qual o horário de funcionamento?', 'Como funciona a entrega?', 'Tem cupom de desconto?'],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode([
        'success' => true,
        'resposta' => "Você pode navegar pelas categorias acima e adicionar seus itens ao carrinho. Se preferir, explore outras lojas perto de você no AcheIAki!",
        'sugestoes' => ['Qual o horário de funcionamento?', 'Quais formas de pagamento?'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// =====================================================================
// MODO B — AGENTE AUTENTICADO DO PAINEL ADMIN E OPERAÇÕES (RBAC + AÇÕES)
// =====================================================================
$user = $_GET['user'] ?? $req['auth']['user'] ?? $req['user'] ?? '';
$pass = $_GET['pass'] ?? $req['auth']['pass'] ?? $req['pass'] ?? '';

if (!pedidozap_autorizado($user, $pass)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sessão expirada ou não autorizada para consultar o Agente IA.'], JSON_UNESCAPED_UNICODE);
    exit;
}

pedidozap_recusar_loja_bloqueada($user, $pass);
$sessao = pedidozap_sessao_info($user, $pass);
if (!$sessao) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sessão inválida.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$ator = trim((string) (($sessao['nome'] ?? '') ?: ($sessao['user'] ?? $user)));
$permFin = pedidozap_tem_permissao($sessao, 'ia_financeiro') || pedidozap_tem_permissao($sessao, 'financeiro');
$permEst = pedidozap_tem_permissao($sessao, 'ia_estoque') || pedidozap_tem_permissao($sessao, 'estoque');
$permOp = pedidozap_tem_permissao($sessao, 'ia_operacao') || pedidozap_tem_permissao($sessao, 'pedidos');
$permCli = pedidozap_tem_permissao($sessao, 'clientes') || $permOp;

$resumo = pz_resumo_executivo_loja();
$intCfg = pedidozap_integracoes_publicas();
$iaPessoal = pz_ler_ia_pessoal();
$iaPessoalPub = pz_ler_ia_pessoal_publica();
$modoIA = !empty($iaPessoal['ativa'])
    ? 'ia_pessoal'
    : ((($intCfg['ia_provider'] ?? 'local') === 'gemini' && !empty($intCfg['gemini_configurado'])) ? 'gemini_hibrido' : 'local');

// Filtra resumo e alertas conforme RBAC do funcionário
$alertasFiltrados = array_values(array_filter($resumo['alertas_ia'], function ($a) use ($permFin, $permEst) {
    if (($a['modulo'] === 'caixa' || $a['modulo'] === 'financeiro') && !$permFin) {
        return false;
    }
    if (($a['modulo'] === 'estoque' || $a['modulo'] === 'ficha_tecnica') && !$permEst && !$permFin) {
        return false;
    }
    return true;
}));

if ($action === 'diagnostico') {
    $sugestoes = [];
    if ($permFin) {
        $sugestoes[] = 'Quanto vendi e qual meu lucro hoje?';
        $sugestoes[] = 'Qual meu produto mais lucrativo?';
        $sugestoes[] = 'Como está o caixa e contas da semana?';
    }
    if ($permEst) {
        $sugestoes[] = 'O que preciso comprar de estoque?';
    }
    if ($permOp) {
        $sugestoes[] = 'Quais pedidos estão em aberto agora?';
        $sugestoes[] = 'Resumo dos entregadores';
    }
    if ($permCli) {
        $sugestoes[] = 'Carrinhos abandonados e clientes VIP';
    }

    echo json_encode([
        'success' => true,
        'nome_agente' => $nomeAgente,
        'modo_ia' => $modoIA,
        'ia_pessoal' => [
            'ativa' => !empty($iaPessoalPub['ativa']),
            'voz_ativa' => !empty($iaPessoalPub['voz_ativa']),
            'ler_resposta_voz' => !empty($iaPessoalPub['ler_resposta_voz']),
            'modelo' => $iaPessoalPub['modelo'] ?? '',
        ],
        'sessao' => $sessao,
        'permissoes_ia' => [
            'financeiro' => $permFin,
            'estoque' => $permEst,
            'operacao' => $permOp,
        ],
        'alertas' => $alertasFiltrados,
        'sugestoes' => $sugestoes,
        'metricas_rapidas' => [
            'pedidos_hoje' => $permOp ? $resumo['hoje']['pedidos'] : null,
            'pedidos_abertos' => $permOp ? $resumo['pedidos_abertos'] : null,
            'faturamento_hoje' => $permFin ? $resumo['hoje']['faturamento_bruto'] : null,
            'lucro_mes' => $permFin ? $resumo['mes']['resultado_operacional'] : null,
            'estoque_baixo' => $permEst ? count($resumo['estoque_baixo']) : null,
            'caixa_aberto' => $permFin ? $resumo['caixa']['aberto'] : null,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// =====================================================================
// PROCESSAMENTO DE PERGUNTA / COMANDO DO GESTOR OU OPERADOR
// =====================================================================
$pergunta = trim((string) ($req['pergunta'] ?? ''));
if ($pergunta === '') {
    echo json_encode(['success' => false, 'message' => 'Envie uma pergunta ou comando para o agente.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$n = pz_norm($pergunta);
$origem = [];
$cards = [];
$acoesUI = [];
$recarregar = [];
$acaoExecutada = null;
$acaoAbrirAba = null;
$acoesLocais = [];
$sugestoes = [];
$resposta = '';

// ---------------------------------------------------------------------
// 0. PRIORIDADE DA IA PESSOAL DO CLIENTE (SE KEY + URL ESTIVEREM CADASTRADAS)
// ---------------------------------------------------------------------
if (!empty($iaPessoal['ativa'])) {
    $pedidosLista = Database::readStrict('pedidos.json');
    $pedidosAbertos = [];
    if (is_array($pedidosLista)) {
        foreach ($pedidosLista as $p) {
            if (is_array($p) && in_array($p['status'] ?? 'novo', ['novo', 'preparo', 'em-rota'], true) && empty($p['cancelado'])) {
                $pedidosAbertos[] = [
                    'id' => $p['id'] ?? '',
                    'cliente' => $p['cliente'] ?? '',
                    'status' => $p['status'] ?? 'novo',
                    'total' => $permFin ? ($p['total'] ?? 0) : null,
                ];
            }
        }
    }

    $cardapioAtual = Database::readStrict('cardapio.json');
    $catsResumo = [];
    foreach ($cardapioAtual as $catNome => $catLista) {
        if (is_array($catLista)) {
            $catsResumo[$catNome] = count($catLista);
        }
    }

    $ctxCliente = [
        'loja' => $config['EMPRESA']['nome'] ?? '',
        'usuario_atual' => $ator,
        'permissoes' => [
            'financeiro' => $permFin,
            'estoque' => $permEst,
            'operacao' => $permOp,
            'clientes' => $permCli,
        ],
        'alertas' => $alertasFiltrados,
        'pedidos_abertos' => $permOp ? array_slice($pedidosAbertos, 0, 12) : 'restrito',
        'hoje' => $permFin ? $resumo['hoje'] : ['pedidos' => $permOp ? $resumo['hoje']['pedidos'] : 'restrito'],
        'mes' => $permFin ? [
            'pedidos' => $resumo['mes']['pedidos'],
            'faturamento_bruto' => $resumo['mes']['faturamento_bruto'],
            'cmv_total' => $resumo['mes']['cmv_total'],
            'despesas_totais' => $resumo['mes']['despesas_totais'],
            'resultado_operacional' => $resumo['mes']['resultado_operacional'],
            'top_produtos' => array_slice($resumo['mes']['por_produto'], 0, 6),
        ] : 'restrito',
        'caixa' => $permFin ? ['aberto' => $resumo['caixa']['aberto'], 'totais' => $resumo['caixa']['totais']] : 'restrito',
        'estoque_itens' => $permEst ? array_map(function ($it) {
            return [
                'nome' => $it['nome'] ?? '',
                'saldo' => $it['quantidade_atual'] ?? 0,
                'unidade' => $it['unidade'] ?? 'un',
                'minimo' => $it['estoque_minimo'] ?? 0,
            ];
        }, array_slice(pz_ler_estoque()['itens'], 0, 25)) : 'restrito',
        'entregadores' => $permOp ? array_map(function ($e) {
            return $e['nome'] ?? '';
        }, pz_ler_entregadores()) : [],
        'categorias_cardapio' => $catsResumo,
        'territorio_resumo' => function_exists('pz_territorio_analisar_loja') ? pz_territorio_analisar_loja(Database::getTenant()) : null,
    ];

    $promptCliente = trim((string) ($iaPessoal['prompt_sistema'] ?? ''));
    $sysCliente = "Você é {$nomeAgente}, a IA pessoal e operacional integrada ao sistema da empresa ({$ctxCliente['loja']}).\n"
        . ($promptCliente !== '' ? "INSTRUÇÕES PERSONALIZADAS DO PROPRIETÁRIO:\n{$promptCliente}\n\n" : '')
        . "REGRAS:\n"
        . "- Responda sempre em português do Brasil claro, direto e prático.\n"
        . "- Use SOMENTE os dados reais do JSON abaixo (incluindo o diagnóstico territorial para ajudar a empresa a vencer a concorrência local e melhorar os produtos); respeite as permissões do usuário.\n"
        . "- Sempre que executar ou recomendar uma ação no sistema, além da resposta em texto, inclua NO FINAL uma ou mais linhas no formato exato [[ACAO:{...JSON...}]] entre os tipos permitidos:\n"
        . "  1) {\"tipo\":\"abrir_aba\",\"aba\":\"visao-geral|territorio|estoque|financeiro|clientes|entregadores|equipe|cardapio|frete|pagamentos|comandas|atendimento|identidade|contato|seguranca\"}\n"
        . "  2) {\"tipo\":\"abrir_caixa\",\"valor\":100}\n"
        . "  3) {\"tipo\":\"movimentar_caixa\",\"mov\":\"sangria|suprimento\",\"valor\":50,\"descricao\":\"Motivo\"}\n"
        . "  4) {\"tipo\":\"lancar_despesa\",\"descricao\":\"Nome\",\"valor\":100,\"pago\":false}\n"
        . "  5) {\"tipo\":\"movimentar_estoque\",\"item\":\"Nome do insumo\",\"mov\":\"entrada|perda\",\"quantidade\":10}\n"
        . "  6) {\"tipo\":\"mover_pedido\",\"pedido_id\":\"123\",\"status\":\"preparo|em-rota|concluido|cancelado\",\"entregador\":\"Nome opcional\"}\n"
        . "  7) {\"tipo\":\"criar_cupom\",\"codigo\":\"CUPOM10\",\"desconto_tipo\":\"percentual|valor|frete_gratis\",\"valor\":10}\n"
        . "  8) {\"tipo\":\"acao_local\",\"comando\":\"Cadastra X-Bacon por 28\"}\n\n"
        . "CONTEXTO REAL DA EMPRESA:\n" . json_encode($ctxCliente, JSON_UNESCAPED_UNICODE);

    $llmRes = pz_executar_ia_pessoal($iaPessoal, $sysCliente, $pergunta);
    if (!empty($llmRes['success']) && !empty($llmRes['texto'])) {
        $textoBruto = $llmRes['texto'];
        $acoesExecutadasLista = [];

        if (preg_match_all('/\[\[ACAO:\s*(\{.*?\})\s*\]\]/s', $textoBruto, $matches)) {
            foreach ($matches[1] as $jsonRaw) {
                $ac = json_decode($jsonRaw, true);
                if (!is_array($ac) || empty($ac['tipo'])) {
                    continue;
                }
                $tipoAc = (string) $ac['tipo'];
                if ($tipoAc === 'abrir_aba' && !empty($ac['aba'])) {
                    $abaAlvo = pz_limpar_str($ac['aba'], 40);
                    $acaoAbrirAba = $abaAlvo;
                    $acoesUI[] = ['label' => 'Abrir ' . $abaAlvo, 'aba' => $abaAlvo];
                } elseif ($tipoAc === 'abrir_caixa' && $permFin) {
                    $val = max(0, (float) ($ac['valor'] ?? 0));
                    $r = pz_abrir_caixa($val, $ator, 'Aberto pela IA pessoal do cliente');
                    if (!empty($r['success'])) {
                        $acoesExecutadasLista[] = 'Caixa aberto com ' . pz_brl($val);
                        $recarregar[] = 'caixa';
                        $recarregar[] = 'resumo';
                    }
                } elseif ($tipoAc === 'movimentar_caixa' && $permFin) {
                    $mov = ($ac['mov'] ?? '') === 'sangria' ? 'sangria' : 'suprimento';
                    $val = max(0, (float) ($ac['valor'] ?? 0));
                    $desc = pz_limpar_str($ac['descricao'] ?? 'Via IA pessoal', 120);
                    if ($val > 0) {
                        $r = pz_movimentar_caixa($mov, $val, $desc, 'Dinheiro', $ator);
                        if (!empty($r['success'])) {
                            $acoesExecutadasLista[] = ucfirst($mov) . ' de ' . pz_brl($val);
                            $recarregar[] = 'caixa';
                            $recarregar[] = 'resumo';
                        }
                    }
                } elseif ($tipoAc === 'lancar_despesa' && $permFin) {
                    $val = max(0, (float) ($ac['valor'] ?? 0));
                    $desc = pz_limpar_str($ac['descricao'] ?? 'Despesa IA', 120);
                    if ($val > 0) {
                        $r = pz_salvar_conta_financeira([
                            'tipo' => 'pagar',
                            'descricao' => $desc,
                            'categoria' => 'Operacional',
                            'valor' => $val,
                            'vencimento' => date('Y-m-d'),
                            'status' => !empty($ac['pago']) ? 'pago' : 'pendente',
                            'forma_pagamento' => 'PIX',
                        ], $ator);
                        if (!empty($r['success'])) {
                            $acoesExecutadasLista[] = 'Despesa "' . $desc . '" (' . pz_brl($val) . ')';
                            $recarregar[] = 'financeiro';
                            $recarregar[] = 'resumo';
                        }
                    }
                } elseif ($tipoAc === 'movimentar_estoque' && $permEst) {
                    $nomeItem = pz_norm($ac['item'] ?? '');
                    $qtdMov = max(0, (float) ($ac['quantidade'] ?? 0));
                    $movEst = ($ac['mov'] ?? '') === 'perda' ? 'perda' : 'entrada';
                    if ($nomeItem !== '' && $qtdMov > 0) {
                        foreach (pz_ler_estoque()['itens'] as $it) {
                            if (strpos(pz_norm($it['nome'] ?? ''), $nomeItem) !== false || strpos($nomeItem, pz_norm($it['nome'] ?? '')) !== false) {
                                $r = pz_movimentar_estoque([
                                    'item_id' => $it['id'],
                                    'tipo' => $movEst,
                                    'quantidade' => $qtdMov,
                                    'motivo' => 'IA Pessoal',
                                ], $ator);
                                if (!empty($r['success'])) {
                                    $acoesExecutadasLista[] = ucfirst($movEst) . " {$qtdMov} {$it['unidade']} em {$it['nome']}";
                                    $recarregar[] = 'estoque';
                                    $recarregar[] = 'resumo';
                                }
                                break;
                            }
                        }
                    }
                } elseif ($tipoAc === 'mover_pedido' && $permOp && !empty($ac['pedido_id'])) {
                    $pid = preg_replace('/\D/', '', (string) $ac['pedido_id']);
                    $st = (string) ($ac['status'] ?? '');
                    $pedObj = $pid !== '' ? Database::findOrder($pid) : null;
                    if ($pedObj && in_array($st, ['preparo', 'em-rota', 'concluido', 'cancelado'], true)) {
                        if (!empty($ac['entregador'])) {
                            $entNorm = pz_norm($ac['entregador']);
                            foreach (pz_ler_entregadores() as $ent) {
                                if (strpos(pz_norm($ent['nome'] ?? ''), $entNorm) !== false) {
                                    Database::patchOrder($pid, [
                                        'entregador_id' => $ent['id'],
                                        'entregador_nome' => $ent['nome'],
                                        'saiu_em' => date('Y-m-d H:i:s'),
                                    ]);
                                    break;
                                }
                            }
                        }
                        if ($st === 'cancelado') {
                            Database::patchOrder($pid, ['cancelado' => true, 'motivo_cancelamento' => 'Cancelado via IA Pessoal']);
                            Database::updateOrderStatus($pid, 'arquivado');
                            pz_estornar_estoque_pedido($pid, $ator);
                            pz_estornar_venda_financeiro($pid, $ator);
                        } else {
                            Database::updateOrderStatus($pid, $st);
                            if ($st === 'preparo') Database::garantirComandaCozinha($pid);
                            pz_baixar_estoque_pedido($pid, $ator);
                            if ($st === 'concluido') pz_registrar_venda_financeiro($pid, $ator);
                        }
                        $acoesExecutadasLista[] = "Pedido #{$pid} -> {$st}";
                        $recarregar[] = 'resumo';
                    }
                } elseif ($tipoAc === 'criar_cupom' && $permCli && !empty($ac['codigo'])) {
                    $cod = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string) $ac['codigo']));
                    if ($cod !== '') {
                        $r = pz_salvar_cupom([
                            'codigo' => $cod,
                            'tipo' => $ac['desconto_tipo'] ?? 'percentual',
                            'valor' => (float) ($ac['valor'] ?? 10),
                            'minimo_pedido' => 0,
                            'ativo' => true,
                        ], $ator);
                        if (!empty($r['success'])) {
                            $acoesExecutadasLista[] = "Cupom {$cod} criado";
                            $recarregar[] = 'clientes';
                        }
                    }
                } elseif ($tipoAc === 'acao_local' && !empty($ac['comando'])) {
                    $acoesLocais[] = (string) $ac['comando'];
                }
            }
            $textoBruto = trim((string) preg_replace('/\[\[ACAO:.*?\]\]/s', '', $textoBruto));
        }

        echo json_encode([
            'success' => true,
            'nome_agente' => $nomeAgente,
            'modo_ia' => 'ia_pessoal',
            'resposta' => $textoBruto,
            'origem_dados' => ['ia_pessoal_cliente', 'pedidos.json', 'estoque.json', 'financeiro.json'],
            'cards_metricas' => [],
            'acao_executada' => $acoesExecutadasLista ? implode(' | ', $acoesExecutadasLista) : null,
            'recarregar_modulos' => array_values(array_unique($recarregar)),
            'acoes_ui' => $acoesUI,
            'acao_abrir_aba' => $acaoAbrirAba,
            'acoes_locais' => $acoesLocais,
            'sugestoes' => [],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ---------------------------------------------------------------------
// 1. AÇÕES DIRETAS DE CAIXA (Abrir / Sangria / Suprimento)
// ---------------------------------------------------------------------
if (preg_match('/\b(abrir|abre|iniciar)\s+(?:o\s+)?caixa\b/', $n)) {
    if (!$permFin) {
        echo json_encode(['success' => true, 'negado_permissao' => true, 'resposta' => 'Seu perfil não tem permissão para abrir ou movimentar o caixa.', 'origem_dados' => ['rbac']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $valor = pz_extrair_valor_monetario($n) ?? 0.0;
    $res = pz_abrir_caixa($valor, $ator, 'Aberto via ' . $nomeAgente);
    $origem = ['caixa.json', 'auditoria.json'];
    if (!empty($res['success'])) {
        $acaoExecutada = 'Caixa aberto com troco inicial de ' . pz_brl($valor);
        $resposta = "Pronto! **{$acaoExecutada}** por {$ator}. Agora todas as vendas e conferências do dia serão somadas nele.";
        $recarregar = ['resumo', 'caixa'];
        $acoesUI[] = ['label' => 'Ver Caixa do Dia', 'aba' => 'financeiro'];
    } else {
        $resposta = $res['message'] ?? 'Não foi possível abrir o caixa.';
        $acoesUI[] = ['label' => 'Abrir aba Financeiro & Caixa', 'aba' => 'financeiro'];
    }
} elseif (preg_match('/\b(sangria|suprimento|reforco)\b/', $n) && pz_extrair_valor_monetario($n) !== null) {
    if (!$permFin) {
        echo json_encode(['success' => true, 'negado_permissao' => true, 'resposta' => 'Seu perfil não tem permissão financeira para lançar sangria ou suprimento.', 'origem_dados' => ['rbac']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $tipo = strpos($n, 'sangria') !== false ? 'sangria' : 'suprimento';
    $valor = pz_extrair_valor_monetario($n);
    $desc = trim(preg_replace('/\b(registrar|lancar|fazer|sangria|suprimento|reforco|de|no|caixa|r\$|\d+(?:[.,]\d{1,2})?|reais)\b/i', ' ', $pergunta));
    $desc = trim((string) preg_replace('/\s+/', ' ', $desc)) ?: ($tipo === 'sangria' ? 'Sangria solicitada via IA' : 'Suprimento de caixa via IA');
    $res = pz_movimentar_caixa($tipo, $valor, $desc, 'Dinheiro', $ator);
    $origem = ['caixa.json', 'auditoria.json'];
    if (!empty($res['success'])) {
        $acaoExecutada = ucfirst($tipo) . ' de ' . pz_brl($valor) . ' registrada';
        $resposta = "Feito! Lancei **{$acaoExecutada}** ({$desc}) no caixa atual.";
        $recarregar = ['resumo', 'caixa'];
        $acoesUI[] = ['label' => 'Conferir Caixa', 'aba' => 'financeiro'];
    } else {
        $resposta = ($res['message'] ?? 'Erro ao movimentar caixa.') . ' Dica: você pode dizer "Abrir caixa com 100 reais".';
    }
}
// ---------------------------------------------------------------------
// 2. AÇÃO DIRETA FINANCEIRA — LANÇAR CONTA / DESPESA
// ---------------------------------------------------------------------
elseif (preg_match('/\b(lancar|cadastrar|registrar|adicionar)\s+(?:uma\s+)?(despesa|conta(?:\s+a\s+pagar)?|gasto|pagamento)\b/', $n) && pz_extrair_valor_monetario($n) !== null) {
    if (!$permFin) {
        echo json_encode(['success' => true, 'negado_permissao' => true, 'resposta' => 'Seu perfil não tem permissão para lançar despesas no financeiro.', 'origem_dados' => ['rbac']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $valor = pz_extrair_valor_monetario($n);
    $pago = strpos($n, 'pago') !== false || strpos($n, 'paga') !== false || strpos($n, 'ja paguei') !== false;
    $desc = trim(preg_replace('/\b(lancar|cadastrar|registrar|adicionar|uma|despesa|conta|a|pagar|gasto|pagamento|de|no|valor|r\$|\d+(?:[.,]\d{1,2})?|reais|pago|paga)\b/i', ' ', $pergunta));
    $desc = trim((string) preg_replace('/\s+/', ' ', $desc)) ?: 'Despesa lançada via IA';
    $res = pz_salvar_conta_financeira([
        'tipo' => 'pagar',
        'descricao' => $desc,
        'categoria' => 'Operacional',
        'valor' => $valor,
        'vencimento' => date('Y-m-d'),
        'status' => $pago ? 'pago' : 'pendente',
        'forma_pagamento' => 'PIX',
    ], $ator);
    $origem = ['financeiro.json', 'auditoria.json'];
    if (!empty($res['success'])) {
        $acaoExecutada = 'Despesa "' . $desc . '" de ' . pz_brl($valor) . ' (' . ($pago ? 'paga' : 'pendente') . ')';
        $resposta = "Pronto! Registrei **{$acaoExecutada}** no módulo financeiro.";
        $recarregar = ['resumo', 'financeiro'];
        $acoesUI[] = ['label' => 'Abrir Contas & DRE', 'aba' => 'financeiro'];
    } else {
        $resposta = $res['message'] ?? 'Não consegui lançar a conta.';
    }
}
// ---------------------------------------------------------------------
// 3. AÇÃO DIRETA DE ESTOQUE — ENTRADA OU PERDA/BAIXA
// ---------------------------------------------------------------------
elseif (preg_match('/\b(entrada|repor|comprar|adicionar|perda|baixa|desperdicio)\b/', $n) && strpos($n, 'estoque') !== false && preg_match('/\d+(?:[.,]\d+)?/', $n)) {
    if (!$permEst) {
        echo json_encode(['success' => true, 'negado_permissao' => true, 'resposta' => 'Seu perfil não possui permissão de estoque.', 'origem_dados' => ['rbac']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $est = pz_ler_estoque()['itens'];
    $itemAlvo = null;
    foreach ($est as $it) {
        $nomeNorm = pz_norm($it['nome'] ?? '');
        if ($nomeNorm !== '' && strpos($n, $nomeNorm) !== false) {
            $itemAlvo = $it;
            break;
        }
    }
    if (!$itemAlvo) {
        foreach ($est as $it) {
            $palavras = array_filter(explode(' ', pz_norm($it['nome'] ?? '')), function ($w) {
                return strlen($w) >= 4;
            });
            foreach ($palavras as $w) {
                if (strpos($n, $w) !== false) {
                    $itemAlvo = $it;
                    break 2;
                }
            }
        }
    }
    if (!$itemAlvo) {
        $origem = ['estoque.json'];
        $resposta = 'Não encontrei nenhum insumo correspondente no seu cadastro de estoque. Cadastre primeiro na aba **Estoque & CMV** ou informe o nome exato do item.';
        $acoesUI[] = ['label' => 'Ir para Estoque & CMV', 'aba' => 'estoque'];
    } else {
        preg_match('/(\d+(?:[.,]\d+)?)/', $n, $mQtd);
        $qtd = (float) str_replace(',', '.', $mQtd[1] ?? '0');
        $tipoMov = (strpos($n, 'perda') !== false || strpos($n, 'baixa') !== false || strpos($n, 'desperdicio') !== false) ? 'perda' : 'entrada';
        $res = pz_movimentar_estoque([
            'item_id' => $itemAlvo['id'],
            'tipo' => $tipoMov,
            'quantidade' => $qtd,
            'motivo' => 'Movimentação via ' . $nomeAgente,
        ], $ator);
        $origem = ['estoque.json', 'movimentacoes_estoque.json', 'auditoria.json'];
        if (!empty($res['success'])) {
            $acaoExecutada = ($tipoMov === 'entrada' ? 'Entrada' : 'Perda/Baixa') . " de {$qtd} {$itemAlvo['unidade']} em {$itemAlvo['nome']}";
            $resposta = "Movimentação registrada: **{$acaoExecutada}**. Saldo atual: **{$res['quantidade_atual']} {$itemAlvo['unidade']}**.";
            $recarregar = ['resumo', 'estoque'];
            $acoesUI[] = ['label' => 'Abrir Estoque', 'aba' => 'estoque'];
        } else {
            $resposta = $res['message'] ?? 'Erro ao movimentar estoque.';
        }
    }
}
// ---------------------------------------------------------------------
// 4. AÇÃO DIRETA — MOVER / CANCELAR PEDIDO OU DESPACHAR ENTREGADOR
// ---------------------------------------------------------------------
elseif (preg_match('/pedido\s*#?\s*(\d{3,18})/', $n, $mPed) && preg_match('/\b(preparo|cozinha|rota|pronto|concluido|entregue|finalizar|cancelar|despachar|enviar)\b/', $n)) {
    if (!$permOp) {
        echo json_encode(['success' => true, 'negado_permissao' => true, 'resposta' => 'Seu perfil não possui permissão operacional de pedidos.', 'origem_dados' => ['rbac']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $pedId = $mPed[1];
    $ped = Database::findOrder($pedId);
    if (!$ped) {
        $origem = ['pedidos.json'];
        $resposta = "Não encontrei o pedido **#{$pedId}** na base da loja.";
    } else {
        $novoStatus = null;
        $cancelar = false;
        if (strpos($n, 'cancelar') !== false) {
            $novoStatus = 'arquivado';
            $cancelar = true;
        } elseif (strpos($n, 'concluido') !== false || strpos($n, 'entregue') !== false || strpos($n, 'finalizar') !== false) {
            $novoStatus = 'concluido';
        } elseif (strpos($n, 'rota') !== false || strpos($n, 'pronto') !== false || strpos($n, 'despachar') !== false || strpos($n, 'enviar') !== false) {
            $novoStatus = 'em-rota';
        } elseif (strpos($n, 'preparo') !== false || strpos($n, 'cozinha') !== false) {
            $novoStatus = 'preparo';
        }

        // Verifica se citou algum entregador
        $entregadorEncontrado = null;
        foreach (pz_ler_entregadores() as $ent) {
            $ne = pz_norm($ent['nome'] ?? '');
            if ($ne !== '' && strpos($n, $ne) !== false) {
                $entregadorEncontrado = $ent;
                break;
            }
        }
        if ($entregadorEncontrado) {
            Database::patchOrder($pedId, [
                'entregador_id' => $entregadorEncontrado['id'],
                'entregador_nome' => $entregadorEncontrado['nome'],
                'saiu_em' => date('Y-m-d H:i:s'),
            ]);
            if (!$novoStatus) {
                $novoStatus = 'em-rota';
            }
        }

        if ($cancelar) {
            Database::patchOrder($pedId, ['cancelado' => true, 'motivo_cancelamento' => 'Cancelado via ' . $nomeAgente]);
            Database::updateOrderStatus($pedId, 'arquivado');
            pz_estornar_estoque_pedido($pedId, $ator);
            pz_estornar_venda_financeiro($pedId, $ator);
            pz_registrar_auditoria('pedidos', 'pedido_cancelado_ia', 'Pedido #' . $pedId, $ator);
            $acaoExecutada = "Pedido #{$pedId} cancelado com estorno automático";
            $resposta = "Feito! **{$acaoExecutada}** de estoque e lançamento financeiro (quando aplicável).";
        } elseif ($novoStatus) {
            Database::updateOrderStatus($pedId, $novoStatus);
            if ($novoStatus === 'preparo') {
                Database::garantirComandaCozinha($pedId);
            }
            if (in_array($novoStatus, ['preparo', 'em-rota', 'concluido'], true)) {
                pz_baixar_estoque_pedido($pedId, $ator);
            }
            if ($novoStatus === 'concluido') {
                pz_registrar_venda_financeiro($pedId, $ator);
            }
            $acaoExecutada = "Pedido #{$pedId} atualizado para {$novoStatus}" . ($entregadorEncontrado ? " (Entregador: {$entregadorEncontrado['nome']})" : '');
            $resposta = "Atualizado! **{$acaoExecutada}**.";
        }
        $origem = ['pedidos.json', 'estoque.json', 'financeiro.json'];
        $recarregar = ['resumo', 'estoque', 'financeiro'];
    }
}
// ---------------------------------------------------------------------
// 5. AÇÃO DIRETA — CRIAR CUPOM PROMOCIONAL
// ---------------------------------------------------------------------
elseif (preg_match('/\b(criar|gerar|cadastrar)\s+(?:o\s+)?cupom\s+([a-z0-9_-]{3,24})\b/', $n, $mCup)) {
    if (!$permCli) {
        echo json_encode(['success' => true, 'negado_permissao' => true, 'resposta' => 'Seu perfil não possui permissão de marketing/cupons.', 'origem_dados' => ['rbac']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $codigo = strtoupper($mCup[2]);
    $tipo = strpos($n, 'frete') !== false ? 'frete_gratis' : ((strpos($n, 'reais') !== false || strpos($n, 'r$') !== false) ? 'valor' : 'percentual');
    $restante = substr($n, strpos($n, strtolower($codigo)) + strlen($codigo));
    $valor = pz_extrair_valor_monetario($restante) ?? 10.0;
    $res = pz_salvar_cupom([
        'codigo' => $codigo,
        'tipo' => $tipo,
        'valor' => $valor,
        'minimo_pedido' => 0,
        'ativo' => true,
    ], $ator);
    $origem = ['marketing.json', 'auditoria.json'];
    if (!empty($res['success'])) {
        $acaoExecutada = "Cupom {$codigo} criado e ativo";
        $resposta = "Cupom **{$codigo}** criado! Tipo: **{$tipo}**" . ($tipo !== 'frete_gratis' ? ' (' . $valor . ($tipo === 'percentual' ? '%' : ' R$') . ')' : '') . ". Clientes já podem aplicar no checkout.";
        $recarregar = ['marketing'];
        $acoesUI[] = ['label' => 'Ver Cupons & Clientes', 'aba' => 'clientes'];
    } else {
        $resposta = $res['message'] ?? 'Não foi possível criar o cupom.';
    }
}
// ---------------------------------------------------------------------
// 6. CONSULTA: ESTOQUE BAIXO & LISTA DE COMPRAS
// ---------------------------------------------------------------------
elseif (strpos($n, 'estoque') !== false || strpos($n, 'comprar') !== false || strpos($n, 'insumo') !== false || strpos($n, 'repor') !== false || strpos($n, 'falt') !== false) {
    if (!$permEst) {
        echo json_encode(['success' => true, 'negado_permissao' => true, 'resposta' => 'Acesso restrito: seu perfil não inclui o módulo de Estoque.', 'origem_dados' => ['rbac']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $est = pz_ler_estoque();
    $baixo = $resumo['estoque_baixo'];
    $origem = ['estoque.json', 'movimentacoes_estoque.json'];
    $custoCompra = 0.0;
    foreach ($baixo as $b) {
        $custoCompra += ((float) $b['sugestao_compra']) * ((float) $b['custo_unitario']);
    }
    $cards = [
        ['titulo' => 'Total de Insumos', 'valor' => count($est['itens'])],
        ['titulo' => 'Abaixo do Mínimo', 'valor' => count($baixo), 'destaque' => count($baixo) > 0 ? 'critico' : 'ok'],
        ['titulo' => 'Reposição Estimada', 'valor' => pz_brl($custoCompra)],
    ];
    if (!$est['itens']) {
        $resposta = 'Seu estoque ainda não possui insumos cadastrados. Abra a aba **Estoque & CMV** para cadastrar os ingredientes principais e ativar a baixa automática.';
    } elseif ($baixo) {
        $linhas = array_map(function ($b) {
            return "• **{$b['nome']}**: atual {$b['quantidade_atual']} {$b['unidade']} (mín. {$b['estoque_minimo']}) → sugerido comprar **{$b['sugestao_compra']} {$b['unidade']}**";
        }, array_slice($baixo, 0, 8));
        $resposta = "Encontrei **" . count($baixo) . " insumo(s)** no estoque mínimo ou zerado:\n" . implode("\n", $linhas)
            . ($custoCompra > 0 ? "\n\nInvestimento estimado de reposição: **" . pz_brl($custoCompra) . "**." : '');
    } else {
        $resposta = "Excelente! Todos os **" . count($est['itens']) . " insumos** cadastrados estão acima do estoque mínimo neste momento.";
    }
    $acoesUI[] = ['label' => 'Abrir Estoque & Fornecedores', 'aba' => 'estoque'];
    $sugestoes = ['Qual meu produto mais lucrativo?', 'Quais pedidos estão em aberto agora?'];
}
// ---------------------------------------------------------------------
// 7. CONSULTA: FICHAS TÉCNICAS, CMV E LUCRATIVIDADE POR PRODUTO
// ---------------------------------------------------------------------
elseif (strpos($n, 'lucrativ') !== false || strpos($n, 'margem') !== false || strpos($n, 'cmv') !== false || strpos($n, 'ficha') !== false || strpos($n, 'rentab') !== false || strpos($n, 'custo') !== false) {
    if (!$permFin && !$permEst) {
        echo json_encode(['success' => true, 'negado_permissao' => true, 'resposta' => 'Acesso restrito a informações de CMV e lucratividade.', 'origem_dados' => ['rbac']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $prods = $resumo['mes']['por_produto'];
    $fichas = pz_mapa_fichas_por_produto();
    $origem = ['fichas_tecnicas.json', 'pedidos.json', 'estoque.json'];
    $comFicha = array_values(array_filter($prods, function ($p) {
        return !empty($p['tem_ficha']) && $p['quantidade'] > 0;
    }));
    $semFicha = array_values(array_filter($prods, function ($p) {
        return empty($p['tem_ficha']) && $p['quantidade'] > 0;
    }));
    $cards = [
        ['titulo' => 'Fichas Cadastradas', 'valor' => count($fichas)],
        ['titulo' => 'CMV do Mês', 'valor' => pz_brl($resumo['mes']['cmv_total'])],
        ['titulo' => 'Margem Contribuição', 'valor' => $resumo['mes']['margem_contribuicao_pct'] . '%'],
    ];
    if ($comFicha) {
        $top = array_slice($comFicha, 0, 4);
        $linhas = array_map(function ($p) {
            return "• **{$p['nome']}**: {$p['quantidade']}x vendido | Faturamento " . pz_brl($p['faturamento']) . " | CMV " . pz_brl($p['cmv']) . " | **Lucro " . pz_brl($p['lucro']) . " ({$p['margem_pct']}%)**";
        }, $top);
        $resposta = "Ranking de rentabilidade no mês (com base nas fichas técnicas cadastradas):\n" . implode("\n", $linhas);
        if ($resumo['produtos_baixa_margem']) {
            $b = $resumo['produtos_baixa_margem'][0];
            $resposta .= "\n\n⚠️ Atenção: **{$b['nome']}** está com margem de **{$b['margem_pct']}%**.";
        }
        if ($semFicha) {
            $resposta .= "\n\nNota: " . count($semFicha) . " produto(s) vendido(s) ainda estão sem ficha técnica (ex.: {$semFicha[0]['nome']}).";
        }
    } elseif ($prods) {
        $top = array_slice($prods, 0, 3);
        $nomes = implode(', ', array_map(function ($p) {
            return $p['nome'] . ' (' . $p['quantidade'] . 'x)';
        }, $top));
        $resposta = "Você já tem vendas no mês ({$nomes}), mas **nenhuma ficha técnica** vinculada aos produtos vendidos. Cadastre a ficha técnica em **Estoque & CMV** para calcular CMV exato e lucro real por prato!";
    } else {
        $resposta = "Ainda não há vendas no mês para ranquear lucratividade. Você tem **" . count($fichas) . " ficha(s) técnica(s)** cadastrada(s).";
    }
    $acoesUI[] = ['label' => 'Gerenciar Fichas Técnicas', 'aba' => 'estoque'];
    $sugestoes = ['Quanto vendi e qual meu lucro no mês?', 'O que preciso comprar de estoque?'];
}
// ---------------------------------------------------------------------
// 8. CONSULTA: CAIXA DO DIA E CONTAS A PAGAR
// ---------------------------------------------------------------------
elseif (strpos($n, 'caixa') !== false || strpos($n, 'conta') !== false || strpos($n, 'venc') !== false || strpos($n, 'pagar') !== false || strpos($n, 'despesa') !== false) {
    if (!$permFin) {
        echo json_encode(['success' => true, 'negado_permissao' => true, 'resposta' => 'Seu perfil não tem permissão para consultar caixa e contas financeiras.', 'origem_dados' => ['rbac']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $cx = $resumo['caixa'];
    $t = $cx['totais'];
    $venc = $resumo['mes']['contas_vencendo_7d'];
    $origem = ['caixa.json', 'financeiro.json'];
    $cards = [
        ['titulo' => 'Caixa', 'valor' => $cx['aberto'] ? 'ABERTO' : 'FECHADO', 'destaque' => $cx['aberto'] ? 'ok' : 'aviso'],
        ['titulo' => 'Dinheiro Esperado', 'valor' => pz_brl($t['dinheiro_esperado'] ?? 0)],
        ['titulo' => 'PIX + Cartão (Cx)', 'valor' => pz_brl(($t['pix_esperado'] ?? 0) + ($t['cartao_esperado'] ?? 0))],
        ['titulo' => 'Contas em 7 dias', 'valor' => count($venc)],
    ];
    $partes = [];
    if ($cx['aberto']) {
        $partes[] = "O caixa está **ABERTO** (saldo inicial " . pz_brl($t['saldo_inicial']) . "). Esperado em dinheiro: **" . pz_brl($t['dinheiro_esperado']) . "** | PIX: **" . pz_brl($t['pix_esperado']) . "** | Cartão: **" . pz_brl($t['cartao_esperado']) . "** | Total em movimento: **" . pz_brl($t['total_geral_esperado']) . "**.";
    } else {
        $partes[] = "O caixa do dia está **FECHADO**. Você pode abrir agora mesmo dizendo: *\"Abrir caixa com 100 reais\"*.";
    }
    if ($venc) {
        $linhasVenc = array_map(function ($c) {
            return "• {$c['vencimento']} — **{$c['descricao']}**: " . pz_brl($c['valor']);
        }, array_slice($venc, 0, 5));
        $partes[] = "Contas a pagar vencendo em até 7 dias:\n" . implode("\n", $linhasVenc);
    } else {
        $partes[] = "Não há contas a pagar vencendo nos próximos 7 dias.";
    }
    $resposta = implode("\n\n", $partes);
    $acoesUI[] = ['label' => 'Abrir Caixa & Financeiro', 'aba' => 'financeiro'];
    $sugestoes = ['Quanto vendi hoje?', 'DRE e resultado do mês'];
}
// ---------------------------------------------------------------------
// 9. CONSULTA: VENDAS, FATURAMENTO E DRE GERENCIAL
// ---------------------------------------------------------------------
elseif (strpos($n, 'vendi') !== false || strpos($n, 'venda') !== false || strpos($n, 'faturament') !== false || strpos($n, 'lucro') !== false || strpos($n, 'dre') !== false || strpos($n, 'resultado') !== false || strpos($n, 'ticket') !== false || strpos($n, 'resumo') !== false || strpos($n, 'hoje') !== false || strpos($n, 'mes') !== false || strpos($n, 'semana') !== false) {
    if (!$permFin) {
        if ($permOp) {
            $origem = ['pedidos.json'];
            $resposta = "Hoje você teve **{$resumo['hoje']['pedidos']} pedido(s)** válidos e há **{$resumo['pedidos_abertos']} pedido(s) em aberto** no momento. (Valores financeiros detalhados são restritos ao perfil financeiro.)";
        } else {
            echo json_encode(['success' => true, 'negado_permissao' => true, 'resposta' => 'Seu perfil não possui acesso aos indicadores financeiros.', 'origem_dados' => ['rbac']], JSON_UNESCAPED_UNICODE);
            exit;
        }
    } else {
        $ehSemana = strpos($n, 'semana') !== false || strpos($n, '7 dias') !== false;
        $ehMes = strpos($n, 'mes') !== false || strpos($n, 'dre') !== false || strpos($n, 'resultado') !== false;
        $bloco = $ehSemana ? $resumo['semana'] : ($ehMes ? $resumo['mes'] : $resumo['hoje']);
        $labelPer = $ehSemana ? 'nos últimos 7 dias' : ($ehMes ? 'no mês atual' : 'hoje');
        $origem = ['pedidos.json', 'financeiro.json', 'fichas_tecnicas.json', 'movimentacoes_estoque.json'];
        $cards = [
            ['titulo' => 'Faturamento (' . $labelPer . ')', 'valor' => pz_brl($bloco['faturamento_bruto'])],
            ['titulo' => 'Pedidos Válidos', 'valor' => $bloco['pedidos']],
            ['titulo' => 'Ticket Médio', 'valor' => pz_brl($bloco['ticket_medio'])],
            ['titulo' => 'Resultado Operacional', 'valor' => pz_brl($bloco['resultado_operacional']), 'destaque' => $bloco['resultado_operacional'] >= 0 ? 'ok' : 'critico'],
        ];
        $pgTxt = $bloco['por_pagamento']
            ? implode(', ', array_map(function ($pg) {
                return "{$pg['forma']}: " . pz_brl($pg['valor']) . " ({$pg['pedidos']})";
            }, array_slice($bloco['por_pagamento'], 0, 4)))
            : 'sem lançamentos ainda';

        $resposta = "Resumo financeiro **{$labelPer}**:\n"
            . "• **Faturamento bruto:** " . pz_brl($bloco['faturamento_bruto']) . " ({$bloco['pedidos']} pedido(s) | Ticket médio " . pz_brl($bloco['ticket_medio']) . ")\n"
            . "• **CMV + Perdas:** " . pz_brl($bloco['cmv_total']) . " → **Margem de Contribuição:** " . pz_brl($bloco['margem_contribuicao']) . " ({$bloco['margem_contribuicao_pct']}%)\n"
            . "• **Despesas operacionais:** " . pz_brl($bloco['despesas_totais']) . " → **Resultado Líquido Estimado:** " . pz_brl($bloco['resultado_operacional']) . " ({$bloco['lucratividade_pct']}%)\n"
            . "• **Formas de pagamento:** {$pgTxt}.";
        $acoesUI[] = ['label' => 'Ver DRE Completo', 'aba' => 'financeiro'];
        $sugestoes = ['Qual meu produto mais lucrativo?', 'O que preciso comprar de estoque?', 'Como está o caixa?'];
    }
}
// ---------------------------------------------------------------------
// 10. CONSULTA: PEDIDOS EM ABERTO / COZINHA / ENTREGADORES
// ---------------------------------------------------------------------
elseif (strpos($n, 'entregador') !== false || strpos($n, 'motoboy') !== false || strpos($n, 'despach') !== false) {
    if (!$permOp) {
        echo json_encode(['success' => true, 'negado_permissao' => true, 'resposta' => 'Seu perfil não possui acesso à área de entrega.', 'origem_dados' => ['rbac']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $rel = pz_relatorio_entregadores();
    $origem = ['entregadores.json', 'pedidos.json'];
    $cards = [['titulo' => 'Entregadores Ativos', 'valor' => count($rel)]];
    if (!$rel) {
        $resposta = 'Nenhum entregador cadastrado ainda. Cadastre na aba **Entregadores** para vincular pedidos ao despacho.';
    } else {
        $linhas = array_map(function ($e) {
            return "• **{$e['nome']}**: {$e['entregas']} entrega(s) | Pedidos " . pz_brl($e['valor_pedidos']) . " | Taxas " . pz_brl($e['taxas_entrega']);
        }, array_slice($rel, 0, 6));
        $resposta = "Desempenho dos entregadores:\n" . implode("\n", $linhas);
    }
    $acoesUI[] = ['label' => 'Abrir Entregadores', 'aba' => 'entregadores'];
} elseif (strpos($n, 'pedido') !== false || strpos($n, 'cozinha') !== false || strpos($n, 'fila') !== false || strpos($n, 'aberto') !== false || strpos($n, 'atras') !== false) {
    if (!$permOp) {
        echo json_encode(['success' => true, 'negado_permissao' => true, 'resposta' => 'Seu perfil não possui permissão operacional de pedidos.', 'origem_dados' => ['rbac']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $pedidos = Database::readStrict('pedidos.json');
    if (!is_array($pedidos)) {
        $pedidos = [];
    }
    $abertos = array_values(array_filter($pedidos, function ($p) {
        return is_array($p) && in_array($p['status'] ?? 'novo', ['novo', 'preparo', 'em-rota'], true) && empty($p['cancelado']);
    }));
    $cont = ['novo' => 0, 'preparo' => 0, 'em-rota' => 0];
    foreach ($abertos as $p) {
        $s = $p['status'] ?? 'novo';
        if (isset($cont[$s])) {
            $cont[$s]++;
        }
    }
    $origem = ['pedidos.json'];
    $cards = [
        ['titulo' => 'Novos', 'valor' => $cont['novo']],
        ['titulo' => 'Na Cozinha', 'valor' => $cont['preparo']],
        ['titulo' => 'Em Rota', 'valor' => $cont['em-rota']],
    ];
    if (!$abertos) {
        $resposta = 'Não há nenhum pedido em aberto na fila agora!';
    } else {
        $linhas = array_map(function ($p) {
            return "• **#{$p['id']}** ({$p['status']}) — {$p['cliente']} — " . pz_brl($p['total'] ?? 0);
        }, array_slice($abertos, 0, 6));
        $resposta = "Há **" . count($abertos) . " pedido(s) em andamento** ({$cont['novo']} novos, {$cont['preparo']} preparo, {$cont['em-rota']} em rota):\n"
            . implode("\n", $linhas)
            . "\n\nVocê pode comandar por aqui, por exemplo: *\"Mover pedido #{$abertos[0]['id']} para preparo\"*.";
    }
    $sugestoes = ['Quanto vendi hoje?', 'O que preciso comprar de estoque?'];
}
// ---------------------------------------------------------------------
// 11. CONSULTA: CLIENTES, FIDELIDADE, CUPONS E RECUPERAÇÃO DE CARRINHO
// ---------------------------------------------------------------------
elseif (strpos($n, 'cliente') !== false || strpos($n, 'vip') !== false || strpos($n, 'fidelidade') !== false || strpos($n, 'abandon') !== false || strpos($n, 'recuper') !== false || strpos($n, 'cupom') !== false) {
    if (!$permCli) {
        echo json_encode(['success' => true, 'negado_permissao' => true, 'resposta' => 'Seu perfil não possui acesso ao módulo de Clientes e Marketing.', 'origem_dados' => ['rbac']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $clientes = pz_ler_clientes();
    $mkt = pz_ler_marketing();
    $rec = array_values(array_filter(pz_ler_recuperacao(), function ($r) {
        return ($r['status'] ?? '') === 'abandonado';
    }));
    $origem = ['clientes.json', 'marketing.json', 'recuperacao.json'];
    $cards = [
        ['titulo' => 'Clientes no CRM', 'valor' => count($clientes)],
        ['titulo' => 'Cupons Ativos', 'valor' => count(array_filter($mkt['cupons'], function ($c) { return !empty($c['ativo']); }))],
        ['titulo' => 'Carrinhos Abandonados', 'valor' => count($rec)],
    ];
    $topCli = array_slice($clientes, 0, 3);
    $vips = $topCli ? implode(', ', array_map(function ($c) {
        return "{$c['nome']} (" . ($c['total_pedidos'] ?? 0) . " ped / " . pz_brl($c['total_gasto'] ?? 0) . ")";
    }, $topCli)) : 'nenhum cliente registrado ainda';
    $resposta = "Base CRM com **" . count($clientes) . " cliente(s)**. Principais VIPs: {$vips}.\n"
        . "Carrinhos abandonados aguardando abordagem: **" . count($rec) . "**."
        . ($mkt['cupons'] ? "\nCupons cadastrados: **" . count($mkt['cupons']) . "**." : "\nDica: diga *\"Criar cupom VOLTA10 10%\"* para ativar um cupom na hora.");
    $acoesUI[] = ['label' => 'Abrir Clientes & Cupons', 'aba' => 'clientes'];
}
// ---------------------------------------------------------------------
// 11B. CONSULTA: DISPUTA DE TERRITÓRIO & MELHORIA CONTÍNUA DE PRODUTOS
// ---------------------------------------------------------------------
elseif (strpos($n, 'territorio') !== false || strpos($n, 'concorrenc') !== false || strpos($n, 'melhorar') !== false || strpos($n, 'ranking') !== false || strpos($n, 'score') !== false || strpos($n, 'bairro') !== false) {
    $ter = pz_territorio_analisar_loja(Database::getTenant());
    $origem = ['config.json', 'cardapio.json', 'pedidos.json', 'avaliacoes.json'];
    if (!empty($ter['success'])) {
        $rk = $ter['ranking'];
        $lj = $ter['loja'];
        $cards = [
            ['titulo' => 'Score Territorial', 'valor' => $lj['score_territorio'] . '/100'],
            ['titulo' => 'Posição no Território', 'valor' => '#' . $rk['posicao'] . ' de ' . $rk['total_lojas_territorio']],
            ['titulo' => 'Nota Média', 'valor' => $lj['nota_media'] . ' ★'],
        ];
        $topDicas = array_slice($ter['dicas'] ?? [], 0, 3);
        $dicasTxt = implode("\n", array_map(function ($d) {
            return '• **' . $d['titulo'] . '**: ' . $d['acao_sugerida'] . ' (' . $d['impacto_pontos'] . ')';
        }, $topDicas));
        $resposta = "**Ranking em {$rk['escopo']}:** #{$rk['posicao']} de {$rk['total_lojas_territorio']} empresa(s) com Score **{$lj['score_territorio']}/100** (Líder: {$rk['score_lider']}/100).\n"
            . "Média regional: Preço **R$ " . number_format((float) $rk['media_preco_regiao'], 2, ',', '.') . "** · Entrega **{$rk['media_tempo_regiao']} min**.\n\n"
            . "**O que melhorar agora (custo R$ 0):**\n{$dicasTxt}";
        $acoesUI[] = ['label' => 'Abrir Território & Melhorias', 'aba' => 'territorio'];
    } else {
        $resposta = 'Ainda não consegui calcular o diagnóstico territorial desta loja. Preencha cidade e bairro na aba Território & Melhorias.';
        $acoesUI[] = ['label' => 'Configurar Território', 'aba' => 'territorio'];
    }
}
// ---------------------------------------------------------------------
// 12. SAUDAÇÃO / BRIEFING PROATIVO COMPLETO OU GEMINI HÍBRIDO
// ---------------------------------------------------------------------
else {
    $ehSaudacaoOuBriefing = preg_match('/\b(oi|ola|bom dia|boa tarde|boa noite|ajuda|comecar|briefing|alertas|atencao|diagnostico)\b/', $n);
    if (!$ehSaudacaoOuBriefing && $modoIA === 'gemini_hibrido') {
        $ctxSeguro = [
            'loja' => $config['EMPRESA']['nome'] ?? '',
            'alertas' => $alertasFiltrados,
            'pedidos_abertos' => $permOp ? $resumo['pedidos_abertos'] : 'restrito',
            'hoje' => $permFin ? $resumo['hoje'] : ['pedidos' => $permOp ? $resumo['hoje']['pedidos'] : 'restrito'],
            'mes' => $permFin ? [
                'pedidos' => $resumo['mes']['pedidos'],
                'faturamento_bruto' => $resumo['mes']['faturamento_bruto'],
                'cmv_total' => $resumo['mes']['cmv_total'],
                'despesas_totais' => $resumo['mes']['despesas_totais'],
                'resultado_operacional' => $resumo['mes']['resultado_operacional'],
                'top_produtos' => array_slice($resumo['mes']['por_produto'], 0, 5),
            ] : 'restrito',
            'estoque_baixo' => $permEst ? $resumo['estoque_baixo'] : 'restrito',
            'caixa_aberto' => $permFin ? $resumo['caixa']['aberto'] : 'restrito',
        ];
        $geminiTexto = pz_chamar_gemini_hibrido($pergunta, $ctxSeguro, $nomeAgente, $tomAgente);
        if ($geminiTexto !== null) {
            $resposta = $geminiTexto;
            $origem = ['gemini_hibrido', 'pedidos.json', 'financeiro.json', 'estoque.json'];
        }
    }

    if ($resposta === '') {
        // Verifica se é intenção de edição local de cardápio/configuração (delegada ao motor local do assistente-ia.js)
        if (preg_match('/\b(cor|cores|telefone|whatsapp|horario|endereco|cardapio|produto|prato|lanche|pizza|bebida|categoria|preco|preço|nome da loja|abrir aba|ir para)\b/', $n)) {
            echo json_encode([
                'success' => true,
                'delegar_local' => true,
                'nome_agente' => $nomeAgente,
                'modo_ia' => $modoIA,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $origem = ['pedidos.json', 'caixa.json', 'estoque.json', 'financeiro.json'];
        $linhas = ["Olá, **{$ator}**! Sou o **{$nomeAgente}** e acompanho a operação da loja em tempo real."];
        if ($permFin) {
            $linhas[] = "• **Hoje:** {$resumo['hoje']['pedidos']} pedido(s) | Faturamento **" . pz_brl($resumo['hoje']['faturamento_bruto']) . "** | Caixa **" . ($resumo['caixa']['aberto'] ? 'ABERTO' : 'FECHADO') . "**.";
        } elseif ($permOp) {
            $linhas[] = "• **Operação agora:** {$resumo['pedidos_abertos']} pedido(s) em aberto.";
        }
        if ($alertasFiltrados) {
            $linhas[] = "\n**Pontos de atenção agora:**";
            foreach (array_slice($alertasFiltrados, 0, 3) as $al) {
                $linhas[] = "⚠️ **{$al['titulo']}** — {$al['mensagem']}";
            }
        }
        $resposta = implode("\n", $linhas);
        $sugestoes = array_values(array_filter([
            $permFin ? 'Quanto vendi e qual meu lucro hoje?' : null,
            $permEst ? 'O que preciso comprar de estoque?' : null,
            $permOp ? 'Quais pedidos estão em aberto agora?' : null,
            $permFin ? 'Qual meu produto mais lucrativo?' : null,
        ]));
    }
}

echo json_encode([
    'success' => true,
    'nome_agente' => $nomeAgente,
    'modo_ia' => $modoIA,
    'resposta' => $resposta,
    'origem_dados' => $origem,
    'cards_metricas' => $cards,
    'acao_executada' => $acaoExecutada,
    'recarregar_modulos' => $recarregar,
    'acoes_ui' => $acoesUI,
    'sugestoes' => $sugestoes,
], JSON_UNESCAPED_UNICODE);
