<?php
/**
 * API Fiscal (DANFE NFC-e/SAT simplificada e consulta por pedido) — Isolada por Tenant e Autenticada.
 */
require_once __DIR__ . '/tenant_init.php';
require_once __DIR__ . '/auth_lib.php';
require_once __DIR__ . '/saas_lib.php';
require_once __DIR__ . '/pedido_acesso.php';
require_once __DIR__ . '/fiscal_lib.php';

$acao = strtolower(trim((string) ($_GET['action'] ?? 'danfe')));
pedidozap_bootstrap_tenant();
$tenant = Database::getTenant();
$orderId = trim((string) ($_GET['order_id'] ?? $_GET['id'] ?? ''));
$codigoPedido = trim((string) ($_GET['c'] ?? ''));
$user = (string) ($_GET['user'] ?? '');
$pass = (string) ($_GET['pass'] ?? '');
$adminOk = ($user !== '' || $pass !== '') ? (pedidozap_autorizado($user, $pass) !== false) : pz_admin_ok();

if ($acao === 'danfe') {
    if ($orderId === '') {
        if (!$adminOk && $codigoPedido === '') {
            http_response_code(401);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Autenticação obrigatória para acessar documentos fiscais.';
            exit;
        }
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Informe o número do pedido.';
        exit;
    }

    $pedido = Database::findOrder($orderId);
    if (!$pedido || (!$adminOk && !pz_pode_ver_pedido($pedido, $codigoPedido, ''))) {
        http_response_code(401);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Acesso não autorizado a este documento fiscal.';
        exit;
    }

    $notas = pz_fiscal_listar_notas($tenant, 200);
    $nota = null;
    foreach ($notas as $n) {
        if (is_array($n) && (string) ($n['order_id'] ?? '') === $orderId) {
            $nota = $n;
            break;
        }
    }
    if (!$nota) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Documento fiscal não encontrado para o pedido #' . $orderId;
        exit;
    }

    header('Content-Type: text/html; charset=utf-8');
    $esc = static function ($s) {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    };
    $chaveFmt = trim(chunk_split((string) ($nota['chave_acesso'] ?? ''), 4, ' '));
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>DANFE ' . $esc($nota['modelo']) . ' #' . $esc($nota['numero']) . '</title>'
        . '<style>body{font-family:ui-monospace,monospace;max-width:340px;margin:20px auto;padding:14px;border:1px dashed #94a3b8;color:#0f172a;font-size:12px;}h2,p{margin:4px 0;text-align:center;}table{width:100%;border-collapse:collapse;margin:10px 0;}th,td{text-align:left;padding:3px 0;border-bottom:1px dotted #cbd5e1;}.r{text-align:right;}.btn{display:block;width:100%;margin-top:12px;padding:10px;background:#0f172a;color:#fff;border:none;border-radius:6px;cursor:pointer;font-weight:700;}@media print{.btn{display:none;}}</style>'
        . '</head><body>'
        . '<h2>' . $esc($nota['emitente_nome']) . '</h2>'
        . '<p>CNPJ: ' . $esc($nota['emitente_cnpj']) . '</p>'
        . '<p><strong>DOCUMENTO AUXILIAR DA ' . $esc($nota['modelo']) . '</strong></p>'
        . '<p>' . ($nota['ambiente'] === 'homologacao' ? 'EMITIDA EM HOMOLOGAÇÃO (SEM VALOR FISCAL)' : 'AUTORIZADA SEFAZ') . '</p>'
        . '<table><thead><tr><th>Item</th><th class="r">Qtd</th><th class="r">Total</th></tr></thead><tbody>';
    foreach ((array) ($nota['itens'] ?? []) as $it) {
        echo '<tr><td>' . $esc($it['descricao']) . '<br><small>NCM ' . $esc($it['ncm']) . ' CFOP ' . $esc($it['cfop']) . '</small></td>'
            . '<td class="r">' . (int) $it['quantidade'] . '</td>'
            . '<td class="r">R$ ' . number_format((float) $it['valor_total'], 2, ',', '.') . '</td></tr>';
    }
    echo '</tbody></table>'
        . '<p style="font-size:14px;"><strong>VALOR TOTAL: R$ ' . number_format((float) $nota['valor_total'], 2, ',', '.') . '</strong></p>'
        . '<p>NFC-e nº ' . (int) $nota['numero'] . ' · Série ' . (int) $nota['serie'] . '</p>'
        . '<p>Protocolo: ' . $esc($nota['protocolo']) . '</p>'
        . '<p style="word-break:break-all;margin-top:8px;"><strong>Chave de Acesso:</strong><br>' . $esc($chaveFmt) . '</p>'
        . '<p>Cliente: ' . $esc($nota['cliente']) . ' · ' . $esc($nota['emitida_em']) . '</p>'
        . '<button class="btn" onclick="window.print()">Imprimir / Salvar PDF</button>'
        . '</body></html>';
    exit;
}

header('Content-Type: application/json; charset=utf-8');
if (!$adminOk) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Autenticação da loja obrigatória para consultar notas fiscais.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$isMasterFisc = ($user !== '' || $pass !== '') && pedidozap_autorizado($user, $pass) === 'master';
if (!pz_plano_tem_recurso('fiscal_nfce', $tenant, $isMasterFisc)) {
    http_response_code(402);
    echo json_encode(['success' => false, 'codigo_erro' => 'upgrade_plano', 'message' => 'A emissão fiscal NFC-e / SAT está disponível no plano Enterprise / Rede.'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['success' => true, 'tenant' => $tenant, 'notas' => pz_fiscal_listar_notas($tenant, 20)], JSON_UNESCAPED_UNICODE);
