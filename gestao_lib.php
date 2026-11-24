<?php
/**
 * Motor de Gestão Food (Multi-Tenant) — Estoque, Ficha Técnica/CMV,
 * Financeiro/Caixa/DRE, Clientes/Cupons/Fidelidade, Entregadores, Funcionários e Auditoria.
 * Todos os dados ficam isolados por estabelecimento via Database::readStrict / mutate / write.
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/store_schema.php';
require_once __DIR__ . '/saas_lib.php';

function pz_uid($prefix = 'id')
{
    return $prefix . '_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
}

function pz_limpar_str($v, $max = 200)
{
    $txt = trim(strip_tags((string) $v));
    $txt = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $txt) ?? '';
    return function_exists('mb_substr') ? mb_substr($txt, 0, $max) : substr($txt, 0, $max);
}

function pz_num($v, $decimais = 2)
{
    if (is_string($v)) {
        $v = str_replace(['R$', ' ', '.'], ['', '', ''], $v);
        $v = str_replace(',', '.', $v);
    }
    return round((float) $v, $decimais);
}

/**
 * Converte quantidade entre unidades compatíveis (kg <-> g, litro <-> ml).
 */
function pz_converter_unidade($qtd, $unidadeOrigem, $unidadeDestino)
{
    $u1 = strtolower(trim((string) $unidadeOrigem));
    $u2 = strtolower(trim((string) $unidadeDestino));
    $qtd = (float) $qtd;
    if ($u1 === '' || $u2 === '' || $u1 === $u2) {
        return $qtd;
    }
    if ($u1 === 'g' && $u2 === 'kg') {
        return $qtd / 1000.0;
    }
    if ($u1 === 'kg' && $u2 === 'g') {
        return $qtd * 1000.0;
    }
    if ($u1 === 'ml' && ($u2 === 'litro' || $u2 === 'l')) {
        return $qtd / 1000.0;
    }
    if (($u1 === 'litro' || $u1 === 'l') && $u2 === 'ml') {
        return $qtd * 1000.0;
    }
    return $qtd;
}

// =====================================================================
// AUDITORIA / HISTÓRICO DE ALTERAÇÕES (Módulo 8 / Segurança)
// =====================================================================
function pz_registrar_auditoria($modulo, $acao, $detalhes = '', $usuario = '')
{
    $registro = [
        'id' => pz_uid('aud'),
        'data_hora' => date('Y-m-d H:i:s'),
        'usuario' => pz_limpar_str($usuario !== '' ? $usuario : 'sistema', 80),
        'modulo' => pz_limpar_str($modulo, 40),
        'acao' => pz_limpar_str($acao, 80),
        'detalhes' => pz_limpar_str($detalhes, 400),
    ];
    Database::mutate('auditoria.json', function (&$lista) use ($registro) {
        if (!is_array($lista)) {
            $lista = [];
        }
        array_unshift($lista, $registro);
        if (count($lista) > 500) {
            $lista = array_slice($lista, 0, 500);
        }
        return true;
    });
    return $registro;
}

function pz_listar_auditoria($limite = 100)
{
    $lista = Database::readStrict('auditoria.json');
    if (!is_array($lista)) {
        return [];
    }
    return array_slice($lista, 0, max(1, min(300, (int) $limite)));
}

// =====================================================================
// MÓDULO 1 — CONTROLE DE ESTOQUE & FORNECEDORES
// =====================================================================
function pz_ler_estoque()
{
    $data = Database::readStrict('estoque.json');
    $itens = is_array($data['itens'] ?? null) ? $data['itens'] : [];
    $fornecedores = is_array($data['fornecedores'] ?? null) ? $data['fornecedores'] : [];
    return [
        'itens' => $itens,
        'fornecedores' => $fornecedores,
    ];
}

function pz_ler_movimentacoes_estoque($limite = 150)
{
    $lista = Database::readStrict('movimentacoes_estoque.json');
    if (!is_array($lista)) {
        return [];
    }
    return array_slice($lista, 0, max(1, min(500, (int) $limite)));
}

function pz_salvar_item_estoque(array $input, $usuario = '')
{
    $id = pz_limpar_str($input['id'] ?? '', 60);
    $nome = pz_limpar_str($input['nome'] ?? '', 120);
    if ($nome === '') {
        return ['success' => false, 'message' => 'Informe o nome do ingrediente ou mercadoria.'];
    }
    $unidadesValidas = ['kg', 'g', 'litro', 'ml', 'unidade', 'pacote', 'caixa'];
    $unidade = strtolower(pz_limpar_str($input['unidade'] ?? 'unidade', 20));
    if (!in_array($unidade, $unidadesValidas, true)) {
        $unidade = 'unidade';
    }
    $tiposValidos = ['ingrediente', 'revenda', 'embalagem'];
    $tipo = strtolower(pz_limpar_str($input['tipo'] ?? 'ingrediente', 20));
    if (!in_array($tipo, $tiposValidos, true)) {
        $tipo = 'ingrediente';
    }

    $itemSalvo = null;
    $ok = Database::mutate('estoque.json', function (&$data) use ($id, $nome, $unidade, $tipo, $input, &$itemSalvo) {
        if (!is_array($data)) {
            $data = ['itens' => [], 'fornecedores' => []];
        }
        if (!is_array($data['itens'] ?? null)) {
            $data['itens'] = [];
        }
        $existenteIdx = null;
        if ($id !== '') {
            foreach ($data['itens'] as $idx => $it) {
                if (($it['id'] ?? '') === $id) {
                    $existenteIdx = $idx;
                    break;
                }
            }
        }
        $agora = date('Y-m-d H:i:s');
        $novo = [
            'id' => $id !== '' ? $id : pz_uid('est'),
            'codigo' => pz_limpar_str($input['codigo'] ?? '', 40),
            'nome' => $nome,
            'tipo' => $tipo,
            'unidade' => $unidade,
            'quantidade_atual' => round((float) ($input['quantidade_atual'] ?? ($existenteIdx !== null ? $data['itens'][$existenteIdx]['quantidade_atual'] : 0)), 3),
            'estoque_minimo' => max(0, round((float) ($input['estoque_minimo'] ?? 0), 3)),
            'custo_unitario' => max(0, round((float) ($input['custo_unitario'] ?? 0), 4)),
            'fornecedor_id' => pz_limpar_str($input['fornecedor_id'] ?? '', 60),
            'atualizado_em' => $agora,
        ];
        if ($existenteIdx !== null) {
            $data['itens'][$existenteIdx] = $novo;
        } else {
            $novo['criado_em'] = $agora;
            $data['itens'][] = $novo;
        }
        $itemSalvo = $novo;
        return true;
    });

    if ($ok && $itemSalvo) {
        pz_registrar_auditoria('estoque', $id !== '' ? 'item_atualizado' : 'item_cadastrado', $itemSalvo['nome'] . ' (' . $itemSalvo['quantidade_atual'] . ' ' . $itemSalvo['unidade'] . ')', $usuario);
        return ['success' => true, 'item' => $itemSalvo];
    }
    return ['success' => false, 'message' => 'Não foi possível salvar o item no estoque.'];
}

function pz_excluir_item_estoque($id, $usuario = '')
{
    $id = pz_limpar_str($id, 60);
    $removido = '';
    $ok = Database::mutate('estoque.json', function (&$data) use ($id, &$removido) {
        if (!is_array($data['itens'] ?? null)) {
            return false;
        }
        $antes = count($data['itens']);
        $data['itens'] = array_values(array_filter($data['itens'], function ($it) use ($id, &$removido) {
            if (($it['id'] ?? '') === $id) {
                $removido = $it['nome'] ?? $id;
                return false;
            }
            return true;
        }));
        return count($data['itens']) < $antes;
    });
    if ($ok) {
        pz_registrar_auditoria('estoque', 'item_excluido', $removido, $usuario);
        return ['success' => true];
    }
    return ['success' => false, 'message' => 'Item não encontrado.'];
}

function pz_salvar_fornecedor(array $input, $usuario = '')
{
    $id = pz_limpar_str($input['id'] ?? '', 60);
    $nome = pz_limpar_str($input['nome'] ?? '', 120);
    if ($nome === '') {
        return ['success' => false, 'message' => 'Informe o nome do fornecedor.'];
    }
    $salvo = null;
    $ok = Database::mutate('estoque.json', function (&$data) use ($id, $nome, $input, &$salvo) {
        if (!is_array($data)) {
            $data = ['itens' => [], 'fornecedores' => []];
        }
        if (!is_array($data['fornecedores'] ?? null)) {
            $data['fornecedores'] = [];
        }
        $idxEncontrado = null;
        if ($id !== '') {
            foreach ($data['fornecedores'] as $idx => $f) {
                if (($f['id'] ?? '') === $id) {
                    $idxEncontrado = $idx;
                    break;
                }
            }
        }
        $novo = [
            'id' => $id !== '' ? $id : pz_uid('forn'),
            'nome' => $nome,
            'telefone' => preg_replace('/\D/', '', (string) ($input['telefone'] ?? '')),
            'cnpj' => preg_replace('/\D/', '', (string) ($input['cnpj'] ?? '')),
            'categoria' => pz_limpar_str($input['categoria'] ?? '', 80),
            'observacao' => pz_limpar_str($input['observacao'] ?? '', 250),
            'atualizado_em' => date('Y-m-d H:i:s'),
        ];
        if ($idxEncontrado !== null) {
            $data['fornecedores'][$idxEncontrado] = $novo;
        } else {
            $data['fornecedores'][] = $novo;
        }
        $salvo = $novo;
        return true;
    });
    if ($ok) {
        pz_registrar_auditoria('estoque', 'fornecedor_salvo', $nome, $usuario);
        return ['success' => true, 'fornecedor' => $salvo];
    }
    return ['success' => false, 'message' => 'Erro ao salvar fornecedor.'];
}

function pz_excluir_fornecedor($id, $usuario = '')
{
    $id = pz_limpar_str($id, 60);
    $ok = Database::mutate('estoque.json', function (&$data) use ($id) {
        if (!is_array($data['fornecedores'] ?? null)) {
            return false;
        }
        $antes = count($data['fornecedores']);
        $data['fornecedores'] = array_values(array_filter($data['fornecedores'], function ($f) use ($id) {
            return ($f['id'] ?? '') !== $id;
        }));
        return count($data['fornecedores']) < $antes;
    });
    if ($ok) {
        pz_registrar_auditoria('estoque', 'fornecedor_excluido', $id, $usuario);
    }
    return ['success' => (bool) $ok];
}

/**
 * Tipos de movimentação:
 * - compra (entrada + atualiza custo_unitario + opcionalmente lança conta a pagar)
 * - entrada (entrada manual)
 * - saida (saída manual)
 * - perda (perda/desperdício)
 * - ajuste (define quantidade exata de inventário)
 * - venda_baixa / venda_estorno (automáticas por pedido)
 */
function pz_movimentar_estoque(array $input, $usuario = '')
{
    $itemId = pz_limpar_str($input['item_id'] ?? '', 60);
    $tipo = strtolower(pz_limpar_str($input['tipo'] ?? 'entrada', 30));
    $qtd = round((float) ($input['quantidade'] ?? 0), 3);
    $custoTotal = isset($input['custo_total']) && $input['custo_total'] !== '' ? max(0, (float) $input['custo_total']) : null;
    $custoUnit = isset($input['custo_unitario']) && $input['custo_unitario'] !== '' ? max(0, (float) $input['custo_unitario']) : null;
    $motivo = pz_limpar_str($input['motivo'] ?? '', 200);
    $fornecedorId = pz_limpar_str($input['fornecedor_id'] ?? '', 60);
    $pedidoId = pz_limpar_str($input['pedido_id'] ?? '', 60);

    if ($itemId === '' || ($tipo !== 'ajuste' && $qtd <= 0)) {
        return ['success' => false, 'message' => 'Informe o item e uma quantidade válida.'];
    }

    $itemAtualizado = null;
    $movRegistrada = null;
    $erro = '';

    $ok = Database::mutate('estoque.json', function (&$data) use (
        $itemId, $tipo, $qtd, $custoTotal, $custoUnit, $motivo, $fornecedorId, $pedidoId, $usuario, &$itemAtualizado, &$movRegistrada, &$erro
    ) {
        if (!is_array($data['itens'] ?? null)) {
            $erro = 'Estoque vazio.';
            return false;
        }
        $idxAlvo = null;
        foreach ($data['itens'] as $idx => $it) {
            if (($it['id'] ?? '') === $itemId) {
                $idxAlvo = $idx;
                break;
            }
        }
        if ($idxAlvo === null) {
            $erro = 'Item não encontrado no estoque.';
            return false;
        }

        $it = $data['itens'][$idxAlvo];
        $anterior = (float) ($it['quantidade_atual'] ?? 0);
        $novoSaldo = $anterior;

        if (in_array($tipo, ['compra', 'entrada', 'venda_estorno'], true)) {
            $novoSaldo = round($anterior + $qtd, 3);
        } elseif (in_array($tipo, ['saida', 'perda', 'venda_baixa'], true)) {
            $novoSaldo = round($anterior - $qtd, 3);
        } elseif ($tipo === 'ajuste') {
            $novoSaldo = round(max(0, $qtd), 3);
        } else {
            $erro = 'Tipo de movimentação inválido.';
            return false;
        }

        $unitCalculado = (float) ($it['custo_unitario'] ?? 0);
        if ($tipo === 'compra') {
            if ($custoUnit !== null && $custoUnit > 0) {
                $unitCalculado = round($custoUnit, 4);
            } elseif ($custoTotal !== null && $custoTotal > 0 && $qtd > 0) {
                $unitCalculado = round($custoTotal / $qtd, 4);
            }
            $it['custo_unitario'] = $unitCalculado;
            if ($fornecedorId !== '') {
                $it['fornecedor_id'] = $fornecedorId;
            }
        }

        $it['quantidade_atual'] = $novoSaldo;
        $it['atualizado_em'] = date('Y-m-d H:i:s');
        $data['itens'][$idxAlvo] = $it;
        $itemAtualizado = $it;

        $valorMov = $custoTotal !== null
            ? round($custoTotal, 2)
            : round(($tipo === 'ajuste' ? abs($novoSaldo - $anterior) : $qtd) * (float) $it['custo_unitario'], 2);

        $movRegistrada = [
            'id' => pz_uid('mov'),
            'data_hora' => date('Y-m-d H:i:s'),
            'item_id' => $itemId,
            'item_nome' => $it['nome'] ?? '',
            'unidade' => $it['unidade'] ?? 'unidade',
            'tipo' => $tipo,
            'quantidade' => $tipo === 'ajuste' ? round($novoSaldo - $anterior, 3) : $qtd,
            'saldo_anterior' => $anterior,
            'saldo_posterior' => $novoSaldo,
            'custo_unitario' => (float) ($it['custo_unitario'] ?? 0),
            'valor_total' => $valorMov,
            'fornecedor_id' => $fornecedorId,
            'pedido_id' => $pedidoId,
            'motivo' => $motivo,
            'usuario' => pz_limpar_str($usuario !== '' ? $usuario : 'sistema', 80),
        ];
        return true;
    });

    if (!$ok || !$movRegistrada) {
        return ['success' => false, 'message' => $erro ?: 'Falha ao movimentar estoque.'];
    }

    Database::mutate('movimentacoes_estoque.json', function (&$movs) use ($movRegistrada) {
        if (!is_array($movs)) {
            $movs = [];
        }
        array_unshift($movs, $movRegistrada);
        if (count($movs) > 1000) {
            $movs = array_slice($movs, 0, 1000);
        }
        return true;
    });

    if (!empty($input['lancar_financeiro']) && $tipo === 'compra' && $movRegistrada['valor_total'] > 0) {
        pz_salvar_conta_financeira([
            'tipo' => 'pagar',
            'descricao' => 'Compra estoque: ' . $itemAtualizado['nome'] . ' (' . $qtd . ' ' . $itemAtualizado['unidade'] . ')',
            'categoria' => 'Fornecedores / Mercadorias',
            'valor' => $movRegistrada['valor_total'],
            'vencimento' => pz_limpar_str($input['vencimento'] ?? date('Y-m-d'), 20),
            'status' => !empty($input['ja_pago']) ? 'pago' : 'pendente',
            'forma_pagamento' => pz_limpar_str($input['forma_pagamento'] ?? 'PIX', 30),
        ], $usuario);
    }

    if (!in_array($tipo, ['venda_baixa', 'venda_estorno'], true)) {
        pz_registrar_auditoria('estoque', 'mov_' . $tipo, $itemAtualizado['nome'] . ': ' . $qtd . ' ' . $itemAtualizado['unidade'], $usuario);
    }

    return ['success' => true, 'item' => $itemAtualizado, 'movimentacao' => $movRegistrada];
}

// =====================================================================
// MÓDULO 2 — FICHA TÉCNICA, CMV E BAIXA AUTOMÁTICA
// =====================================================================
function pz_ler_fichas_tecnicas()
{
    $lista = Database::readStrict('fichas_tecnicas.json');
    return is_array($lista) ? $lista : [];
}

function pz_calcular_custo_ficha(array $ficha, array $estoqueItens)
{
    $mapaEst = [];
    foreach ($estoqueItens as $it) {
        if (is_array($it) && !empty($it['id'])) {
            $mapaEst[(string) $it['id']] = $it;
        }
    }
    $custoTotal = 0.0;
    $ingredientesDetalhados = [];
    foreach ((array) ($ficha['ingredientes'] ?? []) as $ing) {
        if (!is_array($ing) || empty($ing['item_id'])) {
            continue;
        }
        $est = $mapaEst[(string) $ing['item_id']] ?? null;
        $qtdUso = max(0, (float) ($ing['quantidade'] ?? 0));
        $unidadeUso = strtolower(trim((string) ($ing['unidade'] ?? ($est['unidade'] ?? 'unidade'))));
        $unidadeEst = strtolower(trim((string) ($est['unidade'] ?? $unidadeUso)));
        $custoUnitEst = (float) ($est['custo_unitario'] ?? 0);
        $qtdConvertida = pz_converter_unidade($qtdUso, $unidadeUso, $unidadeEst);
        $custoLinha = round($qtdConvertida * $custoUnitEst, 4);
        $custoTotal += $custoLinha;
        $ingredientesDetalhados[] = [
            'item_id' => (string) $ing['item_id'],
            'nome' => $est['nome'] ?? ($ing['nome'] ?? 'Ingrediente'),
            'quantidade' => $qtdUso,
            'unidade' => $unidadeUso,
            'unidade_estoque' => $unidadeEst,
            'quantidade_estoque' => round($qtdConvertida, 4),
            'custo_unitario_estoque' => $custoUnitEst,
            'custo_linha' => round($custoLinha, 2),
        ];
    }
    $rendimento = max(1, (float) ($ficha['rendimento'] ?? 1));
    $custoUnitario = round($custoTotal / $rendimento, 2);
    return [
        'ingredientes' => $ingredientesDetalhados,
        'custo_total_receita' => round($custoTotal, 2),
        'rendimento' => $rendimento,
        'custo_unitario' => $custoUnitario,
    ];
}

function pz_salvar_ficha_tecnica(array $input, $usuario = '')
{
    $produtoId = pz_limpar_str($input['produto_id'] ?? '', 60);
    $produtoNome = pz_limpar_str($input['produto_nome'] ?? '', 120);
    $ingredientes = is_array($input['ingredientes'] ?? null) ? $input['ingredientes'] : [];
    $rendimento = max(1, (float) ($input['rendimento'] ?? 1));

    if ($produtoId === '' || $produtoNome === '') {
        return ['success' => false, 'message' => 'Selecione um produto do cardápio.'];
    }
    if (!$ingredientes) {
        return ['success' => false, 'message' => 'Adicione pelo menos um ingrediente na ficha técnica.'];
    }

    $estoque = pz_ler_estoque()['itens'];
    $ficha = [
        'produto_id' => $produtoId,
        'produto_nome' => $produtoNome,
        'rendimento' => $rendimento,
        'ingredientes' => $ingredientes,
        'atualizado_em' => date('Y-m-d H:i:s'),
    ];
    $calc = pz_calcular_custo_ficha($ficha, $estoque);
    $ficha['ingredientes'] = $calc['ingredientes'];
    $ficha['custo_total'] = $calc['custo_total_receita'];
    $ficha['custo_unitario'] = $calc['custo_unitario'];

    $ok = Database::mutate('fichas_tecnicas.json', function (&$fichas) use ($produtoId, $ficha) {
        if (!is_array($fichas)) {
            $fichas = [];
        }
        $atualizado = false;
        foreach ($fichas as &$row) {
            if ((string) ($row['produto_id'] ?? '') === (string) $produtoId) {
                $row = $ficha;
                $atualizado = true;
                break;
            }
        }
        unset($row);
        if (!$atualizado) {
            $fichas[] = $ficha;
        }
        return true;
    });

    if ($ok) {
        pz_registrar_auditoria('ficha_tecnica', 'ficha_salva', $produtoNome . ' — Custo R$ ' . number_format($ficha['custo_unitario'], 2, ',', '.'), $usuario);
        return ['success' => true, 'ficha' => $ficha];
    }
    return ['success' => false, 'message' => 'Erro ao salvar ficha técnica.'];
}

function pz_excluir_ficha_tecnica($produtoId, $usuario = '')
{
    $produtoId = pz_limpar_str($produtoId, 60);
    $ok = Database::mutate('fichas_tecnicas.json', function (&$fichas) use ($produtoId) {
        if (!is_array($fichas)) {
            return false;
        }
        $antes = count($fichas);
        $fichas = array_values(array_filter($fichas, function ($f) use ($produtoId) {
            return (string) ($f['produto_id'] ?? '') !== (string) $produtoId;
        }));
        return count($fichas) < $antes;
    });
    if ($ok) {
        pz_registrar_auditoria('ficha_tecnica', 'ficha_excluida', $produtoId, $usuario);
    }
    return ['success' => (bool) $ok];
}

function pz_mapa_fichas_por_produto()
{
    $fichas = pz_ler_fichas_tecnicas();
    $estoque = pz_ler_estoque()['itens'];
    $mapa = [];
    foreach ($fichas as $f) {
        if (!is_array($f) || empty($f['produto_id'])) {
            continue;
        }
        $calc = pz_calcular_custo_ficha($f, $estoque);
        $f['custo_total'] = $calc['custo_total_receita'];
        $f['custo_unitario'] = $calc['custo_unitario'];
        $f['ingredientes'] = $calc['ingredientes'];
        $mapa[(string) $f['produto_id']] = $f;
    }
    return $mapa;
}

/**
 * Baixa automática idempotente de ingredientes ao entrar em preparo/concluído.
 */
function pz_baixar_estoque_pedido($pedidoId, $usuario = 'sistema')
{
    $pedido = Database::findOrder($pedidoId);
    if (!$pedido || !empty($pedido['estoque_baixado']) || !empty($pedido['cancelado'])) {
        return ['executado' => false, 'motivo' => 'ja_baixado_ou_invalido'];
    }
    $fichas = pz_mapa_fichas_por_produto();
    $baixados = 0;

    foreach ((array) ($pedido['itens'] ?? []) as $item) {
        $pid = (string) ($item['idProduto'] ?? $item['id'] ?? '');
        $qtdVenda = max(1, (int) ($item['quantidade'] ?? 1));
        $ficha = $fichas[$pid] ?? null;
        if (!$ficha) {
            continue;
        }
        $rend = max(1, (float) ($ficha['rendimento'] ?? 1));
        foreach ((array) ($ficha['ingredientes'] ?? []) as $ing) {
            $qtdEst = (float) ($ing['quantidade_estoque'] ?? 0);
            if ($qtdEst <= 0 || empty($ing['item_id'])) {
                continue;
            }
            $consumo = round(($qtdEst / $rend) * $qtdVenda, 4);
            if ($consumo > 0) {
                pz_movimentar_estoque([
                    'item_id' => $ing['item_id'],
                    'tipo' => 'venda_baixa',
                    'quantidade' => $consumo,
                    'pedido_id' => (string) $pedidoId,
                    'motivo' => 'Baixa automática pedido #' . $pedidoId . ' (' . $qtdVenda . 'x ' . ($item['nome'] ?? '') . ')',
                ], $usuario);
                $baixados++;
            }
        }
    }

    Database::mutate('pedidos.json', function (&$orders) use ($pedidoId) {
        foreach ($orders as &$o) {
            if ((string) ($o['id'] ?? '') === (string) $pedidoId) {
                $o['estoque_baixado'] = true;
                $o['estoque_baixado_em'] = date('Y-m-d H:i:s');
                return true;
            }
        }
        unset($o);
        return false;
    });

    return ['executado' => true, 'movimentacoes' => $baixados];
}

/**
 * Estorno automático idempotente quando um pedido com baixa é cancelado/arquivado/revertido.
 */
function pz_estornar_estoque_pedido($pedidoId, $usuario = 'sistema')
{
    $pedido = Database::findOrder($pedidoId);
    if (!$pedido || empty($pedido['estoque_baixado'])) {
        return ['executado' => false];
    }
    $fichas = pz_mapa_fichas_por_produto();
    $estornados = 0;

    foreach ((array) ($pedido['itens'] ?? []) as $item) {
        $pid = (string) ($item['idProduto'] ?? $item['id'] ?? '');
        $qtdVenda = max(1, (int) ($item['quantidade'] ?? 1));
        $ficha = $fichas[$pid] ?? null;
        if (!$ficha) {
            continue;
        }
        $rend = max(1, (float) ($ficha['rendimento'] ?? 1));
        foreach ((array) ($ficha['ingredientes'] ?? []) as $ing) {
            $qtdEst = (float) ($ing['quantidade_estoque'] ?? 0);
            if ($qtdEst <= 0 || empty($ing['item_id'])) {
                continue;
            }
            $retorno = round(($qtdEst / $rend) * $qtdVenda, 4);
            if ($retorno > 0) {
                pz_movimentar_estoque([
                    'item_id' => $ing['item_id'],
                    'tipo' => 'venda_estorno',
                    'quantidade' => $retorno,
                    'pedido_id' => (string) $pedidoId,
                    'motivo' => 'Estorno automático pedido #' . $pedidoId,
                ], $usuario);
                $estornados++;
            }
        }
    }

    Database::mutate('pedidos.json', function (&$orders) use ($pedidoId) {
        foreach ($orders as &$o) {
            if ((string) ($o['id'] ?? '') === (string) $pedidoId) {
                $o['estoque_baixado'] = false;
                return true;
            }
        }
        unset($o);
        return false;
    });

    return ['executado' => true, 'movimentacoes' => $estornados];
}

// =====================================================================
// MÓDULO 3 & 4 — CAIXA DIÁRIO, FINANCEIRO, DRE E LUCRATIVIDADE
// =====================================================================
function pz_ler_caixa()
{
    $data = Database::readStrict('caixa.json');
    return [
        'atual' => is_array($data['atual'] ?? null) ? $data['atual'] : null,
        'historico' => is_array($data['historico'] ?? null) ? $data['historico'] : [],
    ];
}

function pz_abrir_caixa($saldoInicial, $usuario = '', $observacao = '')
{
    $saldoInicial = max(0, round((float) $saldoInicial, 2));
    $caixaAberto = null;
    $erro = '';

    $ok = Database::mutate('caixa.json', function (&$data) use ($saldoInicial, $usuario, $observacao, &$caixaAberto, &$erro) {
        if (!is_array($data)) {
            $data = ['atual' => null, 'historico' => []];
        }
        if (!empty($data['atual']) && ($data['atual']['status'] ?? '') === 'aberto') {
            $erro = 'Já existe um caixa aberto.';
            return false;
        }
        $caixaAberto = [
            'id' => pz_uid('cx'),
            'status' => 'aberto',
            'aberto_em' => date('Y-m-d H:i:s'),
            'aberto_por' => pz_limpar_str($usuario ?: 'admin', 80),
            'saldo_inicial' => $saldoInicial,
            'observacao' => pz_limpar_str($observacao, 200),
            'movimentacoes' => [],
        ];
        $data['atual'] = $caixaAberto;
        return true;
    });

    if (!$ok) {
        return ['success' => false, 'message' => $erro ?: 'Erro ao abrir caixa.'];
    }
    pz_registrar_auditoria('financeiro', 'caixa_aberto', 'Saldo inicial R$ ' . number_format($saldoInicial, 2, ',', '.'), $usuario);
    return ['success' => true, 'caixa' => $caixaAberto];
}

function pz_movimentar_caixa($tipo, $valor, $descricao, $forma = 'Dinheiro', $usuario = '', $pedidoId = '')
{
    $tipo = strtolower(pz_limpar_str($tipo, 20));
    if (!in_array($tipo, ['suprimento', 'sangria', 'venda', 'estorno'], true)) {
        return ['success' => false, 'message' => 'Tipo de movimentação de caixa inválido.'];
    }
    $valor = round(max(0, (float) $valor), 2);
    if ($valor <= 0) {
        return ['success' => false, 'message' => 'Informe um valor maior que zero.'];
    }

    $mov = [
        'id' => pz_uid('cmov'),
        'data_hora' => date('Y-m-d H:i:s'),
        'tipo' => $tipo,
        'valor' => $valor,
        'forma' => pz_limpar_str($forma ?: 'Dinheiro', 30),
        'descricao' => pz_limpar_str($descricao, 200),
        'pedido_id' => pz_limpar_str($pedidoId, 60),
        'usuario' => pz_limpar_str($usuario ?: 'admin', 80),
    ];

    $caixaAtualizado = null;
    $erro = '';
    $ok = Database::mutate('caixa.json', function (&$data) use ($mov, &$caixaAtualizado, &$erro) {
        if (empty($data['atual']) || ($data['atual']['status'] ?? '') !== 'aberto') {
            $erro = 'O caixa está fechado. Abra o caixa antes de registrar movimentações.';
            return false;
        }
        $data['atual']['movimentacoes'][] = $mov;
        $caixaAtualizado = $data['atual'];
        return true;
    });

    if (!$ok) {
        return ['success' => false, 'message' => $erro ?: 'Erro ao registrar no caixa.'];
    }
    if (!in_array($tipo, ['venda', 'estorno'], true)) {
        pz_registrar_auditoria('financeiro', 'caixa_' . $tipo, $mov['descricao'] . ' — R$ ' . number_format($valor, 2, ',', '.'), $usuario);
    }
    return ['success' => true, 'movimentacao' => $mov, 'caixa' => $caixaAtualizado];
}

function pz_calcular_totais_caixa(?array $caixa)
{
    if (!$caixa) {
        return [
            'saldo_inicial' => 0.0,
            'suprimentos' => 0.0,
            'sangrias' => 0.0,
            'vendas_dinheiro' => 0.0,
            'vendas_pix' => 0.0,
            'vendas_cartao' => 0.0,
            'estornos' => 0.0,
            'esperado_dinheiro' => 0.0,
            'total_vendas' => 0.0,
        ];
    }
    $inicial = (float) ($caixa['saldo_inicial'] ?? 0);
    $sup = 0.0;
    $san = 0.0;
    $din = 0.0;
    $pix = 0.0;
    $car = 0.0;
    $est = 0.0;

    foreach ((array) ($caixa['movimentacoes'] ?? []) as $m) {
        $t = $m['tipo'] ?? '';
        $v = (float) ($m['valor'] ?? 0);
        $f = strtolower((string) ($m['forma'] ?? ''));
        if ($t === 'suprimento') {
            $sup += $v;
        } elseif ($t === 'sangria') {
            $san += $v;
        } elseif ($t === 'estorno') {
            $est += $v;
            if (strpos($f, 'dinheiro') !== false) {
                $din -= $v;
            } elseif (strpos($f, 'pix') !== false) {
                $pix -= $v;
            } else {
                $car -= $v;
            }
        } elseif ($t === 'venda') {
            if (strpos($f, 'dinheiro') !== false) {
                $din += $v;
            } elseif (strpos($f, 'pix') !== false) {
                $pix += $v;
            } else {
                $car += $v;
            }
        }
    }

    $esperadoDinheiro = round($inicial + $sup + $din - $san, 2);
    $totalVendas = round($din + $pix + $car, 2);
    return [
        'saldo_inicial' => round($inicial, 2),
        'suprimentos' => round($sup, 2),
        'sangrias' => round($san, 2),
        'vendas_dinheiro' => round($din, 2),
        'vendas_pix' => round($pix, 2),
        'vendas_cartao' => round($car, 2),
        'estornos' => round($est, 2),
        'esperado_dinheiro' => $esperadoDinheiro,
        'total_vendas' => $totalVendas,
    ];
}

function pz_fechar_caixa(array $contado, $usuario = '', $observacao = '')
{
    $fechado = null;
    $erro = '';

    $ok = Database::mutate('caixa.json', function (&$data) use ($contado, $usuario, $observacao, &$fechado, &$erro) {
        if (empty($data['atual']) || ($data['atual']['status'] ?? '') !== 'aberto') {
            $erro = 'Não há caixa aberto para fechar.';
            return false;
        }
        $cx = $data['atual'];
        $totais = pz_calcular_totais_caixa($cx);
        $cDin = isset($contado['dinheiro']) && $contado['dinheiro'] !== '' ? round((float) $contado['dinheiro'], 2) : $totais['esperado_dinheiro'];
        $cPix = isset($contado['pix']) && $contado['pix'] !== '' ? round((float) $contado['pix'], 2) : $totais['vendas_pix'];
        $cCar = isset($contado['cartao']) && $contado['cartao'] !== '' ? round((float) $contado['cartao'], 2) : $totais['vendas_cartao'];

        $esperadoTotal = round($totais['esperado_dinheiro'] + $totais['vendas_pix'] + $totais['vendas_cartao'], 2);
        $contadoTotal = round($cDin + $cPix + $cCar, 2);

        $cx['status'] = 'fechado';
        $cx['fechado_em'] = date('Y-m-d H:i:s');
        $cx['fechado_por'] = pz_limpar_str($usuario ?: 'admin', 80);
        $cx['observacao_fechamento'] = pz_limpar_str($observacao, 250);
        $cx['resumo'] = $totais;
        $cx['conferencia'] = [
            'dinheiro_contado' => $cDin,
            'pix_contado' => $cPix,
            'cartao_contado' => $cCar,
            'total_contado' => $contadoTotal,
            'total_esperado' => $esperadoTotal,
            'diferenca_dinheiro' => round($cDin - $totais['esperado_dinheiro'], 2),
            'diferenca_total' => round($contadoTotal - $esperadoTotal, 2),
        ];

        if (!is_array($data['historico'] ?? null)) {
            $data['historico'] = [];
        }
        array_unshift($data['historico'], $cx);
        if (count($data['historico']) > 120) {
            $data['historico'] = array_slice($data['historico'], 0, 120);
        }
        $data['atual'] = null;
        $fechado = $cx;
        return true;
    });

    if (!$ok) {
        return ['success' => false, 'message' => $erro ?: 'Erro ao fechar caixa.'];
    }
    pz_registrar_auditoria('financeiro', 'caixa_fechado', 'Diferença total R$ ' . number_format((float) ($fechado['conferencia']['diferenca_total'] ?? 0), 2, ',', '.'), $usuario);
    return ['success' => true, 'caixa' => $fechado];
}

function pz_ler_financeiro()
{
    $data = Database::readStrict('financeiro.json');
    return is_array($data['contas'] ?? null) ? $data['contas'] : [];
}

function pz_salvar_conta_financeira(array $input, $usuario = '')
{
    $id = pz_limpar_str($input['id'] ?? '', 60);
    $tipo = strtolower(pz_limpar_str($input['tipo'] ?? 'pagar', 20));
    if (!in_array($tipo, ['pagar', 'receber'], true)) {
        $tipo = 'pagar';
    }
    $descricao = pz_limpar_str($input['descricao'] ?? '', 180);
    $valor = round(max(0, (float) ($input['valor'] ?? 0)), 2);
    if ($descricao === '' || $valor <= 0) {
        return ['success' => false, 'message' => 'Informe descrição e valor válido.'];
    }
    $status = strtolower(pz_limpar_str($input['status'] ?? 'pendente', 20));
    if (!in_array($status, ['pendente', 'pago', 'cancelado'], true)) {
        $status = 'pendente';
    }

    $salva = null;
    $ok = Database::mutate('financeiro.json', function (&$data) use ($id, $tipo, $descricao, $valor, $status, $input, $usuario, &$salva) {
        if (!is_array($data)) {
            $data = ['contas' => []];
        }
        if (!is_array($data['contas'] ?? null)) {
            $data['contas'] = [];
        }
        $idxEncontrado = null;
        if ($id !== '') {
            foreach ($data['contas'] as $idx => $c) {
                if (($c['id'] ?? '') === $id) {
                    $idxEncontrado = $idx;
                    break;
                }
            }
        }
        $agora = date('Y-m-d H:i:s');
        $novo = [
            'id' => $id !== '' ? $id : pz_uid('fin'),
            'tipo' => $tipo,
            'descricao' => $descricao,
            'categoria' => pz_limpar_str($input['categoria'] ?? ($tipo === 'pagar' ? 'Despesas diversas' : 'Vendas'), 60),
            'fornecedor_cliente' => pz_limpar_str($input['fornecedor_cliente'] ?? '', 100),
            'valor' => $valor,
            'vencimento' => pz_limpar_str($input['vencimento'] ?? date('Y-m-d'), 20),
            'status' => $status,
            'recorrente' => !empty($input['recorrente']),
            'forma_pagamento' => pz_limpar_str($input['forma_pagamento'] ?? 'PIX', 30),
            'conciliado' => !empty($input['conciliado']) || $status === 'pago',
            'pago_em' => $status === 'pago' ? ($input['pago_em'] ?? date('Y-m-d')) : '',
            'pedido_id' => pz_limpar_str($input['pedido_id'] ?? '', 60),
            'usuario' => pz_limpar_str($usuario ?: 'admin', 80),
            'atualizado_em' => $agora,
        ];
        if ($idxEncontrado !== null) {
            $data['contas'][$idxEncontrado] = $novo;
        } else {
            $novo['criado_em'] = $agora;
            array_unshift($data['contas'], $novo);
        }
        $salva = $novo;
        return true;
    });

    if ($ok && $salva) {
        if (empty($salva['pedido_id'])) {
            pz_registrar_auditoria('financeiro', 'conta_' . $tipo, $descricao . ' — R$ ' . number_format($valor, 2, ',', '.'), $usuario);
        }
        return ['success' => true, 'conta' => $salva];
    }
    return ['success' => false, 'message' => 'Erro ao salvar lançamento financeiro.'];
}

function pz_marcar_conta_paga($id, $usuario = '')
{
    $id = pz_limpar_str($id, 60);
    $conta = null;
    $ok = Database::mutate('financeiro.json', function (&$data) use ($id, &$conta) {
        if (!is_array($data['contas'] ?? null)) {
            return false;
        }
        foreach ($data['contas'] as &$c) {
            if (($c['id'] ?? '') === $id) {
                $c['status'] = 'pago';
                $c['pago_em'] = date('Y-m-d');
                $c['conciliado'] = true;
                $c['atualizado_em'] = date('Y-m-d H:i:s');
                $conta = $c;
                return true;
            }
        }
        unset($c);
        return false;
    });
    if ($ok && $conta) {
        pz_registrar_auditoria('financeiro', 'conta_quitada', $conta['descricao'] . ' — R$ ' . number_format((float) $conta['valor'], 2, ',', '.'), $usuario);
        return ['success' => true, 'conta' => $conta];
    }
    return ['success' => false, 'message' => 'Lançamento não encontrado.'];
}

function pz_excluir_conta_financeira($id, $usuario = '')
{
    $id = pz_limpar_str($id, 60);
    $ok = Database::mutate('financeiro.json', function (&$data) use ($id) {
        if (!is_array($data['contas'] ?? null)) {
            return false;
        }
        $antes = count($data['contas']);
        $data['contas'] = array_values(array_filter($data['contas'], function ($c) use ($id) {
            return ($c['id'] ?? '') !== $id;
        }));
        return count($data['contas']) < $antes;
    });
    if ($ok) {
        pz_registrar_auditoria('financeiro', 'conta_excluida', $id, $usuario);
    }
    return ['success' => (bool) $ok];
}

/**
 * Registra receita do pedido no caixa aberto e em contas a receber (conciliada) de forma idempotente.
 */
function pz_registrar_venda_financeiro($pedidoId, $usuario = 'sistema')
{
    $pedido = Database::findOrder($pedidoId);
    if (!$pedido || !empty($pedido['financeiro_lancado']) || !empty($pedido['cancelado'])) {
        return ['executado' => false];
    }
    $total = round((float) ($pedido['total'] ?? 0), 2);
    if ($total <= 0) {
        return ['executado' => false];
    }
    $forma = (string) ($pedido['pagamento'] ?? 'Dinheiro');

    // Se houver caixa aberto, lança no caixa
    $cx = pz_ler_caixa();
    if (!empty($cx['atual']) && ($cx['atual']['status'] ?? '') === 'aberto') {
        pz_movimentar_caixa('venda', $total, 'Pedido #' . $pedidoId . ' (' . ($pedido['cliente'] ?? 'Cliente') . ')', $forma, $usuario, (string) $pedidoId);
    }

    // Lança em contas a receber como recebido (conciliado)
    pz_salvar_conta_financeira([
        'tipo' => 'receber',
        'descricao' => 'Venda Pedido #' . $pedidoId . ' — ' . ($pedido['cliente'] ?? 'Cliente'),
        'categoria' => 'Vendas ' . ($pedido['canal'] ?: 'site'),
        'fornecedor_cliente' => $pedido['cliente'] ?? 'Cliente',
        'valor' => $total,
        'vencimento' => date('Y-m-d'),
        'status' => 'pago',
        'pago_em' => date('Y-m-d'),
        'forma_pagamento' => $forma,
        'conciliado' => true,
        'pedido_id' => (string) $pedidoId,
    ], $usuario);

    Database::mutate('pedidos.json', function (&$orders) use ($pedidoId) {
        foreach ($orders as &$o) {
            if ((string) ($o['id'] ?? '') === (string) $pedidoId) {
                $o['financeiro_lancado'] = true;
                return true;
            }
        }
        unset($o);
        return false;
    });

    return ['executado' => true];
}

function pz_estornar_venda_financeiro($pedidoId, $usuario = 'sistema')
{
    $pedido = Database::findOrder($pedidoId);
    if (!$pedido || empty($pedido['financeiro_lancado'])) {
        return ['executado' => false];
    }
    $total = round((float) ($pedido['total'] ?? 0), 2);
    $forma = (string) ($pedido['pagamento'] ?? 'Dinheiro');

    $cx = pz_ler_caixa();
    if ($total > 0 && !empty($cx['atual']) && ($cx['atual']['status'] ?? '') === 'aberto') {
        pz_movimentar_caixa('estorno', $total, 'Estorno Pedido #' . $pedidoId, $forma, $usuario, (string) $pedidoId);
    }

    Database::mutate('financeiro.json', function (&$data) use ($pedidoId) {
        if (!is_array($data['contas'] ?? null)) {
            return false;
        }
        foreach ($data['contas'] as &$c) {
            if ((string) ($c['pedido_id'] ?? '') === (string) $pedidoId) {
                $c['status'] = 'cancelado';
            }
        }
        unset($c);
        return true;
    });

    Database::mutate('pedidos.json', function (&$orders) use ($pedidoId) {
        foreach ($orders as &$o) {
            if ((string) ($o['id'] ?? '') === (string) $pedidoId) {
                $o['financeiro_lancado'] = false;
                return true;
            }
        }
        unset($o);
        return false;
    });

    return ['executado' => true];
}

/**
 * Calcula DRE Gerencial e Lucratividade real por período (YYYY-MM ou intervalo YYYY-MM-DD..YYYY-MM-DD).
 */
function pz_calcular_dre($dataInicio = '', $dataFim = '')
{
    if ($dataInicio === '') {
        $dataInicio = date('Y-m-01');
    }
    if ($dataFim === '') {
        $dataFim = date('Y-m-t');
    }
    $iniTs = strtotime($dataInicio . ' 00:00:00');
    $fimTs = strtotime($dataFim . ' 23:59:59');

    $pedidos = Database::readStrict('pedidos.json');
    if (!is_array($pedidos)) {
        $pedidos = [];
    }
    $cardapio = Database::readStrict('cardapio.json');
    if (!is_array($cardapio)) {
        $cardapio = [];
    }
    $mapaCategoriaProd = [];
    foreach ($cardapio as $cat => $lista) {
        if (!is_array($lista)) {
            continue;
        }
        foreach ($lista as $p) {
            if (is_array($p) && isset($p['id'])) {
                $mapaCategoriaProd[(string) $p['id']] = $cat;
            }
        }
    }

    $fichas = pz_mapa_fichas_por_produto();

    $faturamentoBruto = 0.0;
    $descontos = 0.0;
    $taxasEntrega = 0.0;
    $cancelamentosValor = 0.0;
    $cancelamentosQtd = 0;
    $pedidosValidos = 0;
    $cmvEstimado = 0.0;
    $recebidoConfirmado = 0.0;

    $porPagamento = [];
    $porCanal = [];
    $porCategoria = [];
    $porProduto = [];

    foreach ($pedidos as $ped) {
        if (!is_array($ped)) {
            continue;
        }
        $quando = (string) ($ped['timestamp'] ?? $ped['data'] ?? '');
        $ts = strtotime(str_replace('/', '-', $quando));
        if ($ts === false || $ts < $iniTs || $ts > $fimTs) {
            continue;
        }
        $status = (string) ($ped['status'] ?? 'novo');
        $total = (float) ($ped['total'] ?? 0);
        if (!empty($ped['cancelado']) || $status === 'arquivado') {
            if (!empty($ped['cancelado'])) {
                $cancelamentosQtd++;
                $cancelamentosValor += $total;
            }
            continue;
        }

        $pedidosValidos++;
        $faturamentoBruto += $total;
        $descontos += (float) ($ped['desconto'] ?? 0);
        $taxasEntrega += (float) ($ped['frete'] ?? 0);
        if ($status === 'concluido') {
            $recebidoConfirmado += $total;
        }

        $pg = trim((string) ($ped['pagamento'] ?? 'Não informado')) ?: 'Não informado';
        if (!isset($porPagamento[$pg])) {
            $porPagamento[$pg] = ['forma' => $pg, 'pedidos' => 0, 'valor' => 0.0];
        }
        $porPagamento[$pg]['pedidos']++;
        $porPagamento[$pg]['valor'] += $total;

        $canal = trim((string) ($ped['canal'] ?? 'site')) ?: 'site';
        if (!isset($porCanal[$canal])) {
            $porCanal[$canal] = ['canal' => $canal, 'pedidos' => 0, 'valor' => 0.0, 'lucro' => 0.0];
        }
        $porCanal[$canal]['pedidos']++;
        $porCanal[$canal]['valor'] += $total;

        $cmvPedido = 0.0;
        foreach ((array) ($ped['itens'] ?? []) as $it) {
            if (!is_array($it)) {
                continue;
            }
            $pid = (string) ($it['idProduto'] ?? $it['id'] ?? '');
            $nome = (string) ($it['nome'] ?? 'Item');
            $qtd = max(1, (int) ($it['quantidade'] ?? 1));
            $receitaItem = (float) ($it['precoTotal'] ?? 0) * $qtd;
            $custoUnit = isset($fichas[$pid]) ? (float) ($fichas[$pid]['custo_unitario'] ?? 0) : 0.0;
            $custoItem = $custoUnit * $qtd;
            $cmvPedido += $custoItem;

            if (!isset($porProduto[$pid ?: $nome])) {
                $porProduto[$pid ?: $nome] = [
                    'id' => $pid,
                    'nome' => $nome,
                    'quantidade' => 0,
                    'faturamento' => 0.0,
                    'cmv' => 0.0,
                    'lucro' => 0.0,
                    'tem_ficha' => isset($fichas[$pid]),
                    'margem_pct' => 0.0,
                ];
            }
            $k = $pid ?: $nome;
            $porProduto[$k]['quantidade'] += $qtd;
            $porProduto[$k]['faturamento'] += $receitaItem;
            $porProduto[$k]['cmv'] += $custoItem;

            $cat = $mapaCategoriaProd[$pid] ?? 'outros';
            if (!isset($porCategoria[$cat])) {
                $porCategoria[$cat] = ['categoria' => $cat, 'faturamento' => 0.0, 'cmv' => 0.0, 'lucro' => 0.0];
            }
            $porCategoria[$cat]['faturamento'] += $receitaItem;
            $porCategoria[$cat]['cmv'] += $custoItem;
        }
        $cmvEstimado += $cmvPedido;
        $porCanal[$canal]['lucro'] += ($total - $cmvPedido);
    }

    // Perdas de estoque no período somam no consumo real
    $perdasEstoque = 0.0;
    foreach (pz_ler_movimentacoes_estoque(500) as $mov) {
        if (($mov['tipo'] ?? '') !== 'perda') {
            continue;
        }
        $tsM = strtotime((string) ($mov['data_hora'] ?? ''));
        if ($tsM !== false && $tsM >= $iniTs && $tsM <= $fimTs) {
            $perdasEstoque += (float) ($mov['valor_total'] ?? 0);
        }
    }

    // Despesas operacionais (contas a pagar no período) e contas pendentes
    $contas = pz_ler_financeiro();
    $despesasPagas = 0.0;
    $despesasPendentes = 0.0;
    $despesasPorCategoria = [];
    $contasVencendo = [];
    $hojeStr = date('Y-m-d');
    $em7Dias = date('Y-m-d', strtotime('+7 days'));

    foreach ($contas as $c) {
        if (!is_array($c) || ($c['tipo'] ?? '') !== 'pagar' || ($c['status'] ?? '') === 'cancelado') {
            continue;
        }
        $venc = (string) ($c['vencimento'] ?? '');
        $val = (float) ($c['valor'] ?? 0);
        if (($c['status'] ?? '') === 'pendente' && $venc !== '' && $venc <= $em7Dias) {
            $contasVencendo[] = $c;
        }
        $ref = ($c['status'] ?? '') === 'pago' && !empty($c['pago_em']) ? $c['pago_em'] : $venc;
        $tsC = strtotime($ref . ' 12:00:00');
        if ($tsC !== false && $tsC >= $iniTs && $tsC <= $fimTs) {
            if (($c['status'] ?? '') === 'pago') {
                $despesasPagas += $val;
            } else {
                $despesasPendentes += $val;
            }
            $catD = $c['categoria'] ?? 'Outras';
            if (!isset($despesasPorCategoria[$catD])) {
                $despesasPorCategoria[$catD] = 0.0;
            }
            $despesasPorCategoria[$catD] += $val;
        }
    }

    $cmvTotal = round($cmvEstimado + $perdasEstoque, 2);
    $despesasTotais = round($despesasPagas + $despesasPendentes, 2);
    $margemContribuicao = round($faturamentoBruto - $cmvTotal, 2);
    $resultadoOperacional = round($margemContribuicao - $despesasTotais, 2);
    $ticketMedio = $pedidosValidos > 0 ? round($faturamentoBruto / $pedidosValidos, 2) : 0.0;

    $listaProdutos = array_values(array_map(function ($p) {
        $p['faturamento'] = round($p['faturamento'], 2);
        $p['cmv'] = round($p['cmv'], 2);
        $p['lucro'] = round($p['faturamento'] - $p['cmv'], 2);
        $p['margem_pct'] = $p['faturamento'] > 0 ? round(($p['lucro'] / $p['faturamento']) * 100, 1) : 0.0;
        return $p;
    }, $porProduto));
    usort($listaProdutos, function ($a, $b) {
        return $b['lucro'] <=> $a['lucro'];
    });

    $listaCats = array_values(array_map(function ($c) {
        $c['faturamento'] = round($c['faturamento'], 2);
        $c['cmv'] = round($c['cmv'], 2);
        $c['lucro'] = round($c['faturamento'] - $c['cmv'], 2);
        return $c;
    }, $porCategoria));

    return [
        'periodo' => ['inicio' => $dataInicio, 'fim' => $dataFim],
        'pedidos' => $pedidosValidos,
        'faturamento_bruto' => round($faturamentoBruto, 2),
        'recebido_confirmado' => round($recebidoConfirmado, 2),
        'a_receber_aberto' => round(max(0, $faturamentoBruto - $recebidoConfirmado), 2),
        'descontos_concedidos' => round($descontos, 2),
        'taxas_entrega' => round($taxasEntrega, 2),
        'cancelamentos' => ['quantidade' => $cancelamentosQtd, 'valor' => round($cancelamentosValor, 2)],
        'ticket_medio' => $ticketMedio,
        'cmv_produtos' => round($cmvEstimado, 2),
        'perdas_estoque' => round($perdasEstoque, 2),
        'cmv_total' => $cmvTotal,
        'margem_contribuicao' => $margemContribuicao,
        'margem_contribuicao_pct' => $faturamentoBruto > 0 ? round(($margemContribuicao / $faturamentoBruto) * 100, 1) : 0.0,
        'despesas_pagas' => round($despesasPagas, 2),
        'despesas_pendentes' => round($despesasPendentes, 2),
        'despesas_totais' => $despesasTotais,
        'resultado_operacional' => $resultadoOperacional,
        'lucratividade_pct' => $faturamentoBruto > 0 ? round(($resultadoOperacional / $faturamentoBruto) * 100, 1) : 0.0,
        'por_pagamento' => array_values($porPagamento),
        'por_canal' => array_values($porCanal),
        'por_categoria' => $listaCats,
        'por_produto' => $listaProdutos,
        'despesas_por_categoria' => $despesasPorCategoria,
        'contas_vencendo_7d' => $contasVencendo,
    ];
}

// =====================================================================
// MÓDULO 5 & 6 — CLIENTES, FIDELIDADE, CUPONS E RECUPERAÇÃO
// =====================================================================
function pz_ler_clientes()
{
    $lista = Database::readStrict('clientes.json');
    return is_array($lista) ? $lista : [];
}

function pz_sincronizar_cliente_pedido(array $pedido)
{
    $tel = preg_replace('/\D/', '', (string) ($pedido['celular'] ?? ''));
    $nome = pz_limpar_str($pedido['cliente'] ?? '', 100);
    if ($tel === '' && $nome === '') {
        return;
    }
    $chave = $tel !== '' ? $tel : md5(strtolower($nome));
    $total = round((float) ($pedido['total'] ?? 0), 2);
    $cfg = pedidozap_normalizar_config(Database::readStrict('config.json'), Database::getTenant());
    $ptsPorReal = max(0, (float) ($cfg['AGENTE_IA']['fidelidade_pontos_por_real'] ?? 1));
    $pontosGanhos = (int) floor($total * $ptsPorReal);

    Database::mutate('clientes.json', function (&$clientes) use ($chave, $tel, $nome, $pedido, $total, $pontosGanhos) {
        if (!is_array($clientes)) {
            $clientes = [];
        }
        $idxEncontrado = null;
        foreach ($clientes as $idx => $cli) {
            if (($cli['chave'] ?? '') === $chave || ($tel !== '' && ($cli['telefone'] ?? '') === $tel)) {
                $idxEncontrado = $idx;
                break;
            }
        }
        $end = is_array($pedido['endereco'] ?? null) ? $pedido['endereco'] : [];
        $endTexto = trim(implode(', ', array_filter([
            $end['rua'] ?? '',
            $end['numero'] ?? '',
            $end['bairro'] ?? '',
            $end['cidade'] ?? '',
        ])));
        $agora = date('Y-m-d H:i:s');
        if ($idxEncontrado !== null) {
            $c = $clientes[$idxEncontrado];
            $c['nome'] = $nome ?: ($c['nome'] ?? 'Cliente');
            if ($tel !== '') {
                $c['telefone'] = $tel;
            }
            if ($endTexto !== '') {
                $c['endereco'] = $endTexto;
            }
            $c['total_pedidos'] = (int) ($c['total_pedidos'] ?? 0) + 1;
            $c['total_gasto'] = round((float) ($c['total_gasto'] ?? 0) + $total, 2);
            $c['pontos_fidelidade'] = (int) ($c['pontos_fidelidade'] ?? 0) + $pontosGanhos;
            $c['ultima_compra'] = $agora;
            $c['ultimo_pedido_id'] = (string) ($pedido['id'] ?? '');
            $clientes[$idxEncontrado] = $c;
        } else {
            array_unshift($clientes, [
                'id' => pz_uid('cli'),
                'chave' => $chave,
                'nome' => $nome ?: 'Cliente',
                'telefone' => $tel,
                'endereco' => $endTexto,
                'total_pedidos' => 1,
                'total_gasto' => $total,
                'pontos_fidelidade' => $pontosGanhos,
                'preferencias' => '',
                'observacoes' => '',
                'primeira_compra' => $agora,
                'ultima_compra' => $agora,
                'ultimo_pedido_id' => (string) ($pedido['id'] ?? ''),
            ]);
        }
        return true;
    });
}

function pz_salvar_cliente(array $input, $usuario = '')
{
    $id = pz_limpar_str($input['id'] ?? '', 60);
    $nome = pz_limpar_str($input['nome'] ?? '', 100);
    $tel = preg_replace('/\D/', '', (string) ($input['telefone'] ?? ''));
    if ($nome === '') {
        return ['success' => false, 'message' => 'Informe o nome do cliente.'];
    }
    $salvo = null;
    $ok = Database::mutate('clientes.json', function (&$clientes) use ($id, $nome, $tel, $input, &$salvo) {
        if (!is_array($clientes)) {
            $clientes = [];
        }
        $idx = null;
        foreach ($clientes as $i => $c) {
            if (($id !== '' && ($c['id'] ?? '') === $id) || ($tel !== '' && ($c['telefone'] ?? '') === $tel)) {
                $idx = $i;
                break;
            }
        }
        $base = $idx !== null ? $clientes[$idx] : [
            'id' => $id !== '' ? $id : pz_uid('cli'),
            'chave' => $tel ?: md5(strtolower($nome)),
            'total_pedidos' => 0,
            'total_gasto' => 0,
            'pontos_fidelidade' => 0,
            'primeira_compra' => date('Y-m-d H:i:s'),
            'ultima_compra' => '',
        ];
        $base['nome'] = $nome;
        $base['telefone'] = $tel;
        $base['endereco'] = pz_limpar_str($input['endereco'] ?? ($base['endereco'] ?? ''), 250);
        $base['preferencias'] = pz_limpar_str($input['preferencias'] ?? ($base['preferencias'] ?? ''), 250);
        $base['observacoes'] = pz_limpar_str($input['observacoes'] ?? ($base['observacoes'] ?? ''), 300);
        if (isset($input['pontos_fidelidade'])) {
            $base['pontos_fidelidade'] = max(0, (int) $input['pontos_fidelidade']);
        }
        if ($idx !== null) {
            $clientes[$idx] = $base;
        } else {
            array_unshift($clientes, $base);
        }
        $salvo = $base;
        return true;
    });
    if ($ok) {
        pz_registrar_auditoria('clientes', 'cliente_salvo', $nome, $usuario);
        return ['success' => true, 'cliente' => $salvo];
    }
    return ['success' => false, 'message' => 'Erro ao salvar cliente.'];
}

function pz_ler_marketing()
{
    $data = Database::readStrict('marketing.json');
    return [
        'cupons' => is_array($data['cupons'] ?? null) ? $data['cupons'] : [],
        'fidelidade_regras' => is_array($data['fidelidade_regras'] ?? null) ? $data['fidelidade_regras'] : [
            'ativo' => true,
            'pontos_por_real' => 1,
            'pontos_para_resgate' => 100,
            'desconto_resgate' => 10.0,
        ],
    ];
}

function pz_salvar_cupom(array $input, $usuario = '')
{
    $codigo = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($input['codigo'] ?? '')));
    if (strlen($codigo) < 3) {
        return ['success' => false, 'message' => 'Informe um código de cupom com pelo menos 3 caracteres.'];
    }
    $tipo = in_array($input['tipo'] ?? 'percentual', ['percentual', 'valor', 'frete_gratis'], true) ? $input['tipo'] : 'percentual';
    $valor = max(0, round((float) ($input['valor'] ?? 0), 2));
    if ($tipo !== 'frete_gratis' && $valor <= 0) {
        return ['success' => false, 'message' => 'Informe o valor ou percentual do desconto.'];
    }
    $cupom = [
        'id' => pz_limpar_str($input['id'] ?? '', 60) ?: pz_uid('cup'),
        'codigo' => $codigo,
        'tipo' => $tipo,
        'valor' => $valor,
        'minimo_pedido' => max(0, round((float) ($input['minimo_pedido'] ?? 0), 2)),
        'primeira_compra' => !empty($input['primeira_compra']),
        'ativo' => !isset($input['ativo']) || !empty($input['ativo']),
        'usos' => max(0, (int) ($input['usos'] ?? 0)),
        'validade' => pz_limpar_str($input['validade'] ?? '', 20),
        'atualizado_em' => date('Y-m-d H:i:s'),
    ];
    $ok = Database::mutate('marketing.json', function (&$data) use ($cupom, $codigo) {
        if (!is_array($data)) {
            $data = ['cupons' => []];
        }
        if (!is_array($data['cupons'] ?? null)) {
            $data['cupons'] = [];
        }
        $atualizado = false;
        foreach ($data['cupons'] as &$c) {
            if (($c['id'] ?? '') === $cupom['id'] || strtoupper((string) ($c['codigo'] ?? '')) === $codigo) {
                $cupom['usos'] = (int) ($c['usos'] ?? $cupom['usos']);
                $c = $cupom;
                $atualizado = true;
                break;
            }
        }
        unset($c);
        if (!$atualizado) {
            array_unshift($data['cupons'], $cupom);
        }
        return true;
    });
    if ($ok) {
        pz_registrar_auditoria('marketing', 'cupom_salvo', $codigo, $usuario);
        return ['success' => true, 'cupom' => $cupom];
    }
    return ['success' => false, 'message' => 'Erro ao salvar cupom.'];
}

function pz_excluir_cupom($codigoOuId, $usuario = '')
{
    $alvo = strtoupper(pz_limpar_str($codigoOuId, 60));
    $ok = Database::mutate('marketing.json', function (&$data) use ($alvo) {
        if (!is_array($data['cupons'] ?? null)) {
            return false;
        }
        $antes = count($data['cupons']);
        $data['cupons'] = array_values(array_filter($data['cupons'], function ($c) use ($alvo) {
            return strtoupper((string) ($c['id'] ?? '')) !== $alvo && strtoupper((string) ($c['codigo'] ?? '')) !== $alvo;
        }));
        return count($data['cupons']) < $antes;
    });
    if ($ok) {
        pz_registrar_auditoria('marketing', 'cupom_excluido', $alvo, $usuario);
    }
    return ['success' => (bool) $ok];
}

function pz_validar_cupom($codigo, $subtotal, $frete = 0.0, $celular = '')
{
    $codigo = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string) $codigo));
    if ($codigo === '') {
        return ['valido' => false, 'message' => 'Cupom vazio.', 'desconto' => 0.0];
    }
    $mkt = pz_ler_marketing();
    $cupom = null;
    foreach ($mkt['cupons'] as $c) {
        if (strtoupper((string) ($c['codigo'] ?? '')) === $codigo) {
            $cupom = $c;
            break;
        }
    }
    // Suporte ao cupom clássico FIDELIDADE10 caso ainda não cadastrado manualmente
    if (!$cupom && $codigo === 'FIDELIDADE10') {
        $cupom = ['codigo' => 'FIDELIDADE10', 'tipo' => 'percentual', 'valor' => 10, 'minimo_pedido' => 0, 'ativo' => true];
    }
    if (!$cupom || empty($cupom['ativo'])) {
        return ['valido' => false, 'message' => 'Cupom não encontrado ou inativo.', 'desconto' => 0.0];
    }
    if (!empty($cupom['validade']) && $cupom['validade'] < date('Y-m-d')) {
        return ['valido' => false, 'message' => 'Cupom expirado.', 'desconto' => 0.0];
    }
    $minimo = (float) ($cupom['minimo_pedido'] ?? 0);
    if ($subtotal < $minimo) {
        return ['valido' => false, 'message' => 'Pedido mínimo de R$ ' . number_format($minimo, 2, ',', '.') . ' para este cupom.', 'desconto' => 0.0];
    }
    if (!empty($cupom['primeira_compra']) && $celular !== '') {
        $tel = preg_replace('/\D/', '', $celular);
        foreach (pz_ler_clientes() as $cli) {
            if (($cli['telefone'] ?? '') === $tel && (int) ($cli['total_pedidos'] ?? 0) > 0) {
                return ['valido' => false, 'message' => 'Cupom válido apenas para primeira compra.', 'desconto' => 0.0];
            }
        }
    }

    $desconto = 0.0;
    if (($cupom['tipo'] ?? '') === 'percentual') {
        $desconto = round($subtotal * ((float) $cupom['valor'] / 100), 2);
    } elseif (($cupom['tipo'] ?? '') === 'valor') {
        $desconto = round(min($subtotal, (float) $cupom['valor']), 2);
    } elseif (($cupom['tipo'] ?? '') === 'frete_gratis') {
        $desconto = round((float) $frete, 2);
    }

    return [
        'valido' => true,
        'codigo' => $codigo,
        'tipo' => $cupom['tipo'],
        'desconto' => $desconto,
        'message' => 'Cupom ' . $codigo . ' aplicado!',
    ];
}

function pz_incrementar_uso_cupom($codigo)
{
    $codigo = strtoupper(trim((string) $codigo));
    if ($codigo === '') {
        return;
    }
    Database::mutate('marketing.json', function (&$data) use ($codigo) {
        if (!is_array($data['cupons'] ?? null)) {
            return false;
        }
        foreach ($data['cupons'] as &$c) {
            if (strtoupper((string) ($c['codigo'] ?? '')) === $codigo) {
                $c['usos'] = (int) ($c['usos'] ?? 0) + 1;
                return true;
            }
        }
        unset($c);
        return false;
    });
}

function pz_ler_recuperacao()
{
    $lista = Database::readStrict('recuperacao.json');
    return is_array($lista) ? $lista : [];
}

function pz_registrar_carrinho_recuperacao(array $input)
{
    $sessao = pz_limpar_str($input['sessao_id'] ?? '', 80);
    $itens = is_array($input['itens'] ?? null) ? $input['itens'] : [];
    if ($sessao === '') {
        return false;
    }
    $tel = preg_replace('/\D/', '', (string) ($input['celular'] ?? ''));
    $nome = pz_limpar_str($input['cliente'] ?? '', 80);
    $total = round((float) ($input['total'] ?? 0), 2);

    return Database::mutate('recuperacao.json', function (&$lista) use ($sessao, $itens, $tel, $nome, $total) {
        if (!is_array($lista)) {
            $lista = [];
        }
        if (!$itens || $total <= 0) {
            $lista = array_values(array_filter($lista, function ($r) use ($sessao) {
                return ($r['sessao_id'] ?? '') !== $sessao;
            }));
            return true;
        }
        $registro = [
            'sessao_id' => $sessao,
            'cliente' => $nome ?: 'Visitante',
            'celular' => $tel,
            'total' => $total,
            'qtd_itens' => count($itens),
            'itens_resumo' => array_slice(array_map(function ($i) {
                return ($i['quantidade'] ?? 1) . 'x ' . ($i['nome'] ?? 'Item');
            }, $itens), 0, 5),
            'status' => 'abandonado',
            'atualizado_em' => date('Y-m-d H:i:s'),
        ];
        $achou = false;
        foreach ($lista as &$r) {
            if (($r['sessao_id'] ?? '') === $sessao) {
                if (($r['status'] ?? '') === 'convertido') {
                    return false;
                }
                $r = array_merge($r, $registro);
                $achou = true;
                break;
            }
        }
        unset($r);
        if (!$achou) {
            array_unshift($lista, $registro);
        }
        if (count($lista) > 100) {
            $lista = array_slice($lista, 0, 100);
        }
        return true;
    });
}

function pz_marcar_carrinho_recuperado($sessaoOuTel, $status = 'convertido')
{
    $alvo = trim((string) $sessaoOuTel);
    if ($alvo === '') {
        return false;
    }
    return Database::mutate('recuperacao.json', function (&$lista) use ($alvo, $status) {
        if (!is_array($lista)) {
            return false;
        }
        $mudou = false;
        foreach ($lista as &$r) {
            if (($r['sessao_id'] ?? '') === $alvo || ($r['celular'] ?? '') !== '' && ($r['celular'] ?? '') === preg_replace('/\D/', '', $alvo)) {
                $r['status'] = $status;
                $r['atualizado_em'] = date('Y-m-d H:i:s');
                $mudou = true;
            }
        }
        unset($r);
        return $mudou;
    });
}

// =====================================================================
// MÓDULO 7 — ENTREGADORES
// =====================================================================
function pz_ler_entregadores()
{
    $lista = Database::readStrict('entregadores.json');
    return is_array($lista) ? $lista : [];
}

function pz_salvar_entregador(array $input, $usuario = '')
{
    $id = pz_limpar_str($input['id'] ?? '', 60);
    $nome = pz_limpar_str($input['nome'] ?? '', 100);
    if ($nome === '') {
        return ['success' => false, 'message' => 'Informe o nome do entregador.'];
    }
    $salvo = null;
    $ok = Database::mutate('entregadores.json', function (&$lista) use ($id, $nome, $input, &$salvo) {
        if (!is_array($lista)) {
            $lista = [];
        }
        $idx = null;
        foreach ($lista as $i => $e) {
            if ($id !== '' && ($e['id'] ?? '') === $id) {
                $idx = $i;
                break;
            }
        }
        $novo = [
            'id' => $id !== '' ? $id : pz_uid('ent'),
            'nome' => $nome,
            'telefone' => preg_replace('/\D/', '', (string) ($input['telefone'] ?? '')),
            'veiculo' => pz_limpar_str($input['veiculo'] ?? 'Moto', 50),
            'placa' => pz_limpar_str($input['placa'] ?? '', 20),
            'taxa_padrao' => max(0, round((float) ($input['taxa_padrao'] ?? 0), 2)),
            'ativo' => !isset($input['ativo']) || !empty($input['ativo']),
            'atualizado_em' => date('Y-m-d H:i:s'),
        ];
        if ($idx !== null) {
            $lista[$idx] = $novo;
        } else {
            array_unshift($lista, $novo);
        }
        $salvo = $novo;
        return true;
    });
    if ($ok) {
        pz_registrar_auditoria('entregadores', 'entregador_salvo', $nome, $usuario);
        return ['success' => true, 'entregador' => $salvo];
    }
    return ['success' => false, 'message' => 'Erro ao salvar entregador.'];
}

function pz_relatorio_entregadores()
{
    $entregadores = pz_ler_entregadores();
    $pedidos = Database::readStrict('pedidos.json');
    if (!is_array($pedidos)) {
        $pedidos = [];
    }
    $stats = [];
    foreach ($entregadores as $e) {
        $stats[$e['id']] = [
            'id' => $e['id'],
            'nome' => $e['nome'],
            'entregas' => 0,
            'valor_pedidos' => 0.0,
            'taxas_entrega' => 0.0,
        ];
    }
    foreach ($pedidos as $p) {
        if (!is_array($p) || empty($p['entregador_id']) || ($p['status'] ?? '') === 'arquivado' || !empty($p['cancelado'])) {
            continue;
        }
        $eid = (string) $p['entregador_id'];
        if (!isset($stats[$eid])) {
            $stats[$eid] = [
                'id' => $eid,
                'nome' => $p['entregador_nome'] ?? 'Entregador',
                'entregas' => 0,
                'valor_pedidos' => 0.0,
                'taxas_entrega' => 0.0,
            ];
        }
        $stats[$eid]['entregas']++;
        $stats[$eid]['valor_pedidos'] = round($stats[$eid]['valor_pedidos'] + (float) ($p['total'] ?? 0), 2);
        $stats[$eid]['taxas_entrega'] = round($stats[$eid]['taxas_entrega'] + (float) ($p['frete'] ?? 0), 2);
    }
    return array_values($stats);
}

// =====================================================================
// MÓDULO 8 — GESTÃO DE FUNCIONÁRIOS & BACKUP
// =====================================================================
function pz_listar_funcionarios()
{
    $lista = Database::readStrict('funcionarios.json');
    if (!is_array($lista)) {
        return [];
    }
    return array_map(function ($f) {
        unset($f['pass_hash']);
        return $f;
    }, $lista);
}

function pz_salvar_funcionario(array $input, $usuario = '')
{
    $id = pz_limpar_str($input['id'] ?? '', 60);
    $nome = pz_limpar_str($input['nome'] ?? '', 100);
    $login = strtolower(preg_replace('/[^a-zA-Z0-9._-]/', '', (string) ($input['login'] ?? '')));
    $funcao = strtolower(pz_limpar_str($input['funcao'] ?? 'atendente', 30));
    if (!in_array($funcao, ['administrador', 'gerente', 'atendente', 'cozinha', 'entregador'], true)) {
        $funcao = 'atendente';
    }
    $senha = (string) ($input['senha'] ?? '');

    if ($nome === '' || strlen($login) < 3) {
        return ['success' => false, 'message' => 'Informe nome e usuário (mínimo 3 caracteres).'];
    }
    if ($id === '' && strlen($senha) < 4) {
        return ['success' => false, 'message' => 'Informe uma senha (mínimo 4 caracteres) para o novo funcionário.'];
    }

    $perms = is_array($input['permissoes'] ?? null)
        ? array_merge(pedidozap_perfis_permissoes_padrao($funcao), $input['permissoes'])
        : pedidozap_perfis_permissoes_padrao($funcao);

    $salvo = null;
    $erro = '';
    $ok = Database::mutate('funcionarios.json', function (&$lista) use ($id, $nome, $login, $funcao, $senha, $perms, $input, &$salvo, &$erro) {
        if (!is_array($lista)) {
            $lista = [];
        }
        $idxAlvo = null;
        foreach ($lista as $i => $f) {
            if ($id !== '' && ($f['id'] ?? '') === $id) {
                $idxAlvo = $i;
            } elseif (strcasecmp((string) ($f['login'] ?? ''), $login) === 0 && ($id === '' || ($f['id'] ?? '') !== $id)) {
                $erro = 'Já existe outro funcionário com este usuário.';
                return false;
            }
        }
        $existente = $idxAlvo !== null ? $lista[$idxAlvo] : [];
        $hash = $senha !== '' ? password_hash($senha, PASSWORD_DEFAULT) : ($existente['pass_hash'] ?? '');
        if ($hash === '') {
            $erro = 'Defina uma senha para este funcionário.';
            return false;
        }
        $novo = [
            'id' => $id !== '' ? $id : pz_uid('func'),
            'nome' => $nome,
            'telefone' => preg_replace('/\D/', '', (string) ($input['telefone'] ?? '')),
            'funcao' => $funcao,
            'login' => $login,
            'pass_hash' => $hash,
            'permissoes' => $perms,
            'ativo' => !isset($input['ativo']) || !empty($input['ativo']),
            'atualizado_em' => date('Y-m-d H:i:s'),
        ];
        if ($idxAlvo !== null) {
            $lista[$idxAlvo] = $novo;
        } else {
            array_unshift($lista, $novo);
        }
        $salvo = $novo;
        unset($salvo['pass_hash']);
        return true;
    });

    if (!$ok) {
        return ['success' => false, 'message' => $erro ?: 'Erro ao salvar funcionário.'];
    }
    pz_registrar_auditoria('equipe', 'funcionario_salvo', $nome . ' (' . $funcao . ')', $usuario);
    return ['success' => true, 'funcionario' => $salvo];
}

function pz_excluir_funcionario($id, $usuario = '')
{
    $id = pz_limpar_str($id, 60);
    $ok = Database::mutate('funcionarios.json', function (&$lista) use ($id) {
        if (!is_array($lista)) {
            return false;
        }
        $antes = count($lista);
        $lista = array_values(array_filter($lista, function ($f) use ($id) {
            return ($f['id'] ?? '') !== $id;
        }));
        return count($lista) < $antes;
    });
    if ($ok) {
        pz_registrar_auditoria('equipe', 'funcionario_excluido', $id, $usuario);
    }
    return ['success' => (bool) $ok];
}

function pz_exportar_backup_tenant()
{
    $arquivos = [
        'config.json',
        'cardapio.json',
        'pedidos.json',
        'estoque.json',
        'movimentacoes_estoque.json',
        'fichas_tecnicas.json',
        'caixa.json',
        'financeiro.json',
        'clientes.json',
        'marketing.json',
        'entregadores.json',
        'auditoria.json',
    ];
    $snapshot = [
        'gerado_em' => date('c'),
        'tenant' => Database::getTenant(),
        'dados' => [],
    ];
    foreach ($arquivos as $arq) {
        $snapshot['dados'][$arq] = Database::readStrict($arq);
    }
    return $snapshot;
}

// =====================================================================
// RESUMO EXECUTIVO + ALERTAS PROATIVOS PARA A IA E PAINEL DE RESULTADOS
// =====================================================================
function pz_resumo_executivo_loja()
{
    $hoje = date('Y-m-d');
    $semanaIni = date('Y-m-d', strtotime('-6 days'));
    $mesIni = date('Y-m-01');
    $mesFim = date('Y-m-t');

    $dreHoje = pz_calcular_dre($hoje, $hoje);
    $dreSemana = pz_calcular_dre($semanaIni, $hoje);
    $dreMes = pz_calcular_dre($mesIni, $mesFim);

    $estoqueData = pz_ler_estoque();
    $estoqueBaixo = [];
    foreach ($estoqueData['itens'] as $it) {
        $atual = (float) ($it['quantidade_atual'] ?? 0);
        $min = (float) ($it['estoque_minimo'] ?? 0);
        if ($min > 0 && $atual <= $min) {
            $estoqueBaixo[] = [
                'id' => $it['id'],
                'nome' => $it['nome'],
                'unidade' => $it['unidade'],
                'quantidade_atual' => $atual,
                'estoque_minimo' => $min,
                'sugestao_compra' => round(max($min * 2 - $atual, $min), 2),
                'custo_unitario' => (float) ($it['custo_unitario'] ?? 0),
            ];
        }
    }

    $cx = pz_ler_caixa();
    $cxTotais = pz_calcular_totais_caixa($cx['atual']);

    $pedidos = Database::readStrict('pedidos.json');
    if (!is_array($pedidos)) {
        $pedidos = [];
    }
    $abertos = array_values(array_filter($pedidos, function ($p) {
        return is_array($p) && in_array($p['status'] ?? 'novo', ['novo', 'preparo', 'em-rota'], true) && empty($p['cancelado']);
    }));

    $config = pedidozap_normalizar_config(Database::readStrict('config.json'), Database::getTenant());
    $slugAtual = Database::getTenant();
    $nomeLojaRes = trim((string) ($config['NOME_LOJA'] ?? $config['EMPRESA']['nome'] ?? $slugAtual)) ?: $slugAtual;
    $margemMin = (float) ($config['AGENTE_IA']['alerta_margem_minima'] ?? 25);

    // Origem de pedidos no mês (mostra de onde vêm as vendas)
    $origemMes = ['link_direto' => 0, 'marketplace' => 0, 'balcao' => 0, 'recuperacao_ia' => 0];
    foreach ($pedidos as $p) {
        if (!is_array($p) || !empty($p['cancelado'])) {
            continue;
        }
        $tsPed = strtotime((string) ($p['timestamp'] ?? $p['criado_em'] ?? ''));
        if (!$tsPed || date('Y-m', $tsPed) !== date('Y-m')) {
            continue;
        }
        $canal = strtolower(trim((string) ($p['canal'] ?? $p['origem'] ?? '')));
        if (!empty($p['cupom_usado']) && stripos((string) $p['cupom_usado'], 'VOLTA') !== false) {
            $origemMes['recuperacao_ia']++;
        } elseif ($canal === 'balcao' || $canal === 'atendimento' || $canal === 'pdv') {
            $origemMes['balcao']++;
        } elseif (strpos($canal, 'marketplace') !== false) {
            $origemMes['marketplace']++;
        } else {
            $origemMes['link_direto']++;
        }
    }

    // Economia estimada em comissões (~20% que seriam cobrados em apps de entrega tradicionais)
    $taxaRef = 0.20;
    $econHoje = round((float) ($dreHoje['faturamento_bruto'] ?? 0) * $taxaRef, 2);
    $econSemana = round((float) ($dreSemana['faturamento_bruto'] ?? 0) * $taxaRef, 2);
    $econMes = round((float) ($dreMes['faturamento_bruto'] ?? 0) * $taxaRef, 2);

    // Base própria de clientes + radar de clientes sumidos (15+ dias sem pedir)
    $clientesTodos = pz_ler_clientes();
    $clientesWpp = count(array_filter($clientesTodos, function ($c) {
        return is_array($c) && strlen(preg_replace('/\D/', '', (string) ($c['telefone'] ?? ''))) >= 10;
    }));
    $mktDados = pz_ler_marketing();
    $cupomAtivo = '';
    foreach ($mktDados['cupons'] as $cup) {
        if (!empty($cup['ativo']) && (empty($cup['validade']) || $cup['validade'] >= date('Y-m-d'))) {
            $cupomAtivo = (string) ($cup['codigo'] ?? '');
            break;
        }
    }
    $sumidos = pz_clientes_sumidos(15, 25, $nomeLojaRes, $slugAtual, $cupomAtivo);
    $clientesNovosSemana = 0;
    $limiteSemanaTs = strtotime('-6 days 00:00:00');
    foreach ($clientesTodos as $c) {
        if (!is_array($c)) {
            continue;
        }
        $priTs = strtotime((string) ($c['primeira_compra'] ?? $c['ultima_compra'] ?? ''));
        if ($priTs && $priTs >= $limiteSemanaTs) {
            $clientesNovosSemana++;
        }
    }

    $produtosBaixaMargem = array_values(array_filter($dreMes['por_produto'], function ($p) use ($margemMin) {
        return !empty($p['tem_ficha']) && $p['faturamento'] > 0 && $p['margem_pct'] < $margemMin;
    }));

    // Alertas proativos gerados para a IA
    $alertas = [];
    if (count($sumidos) > 0) {
        $alertas[] = [
            'nivel' => 'dica',
            'modulo' => 'clientes',
            'titulo' => count($sumidos) . ' cliente(s) que já compraram não pedem há 15+ dias',
            'mensagem' => 'Chame no WhatsApp em 1 clique com oferta e recupere essas vendas hoje.',
            'comando_ia' => 'Quais clientes estão sumidos?',
        ];
    }
    if (!$cx['atual'] || ($cx['atual']['status'] ?? '') !== 'aberto') {
        $alertas[] = [
            'nivel' => 'aviso',
            'modulo' => 'caixa',
            'titulo' => 'Caixa do dia ainda fechado',
            'mensagem' => 'Abra o caixa para conferir dinheiro, PIX e cartão no fim do expediente.',
            'comando_ia' => 'Abrir caixa com 100 reais',
        ];
    }
    if (count($estoqueBaixo) > 0) {
        $nomes = implode(', ', array_map(function ($x) {
            return $x['nome'];
        }, array_slice($estoqueBaixo, 0, 3)));
        $alertas[] = [
            'nivel' => 'critico',
            'modulo' => 'estoque',
            'titulo' => count($estoqueBaixo) . ' item(ns) no estoque mínimo',
            'mensagem' => 'Repor: ' . $nomes . (count($estoqueBaixo) > 3 ? '...' : ''),
            'comando_ia' => 'O que preciso comprar de estoque?',
        ];
    }
    if (count($dreMes['contas_vencendo_7d']) > 0) {
        $totalVenc = array_sum(array_map(function ($c) {
            return (float) ($c['valor'] ?? 0);
        }, $dreMes['contas_vencendo_7d']));
        $alertas[] = [
            'nivel' => 'aviso',
            'modulo' => 'financeiro',
            'titulo' => count($dreMes['contas_vencendo_7d']) . ' conta(s) a pagar em até 7 dias',
            'mensagem' => 'Total a vencer: R$ ' . number_format($totalVenc, 2, ',', '.'),
            'comando_ia' => 'Quais contas vencem esta semana?',
        ];
    }
    if (count($produtosBaixaMargem) > 0) {
        $p0 = $produtosBaixaMargem[0];
        $alertas[] = [
            'nivel' => 'dica',
            'modulo' => 'ficha_tecnica',
            'titulo' => 'Margem baixa em ' . $p0['nome'] . ' (' . $p0['margem_pct'] . '%)',
            'mensagem' => 'Abaixo da meta de ' . $margemMin . '%. Vale revisar preço ou custo dos ingredientes.',
            'comando_ia' => 'Qual meu lanche mais lucrativo?',
        ];
    }

    $resumoSemanalWpp = "*Resumo Semanal AcheIAki — {$nomeLojaRes}*\n"
        . "• Pedidos na semana: " . (int) ($dreSemana['pedidos'] ?? 0) . " (R$ " . number_format((float) ($dreSemana['faturamento_bruto'] ?? 0), 2, ',', '.') . ")\n"
        . "• Economia em comissões (~20% que não foi para outros apps): R$ " . number_format($econSemana, 2, ',', '.') . "\n"
        . "• Novos clientes salvos na base própria: {$clientesNovosSemana} (Total: {$clientesWpp} com WhatsApp)\n"
        . "• Clientes sumidos há 15+ dias prontos para chamar: " . count($sumidos);

    return [
        'hoje' => $dreHoje,
        'semana' => $dreSemana,
        'mes' => $dreMes,
        'economia_taxas' => [
            'hoje' => $econHoje,
            'semana' => $econSemana,
            'mes' => $econMes,
            'taxa_ref_pct' => 20,
        ],
        'origem_pedidos' => $origemMes,
        'base_clientes' => [
            'total' => count($clientesTodos),
            'com_whatsapp' => $clientesWpp,
            'novos_semana' => $clientesNovosSemana,
            'sumidos_15d' => count($sumidos),
            'sumidos_preview' => array_slice($sumidos, 0, 5),
            'cupom_sugerido' => $cupomAtivo,
        ],
        'resumo_semanal_wpp' => $resumoSemanalWpp,
        'caixa' => [
            'aberto' => !empty($cx['atual']) && ($cx['atual']['status'] ?? '') === 'aberto',
            'atual' => $cx['atual'],
            'totais' => $cxTotais,
        ],
        'pedidos_abertos' => count($abertos),
        'estoque_baixo' => $estoqueBaixo,
        'produtos_baixa_margem' => $produtosBaixaMargem,
        'alertas_ia' => $alertas,
    ];
}

function pz_clientes_sumidos(int $diasMin = 15, int $limite = 40, ?string $nomeLoja = null, ?string $slugLoja = null, ?string $cupom = null): array
{
    $clientes = pz_ler_clientes();
    if (!$clientes) {
        return [];
    }
    $slugAtual = $slugLoja ?: Database::getTenant();
    $baseUrl = function_exists('pedidozap_base_url') ? rtrim(pedidozap_base_url(), '/') : '';
    $linkLoja = $baseUrl !== ''
        ? ($slugAtual !== 'default' ? ($baseUrl . '/loja/' . rawurlencode($slugAtual)) : ($baseUrl . '/index.html'))
        : '';
    $nomeExib = trim((string) ($nomeLoja ?: $slugAtual));
    $agora = time();
    $minSeg = max(1, $diasMin) * 86400;
    $out = [];

    foreach ($clientes as $c) {
        if (!is_array($c)) {
            continue;
        }
        $tel = preg_replace('/\D/', '', (string) ($c['telefone'] ?? ''));
        if (strlen($tel) < 10) {
            continue;
        }
        $ultStr = (string) ($c['ultima_compra'] ?? $c['primeira_compra'] ?? '');
        $ultTs = $ultStr !== '' ? strtotime($ultStr) : false;
        if (!$ultTs || ($agora - $ultTs) < $minSeg) {
            continue;
        }
        $dias = (int) floor(($agora - $ultTs) / 86400);
        $nomeCli = trim((string) ($c['nome'] ?? 'Cliente')) ?: 'Cliente';
        $priNome = explode(' ', $nomeCli)[0];
        $telWa = strlen($tel) <= 11 ? ('55' . $tel) : $tel;
        $cupomTxt = $cupom ? (" Use o cupom *{$cupom}*") : ' Peça direto sem taxa de app';
        $msg = "Oi {$priNome}! Bateu saudade do seu pedido na *{$nomeExib}*! Faz {$dias} dias desde a sua última compra.{$cupomTxt} com preço direto de balcão por aqui" . ($linkLoja ? (": {$linkLoja}") : '.');
        $out[] = [
            'id' => (string) ($c['id'] ?? $tel),
            'nome' => $nomeCli,
            'telefone' => $tel,
            'telefone_mascarado' => strlen($tel) >= 8 ? (substr($tel, 0, 2) . ' *****-' . substr($tel, -4)) : '****',
            'dias_sem_pedir' => $dias,
            'total_pedidos' => (int) ($c['total_pedidos'] ?? 1),
            'total_gasto' => round((float) ($c['total_gasto'] ?? 0), 2),
            'ultima_compra' => $ultStr,
            'whatsapp_url' => 'https://wa.me/' . $telWa . '?text=' . rawurlencode($msg),
            'mensagem_sugerida' => $msg,
        ];
    }

    usort($out, function ($a, $b) {
        return ($b['total_gasto'] <=> $a['total_gasto']) ?: ($a['dias_sem_pedir'] <=> $b['dias_sem_pedir']);
    });
    return array_slice($out, 0, max(1, $limite));
}

function pz_otimizar_cardapio_sugestoes(string $slug, bool $aplicar = false): array
{
    $cardapio = Database::readStrict('cardapio.json');
    if (!is_array($cardapio)) {
        $cardapio = [];
    }
    $cfgLoja = pedidozap_normalizar_config(Database::readStrict('config.json'), $slug);
    $nichoLoja = strtolower(trim((string) ($cfgLoja['NICHO'] ?? 'lanchonete')));
    $nichosComida = ['lanchonete', 'pizzaria', 'marmitaria', 'pensao', 'acai', 'confeitaria'];
    $ehComida = in_array($nichoLoja, $nichosComida, true);

    $ter = function_exists('pz_territorio_analisar_loja') ? pz_territorio_analisar_loja($slug) : [];
    $mediaRegiao = (float) ($ter['ranking']['media_preco_regiao'] ?? 0);

    $melhorias = [];
    $alertasPreco = [];
    $alterados = 0;

    foreach ($cardapio as $cat => $lista) {
        if (!is_array($lista)) {
            continue;
        }
        foreach ($lista as $i => $item) {
            if (!is_array($item) || empty($item['nome'])) {
                continue;
            }
            $nome = trim((string) $item['nome']);
            $desc = trim((string) ($item['descricao'] ?? ''));
            $preco = (float) ($item['preco'] ?? ($item['tamanhos'][0]['preco'] ?? 0));
            $catNorm = mb_strtolower(trim((string) $cat), 'UTF-8');
            $nomeNorm = mb_strtolower($nome, 'UTF-8');

            if (strlen($desc) < 18) {
                if (!$ehComida) {
                    if ($nichoLoja === 'mercado' || strpos($catNorm, 'horti') !== false || strpos($catNorm, 'merc') !== false) {
                        $novaDesc = "{$nome} selecionado com qualidade e preço direto do mercado, pronto para entrega rápida.";
                    } elseif ($nichoLoja === 'farmacia' || strpos($catNorm, 'medic') !== false) {
                        $novaDesc = "{$nome} original com procedência garantida e entrega rápida direto da farmácia perto de você.";
                    } elseif ($nichoLoja === 'petshop' || strpos($catNorm, 'racao') !== false || strpos($catNorm, 'cae') !== false || strpos($catNorm, 'gato') !== false) {
                        $novaDesc = "{$nome} original lacrado de fábrica para cuidar do seu pet com entrega rápida no bairro.";
                    } elseif ($nichoLoja === 'moda' || strpos($catNorm, 'fem') !== false || strpos($catNorm, 'masc') !== false || strpos($catNorm, 'calc') !== false) {
                        $novaDesc = "{$nome} com ótimo caimento e acabamento. Peça direto da loja com preço real e entrega rápida.";
                    } elseif ($nichoLoja === 'eletronicos' || strpos($catNorm, 'celul') !== false || strpos($catNorm, 'audio') !== false) {
                        $novaDesc = "{$nome} testado com garantia da loja local e entrega rápida sem sair de casa.";
                    } elseif ($nichoLoja === 'bebidas') {
                        $novaDesc = "{$nome} trincando de gelada, pronta para entrega rápida direta da distribuidora.";
                    } else {
                        $novaDesc = "{$nome} disponível à pronta entrega com preço direto da loja, sem taxa extra de aplicativo.";
                    }
                } else {
                    if (strpos($nomeNorm, 'pizza') !== false || strpos($catNorm, 'pizza') !== false) {
                        $novaDesc = "{$nome} assada na hora com massa leve, molho artesanal e queijo derretido no ponto certo.";
                    } elseif (strpos($nomeNorm, 'burger') !== false || strpos($nomeNorm, 'x-') !== false || strpos($nomeNorm, 'lanche') !== false || strpos($catNorm, 'lanche') !== false || strpos($catNorm, 'hambur') !== false) {
                        $novaDesc = "{$nome} preparado na chapa na hora com ingredientes frescos e pão macio selado.";
                    } elseif (strpos($nomeNorm, 'batata') !== false || strpos($catNorm, 'porc') !== false) {
                        $novaDesc = "{$nome} crocante por fora, macia por dentro e servida quentinha em porção caprichada.";
                    } elseif (strpos($nomeNorm, 'acai') !== false || strpos($catNorm, 'acai') !== false) {
                        $novaDesc = "{$nome} cremoso batido na hora, bem gelado e pronto para montar com seus acompanhamentos favoritos.";
                    } elseif (strpos($catNorm, 'bebida') !== false || strpos($nomeNorm, 'coca') !== false || strpos($nomeNorm, 'refri') !== false || strpos($nomeNorm, 'suco') !== false) {
                        $novaDesc = "{$nome} trincando de gelada para acompanhar o seu pedido.";
                    } else {
                        $novaDesc = "{$nome} feito na hora com capricho e preço direto da loja, sem taxa extra de aplicativo.";
                    }
                }
                $melhorias[] = [
                    'categoria' => $cat,
                    'nome' => $nome,
                    'antes' => $desc,
                    'depois' => $novaDesc,
                ];
                if ($aplicar) {
                    $cardapio[$cat][$i]['descricao'] = $novaDesc;
                    $alterados++;
                }
            }

            if ($mediaRegiao > 0 && $preco > ($mediaRegiao * 1.15) && strpos($catNorm, 'bebida') === false) {
                $sugerido = round($mediaRegiao * 0.92, 2);
                $alertasPreco[] = [
                    'nome' => $nome,
                    'preco_atual' => $preco,
                    'media_regiao' => $mediaRegiao,
                    'preco_sugerido' => $sugerido,
                ];
            }
        }
    }

    if ($aplicar && $alterados > 0) {
        Database::write('cardapio.json', $cardapio);
        Database::mutate('config.json', function (&$c) {
            if (!is_array($c)) {
                $c = [];
            }
            $c['VERSION'] = time();
            return true;
        });
    }

    return [
        'success' => true,
        'aplicado' => $aplicar,
        'alterados' => $alterados,
        'sugestoes_descricao' => array_slice($melhorias, 0, 20),
        'alertas_preco' => array_slice($alertasPreco, 0, 10),
        'media_regiao' => $mediaRegiao,
    ];
}

// =====================================================================
// MÓDULO 11 — IA PESSOAL DO CLIENTE (CHAVE + URL + PROMPTS + VOZ)
// Não vem preenchida na venda do sistema: só ativa quando o cliente salva
// sua própria API Key e URL de acesso dentro de Trocar senha & IA.
// =====================================================================
function pz_ler_ia_pessoal()
{
    $salvo = Database::readStrict('ia_cliente.json');
    if (!is_array($salvo)) {
        $salvo = [];
    }
    $url = trim((string) ($salvo['api_url'] ?? ''));
    $key = trim((string) ($salvo['api_key'] ?? ''));
    $prov = strtolower(trim((string) ($salvo['provedor'] ?? 'auto')));
    if (!in_array($prov, ['auto', 'openai', 'gemini'], true)) {
        $prov = 'auto';
    }
    return [
        'api_url' => $url,
        'api_key' => $key,
        'provedor' => $prov,
        'modelo' => trim((string) ($salvo['modelo'] ?? '')),
        'prompt_sistema' => trim((string) ($salvo['prompt_sistema'] ?? '')),
        'voz_ativa' => !isset($salvo['voz_ativa']) || !empty($salvo['voz_ativa']),
        'ler_resposta_voz' => !empty($salvo['ler_resposta_voz']),
        'ativa' => ($url !== '' && $key !== ''),
        'atualizado_em' => (string) ($salvo['atualizado_em'] ?? ''),
    ];
}

function pz_ler_ia_pessoal_publica()
{
    $cfg = pz_ler_ia_pessoal();
    $key = $cfg['api_key'];
    return [
        'ativa' => $cfg['ativa'],
        'api_url' => $cfg['api_url'],
        'key_configurada' => $key !== '',
        'key_final' => $key !== '' ? substr($key, -4) : '',
        'provedor' => $cfg['provedor'],
        'modelo' => $cfg['modelo'],
        'prompt_sistema' => $cfg['prompt_sistema'],
        'voz_ativa' => $cfg['ativa'] && $cfg['voz_ativa'],
        'ler_resposta_voz' => $cfg['ativa'] && $cfg['ler_resposta_voz'],
        'atualizado_em' => $cfg['atualizado_em'],
    ];
}

function pz_salvar_ia_pessoal(array $input, $usuario = '')
{
    $atual = pz_ler_ia_pessoal();
    $limpar = !empty($input['limpar_chave']);

    if ($limpar) {
        $novo = [
            'api_url' => '',
            'api_key' => '',
            'provedor' => 'auto',
            'modelo' => '',
            'prompt_sistema' => trim((string) ($input['prompt_sistema'] ?? $atual['prompt_sistema'] ?? '')),
            'voz_ativa' => false,
            'ler_resposta_voz' => false,
            'atualizado_em' => date('Y-m-d H:i:s'),
        ];
        Database::write('ia_cliente.json', $novo);
        pz_registrar_auditoria('ia_pessoal', 'chave_removida', 'IA pessoal e comando por voz desativados pelo lojista', $usuario);
        return ['success' => true, 'ia_pessoal' => pz_ler_ia_pessoal_publica()];
    }

    $url = trim((string) ($input['api_url'] ?? $atual['api_url']));
    if ($url !== '' && !preg_match('#^https?://#i', $url)) {
        return ['success' => false, 'message' => 'A URL da IA deve começar com https:// ou http://.'];
    }

    $novaKey = trim((string) ($input['api_key'] ?? ''));
    $keyFinal = $novaKey !== '' ? $novaKey : $atual['api_key'];

    if ($url !== '' && $keyFinal === '') {
        return ['success' => false, 'message' => 'Informe a API Key pessoal da sua IA junto com a URL de acesso.'];
    }
    if ($keyFinal !== '' && $url === '') {
        return ['success' => false, 'message' => 'Informe a URL de acesso da sua IA junto com a API Key.'];
    }

    $prov = strtolower(trim((string) ($input['provedor'] ?? $atual['provedor'])));
    if (!in_array($prov, ['auto', 'openai', 'gemini'], true)) {
        $prov = 'auto';
    }
    $modelo = pz_limpar_str($input['modelo'] ?? $atual['modelo'], 120);
    $prompt = trim(strip_tags((string) ($input['prompt_sistema'] ?? $atual['prompt_sistema'])));
    if (function_exists('mb_substr')) {
        $prompt = mb_substr($prompt, 0, 2500, 'UTF-8');
    } else {
        $prompt = substr($prompt, 0, 2500);
    }

    $novo = [
        'api_url' => substr($url, 0, 500),
        'api_key' => substr($keyFinal, 0, 400),
        'provedor' => $prov,
        'modelo' => $modelo,
        'prompt_sistema' => $prompt,
        'voz_ativa' => isset($input['voz_ativa']) ? !empty($input['voz_ativa']) : true,
        'ler_resposta_voz' => isset($input['ler_resposta_voz']) ? !empty($input['ler_resposta_voz']) : false,
        'atualizado_em' => date('Y-m-d H:i:s'),
    ];

    if (!Database::write('ia_cliente.json', $novo)) {
        return ['success' => false, 'message' => 'Erro ao salvar configurações da IA pessoal.'];
    }

    pz_registrar_auditoria(
        'ia_pessoal',
        'ia_pessoal_salva',
        'URL: ' . ($url ?: '(vazia)') . ' | Voz: ' . ($novo['voz_ativa'] ? 'sim' : 'nao'),
        $usuario
    );

    return ['success' => true, 'ia_pessoal' => pz_ler_ia_pessoal_publica()];
}

function pz_http_post_json($url, array $headers, array $payload, $timeout = 18)
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $status = 0;
    $raw = false;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            return ['ok' => false, 'status' => $status, 'error' => $err ?: 'Falha cURL'];
        }
    } else {
        $headerStr = implode("\r\n", $headers) . "\r\n";
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => $headerStr,
                'content' => $body,
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            return ['ok' => false, 'status' => 0, 'error' => 'Sem conexão com a URL da IA'];
        }
        $status = 200;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $status = (int) $m[1];
        }
    }

    $json = is_string($raw) ? json_decode($raw, true) : null;
    return [
        'ok' => ($status >= 200 && $status < 300),
        'status' => $status,
        'raw' => $raw,
        'json' => is_array($json) ? $json : null,
    ];
}

function pz_executar_ia_pessoal(array $cfg, $systemPrompt, $userPrompt)
{
    $url = trim((string) ($cfg['api_url'] ?? ''));
    $key = trim((string) ($cfg['api_key'] ?? ''));
    if ($url === '' || $key === '') {
        return ['success' => false, 'message' => 'IA pessoal não configurada.'];
    }

    $prov = $cfg['provedor'] ?? 'auto';
    if ($prov === 'auto') {
        $prov = (stripos($url, 'generativelanguage.googleapis.com') !== false || stripos($url, ':generateContent') !== false)
            ? 'gemini'
            : 'openai';
    }

    if ($prov === 'gemini') {
        $modelo = trim((string) ($cfg['modelo'] ?? '')) ?: 'gemini-2.0-flash';
        if (stripos($url, ':generateContent') === false) {
            $url = rtrim($url, '/') . '/models/' . rawurlencode($modelo) . ':generateContent';
        }
        $sep = strpos($url, '?') === false ? '?' : '&';
        if (stripos($url, 'key=') === false) {
            $url .= $sep . 'key=' . rawurlencode($key);
        }
        $payload = [
            'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $userPrompt]]]],
            'generationConfig' => ['temperature' => 0.25, 'maxOutputTokens' => 700],
        ];
        $res = pz_http_post_json($url, ['Content-Type: application/json'], $payload, 20);
        if (!$res['ok']) {
            $msg = $res['json']['error']['message'] ?? $res['error'] ?? ('HTTP ' . $res['status']);
            return ['success' => false, 'message' => 'Erro na IA (Gemini): ' . $msg];
        }
        $texto = trim((string) ($res['json']['candidates'][0]['content']['parts'][0]['text'] ?? ''));
        return $texto !== ''
            ? ['success' => true, 'texto' => $texto, 'provedor' => 'gemini']
            : ['success' => false, 'message' => 'Resposta vazia recebida da URL Gemini.'];
    }

    // Padrão compatível com OpenAI / Groq / OpenRouter / DeepSeek / Ollama / Endpoint próprio
    if (!preg_match('#/(chat/completions|completions|responses)$#i', $url)) {
        $url = rtrim($url, '/') . '/chat/completions';
    }
    $modelo = trim((string) ($cfg['modelo'] ?? '')) ?: 'gpt-4o-mini';
    $payload = [
        'model' => $modelo,
        'temperature' => 0.25,
        'max_tokens' => 700,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ],
    ];
    $res = pz_http_post_json($url, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $key,
    ], $payload, 20);

    if (!$res['ok']) {
        $msg = $res['json']['error']['message'] ?? $res['error'] ?? ('HTTP ' . $res['status']);
        return ['success' => false, 'message' => 'Erro na IA (' . $modelo . '): ' . $msg];
    }
    $texto = trim((string) ($res['json']['choices'][0]['message']['content'] ?? ($res['json']['response'] ?? ($res['json']['text'] ?? ''))));
    return $texto !== ''
        ? ['success' => true, 'texto' => $texto, 'provedor' => 'openai']
        : ['success' => false, 'message' => 'Resposta vazia recebida da URL da IA.'];
}

function pz_testar_ia_pessoal(array $input = [])
{
    $salva = pz_ler_ia_pessoal();
    $cfg = [
        'api_url' => trim((string) ($input['api_url'] ?? '')) ?: $salva['api_url'],
        'api_key' => trim((string) ($input['api_key'] ?? '')) ?: $salva['api_key'],
        'provedor' => trim((string) ($input['provedor'] ?? '')) ?: $salva['provedor'],
        'modelo' => trim((string) ($input['modelo'] ?? '')) ?: $salva['modelo'],
    ];
    $t0 = microtime(true);
    $res = pz_executar_ia_pessoal(
        $cfg,
        'Responda apenas em português de forma curta confirmando que a conexão funcionou.',
        'Teste de conexão com o sistema Food Gestão.'
    );
    $ms = (int) round((microtime(true) - $t0) * 1000);
    $res['latencia_ms'] = $ms;
    return $res;
}
