<?php
/**
 * API Pública do Marketplace (Portal concorrente do iFood).
 * - Lista empresas cadastradas com filtros territoriais, busca em pratos, score, avaliações
 * - Consulta pedidos em tempo real do comprador em todas as lojas
 * - Recebe avaliações pós-pedido para alimentar a disputa territorial
 */
require_once __DIR__ . '/tenant_init.php';
require_once __DIR__ . '/territorio_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$raw = file_get_contents('php://input');
$body = json_decode($raw ?: '', true);
if (!is_array($body)) {
    $body = [];
}

$acao = (string) ($_GET['action'] ?? $body['action'] ?? 'listar_lojas');

if ($acao === 'listar_lojas') {
    $cidadeFiltro = pz_territorio_norm($_GET['cidade'] ?? $body['cidade'] ?? '');
    $bairroFiltro = pz_territorio_norm($_GET['bairro'] ?? $body['bairro'] ?? '');
    $nichoFiltro = pz_territorio_norm($_GET['nicho'] ?? $body['nicho'] ?? '');
    $busca = pz_territorio_norm($_GET['q'] ?? $body['q'] ?? '');
    $soAbertas = !empty($_GET['abertas']) || !empty($body['abertas']);
    $freteGratis = !empty($_GET['frete_gratis']) || !empty($body['frete_gratis']);
    $ordenar = (string) ($_GET['ordenar'] ?? $body['ordenar'] ?? 'territorio');
    $latCli = (float) ($_GET['lat'] ?? $body['lat'] ?? 0);
    $lngCli = (float) ($_GET['lng'] ?? $body['lng'] ?? 0);

    $todas = pz_territorio_coletar_lojas();
    $comparativoSegmentos = pz_territorio_comparativo_segmentos($todas);

    $cidades = [];
    $bairros = [];
    $nichos = [];
    foreach ($todas as $l) {
        if ($l['cidade'] !== '') {
            $cidades[$l['cidade']] = true;
        }
        if ($l['bairro'] !== '') {
            $bairros[$l['bairro']] = true;
        }
        if ($l['nicho'] !== '') {
            $nichos[$l['nicho']] = true;
        }
    }

    $rotulosNicho = [
        'lanchonete' => 'Lanches & Burgers',
        'pizzaria' => 'Pizzaria',
        'marmitaria' => 'Marmitaria & Almoço',
        'pensao' => 'Pensão & Caseira',
        'acai' => 'Açaí & Sorvetes',
        'confeitaria' => 'Confeitaria & Doces',
    ];

    $montarItemLista = function (array $l, array $itensMatch, $dist) use ($rotulosNicho) {
        $raioLoja = max(1.0, (float) ($l['raio_entrega_km'] ?? 10));
        $foraRaio = ($dist !== null && $dist > $raioLoja);
        $entregaEstendida = ($dist !== null && $dist > 15.0 && !$foraRaio);
        $nichoKey = pz_territorio_norm((string) ($l['nicho'] ?? ''));
        $nichoRotulo = $rotulosNicho[$nichoKey] ?? ($l['nicho'] ? ucfirst((string) $l['nicho']) : 'Comércio local');
        return [
            'slug' => $l['slug'],
            'nome' => $l['nome'],
            'slogan' => $l['slogan'] ?? '',
            'cnpj_verificado' => !empty($l['cnpj_verificado']),
            'nicho' => $l['nicho'],
            'nicho_rotulo' => $nichoRotulo,
            'cidade' => $l['cidade'],
            'bairro' => $l['bairro'],
            'estado' => $l['estado'],
            'aberto' => $l['aberto'],
            'cor_primaria' => $l['cor_primaria'],
            'cor_secundaria' => $l['cor_secundaria'] ?? '#1f2937',
            'logo_url' => $l['logo_url'],
            'hero_url' => $l['hero_url'] ?? 'assets/images/placeholder-hero.svg',
            'destaque' => $l['destaque'],
            'nota_media' => $l['nota_media'],
            'total_avaliacoes' => $l['total_avaliacoes'],
            'tempo_medio_min' => $l['tempo_medio_min'],
            'taxa_entrega_base' => $l['taxa_entrega_base'],
            'raio_entrega_km' => $raioLoja,
            'preco_medio' => $l['preco_medio'],
            'media_preco_segmento' => $l['media_preco_segmento'] ?? 0,
            'economia_vs_segmento_pct' => (int) ($l['economia_vs_segmento_pct'] ?? 0),
            'melhor_preco_segmento' => !empty($l['melhor_preco_segmento']),
            'qtd_itens' => $l['qtd_itens'],
            'cupons_ativos' => $l['cupons_ativos'],
            'cupons_lista' => $l['cupons_lista'] ?? [],
            'pagamentos' => $l['pagamentos'] ?? [],
            'cadastro_completo' => !empty($l['cadastro_completo']),
            'score_territorio' => $l['score_territorio'],
            'distancia_km' => $dist,
            'fora_raio' => $foraRaio,
            'atende_regiao' => ($dist === null) ? true : !$foraRaio,
            'entrega_estendida' => $entregaEstendida,
            'url_loja' => $l['url_loja'],
            'itens_preview' => array_slice($itensMatch, 0, 4),
            'avaliacoes_recentes' => array_slice($l['avaliacoes'], 0, 3),
        ];
    };

    $resultado = [];
    foreach ($todas as $l) {
        if ($soAbertas && !$l['aberto']) {
            continue;
        }
        if ($freteGratis && $l['taxa_entrega_base'] > 0) {
            continue;
        }
        if ($cidadeFiltro !== '' && strpos(pz_territorio_norm($l['cidade']), $cidadeFiltro) === false) {
            continue;
        }
        if ($bairroFiltro !== '' && strpos(pz_territorio_norm($l['bairro']), $bairroFiltro) === false) {
            continue;
        }
        if ($nichoFiltro !== '' && pz_territorio_norm($l['nicho']) !== $nichoFiltro) {
            continue;
        }

        $itensMatch = [];
        if ($busca !== '') {
            $matchNome = strpos(pz_territorio_norm($l['nome'] . ' ' . $l['nicho'] . ' ' . $l['bairro'] . ' ' . $l['cidade']), $busca) !== false;
            foreach ($l['itens'] as $it) {
                if (strpos(pz_territorio_norm($it['nome'] . ' ' . $it['descricao']), $busca) !== false) {
                    $itensMatch[] = $it;
                }
            }
            if (!$matchNome && empty($itensMatch)) {
                continue;
            }
        } else {
            $itensMatch = array_slice($l['itens'], 0, 4);
        }

        $dist = ($latCli && $lngCli && $l['lat'] && $l['lng'])
            ? pz_territorio_distancia_km($latCli, $lngCli, $l['lat'], $l['lng'])
            : null;
        $resultado[] = $montarItemLista($l, $itensMatch, $dist);
    }

    // Fallback inteligente quando não houver lojas de um segmento na cidade/bairro ou perto:
    // 1) Busca lojas daquele segmento cujo raio configurado pelo CNPJ (ex.: 100 km) alcança o cliente.
    // 2) Compara melhores preços em outros segmentos próximos.
    $alertaExpansao = null;
    $alternativas = [];
    $temLocalNoFiltro = false;
    foreach ($resultado as $rItem) {
        if (!empty($rItem['atende_regiao']) && empty($rItem['entrega_estendida'])) {
            $temLocalNoFiltro = true;
            break;
        }
    }

    if ($nichoFiltro !== '' && !$temLocalNoFiltro) {
        $estendidasDoSeg = [];
        foreach ($todas as $l) {
            if (pz_territorio_norm($l['nicho']) !== $nichoFiltro) {
                continue;
            }
            if ($soAbertas && !$l['aberto']) {
                continue;
            }
            $dist = ($latCli && $lngCli && $l['lat'] && $l['lng'])
                ? pz_territorio_distancia_km($latCli, $lngCli, $l['lat'], $l['lng'])
                : null;
            $raioLoja = max(1.0, (float) ($l['raio_entrega_km'] ?? 10));
            if ($dist === null || $dist <= $raioLoja) {
                $itemEst = $montarItemLista($l, array_slice($l['itens'], 0, 4), $dist);
                $estendidasDoSeg[$l['slug']] = $itemEst;
            }
        }
        if (!empty($estendidasDoSeg)) {
            foreach ($resultado as $rEx) {
                $estendidasDoSeg[$rEx['slug']] = $rEx;
            }
            $resultado = array_values($estendidasDoSeg);
            $maiorRaio = max(array_column($resultado, 'raio_entrega_km'));
            $alertaExpansao = [
                'tipo' => 'raio_cnpj_estendido',
                'titulo' => 'Recomendação por Raio Estendido do CNPJ',
                'mensagem' => 'Não encontramos lojas desse segmento bem perto de você, então nossa IA buscou empresas com entrega estendida (até ' . $maiorRaio . ' km) que atendem sua localização.',
            ];
        } else {
            foreach ($todas as $l) {
                if (pz_territorio_norm($l['nicho']) === $nichoFiltro) {
                    continue;
                }
                if ($soAbertas && !$l['aberto']) {
                    continue;
                }
                $dist = ($latCli && $lngCli && $l['lat'] && $l['lng'])
                    ? pz_territorio_distancia_km($latCli, $lngCli, $l['lat'], $l['lng'])
                    : null;
                $raioLoja = max(1.0, (float) ($l['raio_entrega_km'] ?? 10));
                if ($dist === null || $dist <= $raioLoja) {
                    $alternativas[] = $montarItemLista($l, array_slice($l['itens'], 0, 4), $dist);
                }
            }
            usort($alternativas, function ($a, $b) {
                return ($b['economia_vs_segmento_pct'] <=> $a['economia_vs_segmento_pct'])
                    ?: ($b['score_territorio'] <=> $a['score_territorio']);
            });
            $alternativas = array_slice($alternativas, 0, 6);
            $alertaExpansao = [
                'tipo' => 'comparativo_outros_segmentos',
                'titulo' => 'Esse segmento ainda não atende nesta região',
                'mensagem' => 'Veja outras lojas e segmentos que já entregam perto do seu endereço:',
            ];
        }
    }

    // Conta quantas empresas existem na região do cliente (ignorando nicho/horário/busca) para mensagens claras quando vazio
    $totalRegiao = 0;
    foreach ($todas as $l) {
        if ($cidadeFiltro !== '' && strpos(pz_territorio_norm($l['cidade']), $cidadeFiltro) === false) {
            continue;
        }
        if ($bairroFiltro !== '' && strpos(pz_territorio_norm($l['bairro']), $bairroFiltro) === false) {
            continue;
        }
        $distReg = ($latCli && $lngCli && $l['lat'] && $l['lng'])
            ? pz_territorio_distancia_km($latCli, $lngCli, $l['lat'], $l['lng'])
            : null;
        $raioReg = max(1.0, (float) ($l['raio_entrega_km'] ?? 10));
        if ($distReg === null || $distReg <= $raioReg) {
            $totalRegiao++;
        }
    }

    usort($resultado, function ($a, $b) use ($ordenar) {
        if ($ordenar === 'preco') {
            return ($b['economia_vs_segmento_pct'] <=> $a['economia_vs_segmento_pct'])
                ?: (($a['preco_medio'] > 0 ? $a['preco_medio'] : 9999) <=> ($b['preco_medio'] > 0 ? $b['preco_medio'] : 9999));
        }
        if ($ordenar === 'tempo') {
            return ($a['tempo_medio_min'] <=> $b['tempo_medio_min']) ?: ($b['score_territorio'] <=> $a['score_territorio']);
        }
        if ($ordenar === 'nota') {
            return ($b['nota_media'] <=> $a['nota_media']) ?: ($b['total_avaliacoes'] <=> $a['total_avaliacoes']);
        }
        if ($ordenar === 'taxa') {
            return ($a['taxa_entrega_base'] <=> $b['taxa_entrega_base']) ?: ($b['score_territorio'] <=> $a['score_territorio']);
        }
        if ($ordenar === 'distancia' && $a['distancia_km'] !== null && $b['distancia_km'] !== null) {
            return $a['distancia_km'] <=> $b['distancia_km'];
        }
        // Padrão: atende região -> destaque -> aberto -> score -> nota
        return ((int) $b['atende_regiao'] <=> (int) $a['atende_regiao'])
            ?: ((int) $b['destaque'] <=> (int) $a['destaque'])
            ?: ((int) $b['aberto'] <=> (int) $a['aberto'])
            ?: ($b['score_territorio'] <=> $a['score_territorio'])
            ?: ($b['nota_media'] <=> $a['nota_media']);
    });

    echo json_encode([
        'success' => true,
        'lojas' => $resultado,
        'total_lojas_regiao' => $totalRegiao,
        'total_lojas_plataforma' => count($todas),
        'comparativo_segmentos' => $comparativoSegmentos,
        'alerta_expansao' => $alertaExpansao,
        'recomendacoes_alternativas' => $alternativas,
        'filtros_disponiveis' => [
            'cidades' => array_keys($cidades),
            'bairros' => array_keys($bairros),
            'nichos' => array_keys($nichos),
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'detalhar_loja') {
    $slug = strtolower(trim((string) ($_GET['slug'] ?? $body['slug'] ?? 'default')));
    $latCli = (float) ($_GET['lat'] ?? $body['lat'] ?? 0);
    $lngCli = (float) ($_GET['lng'] ?? $body['lng'] ?? 0);
    $detalhe = pz_territorio_buscar_loja_detalhe($slug, $latCli, $lngCli);
    if (!$detalhe) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Loja não encontrada.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['success' => true, 'loja' => $detalhe], JSON_UNESCAPED_UNICODE);
    exit;
}

function pz_mkt_chave_hmac()
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
    Database::setTenant($anterior);
    if (is_array($sqlData) && !empty($sqlData['secret'])) {
        return (string) $sqlData['secret'];
    }
    $secretFile = __DIR__ . '/data/.comprador_secret';
    if (is_file($secretFile)) {
        return trim((string) @file_get_contents($secretFile));
    }
    return '';
}

function pz_mkt_validar_token_comprador($token)
{
    $secret = pz_mkt_chave_hmac();
    if ($secret === '' || $token === '') {
        return null;
    }
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
    $esperado = hash_hmac('sha256', $tel . '|' . $exp, $secret);
    return hash_equals($esperado, $sig) ? $tel : null;
}

if ($acao === 'meus_pedidos') {
    $token = (string) ($_GET['token'] ?? $body['token'] ?? '');
    $telToken = pz_mkt_validar_token_comprador($token);
    $telBusca = $telToken ?: preg_replace('/\D/', '', (string) ($_GET['telefone'] ?? $body['telefone'] ?? ''));
    if (strlen($telBusca) >= 12 && strpos($telBusca, '55') === 0) {
        $telBusca = substr($telBusca, 2);
    }
    if (strlen($telBusca) < 10) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Informe seu WhatsApp completo com DDD (10 ou 11 dígitos) para localizar os pedidos.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $codigosLocaisRaw = $_GET['codigos'] ?? $body['codigos'] ?? '';
    $codigosLocais = is_array($codigosLocaisRaw)
        ? $codigosLocaisRaw
        : array_filter(array_map('trim', explode(',', (string) $codigosLocaisRaw)));

    $lojas = pz_territorio_coletar_lojas();
    $anterior = Database::getTenant();
    $encontrados = [];

    foreach ($lojas as $loja) {
        $slug = $loja['slug'];
        Database::setTenant($slug);
        $pedidos = Database::read('pedidos.json');
        $avaliados = [];
        foreach (Database::read('avaliacoes.json') as $av) {
            if (is_array($av) && !empty($av['pedido_id'])) {
                $avaliados[(string) $av['pedido_id']] = (int) ($av['nota'] ?? 5);
            }
        }

        foreach ($pedidos as $p) {
            if (!is_array($p)) {
                continue;
            }
            $telPed = preg_replace('/\D/', '', (string) ($p['celular'] ?? $p['telefone'] ?? $p['whatsapp'] ?? $p['cliente_telefone'] ?? ''));
            if (strlen($telPed) >= 12 && strpos($telPed, '55') === 0) {
                $telPed = substr($telPed, 2);
            }
            if ($telPed === '' || $telPed !== $telBusca) {
                continue;
            }
            $idPed = (string) ($p['id'] ?? '');
            $cod = (string) ($p['codigo'] ?? $p['codigo_acesso'] ?? '');
            $podeRastrear = ($telToken !== null) || ($cod !== '' && in_array($cod, $codigosLocais, true));
            $rastreioUrl = $podeRastrear
                ? ('rastreio.html?id=' . rawurlencode($idPed)
                    . ($cod !== '' ? '&c=' . rawurlencode($cod) : '')
                    . ($slug !== 'default' ? '&tenant=' . rawurlencode($slug) : ''))
                : '';

            $encontrados[] = [
                'id' => $idPed,
                'tenant' => $slug,
                'loja_nome' => $loja['nome'],
                'loja_logo' => $loja['logo_url'],
                'loja_cor' => $loja['cor_primaria'],
                'url_loja' => $loja['url_loja'],
                'url_rastreio' => $rastreioUrl,
                'status' => (string) ($p['status'] ?? 'novo'),
                'cancelado' => !empty($p['cancelado']),
                'subtotal' => (float) ($p['subtotal'] ?? 0),
                'frete' => (float) ($p['frete'] ?? 0),
                'desconto' => (float) ($p['desconto'] ?? 0),
                'total' => (float) ($p['total'] ?? 0),
                'pagamento' => (string) ($p['pagamento'] ?? ''),
                'recebimento' => (string) ($p['recebimento'] ?? $p['tipo_entrega'] ?? ''),
                'entrega_texto' => $podeRastrear ? (string) ($p['entrega'] ?? '') : '',
                'criado_em' => (string) ($p['timestamp'] ?? $p['criado_em'] ?? $p['data'] ?? ''),
                'itens' => array_map(function ($it) {
                    return [
                        'nome' => (string) ($it['nome'] ?? 'Item'),
                        'quantidade' => (int) ($it['quantidade'] ?? 1),
                    ];
                }, (array) ($p['itens'] ?? [])),
                'entregador_nome' => (string) ($p['entregador_nome'] ?? ''),
                'ja_avaliado' => isset($avaliados[$idPed]),
                'nota_dada' => $avaliados[$idPed] ?? null,
            ];
        }
    }

    Database::setTenant($anterior);
    usort($encontrados, function ($a, $b) {
        return strcmp((string) $b['criado_em'], (string) $a['criado_em']);
    });

    echo json_encode([
        'success' => true,
        'autenticado' => $telToken !== null,
        'pedidos' => array_slice($encontrados, 0, 40),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($acao === 'avaliar') {
    $slug = strtolower(trim((string) ($body['tenant'] ?? 'default')));
    $pedidoIdAv = trim((string) ($body['pedido_id'] ?? ''));
    if ($pedidoIdAv === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Informe o pedido para avaliar.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $anteriorAv = Database::getTenant();
    Database::setTenant($slug);
    $pedidoExiste = Database::findOrder($pedidoIdAv);
    Database::setTenant($anteriorAv);
    if (!$pedidoExiste) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Avaliação permitida apenas para pedidos reais da loja.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $res = pz_territorio_registrar_avaliacao($slug, $body);
    if (empty($res['success'])) {
        http_response_code(400);
    }
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Ação desconhecida no Marketplace.'], JSON_UNESCAPED_UNICODE);
