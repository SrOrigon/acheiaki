/**
 * Comandas térmicas — textos vêm de APP_CONFIG.COMANDA (painel da loja).
 */
(function (global) {
    const DEFAULTS = {
        titulo_via_cozinha: 'COZINHA',
        titulo_via_baladao: 'BALCÃO',
        rotulo_pedido: 'Pedido',
        rotulo_itens: 'ITENS',
        rotulo_cliente: 'Cliente',
        rotulo_telefone: 'Tel.',
        rotulo_entrega: 'Entrega',
        rotulo_pagamento: 'Pagamento',
        rotulo_total: 'TOTAL',
        rotulo_frete: 'Taxa entrega',
        rotulo_obs: 'OBS',
        rodape_cozinha: 'Confira os itens antes de enviar.',
        rodape_completa: 'Obrigado pela preferência!',
        mostrar_pagamento_cozinha: false,
        mostrar_telefone_cozinha: true,
        largura_mm: 80,
        erro_popup: 'Libere pop-ups neste site para imprimir a comanda.',
        texto_canal_balcao: 'BALCÃO'
    };

    function textos(opcoes) {
        const cfg = (opcoes && opcoes.comanda) || (global.APP_CONFIG && global.APP_CONFIG.COMANDA) || {};
        const out = Object.assign({}, DEFAULTS, cfg);
        return out;
    }

    function esc(valor) {
        return String(valor == null ? '' : valor).replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[c]));
    }

    function moeda(valor) {
        return 'R$ ' + (Number(valor) || 0).toFixed(2).replace('.', ',');
    }

    function dataPedido(pedido) {
        const bruto = pedido.cozinha_desde || pedido.timestamp || pedido.data || '';
        let d = new Date(bruto);
        if (isNaN(d.getTime()) && typeof bruto === 'string') {
            d = new Date(bruto.replace(' ', 'T'));
        }
        return isNaN(d.getTime()) ? '---' : d.toLocaleString('pt-BR');
    }

    function rotuloCanal(pedido, t) {
        if (pedido.canal === 'atendente') {
            return t.texto_canal_balcao || t.titulo_via_baladao || '';
        }
        return '';
    }

    function linhasItens(pedido, modo, t) {
        const itens = pedido.itens || [];
        if (!itens.length) {
            return '<div class="vazio-itens">—</div>';
        }
        return itens.map((item) => {
            const qtd = item.quantidade || 1;
            const nome = esc(String(item.nome || 'Item').toUpperCase());
            const tam = item.tamanho ? ' (' + esc(item.tamanho) + ')' : '';
            const ops = (item.opcionais || []).map((op) =>
                '<div class="op">+ ' + esc(String(op.nome || '').toUpperCase())
                + (op.preco > 0 ? ' ' + moeda(op.preco) : '') + '</div>'
            ).join('');
            const unit = Number(item.precoTotal) || 0;
            const preco = modo === 'cozinha' ? '' : '<span class="preco">' + moeda(unit * qtd) + '</span>';
            return '<div class="item">'
                + '<div class="linha-principal' + (modo === 'cozinha' ? ' grande' : '') + '">'
                + '<span>' + qtd + '× ' + nome + tam + '</span>' + preco + '</div>'
                + ops + '</div>';
        }).join('');
    }

    function montarHtml(pedido, opcoes) {
        const opts = opcoes || {};
        const modo = opts.modo === 'completa' ? 'completa' : 'cozinha';
        const t = textos(opts);
        const largura = Math.min(Math.max(Number(t.largura_mm) || 80, 58), 80);
        const corpo = Number(largura) - 6;

        const nomeLoja = esc(opts.nomeLoja || (global.APP_CONFIG && global.APP_CONFIG.NOME_LOJA) || '');
        const end = pedido.endereco || {};
        const endereco = [end.rua, end.numero, end.complemento, end.bairro, end.cidade].filter(Boolean).map(esc).join(', ');
        const canal = rotuloCanal(pedido, t);
        const via = modo === 'cozinha' ? (t.titulo_via_cozinha || '') : '';

        let cabecalhoExtra = '';
        if (via) cabecalhoExtra += '<div class="via">' + esc(via) + '</div>';
        if (canal) cabecalhoExtra += '<div class="canal">' + esc(canal) + '</div>';

        let blocoCliente = '';
        if (modo === 'cozinha') {
            blocoCliente += '<div class="bloco"><b>' + esc(t.rotulo_cliente) + ':</b> ' + esc(pedido.cliente || '') + '</div>';
            if (t.mostrar_telefone_cozinha && pedido.celular) {
                blocoCliente += '<div class="bloco">' + esc(t.rotulo_telefone) + ' ' + esc(pedido.celular) + '</div>';
            }
            if (pedido.entrega) {
                blocoCliente += '<div class="bloco">' + esc(t.rotulo_entrega) + ': ' + esc(pedido.entrega) + '</div>';
            }
        } else {
            blocoCliente += '<div class="bloco"><b>' + esc(pedido.cliente || '') + '</b></div>';
            if (pedido.celular) blocoCliente += '<div class="bloco">' + esc(pedido.celular) + '</div>';
            if (endereco) blocoCliente += '<div class="bloco">' + endereco + '</div>';
            if (end.referencia) blocoCliente += '<div class="bloco">Ref: ' + esc(end.referencia) + '</div>';
            if (pedido.entrega) blocoCliente += '<div class="bloco">' + esc(pedido.entrega) + '</div>';
        }

        let rodape = '';
        if (modo === 'cozinha') {
            if (pedido.observacao) {
                rodape += '<div class="obs destaque">' + esc(t.rotulo_obs) + ': ' + esc(pedido.observacao) + '</div>';
            }
            if (t.mostrar_pagamento_cozinha && pedido.pagamento) {
                rodape += '<div class="bloco">' + esc(t.rotulo_pagamento) + ': ' + esc(pedido.pagamento) + '</div>';
            }
            rodape += '<div class="rodape">' + esc(t.rodape_cozinha) + '</div>';
        } else {
            if (pedido.frete) {
                rodape += '<div class="linha-valor"><span>' + esc(t.rotulo_frete) + '</span><span>' + moeda(pedido.frete) + '</span></div>';
            }
            rodape += '<div class="linha-valor total"><span>' + esc(t.rotulo_total) + '</span><span>' + moeda(pedido.total) + '</span></div>';
            rodape += '<div class="bloco">' + esc(t.rotulo_pagamento) + ': ' + esc(pedido.pagamento || '')
                + (pedido.troco ? ' (troco ' + esc(pedido.troco) + ')' : '') + '</div>';
            if (pedido.observacao) {
                rodape += '<div class="obs">' + esc(t.rotulo_obs) + ': ' + esc(pedido.observacao) + '</div>';
            }
            rodape += '<div class="rodape centro">' + esc(t.rodape_completa) + '</div>';
        }

        const css = ''
            + '@page{size:' + largura + 'mm auto;margin:2mm}'
            + 'body{font-family:\"Courier New\",monospace;font-size:12px;width:' + corpo + 'mm;margin:0;color:#000}'
            + 'h1{font-size:15px;text-align:center;margin:0 0 2px;font-weight:bold}'
            + '.centro{text-align:center}.via{text-align:center;font-weight:bold;font-size:13px;margin:2px 0}'
            + '.canal{text-align:center;font-size:11px;margin-bottom:2px}'
            + '.meta{text-align:center;font-size:11px}.sep{border-top:1px dashed #000;margin:5px 0}'
            + '.secao{font-weight:bold;font-size:12px;margin:4px 0 2px}'
            + '.bloco{font-size:11px;margin:2px 0;line-height:1.35}'
            + '.item{margin:4px 0}.linha-principal{display:flex;justify-content:space-between;gap:4px}'
            + '.linha-principal.grande{font-size:14px;font-weight:bold}.op{padding-left:10px;font-size:11px}'
            + '.linha-valor{display:flex;justify-content:space-between;margin:2px 0}'
            + '.total{font-size:14px;font-weight:bold}.obs{border:1px solid #000;padding:4px;margin:4px 0}'
            + '.obs.destaque{font-size:13px;font-weight:bold}.rodape{font-size:10px;margin-top:6px}'
            + '.vazio-itens{font-style:italic}';

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>'
            + esc(t.rotulo_pedido) + ' ' + esc(pedido.id) + '</title><style>' + css + '</style></head><body>'
            + cabecalhoExtra
            + '<h1>' + nomeLoja + '</h1>'
            + '<div class="meta">' + esc(t.rotulo_pedido) + ' #' + esc(pedido.id) + '</div>'
            + '<div class="meta">' + esc(dataPedido(pedido)) + '</div>'
            + '<div class="sep"></div>' + blocoCliente
            + '<div class="sep"></div><div class="secao">' + esc(t.rotulo_itens) + '</div>'
            + linhasItens(pedido, modo, t)
            + '<div class="sep"></div>' + rodape
            + '<script>window.onload=function(){window.focus();window.print();var fechar=function(){try{window.close()}catch(e){}};'
            + 'if(\"onafterprint\" in window){window.onafterprint=fechar}else{setTimeout(fechar,900)};}<\/script>'
            + '</body></html>';
    }

    function idComandaEfetivo(pedido) {
        if (pedido.comanda_id) return String(pedido.comanda_id);
        if ((pedido.status || '') === 'preparo') return 'prep-' + String(pedido.id);
        return '';
    }

    function precisaImprimir(pedido, forcar) {
        if (forcar) return true;
        const id = idComandaEfetivo(pedido);
        if (!id) return false;
        return String(pedido.comanda_impressa_id || '') !== String(id);
    }

    function imprimir(pedido, opcoes) {
        if (!pedido) return false;
        const opts = Object.assign({}, opcoes || {});
        if (!precisaImprimir(pedido, opts.forcar)) return false;

        const html = montarHtml(pedido, opts);
        const janela = global.open('', '_blank', 'width=400,height=720');
        if (!janela) {
            const t = textos(opts);
            alert(t.erro_popup || DEFAULTS.erro_popup);
            return false;
        }
        janela.document.open();
        janela.document.write(html);
        janela.document.close();
        return true;
    }

    global.ComandaPedidoZap = {
        esc,
        moeda,
        textos,
        montarHtml,
        imprimir,
        idComandaEfetivo,
        precisaImprimir
    };
})(typeof window !== 'undefined' ? window : globalThis);
