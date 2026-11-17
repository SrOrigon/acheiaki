<?php
/**
 * Motor Algorítmico de Recomendação Inteligente AcheIAki (100% Local, Sem API Externa).
 * - Lê todos os sinais do cliente: buscas, cliques, tempo de atenção (dwell), favoritos,
 *   carrinho, pedidos reais por loja/prato, faixa de preço e hábito por turno do dia.
 * - Entende sinônimos culinários e necessidades ("barato", "rápido", "almoço", "doce", etc.).
 * - Prioriza a loja onde o cliente mais costuma comprar, pratos que já pediu ou pesquisou
 *   e melhores preços na região (respeitando o raio do CNPJ e o consentimento LGPD).
 */
require_once __DIR__ . '/tenant_init.php';
require_once __DIR__ . '/territorio_lib.php';

function pz_alg_limpar_vid($vid)
{
    $v = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $vid);
    if (strlen($v) < 6) {
        return '';
    }
    return substr($v, 0, 64);
}

function pz_alg_turno_atual()
{
    $hora = (int) date('G');
    if ($hora >= 6 && $hora < 11) {
        return 'cafe';
    }
    if ($hora >= 11 && $hora < 15) {
        return 'almoco';
    }
    if ($hora >= 15 && $hora < 18) {
        return 'tarde';
    }
    if ($hora >= 18 && $hora < 23) {
        return 'jantar';
    }
    return 'madrugada';
}

function pz_alg_turno_rotulo($turno)
{
    $rotulos = [
        'cafe' => 'Café da Manhã',
        'almoco' => 'Hora do Almoço',
        'tarde' => 'Lanche da Tarde',
        'jantar' => 'Noite / Jantar',
        'madrugada' => 'Madrugada',
    ];
    return $rotulos[$turno] ?? 'Para Você';
}

function pz_alg_afinidade_turno($nicho, $turno)
{
    $n = pz_territorio_norm($nicho);
    $mapa = [
        'cafe' => ['confeitaria' => 1.0, 'acai' => 0.4, 'lanchonete' => 0.5, 'pensao' => 0.3, 'marmitaria' => 0.3, 'pizzaria' => 0.1],
        'almoco' => ['pensao' => 1.0, 'marmitaria' => 1.0, 'lanchonete' => 0.5, 'acai' => 0.4, 'confeitaria' => 0.3, 'pizzaria' => 0.3],
        'tarde' => ['acai' => 1.0, 'confeitaria' => 1.0, 'lanchonete' => 0.7, 'marmitaria' => 0.3, 'pensao' => 0.3, 'pizzaria' => 0.4],
        'jantar' => ['lanchonete' => 1.0, 'pizzaria' => 1.0, 'acai' => 0.6, 'marmitaria' => 0.5, 'pensao' => 0.4, 'confeitaria' => 0.4],
        'madrugada' => ['lanchonete' => 1.0, 'pizzaria' => 0.95, 'acai' => 0.5, 'confeitaria' => 0.2, 'marmitaria' => 0.2, 'pensao' => 0.1],
    ];
    return $mapa[$turno][$n] ?? 0.5;
}

function pz_alg_extrair_tags($texto)
{
    $n = pz_territorio_norm($texto);
    $palavras = preg_split('/[^a-z0-9]+/', $n, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $stop = ['com', 'para', 'sem', 'uma', 'uns', 'das', 'dos', 'que', 'por', 'mais', 'seu', 'sua', 'delicioso', 'especial', 'tradicional', 'unidades', 'quero', 'onde', 'tem', 'aqui', 'perto'];
    $tags = [];
    foreach ($palavras as $p) {
        if (strlen($p) >= 3 && !in_array($p, $stop, true)) {
            $tags[$p] = true;
        }
    }
    return array_keys($tags);
}

/**
 * Expande buscas e necessidades em português para tags culinárias, nichos e intenções (economia/rapidez).
 */
function pz_alg_expandir_intencao($texto)
{
    $n = pz_territorio_norm($texto);
    $tagsBase = pz_alg_extrair_tags($n);
    $extraTags = [];
    $nichosInferidos = [];
    $economia = false;
    $rapidez = false;

    $sinonimos = [
        'economia' => ['barato', 'barata', 'economico', 'economica', 'oferta', 'promocao', 'desconto', 'cupom', 'gratis', 'menor preco', 'em conta', 'combo'],
        'rapidez' => ['rapido', 'rapida', 'pressa', 'agora', 'fome', 'imediato', 'urgente', 'chega logo'],
        'lanchonete' => ['lanche', 'burger', 'hamburguer', 'xbacon', 'xsalada', 'xtudo', 'xegg', 'podrao', 'artesanal', 'batata', 'fritas', 'hotdog', 'cachorro', 'pastel'],
        'marmitaria' => ['marmita', 'marmitex', 'quentinha', 'executivo', 'fitness', 'fit', 'salada', 'grelhado', 'frango'],
        'pensao' => ['almoco', 'comida', 'caseira', 'feijoada', 'bife', 'arroz', 'feijao', 'parmegiana', 'caldo', 'sopa', 'prato'],
        'pizzaria' => ['pizza', 'calabresa', 'mussarela', 'portuguesa', 'catupiry', 'borda', 'broto', 'esfiha', 'forno'],
        'acai' => ['acai', 'tigela', 'barca', 'sorvete', 'gelado', 'calor', 'nutella', 'ninho', 'creme'],
        'confeitaria' => ['doce', 'sobremesa', 'bolo', 'torta', 'chocolate', 'brigadeiro', 'pudim', 'cafe', 'cappuccino', 'brownie'],
    ];

    foreach ($sinonimos as $grupo => $termos) {
        foreach ($termos as $t) {
            if (strpos($n, $t) !== false) {
                if ($grupo === 'economia') {
                    $economia = true;
                } elseif ($grupo === 'rapidez') {
                    $rapidez = true;
                } else {
                    $nichosInferidos[$grupo] = true;
                    foreach (array_slice($termos, 0, 4) as $sin) {
                        $extraTags[$sin] = true;
                    }
                }
            }
        }
    }

    foreach ($tagsBase as $t) {
        $extraTags[$t] = true;
    }

    return [
        'tags' => array_keys($extraTags),
        'nichos' => array_keys($nichosInferidos),
        'economia' => $economia,
        'rapidez' => $rapidez,
    ];
}

function pz_alg_aplicar_decaimento(array &$mapa, $fator)
{
    foreach ($mapa as $k => $v) {
        $novo = round(((float) $v) * $fator, 3);
        if (abs($novo) < 0.05) {
            unset($mapa[$k]);
        } else {
            $mapa[$k] = $novo;
        }
    }
}

function pz_alg_registrar_evento(array $ev)
{
    $vid = pz_alg_limpar_vid($ev['visitor_id'] ?? '');
    if ($vid === '') {
        return ['success' => false, 'message' => 'Identificador de sessão ausente.'];
    }

    $pesosEvento = [
        'view_loja' => 1.0,
        'dwell_loja' => 2.2,
        'view_produto' => 1.5,
        'click_produto' => 3.2,
        'busca' => 4.5,
        'favorito' => 7.0,
        'add_carrinho' => 6.5,
        'pedido_concluido' => 14.0,
        'avaliacao_positiva' => 9.0,
        'avaliacao_negativa' => -8.0,
    ];
    $tipo = (string) ($ev['evento'] ?? 'view_loja');
    $peso = $pesosEvento[$tipo] ?? 1.0;

    $dwellMs = max(0, min(120000, (int) ($ev['dwell_ms'] ?? 0)));
    if ($tipo === 'dwell_loja' && $dwellMs > 0) {
        $peso = min(5.5, 1.0 + ($dwellMs / 5500));
    }

    $tenant = strtolower(trim((string) ($ev['tenant'] ?? '')));
    $nicho = pz_territorio_norm($ev['nicho'] ?? '');
    $categoria = pz_territorio_norm($ev['categoria'] ?? '');
    $prodNome = trim((string) ($ev['produto_nome'] ?? ''));
    $termo = trim((string) ($ev['termo'] ?? ''));
    $preco = (float) ($ev['preco'] ?? 0);
    $lat = (float) ($ev['lat'] ?? 0);
    $lng = (float) ($ev['lng'] ?? 0);
    $cidade = trim((string) ($ev['cidade'] ?? ''));
    $bairro = trim((string) ($ev['bairro'] ?? ''));
    $intencao = pz_alg_expandir_intencao($prodNome . ' ' . $termo . ' ' . $categoria . ' ' . $nicho);
    $tags = $intencao['tags'];

    $anterior = Database::getTenant();
    Database::setTenant('default');

    $perfilFinal = null;
    Database::mutate('algoritmo_perfis.json', function (&$perfis) use ($vid, $peso, $tipo, $tenant, $nicho, $categoria, $tags, $intencao, $prodNome, $termo, $preco, $lat, $lng, $cidade, $bairro, &$perfilFinal) {
        if (!is_array($perfis)) {
            $perfis = [];
        }
        $p = is_array($perfis[$vid] ?? null) ? $perfis[$vid] : [
            'visitor_id' => $vid,
            'eventos_count' => 0,
            'nichos' => [],
            'categorias' => [],
            'tags' => [],
            'lojas' => [],
            'compras_por_loja' => [],
            'pratos_comprados' => [],
            'buscas' => [],
            'turnos_nichos' => [],
            'pref_economia' => 0.0,
            'pref_rapidez' => 0.0,
            'ticket_soma' => 0.0,
            'ticket_qtd' => 0,
            'turnos' => [],
            'geo' => ['lat' => 0, 'lng' => 0, 'cidade' => '', 'bairro' => ''],
            'ultima_atualizacao' => date('Y-m-d H:i:s'),
        ];

        // Garante compatibilidade com perfis antigos
        foreach (['compras_por_loja', 'pratos_comprados', 'buscas', 'turnos_nichos'] as $chaveArr) {
            if (!is_array($p[$chaveArr] ?? null)) {
                $p[$chaveArr] = [];
            }
        }
        $p['pref_economia'] = (float) ($p['pref_economia'] ?? 0.0);
        $p['pref_rapidez'] = (float) ($p['pref_rapidez'] ?? 0.0);

        // Decaimento exponencial diário para priorizar interesses atuais sem apagar compras consolidadas
        $ult = strtotime((string) ($p['ultima_atualizacao'] ?? ''));
        if ($ult && time() > $ult) {
            $dias = (time() - $ult) / 86400;
            if ($dias >= 0.25) {
                $fator = pow(0.94, min(30, $dias));
                pz_alg_aplicar_decaimento($p['nichos'], $fator);
                pz_alg_aplicar_decaimento($p['categorias'], $fator);
                pz_alg_aplicar_decaimento($p['tags'], $fator);
                pz_alg_aplicar_decaimento($p['lojas'], $fator);
                $p['pref_economia'] = round($p['pref_economia'] * $fator, 2);
                $p['pref_rapidez'] = round($p['pref_rapidez'] * $fator, 2);
            }
        }

        $p['eventos_count'] = ((int) $p['eventos_count']) + 1;
        $turno = pz_alg_turno_atual();

        if ($nicho !== '') {
            $p['nichos'][$nicho] = round(((float) ($p['nichos'][$nicho] ?? 0)) + $peso, 2);
            if (!is_array($p['turnos_nichos'][$turno] ?? null)) {
                $p['turnos_nichos'][$turno] = [];
            }
            $p['turnos_nichos'][$turno][$nicho] = round(((float) ($p['turnos_nichos'][$turno][$nicho] ?? 0)) + $peso, 2);
        }
        foreach ($intencao['nichos'] as $nInf) {
            if ($nInf !== $nicho) {
                $p['nichos'][$nInf] = round(((float) ($p['nichos'][$nInf] ?? 0)) + ($peso * 0.45), 2);
            }
        }
        if ($categoria !== '') {
            $p['categorias'][$categoria] = round(((float) ($p['categorias'][$categoria] ?? 0)) + $peso, 2);
        }
        if ($tenant !== '') {
            $p['lojas'][$tenant] = round(((float) ($p['lojas'][$tenant] ?? 0)) + ($peso * 0.9), 2);
            if ($tipo === 'pedido_concluido') {
                $p['compras_por_loja'][$tenant] = ((int) ($p['compras_por_loja'][$tenant] ?? 0)) + 1;
            }
        }
        if ($tipo === 'pedido_concluido' && $prodNome !== '') {
            $itensNomes = array_filter(array_map('trim', explode('|', $prodNome)));
            if (count($itensNomes) <= 1) {
                $itensNomes = [trim($prodNome)];
            }
            foreach ($itensNomes as $in) {
                $chavePrato = pz_territorio_norm(mb_substr($in, 0, 70, 'UTF-8'));
                if ($chavePrato !== '') {
                    $p['pratos_comprados'][$chavePrato] = ((int) ($p['pratos_comprados'][$chavePrato] ?? 0)) + 1;
                }
            }
        }
        if ($tipo === 'busca' && mb_strlen($termo, 'UTF-8') >= 2) {
            $termoLimpo = mb_strtolower(mb_substr(trim($termo), 0, 45, 'UTF-8'), 'UTF-8');
            $atualB = is_array($p['buscas'][$termoLimpo] ?? null) ? $p['buscas'][$termoLimpo] : ['count' => 0, 'ultimo_em' => ''];
            $p['buscas'][$termoLimpo] = [
                'count' => ((int) ($atualB['count'] ?? 0)) + 1,
                'ultimo_em' => date('Y-m-d H:i:s'),
            ];
            if (count($p['buscas']) > 25) {
                uasort($p['buscas'], function ($a, $b) {
                    return ($b['count'] <=> $a['count']) ?: strcmp((string) $b['ultimo_em'], (string) $a['ultimo_em']);
                });
                $p['buscas'] = array_slice($p['buscas'], 0, 20, true);
            }
        }
        if (!empty($intencao['economia'])) {
            $p['pref_economia'] = round(min(30.0, $p['pref_economia'] + 2.5), 2);
        }
        if (!empty($intencao['rapidez'])) {
            $p['pref_rapidez'] = round(min(30.0, $p['pref_rapidez'] + 2.5), 2);
        }
        foreach (array_slice($tags, 0, 10) as $tg) {
            $p['tags'][$tg] = round(((float) ($p['tags'][$tg] ?? 0)) + ($peso * 0.9), 2);
        }
        if ($preco > 0 && $peso > 0) {
            $p['ticket_soma'] = round(((float) $p['ticket_soma']) + $preco, 2);
            $p['ticket_qtd'] = ((int) $p['ticket_qtd']) + 1;
        }
        $p['turnos'][$turno] = ((int) ($p['turnos'][$turno] ?? 0)) + 1;
        if ($lat !== 0.0 && $lng !== 0.0) {
            $p['geo'] = [
                'lat' => $lat,
                'lng' => $lng,
                'cidade' => $cidade,
                'bairro' => $bairro,
            ];
        }
        $p['ultima_atualizacao'] = date('Y-m-d H:i:s');

        foreach (['nichos', 'categorias', 'tags', 'lojas', 'compras_por_loja', 'pratos_comprados'] as $campo) {
            arsort($p[$campo]);
            if (count($p[$campo]) > 40) {
                $p[$campo] = array_slice($p[$campo], 0, 40, true);
            }
        }
        $perfis[$vid] = $p;
        if (count($perfis) > 2000) {
            uasort($perfis, function ($a, $b) {
                return strcmp((string) ($b['ultima_atualizacao'] ?? ''), (string) ($a['ultima_atualizacao'] ?? ''));
            });
            $perfis = array_slice($perfis, 0, 1500, true);
        }
        $perfilFinal = $p;
        return true;
    });

    // Atualiza estatísticas agregadas da loja para feedback ao vendedor e taxa de conversão no algoritmo
    if ($tenant !== '') {
        Database::mutate('algoritmo_stats_lojas.json', function (&$stats) use ($tenant, $tipo, $dwellMs, $prodNome) {
            if (!is_array($stats)) {
                $stats = [];
            }
            $s = is_array($stats[$tenant] ?? null) ? $stats[$tenant] : [
                'impressoes' => 0,
                'cliques' => 0,
                'add_carrinho' => 0,
                'pedidos' => 0,
                'dwell_total_ms' => 0,
                'dwell_eventos' => 0,
                'pratos' => [],
            ];
            if ($tipo === 'view_loja' || $tipo === 'view_produto') {
                $s['impressoes']++;
            } elseif ($tipo === 'click_produto') {
                $s['cliques']++;
            } elseif ($tipo === 'add_carrinho') {
                $s['add_carrinho']++;
            } elseif ($tipo === 'pedido_concluido') {
                $s['pedidos']++;
            } elseif ($tipo === 'dwell_loja' && $dwellMs > 0) {
                $s['dwell_total_ms'] += $dwellMs;
                $s['dwell_eventos']++;
            }
            if ($prodNome !== '') {
                $pk = mb_substr($prodNome, 0, 60, 'UTF-8');
                $pr = is_array($s['pratos'][$pk] ?? null) ? $s['pratos'][$pk] : ['cliques' => 0, 'carrinho' => 0];
                if ($tipo === 'click_produto' || $tipo === 'view_produto') {
                    $pr['cliques']++;
                } elseif ($tipo === 'add_carrinho' || $tipo === 'pedido_concluido') {
                    $pr['carrinho']++;
                }
                $s['pratos'][$pk] = $pr;
                if (count($s['pratos']) > 60) {
                    $s['pratos'] = array_slice($s['pratos'], 0, 60, true);
                }
            }
            $stats[$tenant] = $s;
            return true;
        });
    }

    Database::setTenant($anterior);
    return ['success' => true, 'eventos_count' => $perfilFinal['eventos_count'] ?? 1];
}

/**
 * Lê o histórico real de pedidos e favoritos do comprador (por telefone) em todas as lojas,
 * garantindo que a IA reconheça onde ele costuma comprar mesmo em outro navegador/dispositivo.
 */
function pz_alg_historico_comprador($telefone, array $todasLojas)
{
    $telBusca = preg_replace('/\D/', '', (string) $telefone);
    if (strlen($telBusca) >= 12 && strpos($telBusca, '55') === 0) {
        $telBusca = substr($telBusca, 2);
    }
    $res = [
        'compras_por_loja' => [],
        'pratos_comprados' => [],
        'favoritos' => [],
        'ticket_soma' => 0.0,
        'ticket_qtd' => 0,
    ];
    if (strlen($telBusca) < 10) {
        return $res;
    }

    $anterior = Database::getTenant();
    Database::setTenant('default');
    foreach (Database::read('compradores_mkt.json') as $c) {
        if (is_array($c) && ($c['telefone'] ?? '') === $telBusca) {
            $res['favoritos'] = array_values(array_filter((array) ($c['favoritos'] ?? [])));
            break;
        }
    }

    foreach ($todasLojas as $loja) {
        $slug = (string) ($loja['slug'] ?? '');
        if ($slug === '') {
            continue;
        }
        Database::setTenant($slug);
        foreach (Database::read('pedidos.json') as $p) {
            if (!is_array($p) || !empty($p['cancelado'])) {
                continue;
            }
            $telPed = preg_replace('/\D/', '', (string) ($p['celular'] ?? $p['telefone'] ?? $p['whatsapp'] ?? $p['cliente_telefone'] ?? ''));
            if (strlen($telPed) >= 12 && strpos($telPed, '55') === 0) {
                $telPed = substr($telPed, 2);
            }
            if ($telPed !== $telBusca) {
                continue;
            }
            $res['compras_por_loja'][$slug] = ((int) ($res['compras_por_loja'][$slug] ?? 0)) + 1;
            $tot = (float) ($p['total'] ?? 0);
            if ($tot > 0) {
                $res['ticket_soma'] += $tot;
                $res['ticket_qtd']++;
            }
            foreach ((array) ($p['itens'] ?? []) as $it) {
                $nomePr = pz_territorio_norm(mb_substr((string) ($it['nome'] ?? ''), 0, 70, 'UTF-8'));
                if ($nomePr !== '') {
                    $res['pratos_comprados'][$nomePr] = ((int) ($res['pratos_comprados'][$nomePr] ?? 0)) + max(1, (int) ($it['quantidade'] ?? 1));
                }
            }
        }
    }
    Database::setTenant($anterior);
    return $res;
}

function pz_alg_recomendar(array $ctx)
{
    $lat = (float) ($ctx['lat'] ?? 0);
    $lng = (float) ($ctx['lng'] ?? 0);
    $cidadeCtx = trim((string) ($ctx['cidade'] ?? ''));
    $nichoFiltro = pz_territorio_norm($ctx['nicho'] ?? '');
    $vid = pz_alg_limpar_vid($ctx['visitor_id'] ?? '');
    $telCliente = (string) ($ctx['telefone'] ?? '');

    if (($lat === 0.0 || $lng === 0.0) && $cidadeCtx === '') {
        return [
            'success' => false,
            'localizacao_obrigatoria' => true,
            'message' => 'Ative sua localização GPS ou informe seu endereço/CEP para liberar as lojas e recomendações.',
        ];
    }

    $anterior = Database::getTenant();
    Database::setTenant('default');
    $perfis = Database::read('algoritmo_perfis.json');
    $statsLojas = Database::read('algoritmo_stats_lojas.json');
    Database::setTenant($anterior);

    $perfil = ($vid !== '' && is_array($perfis[$vid] ?? null)) ? $perfis[$vid] : [
        'eventos_count' => 0,
        'nichos' => [],
        'categorias' => [],
        'tags' => [],
        'lojas' => [],
        'compras_por_loja' => [],
        'pratos_comprados' => [],
        'buscas' => [],
        'turnos_nichos' => [],
        'pref_economia' => 0.0,
        'pref_rapidez' => 0.0,
        'ticket_soma' => 0.0,
        'ticket_qtd' => 0,
    ];

    $todas = pz_territorio_coletar_lojas();
    $histReal = pz_alg_historico_comprador($telCliente, $todas);

    // Funde histórico real de pedidos (por WhatsApp) com sinais comportamentais da sessão
    $comprasLoja = is_array($perfil['compras_por_loja'] ?? null) ? $perfil['compras_por_loja'] : [];
    foreach ($histReal['compras_por_loja'] as $slugH => $qtdH) {
        $comprasLoja[$slugH] = max((int) ($comprasLoja[$slugH] ?? 0), (int) $qtdH);
    }
    $pratosComprados = is_array($perfil['pratos_comprados'] ?? null) ? $perfil['pratos_comprados'] : [];
    foreach ($histReal['pratos_comprados'] as $prH => $qtdPr) {
        $pratosComprados[$prH] = max((int) ($pratosComprados[$prH] ?? 0), (int) $qtdPr);
    }
    $favoritosCli = $histReal['favoritos'];

    $turno = pz_alg_turno_atual();
    $turnoRotulo = pz_alg_turno_rotulo($turno);

    $ticketSoma = (float) ($perfil['ticket_soma'] ?? 0) + (float) $histReal['ticket_soma'];
    $ticketQtd = (int) ($perfil['ticket_qtd'] ?? 0) + (int) $histReal['ticket_qtd'];
    $ticketAlvo = $ticketQtd > 0 ? ($ticketSoma / $ticketQtd) : 0.0;

    $buscasMapa = is_array($perfil['buscas'] ?? null) ? $perfil['buscas'] : [];
    uasort($buscasMapa, function ($a, $b) {
        return (((int) ($b['count'] ?? 0)) <=> ((int) ($a['count'] ?? 0)))
            ?: strcmp((string) ($b['ultimo_em'] ?? ''), (string) ($a['ultimo_em'] ?? ''));
    });
    $buscasFrequentes = array_slice(array_keys($buscasMapa), 0, 5);
    $tagsDeBuscas = [];
    foreach ($buscasFrequentes as $bf) {
        foreach (pz_alg_expandir_intencao($bf)['tags'] as $tgB) {
            $tagsDeBuscas[$tgB] = true;
        }
    }

    $maxNicho = !empty($perfil['nichos']) ? max(array_map('abs', $perfil['nichos'])) : 1.0;
    $maxCat = !empty($perfil['categorias']) ? max(array_map('abs', $perfil['categorias'])) : 1.0;
    $maxTag = !empty($perfil['tags']) ? max(array_map('abs', $perfil['tags'])) : 1.0;
    $maxLoja = !empty($perfil['lojas']) ? max(array_map('abs', $perfil['lojas'])) : 1.0;
    $maxCompras = !empty($comprasLoja) ? max($comprasLoja) : 1;

    $comparativoSegmentos = pz_territorio_comparativo_segmentos($todas);
    $lojasRankeadas = [];
    $pratosCandidatos = [];

    $prefEconomia = (float) ($perfil['pref_economia'] ?? 0.0);
    $prefRapidez = (float) ($perfil['pref_rapidez'] ?? 0.0);

    foreach ($todas as $loja) {
        $slug = (string) $loja['slug'];
        $dist = ($lat !== 0.0 && $lng !== 0.0 && !empty($loja['lat']) && !empty($loja['lng']))
            ? pz_territorio_distancia_km($lat, $lng, $loja['lat'], $loja['lng'])
            : null;
        $raioLoja = max(1.0, (float) ($loja['raio_entrega_km'] ?? 10));
        $foraRaio = ($dist !== null && $dist > $raioLoja);
        $entregaEstendida = ($dist !== null && $dist > 15.0 && !$foraRaio);

        $cidadeBate = $cidadeCtx !== '' && pz_territorio_norm($loja['cidade']) !== ''
            && strpos(pz_territorio_norm($loja['cidade']), pz_territorio_norm($cidadeCtx)) !== false;
        $atendeRegiao = ($dist === null) ? ($cidadeBate || $cidadeCtx === '') : !$foraRaio;

        // 1. Score Geográfico (0 a 18 pts)
        if ($dist !== null) {
            $escalaGeo = max(6.0, min(55.0, $raioLoja * 0.45));
            $scoreProx = 18.0 * exp(-$dist / $escalaGeo);
            if ($foraRaio) {
                $scoreProx *= 0.15;
            }
        } else {
            $scoreProx = $cidadeBate ? 14.0 : 7.0;
        }
        $bonusRapidez = ($loja['tempo_medio_min'] <= 32 ? 2.5 : 0.0) * ($prefRapidez > 2.0 ? 1.8 : 1.0);
        $scoreProx = min(20.0, $scoreProx + $bonusRapidez + ($loja['taxa_entrega_base'] <= 0 ? 2.0 : 0.0));

        // 2. Score Preço Competitivo (0 a 12 pts, reforçado quando cliente busca economia)
        $economiaPct = (int) ($loja['economia_vs_segmento_pct'] ?? 0);
        $melhorPrecoSeg = !empty($loja['melhor_preco_segmento']);
        $multEco = $prefEconomia > 2.0 ? 1.45 : 1.0;
        $scorePreco = max(0.0, min(12.0, (4.5 + ($economiaPct * 0.25) + ($melhorPrecoSeg ? 2.5 : 0.0)) * $multEco));

        // 3. Score Qualidade & Conversão Real (0 a 15 pts)
        $st = is_array($statsLojas[$slug] ?? null) ? $statsLojas[$slug] : [];
        $taxaConv = (($st['impressoes'] ?? 0) >= 5) ? min(1.0, ((($st['add_carrinho'] ?? 0) + ($st['pedidos'] ?? 0) * 2) / $st['impressoes']) * 2.0) : 0.4;
        $scoreQual = (($loja['score_territorio'] / 100.0) * 10.0) + ($taxaConv * 5.0);

        // 4. Score Horário + Hábito Pessoal no Turno (0 a 12 pts)
        $nichoNorm = pz_territorio_norm($loja['nicho']);
        $habitoTurnoPessoal = (float) (($perfil['turnos_nichos'][$turno][$nichoNorm] ?? 0));
        $scoreTurno = (pz_alg_afinidade_turno($loja['nicho'], $turno) * 8.0) + min(4.0, $habitoTurnoPessoal * 0.5);

        // 5. Hábito Real de Compra na Loja & Favorito (0 a 24 pts) — prioridade no que o cliente costuma comprar
        $qtdComprasLoja = (int) ($comprasLoja[$slug] ?? 0);
        $ehFavorita = in_array($slug, $favoritosCli, true);
        $scoreHabitoCompra = 0.0;
        if ($qtdComprasLoja > 0) {
            $scoreHabitoCompra = min(22.0, 12.0 + ($qtdComprasLoja / max(1, $maxCompras)) * 10.0);
        }
        if ($ehFavorita) {
            $scoreHabitoCompra = min(24.0, $scoreHabitoCompra + 6.0);
        }
        $ehLojaHabitual = ($qtdComprasLoja >= 1) || ($ehFavorita && ((float) ($perfil['lojas'][$slug] ?? 0)) >= 4.0);

        // 6. Afinidade por Interesses e Buscas Recentes na Loja (0 a 19 pts)
        $afNicho = max(0.0, ((float) ($perfil['nichos'][$nichoNorm] ?? 0)) / max(1.0, $maxNicho));
        $afLoja = max(0.0, ((float) ($perfil['lojas'][$slug] ?? 0)) / max(1.0, $maxLoja));
        $scoreBuscasLoja = 0.0;
        $termoBateuLoja = '';
        if (!empty($buscasFrequentes)) {
            $textoLoja = pz_territorio_norm($loja['nome'] . ' ' . $loja['nicho'] . ' ' . implode(' ', array_map(function ($x) {
                return (string) ($x['nome'] ?? '') . ' ' . ($x['categoria'] ?? '');
            }, $loja['itens'])));
            foreach ($buscasFrequentes as $bf) {
                if (strpos($textoLoja, pz_territorio_norm($bf)) !== false) {
                    $scoreBuscasLoja += 6.0;
                    if ($termoBateuLoja === '') {
                        $termoBateuLoja = $bf;
                    }
                }
            }
            $scoreBuscasLoja = min(10.0, $scoreBuscasLoja);
        }
        $scoreAfLoja = (($perfil['eventos_count'] ?? 0) > 0 || $qtdComprasLoja > 0)
            ? min(21.0, ($afNicho * 9.0) + ($afLoja * 6.0) + $scoreBuscasLoja)
            : 11.0;

        // 7. Descoberta controlada (0 a 5 pts)
        $seed = abs(crc32(($vid ?: 'anon') . date('Y-m-d') . $slug)) % 100;
        $scoreExpl = empty($perfil['lojas'][$slug]) && $qtdComprasLoja === 0 ? (2.0 + ($seed / 100.0) * 3.0) : 1.0;

        $matchLoja = (int) round(min(99, max(15, $scoreProx + $scorePreco + $scoreQual + $scoreTurno + $scoreHabitoCompra + $scoreAfLoja + $scoreExpl)));
        if (!$loja['aberto']) {
            $matchLoja = max(10, $matchLoja - 28);
        }
        if ($foraRaio) {
            $matchLoja = max(8, $matchLoja - 25);
        }

        $motivosLoja = [];
        if ($qtdComprasLoja >= 2) {
            $motivosLoja[] = 'Sua loja mais pedida (' . $qtdComprasLoja . ' pedidos)';
        } elseif ($qtdComprasLoja === 1) {
            $motivosLoja[] = 'Você já pediu aqui — peça de novo';
        } elseif ($ehFavorita) {
            $motivosLoja[] = 'Nos seus favoritos';
        }
        if ($termoBateuLoja !== '') {
            $motivosLoja[] = 'Combina com sua busca "' . $termoBateuLoja . '"';
        }
        if ($melhorPrecoSeg) {
            $motivosLoja[] = 'Melhor preço em ' . $loja['nicho'];
        } elseif ($economiaPct >= 8) {
            $motivosLoja[] = $economiaPct . '% mais barato que a média em ' . $loja['nicho'];
        }
        if ($entregaEstendida) {
            $motivosLoja[] = 'Entrega até ' . $raioLoja . ' km (você está a ' . $dist . ' km)';
        } elseif ($dist !== null && $dist <= 3.5) {
            $motivosLoja[] = 'Pertinho de você (' . $dist . ' km)';
        } elseif ($cidadeBate) {
            $motivosLoja[] = 'Na sua cidade';
        }
        if ($afNicho >= 0.45 && count($motivosLoja) < 2) {
            $motivosLoja[] = 'Combina com seus gostos em ' . $loja['nicho'];
        }
        if ($loja['taxa_entrega_base'] <= 0 && count($motivosLoja) < 2) {
            $motivosLoja[] = 'Entrega grátis';
        }
        if (empty($motivosLoja)) {
            $motivosLoja[] = 'Recomendado para você agora';
        }

        $lojasRankeadas[] = [
            'slug' => $slug,
            'nome' => $loja['nome'],
            'slogan' => $loja['slogan'] ?? '',
            'cnpj_verificado' => !empty($loja['cnpj_verificado']),
            'nicho' => $loja['nicho'],
            'cidade' => $loja['cidade'],
            'bairro' => $loja['bairro'],
            'estado' => $loja['estado'] ?? '',
            'aberto' => $loja['aberto'],
            'cor_primaria' => $loja['cor_primaria'],
            'logo_url' => $loja['logo_url'],
            'hero_url' => $loja['hero_url'] ?? 'assets/images/placeholder-hero.svg',
            'nota_media' => $loja['nota_media'],
            'total_avaliacoes' => $loja['total_avaliacoes'],
            'tempo_medio_min' => $loja['tempo_medio_min'],
            'taxa_entrega_base' => $loja['taxa_entrega_base'],
            'raio_entrega_km' => $raioLoja,
            'preco_medio' => $loja['preco_medio'],
            'media_preco_segmento' => $loja['media_preco_segmento'] ?? 0,
            'economia_vs_segmento_pct' => $economiaPct,
            'melhor_preco_segmento' => $melhorPrecoSeg,
            'score_territorio' => $loja['score_territorio'],
            'localizacao_ativa' => !empty($loja['localizacao_ativa']),
            'distancia_km' => $dist,
            'fora_raio' => $foraRaio,
            'atende_regiao' => $atendeRegiao,
            'entrega_estendida' => $entregaEstendida,
            'compras_cliente' => $qtdComprasLoja,
            'eh_loja_habitual' => $ehLojaHabitual,
            'eh_favorita' => $ehFavorita,
            'match_pct' => $matchLoja,
            'motivo_algoritmo' => implode(' · ', array_slice($motivosLoja, 0, 2)),
            'url_loja' => $loja['url_loja'],
            'itens_preview' => array_slice($loja['itens'], 0, 4),
        ];

        // Avalia cada prato individualmente cruzando pedidos anteriores, buscas, tags, preço e loja habitual
        $mediaSeg = (float) ($loja['media_preco_segmento'] ?? 0);
        foreach ($loja['itens'] as $it) {
            $nomeItNorm = pz_territorio_norm($it['nome']);
            $qtdItemPedido = (int) ($pratosComprados[$nomeItNorm] ?? 0);
            $tagsPrato = pz_alg_extrair_tags($it['nome'] . ' ' . $it['descricao'] . ' ' . $it['categoria']);
            $afTags = 0.0;
            $tagMatch = '';
            $buscaMatch = '';
            $melhorTagScore = 0.0;
            foreach ($tagsPrato as $tg) {
                $valTag = (float) ($perfil['tags'][$tg] ?? 0);
                if ($valTag > 0) {
                    $afTags += $valTag / max(1.0, $maxTag);
                    if ($valTag > $melhorTagScore) {
                        $melhorTagScore = $valTag;
                        $tagMatch = $tg;
                    }
                }
                if (isset($tagsDeBuscas[$tg]) && $buscaMatch === '') {
                    $buscaMatch = $tg;
                }
            }
            $afCat = max(0.0, ((float) ($perfil['categorias'][pz_territorio_norm($it['categoria'])] ?? 0)) / max(1.0, $maxCat));
            $bonusPreco = 0.0;
            if ($ticketAlvo > 0 && $it['preco'] > 0) {
                $diff = abs($it['preco'] - $ticketAlvo) / max(1.0, $ticketAlvo);
                $bonusPreco = max(0.0, 5.0 * (1.0 - min(1.0, $diff)));
            }
            if ($mediaSeg > 0 && $it['preco'] > 0 && $it['preco'] < $mediaSeg) {
                $bonusPreco += min(7.0, (($mediaSeg - $it['preco']) / $mediaSeg) * 10.0) * ($prefEconomia > 2.0 ? 1.4 : 1.0);
            }
            $bonusRecompra = $qtdItemPedido > 0 ? min(18.0, 10.0 + ($qtdItemPedido * 3.0)) : ($qtdComprasLoja > 0 ? 6.0 : 0.0);
            $bonusBusca = $buscaMatch !== '' ? 8.0 : 0.0;
            $scoreFotoDesc = ($it['tem_foto'] ? 5.0 : 0.0) + ($it['tem_desc'] ? 2.5 : 0.0);
            $scoreAfItem = (($perfil['eventos_count'] ?? 0) > 0 || $qtdComprasLoja > 0)
                ? min(40.0, ($afNicho * 10.0) + (min(1.0, $afTags) * 12.0) + ($afCat * 5.0) + $bonusPreco + $bonusRecompra + $bonusBusca)
                : (15.0 + $scoreFotoDesc + min(5.0, $bonusPreco));

            $matchItem = (int) round(min(99, max(18, $scoreAfItem + $scoreTurno + $scoreProx + ($scoreQual * 0.6) + $scoreFotoDesc)));
            if (!$loja['aberto'] || $foraRaio) {
                $matchItem = max(12, $matchItem - 28);
            }

            if ($qtdItemPedido > 0) {
                $motivoItem = 'Você já pediu (' . $qtdItemPedido . 'x) · Peça de novo';
            } elseif ($buscaMatch !== '') {
                $motivoItem = 'Baseado na sua busca "' . $buscaMatch . '"';
            } elseif ($qtdComprasLoja > 0) {
                $motivoItem = 'Da sua loja preferida (' . $loja['nome'] . ')';
            } elseif ($tagMatch !== '') {
                $motivoItem = 'Porque você curte "' . $tagMatch . '"';
            } elseif ($economiaPct >= 8) {
                $motivoItem = $economiaPct . '% abaixo da média em ' . $loja['nicho'];
            } else {
                $motivoItem = $afNicho >= 0.4 ? ('Seu estilo: ' . $loja['nicho']) : 'Ótimo custo-benefício perto de você';
            }
            if ($entregaEstendida) {
                $motivoItem .= ' · Entrega até ' . $raioLoja . ' km';
            } elseif ($dist !== null) {
                $motivoItem .= ' · ' . $dist . ' km';
            }

            $pratosCandidatos[] = [
                'id' => $it['id'],
                'nome' => $it['nome'],
                'descricao' => $it['descricao'],
                'preco' => $it['preco'],
                'categoria' => $it['categoria'],
                'nicho' => $loja['nicho'],
                'imagem' => $it['imagem'] ?: 'assets/images/placeholder-logo.svg',
                'tenant' => $slug,
                'loja_nome' => $loja['nome'],
                'loja_cor' => $loja['cor_primaria'],
                'tempo_medio_min' => $loja['tempo_medio_min'],
                'taxa_entrega_base' => $loja['taxa_entrega_base'],
                'raio_entrega_km' => $raioLoja,
                'aberto' => $loja['aberto'],
                'distancia_km' => $dist,
                'fora_raio' => $foraRaio,
                'atende_regiao' => $atendeRegiao,
                'economia_vs_segmento_pct' => $economiaPct,
                'ja_pedido' => $qtdItemPedido > 0,
                'match_pct' => $matchItem,
                'motivo' => $motivoItem,
                'url_loja' => $loja['url_loja'],
            ];
        }
    }

    usort($lojasRankeadas, function ($a, $b) {
        return ((int) $b['atende_regiao'] <=> (int) $a['atende_regiao'])
            ?: ((int) $b['aberto'] <=> (int) $a['aberto'])
            ?: ($b['match_pct'] <=> $a['match_pct'])
            ?: ($b['score_territorio'] <=> $a['score_territorio']);
    });

    usort($pratosCandidatos, function ($a, $b) {
        return ((int) $b['atende_regiao'] <=> (int) $a['atende_regiao'])
            ?: ((int) $b['aberto'] <=> (int) $a['aberto'])
            ?: ($b['match_pct'] <=> $a['match_pct']);
    });

    // Identifica a loja em que o cliente mais costuma comprar (ou sua loja habitual/favorita)
    $lojaHabitual = null;
    $habCandidates = array_values(array_filter($lojasRankeadas, function ($l) {
        return !empty($l['eh_loja_habitual']) || !empty($l['compras_cliente']);
    }));
    if (!empty($habCandidates)) {
        usort($habCandidates, function ($a, $b) {
            return ($b['compras_cliente'] <=> $a['compras_cliente'])
                ?: ((int) $b['eh_favorita'] <=> (int) $a['eh_favorita'])
                ?: ($b['match_pct'] <=> $a['match_pct']);
        });
        $lojaHabitual = $habCandidates[0];
    }

    // Expansão por segmento quando o comprador busca um nicho sem lojas próximas
    $expansaoSegmento = null;
    if ($nichoFiltro !== '') {
        $doSegmentoAtendem = array_values(array_filter($lojasRankeadas, function ($l) use ($nichoFiltro) {
            return pz_territorio_norm($l['nicho']) === $nichoFiltro && !empty($l['atende_regiao']);
        }));
        $proximasMesmoSeg = array_values(array_filter($doSegmentoAtendem, function ($l) {
            return empty($l['entrega_estendida']);
        }));
        if (empty($proximasMesmoSeg) && !empty($doSegmentoAtendem)) {
            $expansaoSegmento = [
                'tipo' => 'raio_cnpj_estendido',
                'mensagem' => 'Não encontramos lojas desse segmento no seu bairro, mas estas empresas possuem raio estendido (até ' . max(array_column($doSegmentoAtendem, 'raio_entrega_km')) . ' km) e entregam na sua localização.',
                'lojas_recomendadas' => array_slice($doSegmentoAtendem, 0, 6),
            ];
        } elseif (empty($doSegmentoAtendem)) {
            $alternativas = array_values(array_filter($lojasRankeadas, function ($l) {
                return !empty($l['atende_regiao']) && !empty($l['aberto']);
            }));
            usort($alternativas, function ($a, $b) {
                return ($b['economia_vs_segmento_pct'] <=> $a['economia_vs_segmento_pct'])
                    ?: ($b['match_pct'] <=> $a['match_pct']);
            });
            $expansaoSegmento = [
                'tipo' => 'outro_segmento_melhor_preco',
                'mensagem' => 'Nenhuma loja do segmento selecionado entrega na sua região (mesmo considerando raios estendidos). Confira as opções com melhor preço em outros segmentos próximos:',
                'lojas_recomendadas' => array_slice($alternativas, 0, 6),
            ];
        }
    }

    arsort($perfil['nichos']);
    arsort($perfil['tags']);

    $necessidades = [];
    if ($lojaHabitual) {
        $necessidades[] = ($lojaHabitual['compras_cliente'] > 0)
            ? ('Costuma pedir em ' . $lojaHabitual['nome'] . ' (' . $lojaHabitual['compras_cliente'] . 'x)')
            : ('Preferida: ' . $lojaHabitual['nome']);
    }
    if ($prefEconomia >= 2.0) {
        $necessidades[] = 'Prioriza melhor preço e economia';
    }
    if ($prefRapidez >= 2.0) {
        $necessidades[] = 'Prefere entrega mais rápida';
    }
    if (!empty($perfil['nichos'])) {
        $topNicho = array_key_first($perfil['nichos']);
        $necessidades[] = 'Gosta de ' . $topNicho;
    }

    return [
        'success' => true,
        'turno' => $turno,
        'turno_rotulo' => $turnoRotulo,
        'loja_habitual' => $lojaHabitual,
        'para_voce_pratos' => array_slice(array_values(array_filter($pratosCandidatos, function ($p) {
            return !empty($p['atende_regiao']);
        })) ?: $pratosCandidatos, 0, 12),
        'lojas' => $lojasRankeadas,
        'comparativo_segmentos' => $comparativoSegmentos,
        'expansao_segmento' => $expansaoSegmento,
        'perfil_usuario' => [
            'eventos_registrados' => (int) ($perfil['eventos_count'] ?? 0),
            'nichos_favoritos' => array_slice(array_keys($perfil['nichos']), 0, 3),
            'interesses_detectados' => array_slice(array_keys($perfil['tags']), 0, 6),
            'buscas_frequentes' => $buscasFrequentes,
            'necessidades_detectadas' => $necessidades,
            'loja_mais_pedida' => $lojaHabitual ? [
                'slug' => $lojaHabitual['slug'],
                'nome' => $lojaHabitual['nome'],
                'compras' => $lojaHabitual['compras_cliente'],
            ] : null,
            'ticket_medio_alvo' => round($ticketAlvo, 2),
        ],
    ];
}

function pz_alg_metricas_loja($slug)
{
    $anterior = Database::getTenant();
    Database::setTenant('default');
    $stats = Database::read('algoritmo_stats_lojas.json');
    Database::setTenant($anterior);

    $s = is_array($stats[$slug] ?? null) ? $stats[$slug] : [
        'impressoes' => 0,
        'cliques' => 0,
        'add_carrinho' => 0,
        'pedidos' => 0,
        'dwell_total_ms' => 0,
        'dwell_eventos' => 0,
        'pratos' => [],
    ];
    $imp = max(1, (int) $s['impressoes']);
    $ctr = round(((int) $s['cliques'] / $imp) * 100, 1);
    $conv = round((((int) $s['add_carrinho'] + (int) $s['pedidos']) / $imp) * 100, 1);
    $dwellSeg = ((int) $s['dwell_eventos']) > 0 ? round(((int) $s['dwell_total_ms'] / (int) $s['dwell_eventos']) / 1000, 1) : 0;

    return [
        'impressoes' => (int) $s['impressoes'],
        'cliques' => (int) $s['cliques'],
        'add_carrinho' => (int) $s['add_carrinho'],
        'pedidos_rastreados' => (int) $s['pedidos'],
        'ctr_pct' => $s['impressoes'] > 0 ? $ctr : 0,
        'conversao_pct' => $s['impressoes'] > 0 ? $conv : 0,
        'atencao_media_seg' => $dwellSeg,
        'top_pratos' => $s['pratos'] ?? [],
    ];
}

function pz_alg_excluir_perfil($vid)
{
    $vid = pz_alg_limpar_vid($vid);
    if ($vid === '') {
        return ['success' => false, 'message' => 'Identificador ausente.'];
    }
    $anterior = Database::getTenant();
    Database::setTenant('default');
    $removido = false;
    Database::mutate('algoritmo_perfis.json', function (&$perfis) use ($vid, &$removido) {
        if (is_array($perfis) && isset($perfis[$vid])) {
            unset($perfis[$vid]);
            $removido = true;
            return true;
        }
        return false;
    });
    Database::setTenant($anterior);
    return ['success' => true, 'removido' => $removido];
}
