<?php
require_once 'tenant_init.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = file_get_contents('php://input');
    $req = json_decode($data, true);

    pedidozap_bootstrap_tenant(is_array($req) ? $req : null);

    if (isset($req['id']) && isset($req['status'])) {
        require_once 'auth_lib.php';
        require_once 'pedido_acesso.php';
        require_once 'saas_lib.php';
        require_once 'gestao_lib.php';

        $user = $_GET['user'] ?? $req['auth']['user'] ?? '';
        $pass = $_GET['pass'] ?? $req['auth']['pass'] ?? '';
        $chaveCozinha = (string) ($_GET['k'] ?? $req['k'] ?? '');
        $authCozinha = pz_cozinha_ok($chaveCozinha);
        $autorizadoPainel = pedidozap_autorizado($user, $pass);

        if (!$autorizadoPainel && !$authCozinha) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Não autorizado.']);
            exit;
        }

        if ($autorizadoPainel) {
            pedidozap_recusar_loja_bloqueada($user, $pass);
        }

        $sessao = $autorizadoPainel ? pedidozap_sessao_info($user, $pass) : ['user' => 'Cozinha', 'nome' => 'Cozinha'];
        $ator = (string) ($sessao['nome'] ?? $sessao['user'] ?? ($user ?: 'Cozinha'));
        $pedidoAntes = Database::findOrder($req['id']);
        $statusAnterior = is_array($pedidoAntes) ? (string) ($pedidoAntes['status'] ?? 'novo') : 'novo';

        if ($authCozinha && !$autorizadoPainel) {
            $statusAtual = $statusAnterior;
            $novoStatus = (string) $req['status'];
            if ($statusAtual !== 'preparo' || $novoStatus !== 'em-rota') {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Na cozinha só é permitido marcar o pedido como pronto para entrega.']);
                exit;
            }
        }

        $camposPatch = [];
        if (isset($req['rastreio_externo'])) {
            $link = trim((string) $req['rastreio_externo']);
            if ($link !== '' && !preg_match('#^https?://#i', $link)) {
                $link = '';
            }
            $camposPatch['rastreio_externo'] = $link;
        }
        if (isset($req['entregador_id'])) {
            $camposPatch['entregador_id'] = pz_limpar_str($req['entregador_id'], 60);
        }
        if (isset($req['entregador_nome'])) {
            $camposPatch['entregador_nome'] = pz_limpar_str($req['entregador_nome'], 80);
        }
        if (isset($req['valor_recebido_entrega']) && is_numeric($req['valor_recebido_entrega'])) {
            $camposPatch['valor_recebido_entrega'] = round((float) $req['valor_recebido_entrega'], 2);
        }

        $novoStatus = (string) $req['status'];
        if (!in_array($novoStatus, ['novo', 'preparo', 'em-rota', 'concluido', 'arquivado', 'cancelado'], true)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Status inválido.']);
            exit;
        }

        $cancelar = ($novoStatus === 'cancelado') || !empty($req['cancelar']);
        $statusPersistir = $novoStatus === 'cancelado' ? 'arquivado' : $novoStatus;
        if ($cancelar) {
            $camposPatch['cancelado'] = true;
            $camposPatch['motivo_cancelamento'] = pz_limpar_str($req['motivo_cancelamento'] ?? 'Cancelado no painel', 200);
        } elseif ($statusPersistir !== 'arquivado') {
            $camposPatch['cancelado'] = false;
        }
        if ($statusPersistir === 'em-rota' && empty($pedidoAntes['saiu_em'])) {
            $camposPatch['saiu_em'] = date('Y-m-d H:i:s');
        }
        if ($statusPersistir === 'concluido' && empty($pedidoAntes['entregue_em'])) {
            $camposPatch['entregue_em'] = date('Y-m-d H:i:s');
        }
        if ($camposPatch) {
            Database::patchOrder($req['id'], $camposPatch);
        }

        if (Database::updateOrderStatus($req['id'], $statusPersistir)) {
            if ($statusPersistir === 'preparo') {
                Database::garantirComandaCozinha($req['id']);
            }

            $estoqueInfo = null;
            $financeiroInfo = null;
            if (!$cancelar && in_array($statusPersistir, ['preparo', 'em-rota', 'concluido'], true)) {
                $estoqueInfo = pz_baixar_estoque_pedido($req['id'], $ator);
            }
            if (!$cancelar && $statusPersistir === 'concluido') {
                $financeiroInfo = pz_registrar_venda_financeiro($req['id'], $ator);
            }
            if ($cancelar || $statusPersistir === 'novo' || ($statusPersistir === 'arquivado' && $statusAnterior !== 'concluido')) {
                $estoqueInfo = pz_estornar_estoque_pedido($req['id'], $ator);
                $financeiroInfo = pz_estornar_venda_financeiro($req['id'], $ator);
                pz_registrar_auditoria('pedidos', $cancelar ? 'pedido_cancelado' : 'pedido_estornado', 'Pedido #' . $req['id'], $ator);
            }

            $meta = [
                'success' => true,
                'estoque' => $estoqueInfo,
                'financeiro' => $financeiroInfo,
            ];
            $pedidoAtual = Database::findOrder($req['id']);
            if (is_array($pedidoAtual)) {
                $meta['comanda_id'] = $pedidoAtual['comanda_id'] ?? '';
                $meta['cozinha_desde'] = $pedidoAtual['cozinha_desde'] ?? '';
                $meta['comanda_impressa_id'] = $pedidoAtual['comanda_impressa_id'] ?? '';
                $meta['estoque_baixado'] = !empty($pedidoAtual['estoque_baixado']);
                $meta['financeiro_registrado'] = !empty($pedidoAtual['financeiro_registrado']);
                $meta['entregador_id'] = $pedidoAtual['entregador_id'] ?? '';
                $meta['entregador_nome'] = $pedidoAtual['entregador_nome'] ?? '';
            }
            echo json_encode($meta, JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Erro ao atualizar status do pedido.']);
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Método não permitido.']);
}
?>
