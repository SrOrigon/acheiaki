<?php
/**
 * Motor Territorial, Disputa de Território, Avaliações e Dicas de Melhoria Contínua.
 * Funciona 100% no servidor sem custo de IA externa (comparativos anônimos + métricas reais).
 * Quando o cliente tem IA Pessoal (BYOK) cadastrada, a IA consome este diagnóstico para atuar.
 */
require_once __DIR__ . '/tenant_init.php';
require_once __DIR__ . '/integracoes_lib.php';
require_once __DIR__ . '/saas_lib.php';
require_once __DIR__ . '/store_schema.php';

function pz_territorio_norm($txt)
{
    $s = mb_strtolower(trim((string) $txt), 'UTF-8');
    $mapa = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'í' => 'i', 'ì' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c',
    ];
    return strtr($s, $mapa);
}

function pz_territorio_distancia_km($lat1, $lng1, $lat2, $lng2)
{
    $lat1 = (float) $lat1;
    $lng1 = (float) $lng1;
    $lat2 = (float) $lat2;
    $lng2 = (float) $lng2;
    if (!$lat1 || !$lng1 || !$lat2 || !$lng2) {
        return null;
    }
    $r = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) * sin($dLat / 2)
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) * sin($dLng / 2);
    return round(2 * $r * asin(min(1.0, sqrt($a))), 2);
}

function pz_territorio_foto_real($url)
{
    $u = strtolower(trim((string) $url));
    if ($u === '') {
        return false;
    }
    if (strpos($u, 'placeholder') !== false || strpos($u, 'default.') !== false) {
        return false;
    }
    return true;
}

function pz_territorio_coletar_lojas($useCache = true)
{
    if ($useCache && function_exists('pz_cache_get')) {
        $cached = pz_cache_get('mkt_lojas_all', null);
        if (is_array($cached) && !empty($cached)) {
            return $cached;
        }
    }
    $anterior = Database::getTenant();
    $registro = pedidozap_ler_registro();
    $slugs = array_keys($registro);
    if (!in_array('default', $slugs, true) && Database::tenantDataExists('default')) {
        array_unshift($slugs, 'default');
    }

    $lojas = [];
    foreach ($slugs as $slug) {
        $meta = is_array($registro[$slug] ?? null) ? $registro[$slug] : [];
        $efetivo = !empty($meta) ? pedidozap_status_efetivo($meta) : ['status' => 'ativo', 'motivo' => ''];
        if (($efetivo['status'] ?? 'ativo') === 'bloqueado') {
            continue;
        }

        Database::setTenant($slug);
        if ($slug !== 'default' && !Database::tenantDataExists($slug)) {
            continue;
        }

        $cfgRaw = Database::readStrict('config.json');
        if ($slug === 'default' && empty($cfgRaw)) {
            $cfgRaw = Database::read('config.json');
        }
        $cfg = pedidozap_normalizar_config(is_array($cfgRaw) ? $cfgRaw : [], $slug);

        $cardRaw = Database::readStrict('cardapio.json');
        if ($slug === 'default' && empty($cardRaw)) {
            $cardRaw = Database::read('cardapio.json');
        }
        $cardapio = is_array($cardRaw) ? $cardRaw : [];
        $pedidos = Database::read('pedidos.json');
        $avaliacoes = Database::read('avaliacoes.json');
        $marketing = Database::read('marketing.json');
        $clientes = Database::read('clientes.json');

        $itens = [];
        $somaPrecos = 0.0;
        $comFoto = 0;
        $comDesc = 0;
        $comOpcional = 0;

        foreach ($cardapio as $catSlug => $lista) {
            if (!is_array($lista)) {
                continue;
            }
            foreach ($lista as $item) {
                if (!is_array($item) || (isset($item['ativo']) && !$item['ativo']) || !empty($item['pausado'])) {
                    continue;
                }
                $preco = (float) ($item['preco'] ?? 0);
                if ($preco <= 0 && !empty($item['tamanhos'][0]['preco'])) {
                    $preco = (float) $item['tamanhos'][0]['preco'];
                }
                $img = (string) ($item['imagem'] ?? $item['foto'] ?? '');
                $desc = trim((string) ($item['descricao'] ?? ''));
                $temFoto = pz_territorio_foto_real($img);
                $temDesc = mb_strlen($desc, 'UTF-8') >= 25;
                if ($temFoto) {
                    $comFoto++;
                }
                if ($temDesc) {
                    $comDesc++;
                }
                if (!empty($item['opcionais']) || !empty($item['tamanhos'])) {
                    $comOpcional++;
                }
                if ($preco > 0) {
                    $somaPrecos += $preco;
                }
                $tamanhos = [];
                foreach ((array) ($item['tamanhos'] ?? []) as $tam) {
                    if (is_array($tam) && !empty($tam['nome'])) {
                        $tamanhos[] = [
                            'nome' => (string) $tam['nome'],
                            'preco' => round((float) ($tam['preco'] ?? 0), 2),
                        ];
                    }
                }
                $opcionais = [];
                foreach ((array) ($item['opcionais'] ?? []) as $opc) {
                    if (is_array($opc) && !empty($opc['nome'])) {
                        $opcionais[] = [
                            'nome' => (string) $opc['nome'],
                            'preco' => round((float) ($opc['preco'] ?? 0), 2),
                        ];
                    }
                }
                $itens[] = [
                    'id' => (string) ($item['id'] ?? ''),
                    'nome' => (string) ($item['nome'] ?? 'Item'),
                    'descricao' => $desc,
                    'preco' => round($preco, 2),
                    'preco_original' => isset($item['precoOriginal']) ? round((float) $item['precoOriginal'], 2) : 0.0,
                    'categoria' => (string) $catSlug,
                    'imagem' => $img,
                    'tem_foto' => $temFoto,
                    'tem_desc' => $temDesc,
                    'destaque' => !empty($item['destaque']),
                    'tamanhos' => $tamanhos,
                    'opcionais' => $opcionais,
                ];
            }
        }

        $qtdItens = count($itens);
        $precoMedio = $qtdItens > 0 ? round($somaPrecos / $qtdItens, 2) : 0.0;

        // Tempo médio operacional e taxa de conclusão
        $tempos = [];
        $temposPreparo = [];
        $totalPed = 0;
        $concluidos = 0;
        $cancelados = 0;
        foreach ($pedidos as $p) {
            if (!is_array($p)) {
                continue;
            }
            $totalPed++;
            $st = (string) ($p['status'] ?? 'novo');
            if (!empty($p['cancelado'])) {
                $cancelados++;
            } elseif ($st === 'concluido' || $st === 'arquivado') {
                $concluidos++;
            }
            $inicio = strtotime((string) ($p['created_at'] ?? $p['criado_em'] ?? $p['timestamp'] ?? $p['data'] ?? ''));
            $aceitoAt = strtotime((string) ($p['aceito_em'] ?? $p['cozinha_desde'] ?? ''));
            $prontoAt = strtotime((string) ($p['pronto_em'] ?? $p['saiu_entrega_em'] ?? $p['saiu_em'] ?? ''));
            if ($aceitoAt && $prontoAt && $prontoAt > $aceitoAt) {
                $mPrep = ($prontoAt - $aceitoAt) / 60;
                if ($mPrep >= 2 && $mPrep <= 120) {
                    $temposPreparo[] = $mPrep;
                }
            }
            $fim = strtotime((string) ($p['entregue_em'] ?? $p['saiu_entrega_em'] ?? $p['saiu_em'] ?? ''));
            if ($inicio && $fim && $fim > $inicio) {
                $min = ($fim - $inicio) / 60;
                if ($min >= 3 && $min <= 180) {
                    $tempos[] = $min;
                }
            }
        }
        $tempoPreparoReal = count($temposPreparo) > 0 ? (int) round(array_sum($temposPreparo) / count($temposPreparo)) : null;
        $tempoReal = count($tempos) > 0
            ? (int) round(array_sum($tempos) / count($tempos))
            : (int) ($cfg['TEMPO_ENTREGA_MIN'] ?? 35);
        if ($tempoReal <= 0) {
            $tempoReal = 35;
        }

        // Avaliações reais dos compradores + depoimentos configurados na loja
        $depoConfig = is_array($cfg['AVALIACOES'] ?? null) ? $cfg['AVALIACOES'] : [];
        $resumoAv = pz_territorio_resumo_avaliacoes(is_array($avaliacoes) ? $avaliacoes : [], $depoConfig);
        $notaMedia = $resumoAv['nota_media'];
        $qtdAval = $resumoAv['total'];

        // Score Territorial (0-100)
        $ptsCardapio = 0;
        if ($qtdItens > 0) {
            $pctFoto = $comFoto / $qtdItens;
            $pctDesc = $comDesc / $qtdItens;
            $pctOpc = min(1.0, $comOpcional / max(1, $qtdItens * 0.4));
            $ptsCardapio = (int) round(($pctFoto * 14) + ($pctDesc * 10) + ($pctOpc * 6));
        }
        $ptsOperacao = 15;
        if ($tempoReal <= 28) {
            $ptsOperacao += 10;
        } elseif ($tempoReal <= 40) {
            $ptsOperacao += 6;
        } elseif ($tempoReal <= 55) {
            $ptsOperacao += 2;
        }
        if ($totalPed > 0 && ($cancelados / $totalPed) > 0.15) {
            $ptsOperacao = max(4, $ptsOperacao - 6);
        }

        $cuponsAtivos = array_values(array_filter((array) ($marketing['cupons'] ?? []), function ($c) {
            return is_array($c) && !empty($c['ativo']);
        }));
        $cuponsPublicos = array_map(function ($c) {
            return [
                'codigo' => (string) ($c['codigo'] ?? ''),
                'tipo' => (string) ($c['tipo'] ?? 'percentual'),
                'valor' => round((float) ($c['valor'] ?? 0), 2),
                'minimo' => round((float) ($c['minimo'] ?? 0), 2),
                'descricao' => (string) ($c['descricao'] ?? ''),
            ];
        }, array_slice($cuponsAtivos, 0, 8));

        $freteCfg = is_array($cfg['FRETE'] ?? null) ? $cfg['FRETE'] : [];
        $taxaBase = (float) ($freteCfg['taxa_fixa'] ?? $freteCfg['taxa_base'] ?? $cfg['FRETE_FIXO'] ?? $cfg['TAXA_ENTREGA'] ?? 0);
        $ptsCompet = 12 + (count($cuponsAtivos) > 0 ? 7 : 0) + ($taxaBase <= 5 ? 6 : ($taxaBase <= 9 ? 3 : 0));
        $ptsCompet = min(25, $ptsCompet);

        $recorrentes = 0;
        foreach ($clientes as $cli) {
            if (is_array($cli) && (int) ($cli['pedidos_count'] ?? $cli['total_pedidos'] ?? 0) >= 2) {
                $recorrentes++;
            }
        }
        $ptsReputacao = (int) round((($notaMedia / 5.0) * 14) + (min(1.0, $recorrentes / max(1, count($clientes) * 0.35)) * 6));
        $score = min(100, max(10, $ptsCardapio + $ptsOperacao + $ptsCompet + $ptsReputacao));

        $endCfg = is_array($cfg['ENDERECO'] ?? null) ? $cfg['ENDERECO'] : [];
        $idCfg = is_array($cfg['IDENTIDADE'] ?? null) ? $cfg['IDENTIDADE'] : [];
        $coresCfg = is_array($idCfg['CORES'] ?? null) ? $idCfg['CORES'] : [];

        $rua = (string) ($endCfg['rua'] ?? '');
        $numero = (string) ($endCfg['numero'] ?? '');
        $cidade = (string) ($endCfg['cidade'] ?? $cfg['ENDERECO_CIDADE'] ?? $meta['cidade'] ?? '');
        $bairro = (string) ($endCfg['bairro'] ?? $cfg['ENDERECO_BAIRRO'] ?? $meta['bairro'] ?? '');
        $estado = (string) ($endCfg['estado'] ?? $cfg['ENDERECO_ESTADO'] ?? $meta['estado'] ?? '');
        $cep = (string) ($endCfg['cep'] ?? $cfg['CEP_LOJA'] ?? $meta['cep'] ?? '');
        $lat = (float) ($endCfg['lat'] ?? $cfg['LATITUDE_LOJA'] ?? $meta['lat'] ?? 0);
        $lng = (float) ($endCfg['lng'] ?? $cfg['LONGITUDE_LOJA'] ?? $meta['lng'] ?? 0);
        $raio = (float) ($freteCfg['raio_max_km'] ?? $cfg['RAIO_ENTREGA_KM'] ?? 10);
        $raioFinal = $raio > 0 ? min(300.0, round($raio, 1)) : 10.0;

        $nicho = (string) ($meta['nicho'] ?? ($cfg['PERFIL_SEGMENTO']['nicho'] ?? $cfg['NICHO'] ?? 'lanchonete'));
        $nome = (string) ($cfg['NOME_LOJA'] ?? $meta['nome_empresa'] ?? 'Loja');
        $logo = (string) ($idCfg['LOGO'] ?? $cfg['LOGO_URL'] ?? 'assets/images/placeholder-logo.svg');
        $heroUrl = (string) ($idCfg['HERO'] ?? 'assets/images/placeholder-hero.svg');
        $cor = (string) ($coresCfg['primaria'] ?? $cfg['COR_PRIMARIA'] ?? '#f97136');
        $corSec = (string) ($coresCfg['secundaria'] ?? '#1f2937');
        $corFundo = (string) ($coresCfg['fundo'] ?? '#f9fafb');
        $aberto = empty($cfg['LOJA_FECHADA_MANUAL']);
        $locAtiva = ($lat !== 0.0 && $lng !== 0.0);

        $cnpj = preg_replace('/\D/', '', (string) ($cfg['CNPJ'] ?? ($cfg['PERFIL_QUALIFICADO']['cnpj'] ?? $meta['cnpj'] ?? '')));
        $razaoSocial = trim((string) ($cfg['RAZAO_SOCIAL'] ?? ($cfg['PERFIL_QUALIFICADO']['razao_social'] ?? $meta['razao_social'] ?? '')));
        $whatsapp = preg_replace('/\D/', '', (string) ($cfg['WHATSAPP_NUMBER'] ?? ($cfg['REDES']['whatsapp'] ?? $meta['whatsapp'] ?? '')));
        $pagCfg = is_array($cfg['PAGAMENTOS'] ?? null) ? $cfg['PAGAMENTOS'] : [];
        $temPag = !empty($pagCfg['pix']) || !empty($pagCfg['debito']) || !empty($pagCfg['credito']) || !empty($pagCfg['dinheiro']);

        $pendencias = [];
        if (strlen($cnpj) !== 14 || !pedidozap_validar_cnpj($cnpj)) {
            $pendencias[] = 'CNPJ válido obrigatório';
        }
        if (strlen($whatsapp) < 10) {
            $pendencias[] = 'WhatsApp com DDD';
        }
        if ($cidade === '' || $bairro === '') {
            $pendencias[] = 'Endereço completo (cidade e bairro)';
        }
        if (!$locAtiva) {
            $pendencias[] = 'Localização GPS ativa';
        }
        if ($qtdItens < 1) {
            $pendencias[] = 'Pelo menos 1 produto ativo no cardápio';
        }
        if (!$temPag) {
            $pendencias[] = 'Pelo menos 1 forma de pagamento';
        }

        $lojas[] = [
            'slug' => $slug,
            'nome' => $nome,
            'slogan' => (string) ($cfg['SLOGAN'] ?? ''),
            'cnpj' => $cnpj,
            'cnpj_verificado' => (strlen($cnpj) === 14 && pedidozap_validar_cnpj($cnpj)),
            'razao_social' => $razaoSocial,
            'nicho' => $nicho,
            'cidade' => $cidade,
            'bairro' => $bairro,
            'estado' => $estado,
            'cep' => $cep,
            'endereco' => [
                'rua' => $rua,
                'numero' => $numero,
                'bairro' => $bairro,
                'cidade' => $cidade,
                'estado' => $estado,
                'cep' => $cep,
            ],
            'whatsapp' => $whatsapp,
            'redes' => is_array($cfg['REDES'] ?? null) ? $cfg['REDES'] : [],
            'horarios' => is_array($cfg['HORARIOS'] ?? null) ? $cfg['HORARIOS'] : [],
            'pagamentos' => $pagCfg,
            'logistica' => is_array($cfg['LOGISTICA'] ?? null) ? $cfg['LOGISTICA'] : [],
            'frete' => [
                'modo' => (string) ($freteCfg['modo'] ?? 'gratis'),
                'taxa_fixa' => round((float) ($freteCfg['taxa_fixa'] ?? 0), 2),
                'taxa_base' => round((float) ($freteCfg['taxa_base'] ?? 0), 2),
                'valor_unidade' => round((float) ($freteCfg['valor_unidade'] ?? 0), 2),
                'frete_gratis_acima' => round((float) ($freteCfg['frete_gratis_acima'] ?? 0), 2),
                'pedido_minimo' => round((float) ($freteCfg['pedido_minimo'] ?? 0), 2),
                'raio_max_km' => $raioFinal,
            ],
            'lat' => $lat,
            'lng' => $lng,
            'localizacao_ativa' => $locAtiva,
            'raio_entrega_km' => $raioFinal,
            'taxa_entrega_base' => round($taxaBase, 2),
            'tempo_medio_min' => $tempoReal,
            'tempo_espera_txt' => (string) ($cfg['TEMPO_ESPERA'] ?? ($tempoReal . ' min')),
            'tempo_preparo_real_min' => $tempoPreparoReal,
            'aberto' => $aberto && ((int) ($cfg['PAUSADA_ATE'] ?? 0) <= time()),
            'pausada_ate' => (int) ($cfg['PAUSADA_ATE'] ?? 0),
            'cor_primaria' => $cor,
            'cor_secundaria' => $corSec,
            'cor_fundo' => $corFundo,
            'logo_url' => $logo,
            'hero_url' => $heroUrl,
            'hero_titulo' => (string) ($cfg['TEXTOS']['HERO_TITULO'] ?? $nome),
            'hero_subtitulo' => (string) ($cfg['TEXTOS']['HERO_SUBTITULO'] ?? ($cfg['SLOGAN'] ?? '')),
            'destaque' => !empty($meta['destaque']),
            'nota_media' => $notaMedia,
            'total_avaliacoes' => $qtdAval,
            'resumo_avaliacoes' => [
                'nota_media' => $notaMedia,
                'total' => $qtdAval,
                'distribuicao' => $resumoAv['distribuicao'],
                'percentuais' => $resumoAv['percentuais'],
                'criterios' => $resumoAv['criterios'],
                'top_tags' => $resumoAv['top_tags'],
            ],
            'qtd_itens' => $qtdItens,
            'com_foto' => $comFoto,
            'com_desc' => $comDesc,
            'com_opcional' => $comOpcional,
            'cupons_ativos' => count($cuponsAtivos),
            'cupons_lista' => $cuponsPublicos,
            'total_pedidos' => $totalPed,
            'cancelados' => $cancelados,
            'preco_medio' => $precoMedio,
            'score_territorio' => $score,
            'pilares' => [
                'cardapio' => $ptsCardapio,
                'operacao' => $ptsOperacao,
                'competitividade' => $ptsCompet,
                'reputacao' => $ptsReputacao,
            ],
            'categorias' => is_array($cfg['CATEGORIAS'] ?? null) ? $cfg['CATEGORIAS'] : [],
            'itens' => $itens,
            'avaliacoes' => array_slice($resumoAv['lista_completa'], 0, 50),
            'cadastro_completo' => count($pendencias) === 0,
            'pendencias_cadastro' => $pendencias,
            'url_loja' => $slug === 'default' ? 'index.html?tenant=default' : ('loja/' . rawurlencode($slug) . '/'),
        ];

        // Sincroniza índice relacional se PDO ativo
        pz_territorio_sync_sql_index(end($lojas), $meta);
    }

    Database::setTenant($anterior);
    pz_territorio_comparativo_segmentos($lojas);
    if (function_exists('pz_cache_set') && !empty($lojas)) {
        pz_cache_set('mkt_lojas_all', $lojas, 25);
    }
    return $lojas;
}

function pz_territorio_comparativo_segmentos(array &$lojas)
{
    $grupos = [];
    foreach ($lojas as $l) {
        $n = pz_territorio_norm($l['nicho'] ?? 'lanchonete');
        if (!isset($grupos[$n])) {
            $grupos[$n] = [
                'nicho' => $l['nicho'],
                'nicho_norm' => $n,
                'qtd_lojas' => 0,
                'precos' => [],
                'taxas' => [],
                'melhor_loja_nome' => '',
                'melhor_loja_slug' => '',
                'menor_preco_loja' => null,
            ];
        }
        $grupos[$n]['qtd_lojas']++;
        $grupos[$n]['taxas'][] = (float) ($l['taxa_entrega_base'] ?? 0);
        if ((float) ($l['preco_medio'] ?? 0) > 0) {
            $pm = (float) $l['preco_medio'];
            $grupos[$n]['precos'][] = $pm;
            if ($grupos[$n]['menor_preco_loja'] === null || $pm < $grupos[$n]['menor_preco_loja']) {
                $grupos[$n]['menor_preco_loja'] = $pm;
                $grupos[$n]['melhor_loja_nome'] = $l['nome'];
                $grupos[$n]['melhor_loja_slug'] = $l['slug'];
            }
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
    $resumo = [];
    foreach ($grupos as $n => $g) {
        $media = count($g['precos']) ? round(array_sum($g['precos']) / count($g['precos']), 2) : 0.0;
        $menor = $g['menor_preco_loja'] !== null ? round($g['menor_preco_loja'], 2) : 0.0;
        $menorTaxa = count($g['taxas']) ? round(min($g['taxas']), 2) : 0.0;
        $ecoMelhor = ($media > 0 && $menor > 0) ? max(0, (int) round((($media - $menor) / $media) * 100)) : 0;
        $rot = $rotulosNicho[$n] ?? ucfirst((string) $g['nicho']);
        $resumo[$n] = [
            'nicho' => $g['nicho'],
            'nicho_norm' => $n,
            'nicho_rotulo' => $rot,
            'total_lojas' => $g['qtd_lojas'],
            'qtd_lojas' => $g['qtd_lojas'],
            'preco_medio' => $media,
            'preco_medio_segmento' => $media,
            'melhor_preco_medio' => $menor,
            'menor_preco_medio' => $menor,
            'economia_melhor_pct' => $ecoMelhor,
            'menor_taxa' => $menorTaxa,
            'melhor_loja_nome' => $g['melhor_loja_nome'],
            'melhor_loja_slug' => $g['melhor_loja_slug'],
        ];
    }

    foreach ($lojas as &$l) {
        $n = pz_territorio_norm($l['nicho'] ?? 'lanchonete');
        $seg = $resumo[$n] ?? null;
        $mediaSeg = $seg ? (float) $seg['preco_medio'] : 0.0;
        $pm = (float) ($l['preco_medio'] ?? 0);
        $economiaPct = ($mediaSeg > 0 && $pm > 0)
            ? (int) round((($mediaSeg - $pm) / $mediaSeg) * 100)
            : 0;
        $l['media_preco_segmento'] = $mediaSeg;
        $l['economia_vs_segmento_pct'] = $economiaPct;
        $l['melhor_preco_segmento'] = ($seg && $seg['melhor_loja_slug'] === $l['slug'] && $pm > 0);
    }
    unset($l);

    return array_values($resumo);
}

function pz_territorio_buscar_loja_detalhe($slug, $latCli = 0.0, $lngCli = 0.0)
{
    $todas = pz_territorio_coletar_lojas();
    $compSegmentos = pz_territorio_comparativo_segmentos($todas);
    foreach ($todas as $l) {
        if ($l['slug'] !== $slug) {
            continue;
        }
        $dist = ($latCli && $lngCli && !empty($l['lat']) && !empty($l['lng']))
            ? pz_territorio_distancia_km($latCli, $lngCli, $l['lat'], $l['lng'])
            : null;
        $raio = (float) ($l['raio_entrega_km'] ?? 10);
        $foraRaio = ($dist !== null && $raio > 0 && $dist > $raio);
        $l['distancia_km'] = $dist;
        $l['fora_raio'] = $foraRaio;
        $l['atende_regiao'] = ($dist === null) ? true : !$foraRaio;
        $l['entrega_estendida'] = ($dist !== null && $dist > 15 && !$foraRaio);
        $l['comparativo_segmentos'] = $compSegmentos;
        return $l;
    }
    return null;
}

function pz_territorio_sync_sql_index(array $loja, array $meta = [])
{
    $pdo = function_exists('pz_pdo') ? pz_pdo() : null;
    if (!$pdo) {
        return;
    }
    try {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $agora = date('Y-m-d H:i:s');
        $params = [
            $loja['slug'],
            mb_substr($loja['nome'], 0, 155, 'UTF-8'),
            mb_substr($loja['nicho'], 0, 55, 'UTF-8'),
            mb_substr($loja['cidade'], 0, 115, 'UTF-8'),
            mb_substr($loja['bairro'], 0, 115, 'UTF-8'),
            mb_substr($loja['estado'], 0, 10, 'UTF-8'),
            mb_substr($loja['cep'], 0, 20, 'UTF-8'),
            (float) $loja['lat'],
            (float) $loja['lng'],
            (float) $loja['raio_entrega_km'],
            (float) $loja['taxa_entrega_base'],
            (int) $loja['tempo_medio_min'],
            $loja['aberto'] ? 1 : 0,
            (string) ($meta['status'] ?? 'ativo'),
            (string) ($meta['plano'] ?? 'essencial'),
            (float) $loja['nota_media'],
            (int) $loja['total_avaliacoes'],
            (int) $loja['score_territorio'],
            $loja['destaque'] ? 1 : 0,
            mb_substr($loja['logo_url'], 0, 490, 'UTF-8'),
            mb_substr($loja['cor_primaria'], 0, 25, 'UTF-8'),
            $agora,
        ];
        if ($driver === 'pgsql') {
            $sql = 'INSERT INTO pz_tenants_index (slug, nome_loja, nicho, cidade, bairro, estado, cep, lat, lng, raio_entrega_km, taxa_entrega_base, tempo_medio_min, aberto, status, plano, nota_media, total_avaliacoes, score_territorio, destaque, logo_url, cor_primaria, atualizado_em)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                    ON CONFLICT (slug) DO UPDATE SET
                        nome_loja=EXCLUDED.nome_loja, nicho=EXCLUDED.nicho, cidade=EXCLUDED.cidade, bairro=EXCLUDED.bairro,
                        estado=EXCLUDED.estado, cep=EXCLUDED.cep, lat=EXCLUDED.lat, lng=EXCLUDED.lng,
                        raio_entrega_km=EXCLUDED.raio_entrega_km, taxa_entrega_base=EXCLUDED.taxa_entrega_base,
                        tempo_medio_min=EXCLUDED.tempo_medio_min, aberto=EXCLUDED.aberto, status=EXCLUDED.status,
                        plano=EXCLUDED.plano, nota_media=EXCLUDED.nota_media, total_avaliacoes=EXCLUDED.total_avaliacoes,
                        score_territorio=EXCLUDED.score_territorio, destaque=EXCLUDED.destaque, logo_url=EXCLUDED.logo_url,
                        cor_primaria=EXCLUDED.cor_primaria, atualizado_em=EXCLUDED.atualizado_em';
        } else {
            $sql = 'INSERT INTO pz_tenants_index (slug, nome_loja, nicho, cidade, bairro, estado, cep, lat, lng, raio_entrega_km, taxa_entrega_base, tempo_medio_min, aberto, status, plano, nota_media, total_avaliacoes, score_territorio, destaque, logo_url, cor_primaria, atualizado_em)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                    ON DUPLICATE KEY UPDATE
                        nome_loja=VALUES(nome_loja), nicho=VALUES(nicho), cidade=VALUES(cidade), bairro=VALUES(bairro),
                        estado=VALUES(estado), cep=VALUES(cep), lat=VALUES(lat), lng=VALUES(lng),
                        raio_entrega_km=VALUES(raio_entrega_km), taxa_entrega_base=VALUES(taxa_entrega_base),
                        tempo_medio_min=VALUES(tempo_medio_min), aberto=VALUES(aberto), status=VALUES(status),
                        plano=VALUES(plano), nota_media=VALUES(nota_media), total_avaliacoes=VALUES(total_avaliacoes),
                        score_territorio=VALUES(score_territorio), destaque=VALUES(destaque), logo_url=VALUES(logo_url),
                        cor_primaria=VALUES(cor_primaria), atualizado_em=VALUES(atualizado_em)';
        }
        $pdo->prepare($sql)->execute($params);
    } catch (Throwable $e) {
        // não interrompe o fluxo público caso índice falhe
    }
}

function pz_territorio_analisar_loja($slug)
{
    $todas = pz_territorio_coletar_lojas();
    $atual = null;
    foreach ($todas as $l) {
        if ($l['slug'] === $slug) {
            $atual = $l;
            break;
        }
    }
    if (!$atual) {
        return ['success' => false, 'message' => 'Loja não localizada no índice territorial.'];
    }

    // Filtra concorrentes do mesmo território (cidade/bairro) ou nicho quando território ainda em expansão
    $cidNorm = pz_territorio_norm($atual['cidade']);
    $nichoNorm = pz_territorio_norm($atual['nicho']);
    $pares = array_values(array_filter($todas, function ($l) use ($cidNorm, $nichoNorm) {
        if ($cidNorm !== '' && pz_territorio_norm($l['cidade']) !== '' && pz_territorio_norm($l['cidade']) === $cidNorm) {
            return true;
        }
        return pz_territorio_norm($l['nicho']) === $nichoNorm;
    }));
    if (count($pares) < 2) {
        $pares = $todas;
    }

    usort($pares, function ($a, $b) {
        return ($b['score_territorio'] <=> $a['score_territorio'])
            ?: ($b['nota_media'] <=> $a['nota_media'])
            ?: ($a['tempo_medio_min'] <=> $b['tempo_medio_min']);
    });

    $posicao = 1;
    foreach ($pares as $idx => $p) {
        if ($p['slug'] === $slug) {
            $posicao = $idx + 1;
            break;
        }
    }

    $precos = array_values(array_filter(array_column($pares, 'preco_medio'), function ($v) { return $v > 0; }));
    $tempos = array_values(array_filter(array_column($pares, 'tempo_medio_min'), function ($v) { return $v > 0; }));
    $fretes = array_column($pares, 'taxa_entrega_base');
    $scores = array_column($pares, 'score_territorio');

    $mediaPrecoReg = count($precos) ? round(array_sum($precos) / count($precos), 2) : $atual['preco_medio'];
    $mediaTempoReg = count($tempos) ? (int) round(array_sum($tempos) / count($tempos)) : $atual['tempo_medio_min'];
    $mediaFreteReg = count($fretes) ? round(array_sum($fretes) / count($fretes), 2) : $atual['taxa_entrega_base'];
    $scoreLider = count($scores) ? max($scores) : $atual['score_territorio'];

    // Gera dicas acionáveis específicas para esta loja e seus produtos
    $dicas = [];
    $semFoto = array_values(array_filter($atual['itens'], function ($i) { return !$i['tem_foto']; }));
    $semDesc = array_values(array_filter($atual['itens'], function ($i) { return !$i['tem_desc']; }));

    if ($atual['qtd_itens'] === 0) {
        $dicas[] = [
            'id' => 'cardapio_vazio',
            'prioridade' => 'alta',
            'categoria' => 'cardapio',
            'titulo' => 'Cadastre seus primeiros produtos para aparecer no Marketplace',
            'motivo' => 'Sua loja ainda não possui itens publicados para os compradores da região.',
            'acao_sugerida' => 'Adicione pelo menos 5 produtos com preço e foto na aba Cardápio.',
            'impacto_pontos' => '+25 pts',
            'aba_destino' => 'cardapio',
            'comando_ia' => 'Sugira 5 produtos populares com descrição atrativa para meu segmento.',
        ];
    } else {
        if (count($semFoto) > 0) {
            $nomes = implode(', ', array_slice(array_column($semFoto, 'nome'), 0, 3));
            $dicas[] = [
                'id' => 'itens_sem_foto',
                'prioridade' => 'alta',
                'categoria' => 'cardapio',
                'titulo' => count($semFoto) . ' produto(s) sem foto real no cardápio',
                'motivo' => "No Marketplace, pratos sem foto convertem até 3x menos. Itens afetados: {$nomes}.",
                'acao_sugerida' => 'Envie fotos reais nítidas na aba Cardápio para ganhar destaque imediato no bairro.',
                'impacto_pontos' => '+14 pts',
                'aba_destino' => 'cardapio',
                'comando_ia' => '',
            ];
        }
        if (count($semDesc) > 0) {
            $nomesDesc = implode(', ', array_slice(array_column($semDesc, 'nome'), 0, 3));
            $dicas[] = [
                'id' => 'itens_sem_descricao',
                'prioridade' => 'media',
                'categoria' => 'cardapio',
                'titulo' => count($semDesc) . ' produto(s) com descrição curta ou vazia',
                'motivo' => "Descrições detalhadas (ingredientes e peso) reduzem dúvidas e aumentam o ticket médio. Ex.: {$nomesDesc}.",
                'acao_sugerida' => 'Complete a descrição dos principais pratos destacando diferenciais e acompanhamentos.',
                'impacto_pontos' => '+10 pts',
                'aba_destino' => 'cardapio',
                'comando_ia' => "Crie descrições vendedoras e curtas para estes produtos: {$nomesDesc}.",
            ];
        }
    }

    if (empty($atual['localizacao_ativa']) || $atual['cidade'] === '' || $atual['bairro'] === '') {
        $dicas[] = [
            'id' => 'endereco_territorio',
            'prioridade' => 'alta',
            'categoria' => 'territorio',
            'titulo' => empty($atual['localizacao_ativa'])
                ? 'Localização GPS da empresa obrigatória não configurada'
                : 'Defina cidade e bairro da loja na disputa territorial',
            'motivo' => 'Sem localização ativa (latitude/longitude + cidade/bairro), os clientes próximos não encontram sua loja e o algoritmo não entrega seus pratos na região.',
            'acao_sugerida' => 'Toque em "Capturar localização GPS da empresa" ou informe o CEP e salve abaixo.',
            'impacto_pontos' => 'Obrigatório no Marketplace',
            'aba_destino' => 'territorio',
            'comando_ia' => '',
        ];
    }

    if ($atual['tempo_medio_min'] > $mediaTempoReg + 5) {
        $dicas[] = [
            'id' => 'tempo_preparo_alto',
            'prioridade' => 'alta',
            'categoria' => 'entrega',
            'titulo' => "Tempo de entrega ({$atual['tempo_medio_min']} min) acima da média local ({$mediaTempoReg} min)",
            'motivo' => 'Compradores escolhem primeiro quem entrega mais rápido na região.',
            'acao_sugerida' => 'Use o Painel da Cozinha (cozinha.html) e pré-separe insumos de giro rápido nos horários de pico.',
            'impacto_pontos' => '+10 pts',
            'aba_destino' => 'estoque',
            'comando_ia' => 'Analise meus pedidos recentes e diga como reduzir o tempo de preparo e entrega.',
        ];
    }

    if ($mediaPrecoReg > 0 && $atual['preco_medio'] > $mediaPrecoReg * 1.18) {
        $dicas[] = [
            'id' => 'preco_acima_regiao',
            'prioridade' => 'media',
            'categoria' => 'preco',
            'titulo' => 'Preço médio (' . number_format($atual['preco_medio'], 2, ',', '.') . ') está acima da média do território (R$ ' . number_format($mediaPrecoReg, 2, ',', '.') . ')',
            'motivo' => 'Sem combos ou adicionais de alto valor percebido, o cliente compara preço puro e escolhe o concorrente.',
            'acao_sugerida' => 'Crie um Combo com bebida/acompanhamento ou revise o CMV na Ficha Técnica.',
            'impacto_pontos' => '+8 pts',
            'aba_destino' => 'cardapio',
            'comando_ia' => 'Com base no meu cardápio atual, sugira 2 combos competitivos mantendo margem saudável.',
        ];
    }

    if ($atual['cupons_ativos'] === 0) {
        $dicas[] = [
            'id' => 'sem_cupom_territorio',
            'prioridade' => 'media',
            'categoria' => 'marketing',
            'titulo' => 'Nenhum cupom ativo para atrair novos clientes do bairro',
            'motivo' => 'Lojas com cupom de primeira compra ganham até 2x mais cliques no Marketplace.',
            'acao_sugerida' => 'Crie um cupom como BEMVINDO10 ou FRETEZERO com pedido mínimo.',
            'impacto_pontos' => '+7 pts',
            'aba_destino' => 'clientes',
            'comando_ia' => 'Crie um cupom BAIRRO10 de 10% para atrair clientes próximos.',
        ];
    }

    if ($atual['taxa_entrega_base'] > max(7.0, $mediaFreteReg + 3.0)) {
        $dicas[] = [
            'id' => 'frete_alto',
            'prioridade' => 'dica',
            'categoria' => 'entrega',
            'titulo' => 'Taxa de entrega acima da média regional',
            'motivo' => "Sua taxa base (R$ " . number_format($atual['taxa_entrega_base'], 2, ',', '.') . ") supera a média da região (R$ " . number_format($mediaFreteReg, 2, ',', '.') . ').',
            'acao_sugerida' => 'Considere frete grátis acima de um valor mínimo para aumentar o ticket médio.',
            'impacto_pontos' => '+6 pts',
            'aba_destino' => 'frete',
            'comando_ia' => '',
        ];
    }

    if (empty($dicas)) {
        $dicas[] = [
            'id' => 'excelencia_territorial',
            'prioridade' => 'dica',
            'categoria' => 'territorio',
            'titulo' => 'Operação competitiva no seu território!',
            'motivo' => 'Seu cardápio, prazo e oferta estão alinhados com os líderes locais.',
            'acao_sugerida' => 'Acompanhe as avaliações dos compradores e lance promoções sazonais para manter o 1º lugar.',
            'impacto_pontos' => 'Manutenção',
            'aba_destino' => 'visao-geral',
            'comando_ia' => 'Gere um plano semanal de crescimento para manter minha liderança no bairro.',
        ];
    }

    // Remove campo pesado antes de devolver
    unset($atual['itens']);

    return [
        'success' => true,
        'loja' => $atual,
        'ranking' => [
            'posicao' => $posicao,
            'total_lojas_territorio' => count($pares),
            'escopo' => $atual['cidade'] !== '' ? ($atual['cidade'] . ($atual['bairro'] ? ' · ' . $atual['bairro'] : '')) : ('Segmento ' . $atual['nicho']),
            'score_lider' => $scoreLider,
            'media_preco_regiao' => $mediaPrecoReg,
            'media_tempo_regiao' => $mediaTempoReg,
            'media_frete_regiao' => $mediaFreteReg,
        ],
        'top_produtos_para_melhorar' => array_slice(array_map(function ($i) {
            $problemas = [];
            if (!$i['tem_foto']) {
                $problemas[] = 'Sem foto real';
            }
            if (!$i['tem_desc']) {
                $problemas[] = 'Descrição curta';
            }
            return [
                'nome' => $i['nome'],
                'categoria' => $i['categoria'],
                'preco' => $i['preco'],
                'problemas' => $problemas,
            ];
        }, array_merge($semFoto, $semDesc)), 0, 8),
        'dicas' => $dicas,
    ];
}

function pz_territorio_resumo_avaliacoes(array $avaliacoes, array $depoimentosConfig = [])
{
    $dist = ['5' => 0, '4' => 0, '3' => 0, '2' => 0, '1' => 0];
    $somaNota = 0.0;
    $qtdReal = 0;
    $somaComida = 0.0;
    $qtdComida = 0;
    $somaEntrega = 0.0;
    $qtdEntrega = 0;
    $somaCusto = 0.0;
    $qtdCusto = 0;
    $tagsCount = [];
    $listaCompleta = [];

    foreach ($avaliacoes as $av) {
        if (!is_array($av) || !isset($av['nota'])) {
            continue;
        }
        $n = max(1, min(5, (int) $av['nota']));
        $dist[(string) $n]++;
        $somaNota += $n;
        $qtdReal++;

        if (!empty($av['nota_comida'])) {
            $somaComida += max(1, min(5, (int) $av['nota_comida']));
            $qtdComida++;
        }
        if (!empty($av['nota_entrega'])) {
            $somaEntrega += max(1, min(5, (int) $av['nota_entrega']));
            $qtdEntrega++;
        }
        if (!empty($av['nota_custo'])) {
            $somaCusto += max(1, min(5, (int) $av['nota_custo']));
            $qtdCusto++;
        }
        foreach ((array) ($av['melhorias_tags'] ?? []) as $t) {
            $tClean = trim((string) $t);
            if ($tClean !== '') {
                $tagsCount[$tClean] = ($tagsCount[$tClean] ?? 0) + 1;
            }
        }
        $listaCompleta[] = [
            'id' => (string) ($av['id'] ?? ''),
            'pedido_id' => (string) ($av['pedido_id'] ?? ''),
            'cliente_nome' => (string) ($av['cliente_nome'] ?? 'Cliente'),
            'nota' => $n,
            'nota_comida' => !empty($av['nota_comida']) ? (int) $av['nota_comida'] : null,
            'nota_entrega' => !empty($av['nota_entrega']) ? (int) $av['nota_entrega'] : null,
            'nota_custo' => !empty($av['nota_custo']) ? (int) $av['nota_custo'] : null,
            'comentario' => (string) ($av['comentario'] ?? ''),
            'melhorias_tags' => array_values((array) ($av['melhorias_tags'] ?? [])),
            'recomendaria' => !isset($av['recomendaria']) || !empty($av['recomendaria']),
            'verificada' => !empty($av['verificada']) || ((string) ($av['pedido_id'] ?? '') !== ''),
            'resposta_loja' => (string) ($av['resposta_loja'] ?? ''),
            'resposta_autor' => (string) ($av['resposta_autor'] ?? ''),
            'resposta_em' => (string) ($av['resposta_em'] ?? ''),
            'criado_em' => (string) ($av['criado_em'] ?? ''),
            'origem' => 'avaliacao',
        ];
    }

    foreach ($depoimentosConfig as $dep) {
        if (!is_array($dep)) {
            continue;
        }
        $txtDep = trim((string) ($dep['texto'] ?? ''));
        $nomeDep = trim((string) ($dep['nome'] ?? ''));
        if ($txtDep === '' && $nomeDep === '') {
            continue;
        }
        $nDep = max(1, min(5, (int) ($dep['nota'] ?? 5)));
        $listaCompleta[] = [
            'id' => '',
            'pedido_id' => '',
            'cliente_nome' => $nomeDep ?: 'Cliente da loja',
            'nota' => $nDep,
            'nota_comida' => null,
            'nota_entrega' => null,
            'nota_custo' => null,
            'comentario' => $txtDep,
            'melhorias_tags' => [],
            'recomendaria' => true,
            'verificada' => false,
            'resposta_loja' => '',
            'resposta_autor' => '',
            'resposta_em' => '',
            'criado_em' => '',
            'foto' => (string) ($dep['foto'] ?? ''),
            'origem' => 'destaque',
        ];
    }

    $notaMedia = $qtdReal > 0 ? round($somaNota / $qtdReal, 1) : 4.8;
    $percentuais = [];
    foreach ($dist as $estrela => $qtd) {
        $percentuais[$estrela] = $qtdReal > 0 ? (int) round(($qtd / $qtdReal) * 100) : 0;
    }
    arsort($tagsCount);
    $topTags = [];
    foreach (array_slice($tagsCount, 0, 8, true) as $tag => $qtd) {
        $topTags[] = ['tag' => $tag, 'qtd' => $qtd];
    }

    return [
        'nota_media' => $notaMedia,
        'total' => $qtdReal,
        'distribuicao' => $dist,
        'percentuais' => $percentuais,
        'criterios' => [
            'comida' => $qtdComida > 0 ? round($somaComida / $qtdComida, 1) : null,
            'entrega' => $qtdEntrega > 0 ? round($somaEntrega / $qtdEntrega, 1) : null,
            'custo' => $qtdCusto > 0 ? round($somaCusto / $qtdCusto, 1) : null,
        ],
        'top_tags' => $topTags,
        'lista_completa' => $listaCompleta,
    ];
}

function pz_territorio_registrar_avaliacao($slug, array $dados)
{
    $nota = max(1, min(5, (int) ($dados['nota'] ?? 5)));
    $notaComida = !empty($dados['nota_comida']) ? max(1, min(5, (int) $dados['nota_comida'])) : null;
    $notaEntrega = !empty($dados['nota_entrega']) ? max(1, min(5, (int) $dados['nota_entrega'])) : null;
    $notaCusto = !empty($dados['nota_custo']) ? max(1, min(5, (int) $dados['nota_custo'])) : null;
    $comentario = trim(strip_tags((string) ($dados['comentario'] ?? '')));
    $pedidoId = trim((string) ($dados['pedido_id'] ?? ''));
    $clienteNome = trim(strip_tags((string) ($dados['cliente_nome'] ?? 'Cliente'))) ?: 'Cliente';
    $compradorId = (int) ($dados['comprador_id'] ?? 0);
    $verificada = !empty($dados['verificada']) || ($pedidoId !== '');
    $recomendaria = !isset($dados['recomendaria']) || !empty($dados['recomendaria']);
    $tags = array_values(array_filter(array_map(function ($t) {
        return trim(strip_tags((string) $t));
    }, (array) ($dados['melhorias_tags'] ?? []))));

    $anterior = Database::getTenant();
    Database::setTenant($slug);
    if ($slug !== 'default' && !Database::tenantDataExists($slug)) {
        Database::setTenant($anterior);
        return ['success' => false, 'message' => 'Loja não encontrada.'];
    }

    $registro = [
        'id' => 'av_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(3)), 0, 6),
        'pedido_id' => $pedidoId,
        'comprador_id' => $compradorId,
        'cliente_nome' => $clienteNome,
        'nota' => $nota,
        'nota_comida' => $notaComida,
        'nota_entrega' => $notaEntrega,
        'nota_custo' => $notaCusto,
        'comentario' => $comentario,
        'melhorias_tags' => $tags,
        'recomendaria' => $recomendaria,
        'verificada' => $verificada,
        'resposta_loja' => '',
        'resposta_autor' => '',
        'resposta_em' => '',
        'criado_em' => date('Y-m-d H:i:s'),
    ];

    $ok = Database::mutate('avaliacoes.json', function (&$lista) use ($registro) {
        if (!is_array($lista)) {
            $lista = [];
        }
        if ($registro['pedido_id'] !== '') {
            foreach ($lista as $item) {
                if (is_array($item) && (string) ($item['pedido_id'] ?? '') === $registro['pedido_id']) {
                    return false;
                }
            }
        }
        array_unshift($lista, $registro);
        if (count($lista) > 300) {
            $lista = array_slice($lista, 0, 300);
        }
        return true;
    });

    Database::setTenant($anterior);
    if (!$ok) {
        return ['success' => false, 'message' => 'Este pedido já foi avaliado ou não pôde ser registrado.'];
    }

    $pdo = function_exists('pz_pdo') ? pz_pdo() : null;
    if ($pdo) {
        try {
            $stmt = $pdo->prepare('INSERT INTO pz_avaliacoes (tenant_slug, pedido_codigo, comprador_id, cliente_nome, nota, comentario, melhorias_tags_json, criado_em) VALUES (?,?,?,?,?,?,?,?)');
            $stmt->execute([
                $slug,
                $pedidoId,
                $compradorId,
                $clienteNome,
                $nota,
                $comentario,
                json_encode($tags, JSON_UNESCAPED_UNICODE),
                $registro['criado_em'],
            ]);
        } catch (Throwable $e) {
            // avaliacao ja garantida via Database
        }
    }

    return ['success' => true, 'avaliacao' => $registro];
}

function pz_territorio_responder_avaliacao($slug, $avId, $resposta, $autor = 'Estabelecimento')
{
    $avId = trim((string) $avId);
    $respLimpa = trim(strip_tags((string) $resposta));
    $autorLimpo = trim(strip_tags((string) $autor)) ?: 'Estabelecimento';
    if ($avId === '' || mb_strlen($respLimpa, 'UTF-8') < 2) {
        return ['success' => false, 'message' => 'Informe o ID da avaliação e a resposta do estabelecimento.'];
    }

    $anterior = Database::getTenant();
    Database::setTenant($slug);
    $encontrada = null;
    Database::mutate('avaliacoes.json', function (&$lista) use ($avId, $respLimpa, $autorLimpo, &$encontrada) {
        if (!is_array($lista)) {
            return false;
        }
        foreach ($lista as $i => $item) {
            if (!is_array($item) || (string) ($item['id'] ?? '') !== $avId) {
                continue;
            }
            $lista[$i]['resposta_loja'] = $respLimpa;
            $lista[$i]['resposta_autor'] = $autorLimpo;
            $lista[$i]['resposta_em'] = date('Y-m-d H:i:s');
            $encontrada = $lista[$i];
            return true;
        }
        return false;
    });
    Database::setTenant($anterior);

    if (!$encontrada) {
        return ['success' => false, 'message' => 'Avaliação não encontrada para responder.'];
    }
    return ['success' => true, 'avaliacao' => $encontrada];
}
