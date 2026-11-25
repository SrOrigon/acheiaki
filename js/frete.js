/**
 * Calculadora de frete configurável pelo lojista.
 * Modos: gratis, fixo, distancia, peso, bairro.
 */
(function (global) {
    function num(value) {
        const n = parseFloat(value);
        return Number.isFinite(n) ? n : 0;
    }

    function moeda(valor) {
        return 'R$ ' + num(valor).toFixed(2).replace('.', ',');
    }

    function haversineKm(aLat, aLng, bLat, bLng) {
        const R = 6371;
        const toRad = (deg) => (deg * Math.PI) / 180;
        const dLat = toRad(bLat - aLat);
        const dLng = toRad(bLng - aLng);
        const lat1 = toRad(aLat);
        const lat2 = toRad(bLat);
        const h = Math.sin(dLat / 2) ** 2 + Math.cos(lat1) * Math.cos(lat2) * Math.sin(dLng / 2) ** 2;
        return 2 * R * Math.asin(Math.min(1, Math.sqrt(h)));
    }

    function normalizar(texto) {
        return String(texto || '')
            .trim()
            .toLowerCase()
            .normalize('NFD')
            .replace(/[\u0300-\u036f]/g, '');
    }

    function regras(config) {
        const frete = (config && config.FRETE) || {};
        return {
            modo: frete.modo || 'gratis',
            taxaFixa: num(frete.taxa_fixa),
            unidade: frete.unidade_distancia === 'm' ? 'm' : 'km',
            taxaBase: num(frete.taxa_base),
            valorUnidade: num(frete.valor_unidade),
            raioMax: num(frete.raio_max_km),
            usarFaixas: !!frete.usar_faixas,
            faixas: Array.isArray(frete.faixas) ? frete.faixas : [],
            gratisAcima: num(frete.frete_gratis_acima),
            pedidoMinimo: num(frete.pedido_minimo),
            pesoUnidade: frete.peso_unidade || 'kg',
            pesoTaxaBase: num(frete.peso_taxa_base),
            pesoValor: num(frete.peso_valor_unidade),
            bairros: Array.isArray(frete.bairros) ? frete.bairros : []
        };
    }

    function calcular(opts) {
        const config = opts.config || {};
        const r = regras(config);
        const subtotal = num(opts.subtotal);
        const retirada = !!opts.retirada;

        const base = {
            valor: 0,
            subtotal: subtotal,
            total: subtotal,
            ok: true,
            foraDaArea: false,
            abaixoDoMinimo: false,
            rotulo: 'Grátis',
            detalhe: '',
            distanciaKm: opts.distanciaKm == null ? null : num(opts.distanciaKm)
        };

        if (r.pedidoMinimo > 0 && subtotal < r.pedidoMinimo) {
            base.ok = false;
            base.abaixoDoMinimo = true;
            base.rotulo = 'Pedido mínimo';
            base.detalhe = 'Pedido mínimo de ' + moeda(r.pedidoMinimo) + '.';
            base.total = subtotal;
            return base;
        }

        if (retirada || r.modo === 'gratis') {
            base.rotulo = retirada ? 'Retirada na loja' : 'Frete grátis';
            base.detalhe = base.rotulo;
            return aplicarGratis(base, r, subtotal);
        }

        if (r.modo === 'fixo') {
            base.valor = Math.max(0, r.taxaFixa);
            base.rotulo = 'Taxa fixa';
            base.detalhe = 'Taxa única de entrega';
            return aplicarGratis(base, r, subtotal);
        }

        if (r.modo === 'bairro') {
            const alvo = normalizar(opts.bairro);
            const textoEnd = normalizar(opts.enderecoTexto || opts.bairro || '');
            let achou = alvo ? r.bairros.find((b) => normalizar(b.nome) === alvo) : null;
            if (!achou && textoEnd) {
                achou = r.bairros.find((b) => {
                    const bn = normalizar(b.nome);
                    return bn && textoEnd.indexOf(bn) !== -1;
                });
            }
            if (!achou && opts.fallbackDistancia) {
                base.valor = Math.max(0, r.taxaFixa > 0 ? r.taxaFixa : (r.taxaBase > 0 ? r.taxaBase : (r.bairros[0] ? num(r.bairros[0].valor) : 0)));
                base.rotulo = 'Taxa padrão (fallback)';
                base.detalhe = 'Contingência aplicada para ruas novas';
                return aplicarGratis(base, r, subtotal);
            }
            if (!achou) {
                base.ok = false;
                base.foraDaArea = true;
                base.rotulo = 'Bairro não atendido';
                base.detalhe = 'Não entregamos neste bairro.';
                base.total = subtotal;
                return base;
            }
            base.valor = Math.max(0, num(achou.valor));
            base.rotulo = 'Taxa do bairro';
            base.detalhe = achou.nome || 'Bairro';
            return aplicarGratis(base, r, subtotal);
        }

        if (r.modo === 'peso') {
            const gramas = num(opts.pesoGramas);
            const qtd = num(opts.qtdItens);
            let adicional = 0;
            if (r.pesoUnidade === 'g') adicional = gramas * r.pesoValor;
            else if (r.pesoUnidade === 'un') adicional = qtd * r.pesoValor;
            else adicional = (gramas / 1000) * r.pesoValor;
            base.valor = Math.max(0, r.pesoTaxaBase + adicional);
            base.rotulo = 'Taxa por ' + (r.pesoUnidade === 'un' ? 'quantidade' : 'peso');
            base.detalhe = r.pesoUnidade === 'un'
                ? qtd + ' itens'
                : (gramas / 1000).toFixed(2).replace('.', ',') + ' kg';
            return aplicarGratis(base, r, subtotal);
        }

        const km = opts.distanciaKm;
        if (km == null || !Number.isFinite(Number(km))) {
            if (opts.fallbackDistancia) {
                const alvoBairro = normalizar(opts.bairro);
                if (alvoBairro && r.bairros.length) {
                    const achouB = r.bairros.find((b) => normalizar(b.nome) === alvoBairro);
                    if (achouB) {
                        base.valor = Math.max(0, num(achouB.valor));
                        base.rotulo = 'Taxa por bairro (fallback)';
                        base.detalhe = achouB.nome || 'Bairro';
                        return aplicarGratis(base, r, subtotal);
                    }
                }
                base.valor = Math.max(0, r.taxaBase > 0 ? r.taxaBase : r.taxaFixa);
                base.rotulo = 'Taxa padrão (sem GPS)';
                base.detalhe = 'Contingência aplicada para concluir seu pedido';
                return aplicarGratis(base, r, subtotal);
            }
            base.ok = false;
            base.rotulo = 'Frete a calcular';
            base.detalhe = 'Informe o endereço completo para calcular a entrega.';
            base.total = subtotal;
            return base;
        }

        const distanciaKm = num(km);
        if (r.raioMax > 0 && distanciaKm > r.raioMax) {
            base.ok = false;
            base.foraDaArea = true;
            base.distanciaKm = distanciaKm;
            base.rotulo = 'Fora da área';
            base.detalhe = 'Atendemos até ' + r.raioMax + ' km.';
            base.total = subtotal;
            return base;
        }

        if (r.usarFaixas && r.faixas.length) {
            const faixas = r.faixas
                .map((f) => ({ ate: num(f.ate_km), valor: num(f.valor) }))
                .filter((f) => f.ate > 0)
                .sort((a, b) => a.ate - b.ate);
            const faixa = faixas.find((f) => distanciaKm <= f.ate);
            if (!faixa) {
                base.ok = false;
                base.foraDaArea = true;
                base.distanciaKm = distanciaKm;
                base.rotulo = 'Fora da área';
                base.detalhe = 'Distância acima das faixas cadastradas.';
                base.total = subtotal;
                return base;
            }
            base.valor = Math.max(0, faixa.valor);
            base.rotulo = 'Faixa de entrega';
            base.detalhe = 'Até ' + faixa.ate + ' km';
        } else {
            const unidades = r.unidade === 'm' ? distanciaKm * 1000 : distanciaKm;
            base.valor = Math.max(0, r.taxaBase + unidades * r.valorUnidade);
            base.rotulo = r.unidade === 'm' ? 'Taxa por metro' : 'Taxa por km';
            base.detalhe = distanciaKm.toFixed(2).replace('.', ',') + ' km';
        }

        base.distanciaKm = distanciaKm;
        return aplicarGratis(base, r, subtotal);
    }

    function aplicarGratis(resultado, r, subtotal) {
        if (resultado.ok && r.gratisAcima > 0 && subtotal >= r.gratisAcima) {
            resultado.valor = 0;
            resultado.rotulo = 'Frete grátis';
            resultado.detalhe = 'Pedidos a partir de ' + moeda(r.gratisAcima);
        }
        resultado.valor = Math.round(num(resultado.valor) * 100) / 100;
        resultado.total = Math.round((subtotal + resultado.valor) * 100) / 100;
        return resultado;
    }

    function apiUrl(path) {
        if (global.TENANT && global.TENANT.apiUrl) return global.TENANT.apiUrl(path);
        return path;
    }

    async function geocode(query) {
        const url = apiUrl('geocode.php?q=' + encodeURIComponent(query));
        const response = await fetch(url, { headers: { Accept: 'application/json' } });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Não foi possível localizar o endereço.');
        }
        return { lat: num(data.lat), lng: num(data.lng), label: data.label || '' };
    }

    global.PedidoZapFrete = {
        calcular: calcular,
        haversineKm: haversineKm,
        geocode: geocode,
        moeda: moeda
    };
})(typeof window !== 'undefined' ? window : globalThis);
