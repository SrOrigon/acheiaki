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
    trackingInterval = setInterval(fetchLocation, 5000);
    if (typeof PedidoZapCelulas !== 'undefined') {
        PedidoZapCelulas.escutar((ev) => {
            if (!ev) return;
            if (ev.tipo === 'pedido_status' || ev.tipo === 'pedido_gps') {
                const evId = ev.dados && ev.dados.id ? String(ev.dados.id) : '';
                if (!evId || evId === String(orderId)) {
                    fetchLocation();
                }
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
            `;
            overlay.appendChild(privacyMsg);
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
