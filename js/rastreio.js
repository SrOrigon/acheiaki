let map, motoboyMarker, destinationMarker, shopMarker;
const urlParams = new URLSearchParams(window.location.search);
let orderId = urlParams.get('id');
let orderCode = urlParams.get('c') || '';

let SHOP_COORDS = [-15.793889, -47.882778];
let SHOP_NAME = 'Loja';

// Persistência: Se não houver ID na URL, tenta pegar o último do localStorage
if (!orderId) {
    orderId = localStorage.getItem('ultimoPedidoId');
    orderCode = localStorage.getItem('ultimoPedidoCodigo') || '';
} else {
    if (!orderCode && localStorage.getItem('ultimoPedidoId') === orderId) {
        orderCode = localStorage.getItem('ultimoPedidoCodigo') || '';
    }
    localStorage.setItem('ultimoPedidoId', orderId);
    localStorage.setItem('ultimoPedidoCodigo', orderCode);
}

const orderTitle = document.getElementById('order-title');
const statusText = document.getElementById('status-text');
const loading = document.getElementById('loading');
const errorContainer = document.getElementById('error-container');
let lastTrackHash = '';
let destinationCoords = null;

async function carregarLoja() {
    try {
        if (window.PedidoZapStore) {
            await PedidoZapStore.load({ fallbackStatic: true, silent: true });
        }
    } catch (e) {
        console.warn('Rastreio sem configuração da loja', e);
    }
    const cfg = window.APP_CONFIG || {};
    SHOP_NAME = cfg.NOME_LOJA || 'Loja';
    const end = cfg.ENDERECO || {};
    if (end.lat != null && end.lng != null && end.lat !== '' && end.lng !== '') {
        SHOP_COORDS = [Number(end.lat), Number(end.lng)];
    }
}

if (!orderId) {
    showError("Acesse o link enviado no seu WhatsApp para rastrear seu pedido.");
} else {
    orderTitle.innerText = `Pedido #${orderId}`;
    carregarLoja().finally(() => {
        initMap();
        startTracking();
    });
}

function initMap() {
    map = L.map('map', {
        zoomControl: false
    }).setView(SHOP_COORDS, 16);
    
    // Usando o servidor principal sem subdomínios para evitar problemas de SSL/Proxy
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        errorTileUrl: 'https://via.placeholder.com/256/f0f0f0/cccccc?text=Mapa+Carregando...'
    }).addTo(map);

    L.control.zoom({ position: 'bottomright' }).addTo(map);

    // Forçar redimensionamento para evitar mapa cinza
    setTimeout(() => {
        map.invalidateSize();
    }, 500);

    // Marker da Loja
    const shopIcon = L.divIcon({
        className: 'custom-div-icon',
        html: "<div style='background-color:#1e2937; color:white; border-radius:50%; width:34px; height:34px; display:flex; align-items:center; justify-content:center; border:2px solid white; box-shadow:0 0 10px rgba(0,0,0,0.2);'><i class='fa-solid fa-store'></i></div>",
        iconSize: [34, 34],
        iconAnchor: [17, 17]
    });
    shopMarker = L.marker(SHOP_COORDS, { icon: shopIcon }).addTo(map).bindPopup(SHOP_NAME);
}

let trackingInterval;
function startTracking() {
    const linkLoja = document.getElementById('rastreio-link-loja');
    if (linkLoja) {
        const slug = (typeof TENANT !== 'undefined' && TENANT.slug) ? TENANT.slug : '';
        if (typeof PedidoZapCelulas !== 'undefined' && slug && slug !== 'default') {
            linkLoja.href = PedidoZapCelulas.urlComTenant('index.html', slug);
        } else if (typeof TENANT !== 'undefined' && TENANT.apiUrl && slug && slug !== 'default') {
            linkLoja.href = TENANT.apiUrl('index.html');
        } else {
            linkLoja.href = 'marketplace.html';
        }
    }
    fetchLocation();
    trackingInterval = setInterval(() => {
        if (window.AcheIAkiRealtime && window.AcheIAkiRealtime.estaConectado && window.AcheIAkiRealtime.estaConectado()) {
            return; // SSE conectado entrega atualização instantânea sem polling redundante
        }
        fetchLocation();
    }, 12000);
    const navBox = document.getElementById('rastreio-nav');
    if (navBox && window.AcheIAkiRealtime && 'Notification' in window && Notification.permission !== 'granted') {
        const btnPush = document.createElement('button');
        btnPush.type = 'button';
        btnPush.style.cssText = 'flex-basis:100%;padding:10px 12px;border-radius:12px;border:1px solid #f59e0b;background:#fffbeb;color:#92400e;font-weight:700;font-size:0.86rem;cursor:pointer;';
        btnPush.innerHTML = '<i class="fa-solid fa-bell"></i> Ativar notificações em tempo real deste pedido';
        btnPush.addEventListener('click', async () => {
            btnPush.disabled = true;
            const resPush = await window.AcheIAkiRealtime.ativarPush({ role: 'cliente', pedidoId: String(orderId || '') });
            btnPush.textContent = (resPush && resPush.success) ? '✓ Alertas em tempo real ativados!' : ((resPush && resPush.message) || 'Alertas ativados neste aparelho');
        });
        navBox.appendChild(btnPush);
    }
    if (typeof PedidoZapCelulas !== 'undefined') {
        PedidoZapCelulas.escutar((ev) => {
            if (!ev) return;
            const d = ev.dados || {};
            const evId = d.id ? String(d.id) : '';
            if (evId && evId !== String(orderId)) return;
            if (ev.tipo === 'pedido_gps' && typeof d.lat === 'number' && typeof d.lng === 'number' && typeof updateMotoboyMarker === 'function') {
                updateMotoboyMarker(d.lat, d.lng);
                if (loading) loading.style.display = 'none';
            } else if (ev.tipo === 'pedido_status' || ev.tipo === 'pedido_gps') {
                fetchLocation();
            }
        });
    }
}

function apiFetch(path, options) {
    if (typeof TENANT !== 'undefined' && TENANT.fetchJson) {
        return TENANT.fetchJson(path, options);
    }
    return fetch(path, options);
}

function fetchLocation() {
    apiFetch(`get_order_status.php?id=${encodeURIComponent(orderId)}&c=${encodeURIComponent(orderCode)}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                const currentTrackHash = JSON.stringify(data.order);
                if (currentTrackHash === lastTrackHash) return;
                lastTrackHash = currentTrackHash;

                const order = data.order;
                updateStatusUI(order.status);
                mostrarRastreioExterno(order.rastreio_externo);

                // Geocode destination if not already done
                if (!destinationCoords && order.endereco) {
                    geocodeAddress(order.endereco);
                }

                if (order.status === 'concluido' || order.status === 'arquivado') {
                    clearInterval(trackingInterval);
                }

                if (order.location) {
                    const { lat, lng } = order.location;
                    updateMotoboyMarker(lat, lng);
                    
                    loading.style.opacity = '0';
                    setTimeout(() => loading.style.display = 'none', 500);
                    errorContainer.style.display = 'none';
                } else {
                    // Se ainda não tem localização mas já saiu, foca na loja ou destino
                    if (order.status === 'em-rota') {
                         loading.querySelector('p').innerText = "Aguardando sinal do GPS...";
                    } else {
                        loading.style.opacity = '0';
                        setTimeout(() => loading.style.display = 'none', 500);
                    }
                }
            } else {
                showError("Pedido não encontrado.");
                clearInterval(trackingInterval);
            }
        })
        .catch(err => console.error("Erro ao buscar localização:", err));
}

function geocodeAddress(end) {
    if (!end || !end.rua) return;
    const query = [end.rua, end.numero, end.bairro, end.cidade, end.estado, 'Brasil'].filter(Boolean).join(', ');
    fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}&limit=1`)
        .then(res => res.json())
        .then(results => {
            if (results && results.length > 0) {
                destinationCoords = [parseFloat(results[0].lat), parseFloat(results[0].lon)];
                updateDestinationMarker();
            }
        })
        .catch(err => console.error("Erro no geocoding:", err));
}

function updateDestinationMarker() {
    if (!destinationCoords) return;

    const destIcon = L.divIcon({
        className: 'custom-div-icon',
        html: "<div style='background-color:#10b981; color:white; border-radius:50%; width:34px; height:34px; display:flex; align-items:center; justify-content:center; border:2px solid white; box-shadow:0 0 10px rgba(0,0,0,0.2);'><i class='fa-solid fa-house-user'></i></div>",
        iconSize: [34, 34],
        iconAnchor: [17, 17]
    });

    if (!destinationMarker) {
        destinationMarker = L.marker(destinationCoords, { icon: destIcon }).addTo(map).bindPopup("Seu Endereço");
    } else {
        destinationMarker.setLatLng(destinationCoords);
    }
    fitMap();
}

function mostrarRastreioExterno(url) {
    let el = document.getElementById('rastreio-externo');
    if (!url || !/^https?:\/\//i.test(String(url))) {
        if (el) el.remove();
        return;
    }
    if (!el) {
        el = document.createElement('a');
        el.id = 'rastreio-externo';
        el.target = '_blank';
        el.rel = 'noopener noreferrer';
        el.textContent = 'Acompanhar entrega';
        el.style.cssText = 'display:block;margin:12px auto 0;background:#111827;color:#fff;text-align:center;padding:12px 16px;border-radius:12px;text-decoration:none;font-weight:700;max-width:280px;';
        const overlay = document.querySelector('.overlay') || document.body;
        overlay.appendChild(el);
    }
    el.href = url;
}

function updateStatusUI(status) {
    const steps = ['novo', 'preparo', 'em-rota', 'concluido'];
    const currentIdx = steps.indexOf(status);
    
    const progress = document.getElementById('step-progress');
    const percent = Math.max(0, (currentIdx / (steps.length - 1)) * 100);
    progress.style.width = `${percent}%`;

    steps.forEach((s, idx) => {
        const el = document.getElementById(`step-${s}`);
        if (!el) return;
        if (idx < currentIdx) el.className = 'step completed';
        else if (idx === currentIdx) el.className = 'step active';
        else el.className = 'step';
    });

    const texts = {
        'novo': 'Pedido confirmado! Logo entrará em preparo.',
        'preparo': 'Seu lanche está sendo preparado com carinho! 🍔',
        'em-rota': 'O motoboy saiu para entrega! Acompanhe no mapa. 🛵',
        'concluido': 'Pedido entregue! Bom apetite! 😋',
        'arquivado': 'Este pedido foi finalizado e arquivado.'
    };
    statusText.innerText = texts[status] || 'Aguardando atualização...';

    const mapEl = document.getElementById('map');
    const statusIcon = document.getElementById('status-icon');
    const overlay = document.querySelector('.overlay');
    let privacyMsg = document.getElementById('privacy-msg');
    
    if (status === 'em-rota') {
        mapEl.style.opacity = '1';
        mapEl.style.filter = 'none';
        statusIcon.classList.add('pulse-animation');
    } else if (status === 'concluido' || status === 'arquivado') {
        statusIcon.classList.remove('pulse-animation');
        mapEl.style.display = 'none'; // Fechar completamente o mapa
        
        if (!privacyMsg) {
            privacyMsg = document.createElement('div');
            privacyMsg.id = 'privacy-msg';
            privacyMsg.style.cssText = 'background: var(--success); color: white; padding: 20px; border-radius: 20px; text-align: center; box-shadow: 0 10px 25px rgba(0,0,0,0.15); animation: fade-in 0.5s ease-out;';
            privacyMsg.innerHTML = `
                <i class="fa-solid fa-shield-halved" style="font-size: 2rem; margin-bottom: 10px;"></i>
                <h3 style="margin:0 0 5px 0;">Rastreio Encerrado</h3>
                <p style="margin:0; font-size: 0.9em;">Por motivos de segurança, o sinal de GPS foi finalizado e os dados do entregador apagados do sistema.</p>
                <div id="rastreio-avaliar-box" style="margin-top:14px;background:rgba(255,255,255,0.96);color:#0f172a;border-radius:14px;padding:14px;text-align:left;pointer-events:auto;">
                    <strong style="display:block;font-size:0.95rem;margin-bottom:6px;">⭐ Avalie seu pedido</strong>
                    <div id="rastreio-av-stars" style="display:flex;gap:6px;margin-bottom:8px;">
                        <button type="button" data-r-star="1" style="border:1px solid #fde68a;background:#fffbeb;color:#f59e0b;font-size:1.35rem;border-radius:8px;padding:4px 9px;cursor:pointer;">★</button>
                        <button type="button" data-r-star="2" style="border:1px solid #fde68a;background:#fffbeb;color:#f59e0b;font-size:1.35rem;border-radius:8px;padding:4px 9px;cursor:pointer;">★</button>
                        <button type="button" data-r-star="3" style="border:1px solid #fde68a;background:#fffbeb;color:#f59e0b;font-size:1.35rem;border-radius:8px;padding:4px 9px;cursor:pointer;">★</button>
                        <button type="button" data-r-star="4" style="border:1px solid #fde68a;background:#fffbeb;color:#f59e0b;font-size:1.35rem;border-radius:8px;padding:4px 9px;cursor:pointer;">★</button>
                        <button type="button" data-r-star="5" style="border:1px solid #fde68a;background:#fffbeb;color:#f59e0b;font-size:1.35rem;border-radius:8px;padding:4px 9px;cursor:pointer;">★</button>
                    </div>
                    <textarea id="rastreio-av-txt" rows="2" placeholder="Como estava a comida e a entrega?" style="width:100%;padding:8px;border:1px solid #cbd5e1;border-radius:8px;box-sizing:border-box;font-family:inherit;"></textarea>
                    <button type="button" id="rastreio-av-enviar" style="margin-top:8px;width:100%;border:none;border-radius:10px;padding:10px;background:#e85d04;color:#fff;font-weight:800;cursor:pointer;">Enviar feedback</button>
                    <p id="rastreio-av-msg" style="margin:6px 0 0;font-size:0.84rem;font-weight:700;"></p>
                </div>
            `;
            overlay.appendChild(privacyMsg);
            let notaR = 5;
            privacyMsg.querySelectorAll('[data-r-star]').forEach((b) => {
                b.addEventListener('click', () => {
                    notaR = Number(b.getAttribute('data-r-star') || 5);
                    privacyMsg.querySelectorAll('[data-r-star]').forEach((x) => {
                        x.style.color = Number(x.getAttribute('data-r-star')) <= notaR ? '#f59e0b' : '#cbd5e1';
                    });
                });
            });
            const btnEnvR = document.getElementById('rastreio-av-enviar');
            if (btnEnvR) {
                btnEnvR.addEventListener('click', () => {
                    const msgR = document.getElementById('rastreio-av-msg');
                    if (msgR) msgR.textContent = 'Publicando...';
                    const baseMkt = (location.pathname.includes('/loja/')) ? '../../marketplace_api.php?action=avaliar' : 'marketplace_api.php?action=avaliar';
                    fetch(baseMkt, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            tenant: (typeof TENANT !== 'undefined' && TENANT.slug) ? TENANT.slug : 'default',
                            pedido_id: String(orderId || ''),
                            nota: notaR,
                            comentario: (document.getElementById('rastreio-av-txt') || {}).value || ''
                        })
                    }).then((r) => r.json()).then((d) => {
                        if (msgR) {
                            msgR.style.color = d.success ? '#15803d' : '#b91c1c';
                            msgR.textContent = d.success ? 'Feedback publicado para todos! Obrigado.' : (d.message || 'Erro ao enviar.');
                        }
                    }).catch(() => {
                        if (msgR) msgR.textContent = 'Sem conexão para publicar agora.';
                    });
                });
            }
        }
    }
}

function updateMotoboyMarker(lat, lng) {
    const pos = [lat, lng];
    const motoboyIcon = L.divIcon({
        className: 'custom-div-icon',
        html: "<div style='background-color:#f97136; color:white; border-radius:50%; width:44px; height:44px; display:flex; align-items:center; justify-content:center; border:3px solid white; box-shadow:0 0 20px rgba(249, 113, 54, 0.5);'><i class='fa-solid fa-motorcycle'></i></div>",
        iconSize: [44, 44],
        iconAnchor: [22, 22]
    });

    if (!motoboyMarker) {
        motoboyMarker = L.marker(pos, { icon: motoboyIcon }).addTo(map).bindPopup("Entregador");
    } else {
        motoboyMarker.setLatLng(pos);
    }
    fitMap();
}

function fitMap() {
    const points = [];
    if (SHOP_COORDS) points.push(SHOP_COORDS);
    if (destinationCoords) points.push(destinationCoords);
    if (motoboyMarker) points.push(motoboyMarker.getLatLng());

    if (points.length > 1) {
        map.fitBounds(L.latLngBounds(points), { padding: [50, 50] });
    } else if (points.length === 1) {
        map.setView(points[0], 16);
    }
}

function showError(msg) {
    loading.style.display = 'none';
    errorContainer.innerText = msg;
    errorContainer.style.display = 'block';
    
    // Fechar mapa em caso de erro ou pedido deletado
    const mapEl = document.getElementById('map');
    if (mapEl) mapEl.style.display = 'none';
}
