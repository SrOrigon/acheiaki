let watchId = null;
const btnToggle = document.getElementById('toggle-tracking');
const statusDisplay = document.getElementById('status-display');
const orderInput = document.getElementById('order-id');
const coordsDisplay = document.getElementById('coords-display');
let lastOrderHash = '';

// Check if URL has order ID
const urlParams = new URLSearchParams(window.location.search);
const selectPedido = document.getElementById('select-pedido');

const chaveStorage = (typeof TENANT !== 'undefined' && TENANT.storageKey) ? TENANT.storageKey('entregador_chave') : 'entregador_chave';
if (urlParams.get('k')) localStorage.setItem(chaveStorage, urlParams.get('k'));
const chaveEntregador = urlParams.get('k') || localStorage.getItem(chaveStorage) || '';
const codigoDaUrl = urlParams.get('c') || '';

function acessoQuery(id) {
    let q = 'k=' + encodeURIComponent(chaveEntregador);
    const adminPass = sessionStorage.getItem('admin_pass');
    if (adminPass) {
        q += '&user=' + encodeURIComponent(sessionStorage.getItem('admin_user') || '') + '&pass=' + encodeURIComponent(adminPass);
    }
    if (codigoDaUrl && String(id) === String(urlParams.get('id'))) {
        q += '&c=' + encodeURIComponent(codigoDaUrl);
    }
    return q;
}

if (urlParams.has('id')) {
    orderInput.value = urlParams.get('id');
    setTimeout(fetchOrderInfo, 500);
}

// Carrega pedidos ativos para o select
function apiFetch(path, options) {
    if (typeof TENANT !== 'undefined' && TENANT.fetchJson) {
        return TENANT.fetchJson(path, options);
    }
    return fetch(path, options);
}

function esc(valor) {
    return String(valor == null ? '' : valor).replace(/[&<>"']/g, (c) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    }[c]));
}

function carregarPedidosAtivos() {
    apiFetch('get_active_orders.php?' + acessoQuery(''))
        .then(res => res.json())
        .then(data => {
            if (!data.success) {
                selectPedido.innerHTML = '<option value="">' + esc(data.message || 'Peça à loja o link do entregador.') + '</option>';
                return;
            }
            if (data.success) {
                const options = data.orders.map(p => {
                    const statusIcon = p.status === 'em-rota' ? '🛵' : '🔥';
                    return `<option value="${esc(p.id)}">${statusIcon} #${esc(p.id)} - ${esc(p.cliente)}</option>`;
                });
                
                selectPedido.innerHTML = '<option value="">-- Selecione o Pedido --</option>' + options.join('');
                
                if (orderInput.value) {
                    selectPedido.value = orderInput.value;
                }
            }
        })
        .catch(err => {
            console.error("Erro ao carregar pedidos ativos:", err);
            selectPedido.innerHTML = '<option value="">Não achei os pedidos. Toque em voltar.</option>';
        });
}

carregarPedidosAtivos();

// Sincroniza select com input manual
selectPedido.addEventListener('change', (e) => {
    if (e.target.value) {
        orderInput.value = e.target.value;
        fetchOrderInfo();
    }
});

orderInput.addEventListener('blur', fetchOrderInfo);

function fetchOrderInfo() {
    const id = orderInput.value.trim();
    if (!id) return;
    
    // Sincroniza dropdown se digitar manualmente
    if (selectPedido.value !== id) {
        selectPedido.value = id;
    }

    apiFetch(`get_order_status.php?id=${encodeURIComponent(id)}&${acessoQuery(id)}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const currentOrderHash = JSON.stringify(data.order);
                if (currentOrderHash === lastOrderHash) return;
                lastOrderHash = currentOrderHash;

                const order = data.order;
                const container = document.getElementById('order-info-card');
                const content = document.getElementById('order-details-content');
                
                const end = order.endereco || {};
                let itensStr = (order.itens || []).map(it => `• ${esc(it.quantidade)}x ${esc(it.nome)}`).join('<br>');
                
                content.innerHTML = `
                    <p style="font-size: 1.1em; color: var(--primary); font-weight: 700; margin-bottom: 5px;">${esc(order.cliente)}</p>
                    <p><strong>Endereço:</strong><br>${esc(end.rua)}, ${esc(end.numero)}<br>${esc(end.bairro)}</p>
                    <a href="https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(`${end.rua || ''}, ${end.numero || ''}, ${end.bairro || ''}`)}" target="_blank" style="display: inline-block; background: #4285F4; color: white; padding: 8px 12px; border-radius: 8px; text-decoration: none; font-size: 0.8em; margin-bottom: 10px;"><i class="fa-solid fa-map-location-dot"></i> Abrir no Google Maps</a>
                    
                    <div style="background: #f8fafc; padding: 10px; border-radius: 8px; border: 1px solid #e2e8f0; margin-top: 10px;">
                        <p style="margin: 0;"><strong>Itens:</strong><br>${itensStr}</p>
                        <hr style="border:0; border-top:1px solid #e2e8f0; margin:8px 0;">
                        <p style="margin: 0;"><strong>Subtotal:</strong> R$ ${parseFloat(order.subtotal != null ? order.subtotal : order.total).toFixed(2).replace('.', ',')}</p>
                        <p style="margin: 0;"><strong>Entrega:</strong> R$ ${parseFloat(order.frete || 0).toFixed(2).replace('.', ',')} ${order.frete_detalhe ? '(' + esc(order.frete_detalhe) + ')' : ''}</p>
                        <p style="margin: 0;"><strong>Total:</strong> R$ ${parseFloat(order.total).toFixed(2).replace('.', ',')}</p>
                        <p style="margin: 0;"><strong>Pagamento:</strong> ${esc(order.pagamento || 'N/A')}</p>
                    </div>
                `;
                if (order.status === 'concluido' || order.status === 'arquivado') {
                    if (watchId !== null) {
                        alert("Este pedido foi finalizado. O rastreio foi encerrado automaticamente.");
                        stopTracking();
                    }
                }
                
                container.style.display = 'block';
            }
        })
        .catch(err => console.error("Erro ao buscar info do pedido:", err));
}

const ajudaLocal = document.getElementById('ajuda-local');
const ajudaTexto = document.getElementById('ajuda-texto');
const passosLocal = document.getElementById('passos-local');
const btnPedirLocal = document.getElementById('btn-pedir-local');
const btnAbrirAjuste = document.getElementById('btn-abrir-ajuste');
const textoLocal = document.getElementById('texto-local');
let localLigada = false;
let pedindoLocal = false;

function ehCelular() {
    const ua = navigator.userAgent || '';
    if (/Android|iPhone|iPad|iPod/i.test(ua)) return true;
    if (navigator.userAgentData && navigator.userAgentData.mobile) return true;
    return window.matchMedia('(pointer: coarse)').matches && window.innerWidth < 900;
}

function ehAndroid() {
    return /Android/i.test(navigator.userAgent || '');
}

function marcarStatus(texto, tipo) {
    statusDisplay.innerText = texto;
    statusDisplay.classList.remove('status-off', 'status-on', 'status-pc');
    statusDisplay.classList.add(tipo);
}

function mostrarAjuda(texto, passos, abrirAjuste) {
    ajudaTexto.textContent = texto;
    passosLocal.innerHTML = (passos || []).map((passo) => '<li>' + passo + '</li>').join('');
    ajudaLocal.hidden = false;
    if (btnAbrirAjuste) btnAbrirAjuste.hidden = !abrirAjuste;
}

function esconderAjuda() {
    if (ajudaLocal) ajudaLocal.hidden = true;
}

function explicarFalha(error) {
    const codigo = error && error.code;
    if (codigo === 1) {
        marcarStatus('O celular bloqueou', 'status-off');
        mostrarAjuda(
            'Toque em Pedir a localização de novo e escolha Permitir.',
            ehAndroid()
                ? ['Se não perguntar, toque no cadeado ao lado do endereço do site.', 'Ligue a opção Localização.', 'Volte e toque no botão de novo.']
                : ['Abra Ajustes no iPhone.', 'Toque em Privacidade e Segurança, depois em Serviços de Localização.', 'Ligue e volte aqui para tocar no botão de novo.'],
            false
        );
        return;
    }
    marcarStatus('Ligue a localização do celular', 'status-off');
    mostrarAjuda(
        ehAndroid()
            ? 'A localização do Android está desligada. Toque no botão preto para abrir o ajuste e ligue.'
            : 'A localização do iPhone está desligada. Siga os passos e volte para esta tela.',
        ehAndroid()
            ? ['Toque em Abrir a localização do celular.', 'Ligue a chave Localização.', 'Volte ao navegador e toque em Pedir a localização de novo.']
            : ['Abra Ajustes.', 'Privacidade e Segurança.', 'Serviços de Localização e ligue a chave.', 'Volte aqui e toque em Pedir a localização de novo.'],
        ehAndroid()
    );
}

function pedirLocalizacao() {
    if (!ehCelular()) return;
    if (!navigator.geolocation) {
        marcarStatus('Este navegador não acha o celular', 'status-off');
        mostrarAjuda('Abra esta tela no Chrome do Android ou no Safari do iPhone.', [], false);
        return;
    }
    if (pedindoLocal) return;
    pedindoLocal = true;
    marcarStatus('O celular está perguntando', 'status-pc');
    navigator.geolocation.getCurrentPosition(
        () => {
            pedindoLocal = false;
            localLigada = true;
            marcarStatus('Localização ligada', 'status-on');
            esconderAjuda();
            if (coordsDisplay) {
                coordsDisplay.hidden = false;
                coordsDisplay.innerText = 'O celular achou você.';
            }
        },
        (error) => {
            pedindoLocal = false;
            localLigada = false;
            if (error && error.code === 3) {
                navigator.geolocation.getCurrentPosition(
                    () => {
                        localLigada = true;
                        marcarStatus('Localização ligada', 'status-on');
                        esconderAjuda();
                    },
                    explicarFalha,
                    { enableHighAccuracy: false, timeout: 15000, maximumAge: 60000 }
                );
                return;
            }
            explicarFalha(error);
        },
        { enableHighAccuracy: true, timeout: 12000, maximumAge: 0 }
    );
}

function prepararTela() {
    if (!ehCelular()) {
        marcarStatus('Use no celular do entregador', 'status-pc');
        if (textoLocal) {
            textoLocal.textContent = 'No computador a localização fica desligada de propósito. Quem entrega abre esta mesma tela no celular, e o celular pede para ligar.';
        }
        mostrarAjuda(
            'Mande este endereço para o celular de quem faz a entrega. Lá a localização é pedida sozinha.',
            [],
            false
        );
        if (btnPedirLocal) btnPedirLocal.hidden = true;
        return;
    }
    if (textoLocal) {
        textoLocal.textContent = 'O celular vai pedir a localização. Toque em Permitir. Sem isso o cliente não vê a entrega no mapa.';
    }
    if (ehAndroid() && btnAbrirAjuste) {
        btnAbrirAjuste.href = 'intent:#Intent;action=android.settings.LOCATION_SOURCE_SETTINGS;end';
    }
    pedirLocalizacao();
}

if (btnPedirLocal) btnPedirLocal.addEventListener('click', pedirLocalizacao);
prepararTela();

btnToggle.addEventListener('click', () => {
    if (watchId === null) {
        startTracking();
    } else {
        stopTracking();
    }
});

function startTracking() {
    const orderId = orderInput.value;
    if (!ehCelular()) {
        mostrarAjuda('Abra esta tela no celular de quem entrega. O computador não liga a localização.', [], false);
        return;
    }
    if (!orderId) {
        alert('Escolha o pedido que você vai levar.');
        return;
    }

    if (!navigator.geolocation) {
        explicarFalha({ code: 2 });
        return;
    }

    watchId = navigator.geolocation.watchPosition(
        (position) => {
            const latitude = position.coords.latitude;
            const longitude = position.coords.longitude;
            localLigada = true;
            esconderAjuda();
            updateBackend(orderId, latitude, longitude);
            if (coordsDisplay) {
                coordsDisplay.hidden = false;
                coordsDisplay.innerText = 'O cliente está vendo você no mapa.';
            }
        },
        (error) => {
            if (error && error.code === 3) {
                marcarStatus('Ainda procurando você', 'status-pc');
                return;
            }
            explicarFalha(error);
            stopTracking(true);
        },
        {
            enableHighAccuracy: true,
            maximumAge: 10000,
            timeout: 20000
        }
    );

    btnToggle.innerText = 'Terminar entrega';
    btnToggle.classList.add('active');
    marcarStatus('Cliente está vendo você no mapa', 'status-on');
    orderInput.disabled = true;
    if (selectPedido) selectPedido.disabled = true;
}

function stopTracking(porErro) {
    if (watchId !== null) {
        navigator.geolocation.clearWatch(watchId);
        watchId = null;
    }

    btnToggle.innerText = 'Começar a entrega';
    btnToggle.classList.remove('active');
    orderInput.disabled = false;
    if (selectPedido) selectPedido.disabled = false;
    if (!porErro) {
        if (ehCelular() && localLigada) marcarStatus('Localização ligada', 'status-on');
        else if (!ehCelular()) marcarStatus('Use no celular do entregador', 'status-pc');
        else marcarStatus('Localização do celular ainda não ligou', 'status-off');
    }
}

function updateBackend(id, lat, lng) {
    apiFetch('update_location.php?' + acessoQuery(id), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            id,
            lat,
            lng,
            k: chaveEntregador,
            c: String(id) === String(urlParams.get('id')) ? codigoDaUrl : '',
            tenant: (typeof TENANT !== 'undefined') ? TENANT.slug : 'default'
        })
    })
    .then(res => res.json())
    .then(data => {
        if (!data.success) {
            console.error("Erro no backend:", data.message);
        } else if (typeof PedidoZapCelulas !== 'undefined') {
            PedidoZapCelulas.emitir('pedido_gps', {
                id: String(id),
                lat: lat,
                lng: lng,
                tenant: (typeof TENANT !== 'undefined' && TENANT.slug) ? TENANT.slug : 'default'
            });
        }
    })
    .catch(err => console.error("Erro de conexão:", err));
}

function aplicarAjudaEntregador() {
    const aj = (typeof configAjuda === 'function' ? configAjuda().entregador : null)
        || (window.APP_CONFIG && APP_CONFIG.AJUDA && APP_CONFIG.AJUDA.entregador) || {};
    const map = {
        'entregador-ajuda-intro': aj.intro,
        'entregador-ajuda-lista': aj.lista,
        'entregador-ajuda-sem-pedido': aj.sem_pedido,
        'entregador-ajuda-gps': aj.gps
    };
    Object.keys(map).forEach((id) => {
        const el = document.getElementById(id);
        if (el && map[id]) el.textContent = map[id];
    });
}

document.addEventListener('pedidozap-store', aplicarAjudaEntregador);
if (window.APP_CONFIG) aplicarAjudaEntregador();

(function vincularCelulasMotoboy() {
    const linkPainel = document.getElementById('link-voltar-painel-motoboy');
    if (linkPainel && typeof TENANT !== 'undefined' && TENANT.apiUrl) {
        linkPainel.href = TENANT.apiUrl('painel.html');
    }
    if (typeof PedidoZapCelulas !== 'undefined') {
        PedidoZapCelulas.escutar((ev) => {
            if (!ev) return;
            if (ev.tipo === 'pedido_criado' || ev.tipo === 'pedido_status') {
                carregarPedidosAtivos();
                if (orderInput && orderInput.value) fetchOrderInfo();
            }
        });
    }
})();

// Auto-refresh order info
setInterval(() => {
    if (orderInput.value) {
        fetchOrderInfo();
    }
    carregarPedidosAtivos();
}, 10000);
