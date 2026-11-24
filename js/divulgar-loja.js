/**
 * Kit Dia 1 para o Lojista: Link Direto, WhatsApp, Bio/Google Maps e Panfleto de Sacola/Balcão.
 * 100% local e pronto para o comerciante converter seus clientes atuais sem comissão.
 */
(function () {
    'use strict';

    function obterSlug() {
        if (window.PedidoZapTenant && typeof window.PedidoZapTenant.getSlug === 'function') {
            return window.PedidoZapTenant.getSlug() || 'default';
        }
        const params = new URLSearchParams(window.location.search);
        return params.get('tenant') || sessionStorage.getItem('pedidozap_tenant_slug') || 'default';
    }

    function obterNomeLoja() {
        const inp = document.getElementById('input-nome-loja');
        if (inp && inp.value && inp.value.trim()) return inp.value.trim();
        if (window.NOME_LOJA) return String(window.NOME_LOJA).trim();
        if (window.CONFIG && window.CONFIG.nomeLoja) return String(window.CONFIG.nomeLoja).trim();
        return 'Nossa Loja';
    }

    function obterUrlLoja() {
        const slug = obterSlug();
        const origin = window.location.origin;
        if (!slug || slug === 'default') {
            return origin + '/index.html';
        }
        return origin + '/loja/' + encodeURIComponent(slug) + '/';
    }

    function escaparHtml(str) {
        return String(str || '').replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function mostrarAviso(texto) {
        const box = document.getElementById('aki-div-feedback');
        if (!box) return;
        box.textContent = texto;
        box.hidden = false;
        clearTimeout(box._t);
        box._t = setTimeout(function () { box.hidden = true; }, 4500);
    }

    function copiarTexto(texto, msgSucesso) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(texto).then(function () {
                mostrarAviso(msgSucesso);
            }).catch(function () {
                copiarFallback(texto, msgSucesso);
            });
            return;
        }
        copiarFallback(texto, msgSucesso);
    }

    function copiarFallback(texto, msgSucesso) {
        const ta = document.createElement('textarea');
        ta.value = texto;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); mostrarAviso(msgSucesso); } catch (e) { mostrarAviso('Copie manualmente: ' + texto); }
        document.body.removeChild(ta);
    }

    function abrirPanfletoImprimir() {
        const nome = obterNomeLoja();
        const url = obterUrlLoja();
        const w = window.open('', '_blank', 'width=760,height=900');
        if (!w) {
            mostrarAviso('Permita pop-ups para visualizar e imprimir o panfleto.');
            return;
        }
        const docHtml = '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8">'
            + '<title>Panfleto ' + escaparHtml(nome) + ' — AcheIAki</title>'
            + '<style>'
            + 'body{font-family:Inter,system-ui,sans-serif;margin:0;padding:24px;background:#faf7f2;color:#231915;}'
            + '.sheet{max-width:520px;margin:0 auto;background:#fff;border:2px dashed #ea580c;border-radius:20px;padding:30px 26px;text-align:center;}'
            + '.badge{display:inline-block;background:#ea580c;color:#fff;padding:6px 14px;border-radius:999px;font-size:13px;font-weight:700;letter-spacing:.03em;}'
            + '.badge span{color:#fde68a;}'
            + 'h1{margin:16px 0 8px;font-size:28px;line-height:1.18;color:#231915;}'
            + 'p.sub{margin:0 0 18px;font-size:16px;color:#4b3f39;line-height:1.5;}'
            + '.coupon{background:#ecfdf5;border:2px solid #15803d;border-radius:14px;padding:14px;margin:18px 0;}'
            + '.coupon small{display:block;font-size:13px;font-weight:700;color:#166534;text-transform:uppercase;}'
            + '.coupon strong{display:block;font-size:26px;color:#15803d;margin-top:4px;letter-spacing:.04em;}'
            + '.linkbox{background:#fff7ed;border:1px solid #fed7aa;border-radius:12px;padding:14px;margin:16px 0;word-break:break-all;}'
            + '.linkbox small{display:block;font-size:12px;color:#9a3412;margin-bottom:4px;font-weight:700;}'
            + '.linkbox code{font-size:15px;font-weight:800;color:#231915;}'
            + '.steps{text-align:left;background:#faf7f2;border-radius:12px;padding:14px 18px;font-size:14px;line-height:1.55;color:#4b3f39;}'
            + '.print-actions{text-align:center;margin-bottom:16px;}'
            + '.btn-print{background:#ea580c;color:#fff;border:0;padding:12px 22px;border-radius:10px;font-size:15px;font-weight:700;cursor:pointer;}'
            + '@media print{body{background:#fff;padding:0;}.print-actions{display:none;}.sheet{border:2px dashed #000;box-shadow:none;}}'
            + '</style></head><body>'
            + '<div class="print-actions"><button class="btn-print" onclick="window.print()">Imprimir / Salvar PDF (colocar na sacola ou balcão)</button></div>'
            + '<div class="sheet">'
            + '<div class="badge">Ache<span>IA</span>ki • Pedido Direto Taxa Zero</div>'
            + '<h1>' + escaparHtml(nome) + '</h1>'
            + '<p class="sub"><strong>Pagou mais caro nesse pedido?</strong> Na próxima vez, peça direto pelo nosso site próprio — preço real de balcão e sem taxa de aplicativo!</p>'
            + '<div style="margin:14px auto;display:inline-block;padding:12px;background:#fff;border:2px solid #231915;border-radius:14px;">'
            + '<img src="https://api.qrserver.com/v1/create-qr-code/?size=168x168&color=231915&data=' + encodeURIComponent(url) + '" alt="QR Code ' + escaparHtml(nome) + '" style="width:168px;height:168px;display:block;" onerror="this.style.display=\'none\'">'
            + '<div style="font-size:11px;font-weight:800;color:#ea580c;margin-top:6px;">APONTE A CÂMERA PARA PEDIR</div>'
            + '</div>'
            + '<div class="coupon"><small>Vantagem exclusiva de pedido direto</small><strong>PEÇA PELO LINK DIRETO</strong><div style="font-size:13px;color:#065f46;margin-top:4px;">Use seu cupom DIRETO10 (se ativo na loja) e economize já no próximo pedido</div></div>'
            + '<div class="linkbox"><small>ACESSE NO CELULAR:</small><code>' + escaparHtml(url) + '</code></div>'
            + '<div class="steps"><strong>Como pedir em 1 minuto:</strong><br>1. Digite ou salve o endereço acima no navegador do celular.<br>2. Escolha seu pedido e finalize com 1 toque.<br>3. Acompanhe tudo em tempo real sem baixar nada!</div>'
            + '</div></body></html>';
        w.document.open();
        w.document.write(docHtml);
        w.document.close();
    }

    function iniciarKitDivulgacao() {
        const urlEl = document.getElementById('aki-div-url');
        if (!urlEl) return;

        function atualizarCampoUrl() {
            urlEl.value = obterUrlLoja();
        }
        atualizarCampoUrl();
        setTimeout(atualizarCampoUrl, 1200);

        const btnCopiar = document.getElementById('aki-div-btn-copiar');
        if (btnCopiar) {
            btnCopiar.addEventListener('click', function () {
                const u = obterUrlLoja();
                urlEl.value = u;
                copiarTexto(u, 'Link da loja copiado! Cole no WhatsApp, Instagram ou Google Meu Negócio.');
            });
        }

        const btnZap = document.getElementById('aki-div-btn-zap');
        if (btnZap) {
            btnZap.addEventListener('click', function () {
                const nome = obterNomeLoja();
                const u = obterUrlLoja();
                const msg = 'Oi! Agora a loja ' + nome + ' está no AcheIAki com preço direto e entrega rápida — sem taxa de app! Faça seu pedido por aqui: ' + u;
                copiarTexto(msg, 'Texto pronto copiado e abrindo WhatsApp para enviar na sua lista/status.');
                window.open('https://wa.me/?text=' + encodeURIComponent(msg), '_blank', 'noopener');
            });
        }

        const btnBio = document.getElementById('aki-div-btn-bio');
        if (btnBio) {
            btnBio.addEventListener('click', function () {
                const u = obterUrlLoja();
                const texto = 'Peça direto sem taxa de app:\n' + u;
                copiarTexto(texto, 'Texto da Bio do Instagram / Link da Loja (Google Maps) copiado!');
            });
            if (btnBio.parentElement && !document.getElementById('aki-div-btn-autozap')) {
                const btnAutoZap = document.createElement('button');
                btnAutoZap.type = 'button';
                btnAutoZap.id = 'aki-div-btn-autozap';
                btnAutoZap.className = btnBio.className || 'aki-bento-btn';
                btnAutoZap.style.cssText = 'background:#0d9488;color:#fff;border:none;border-radius:10px;padding:10px 14px;font-weight:700;cursor:pointer;';
                btnAutoZap.innerHTML = '<i class="fa-solid fa-robot"></i> Auto-resposta WhatsApp Business';
                btnAutoZap.addEventListener('click', function () {
                    const nome = obterNomeLoja();
                    const u = obterUrlLoja();
                    const autoMsg = 'Olá! Seja bem-vindo(a) à ' + nome + '.\nPara ver nosso catálogo e pedir rapidinho (com preço direto da loja e entrega calculada na hora), toque aqui:\n👉 ' + u;
                    copiarTexto(autoMsg, 'Mensagem automática para WhatsApp Business copiada! Cole em Configurações -> Saudação do WhatsApp.');
                });
                btnBio.parentElement.appendChild(btnAutoZap);
            }
        }

        const btnPanfleto = document.getElementById('aki-div-btn-panfleto');
        if (btnPanfleto) {
            btnPanfleto.addEventListener('click', abrirPanfletoImprimir);
        }

        const btnCupom = document.getElementById('aki-div-btn-cupom');
        if (btnCupom) {
            btnCupom.addEventListener('click', function () {
                if (window.PedidoZapModoAdmin && typeof window.PedidoZapModoAdmin.get === 'function' && window.PedidoZapModoAdmin.get() === 'basico') {
                    window.PedidoZapModoAdmin.set('avancado');
                }
                const abaClientes = document.querySelector('#admin-tabs [data-tab="clientes"]');
                if (abaClientes) {
                    abaClientes.click();
                }
                let tentativas = 0;
                const timer = setInterval(function () {
                    tentativas++;
                    const inp = document.getElementById('fg-cup-cod');
                    if (inp) {
                        clearInterval(timer);
                        if (!inp.value) inp.value = 'DIRETO10';
                        inp.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        inp.focus();
                    } else if (tentativas >= 15) {
                        clearInterval(timer);
                    }
                }, 150);
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciarKitDivulgacao);
    } else {
        iniciarKitDivulgacao();
    }
})();
