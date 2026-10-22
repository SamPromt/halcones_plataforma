(() => {
  const api = window.HalconesApi;
  const ticketContainer = document.getElementById('ticket-container');
  const fleetList = document.getElementById('fleet-list');
  const mapElement = document.getElementById('fleet-map');
  const badge = document.getElementById('badge');
  const toast = document.getElementById('toast');
  const connectionStatus = document.getElementById('connection-status');
  const statusClasses = {
    AVAILABLE: 'border-emerald-500/30 bg-emerald-500/10 text-emerald-300',
    BUSY: 'border-red-500/30 bg-red-500/10 text-red-300',
    OUT_OF_SERVICE: 'border-amber-500/30 bg-amber-500/10 text-amber-300'
  };
  const statusLabels = { AVAILABLE: 'Libre', BUSY: 'En servicio', OUT_OF_SERVICE: 'Fuera de servicio' };
  let map;
  let markers = new Map();
  let toastTimer;

  ticketContainer.innerHTML = '<p class="rounded-2xl border border-slate-800 bg-slate-950/70 p-4 text-sm text-slate-400">Inicia sesión para cargar solicitudes.</p>';
  fleetList.innerHTML = '<p class="rounded-2xl border border-slate-800 bg-slate-950/70 p-4 text-sm text-slate-400">Inicia sesión para cargar la flota.</p>';
  badge.textContent = '0 pendientes';

  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, character => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[character]);
  }

  function formatCurrency(value) {
    if (value === null || value === undefined || value === '') return 'Por definir';
    return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(Number(value));
  }

  function notify(message) {
    const messageElement = toast.querySelector('div > div > p:last-child');
    if (messageElement) messageElement.textContent = message;
    toast.classList.remove('translate-y-24', 'opacity-0');
    toast.classList.add('translate-y-0', 'opacity-100');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => {
      toast.classList.remove('translate-y-0', 'opacity-100');
      toast.classList.add('translate-y-24', 'opacity-0');
    }, 3000);
  }

  function initMap() {
    if (!window.L || map) return;
    map = L.map(mapElement, { zoomControl: true }).setView([4.337, -74.363], 14);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; OpenStreetMap'
    }).addTo(map);
    setTimeout(() => map.invalidateSize(), 150);
  }

  function renderDrivers(drivers) {
    fleetList.replaceChildren();
    markers.forEach(marker => marker.remove());
    markers.clear();
    let gpsCount = 0;

    if (drivers.length === 0) {
      fleetList.innerHTML = '<p class="rounded-2xl border border-slate-800 bg-slate-950/70 p-4 text-sm text-slate-400">Sin conductores con GPS reciente.</p>';
    }

    drivers.forEach(driver => {
      const statusClass = statusClasses[driver.status] || 'border-slate-700 bg-slate-900 text-slate-300';
      const statusLabel = statusLabels[driver.status] || driver.status;
      const item = document.createElement('div');
      item.className = 'flex items-center justify-between rounded-2xl border border-slate-800 bg-slate-950/70 p-3';
      item.innerHTML = `<div class="flex items-center gap-3"><span class="h-2.5 w-2.5 rounded-full ${driver.status === 'AVAILABLE' ? 'bg-emerald-400' : 'bg-primary'}"></span><div><p class="font-bold text-white">${escapeHtml(driver.name)}</p><p class="text-[10px] uppercase tracking-[0.18em] text-slate-400">${escapeHtml(driver.plate)} · ${escapeHtml(driver.driver_code)}</p></div></div><span class="rounded-full border px-2 py-1 text-[9px] font-bold uppercase tracking-[0.18em] ${statusClass}">${escapeHtml(statusLabel)}</span>`;
      fleetList.appendChild(item);

      if (driver.last_latitude === null || driver.last_longitude === null) return;
      gpsCount++;
      const position = [Number(driver.last_latitude), Number(driver.last_longitude)];
      const marker = L.circleMarker(position, {
        radius: 8,
        color: driver.status === 'AVAILABLE' ? '#10b981' : '#c8102e',
        fillColor: driver.status === 'AVAILABLE' ? '#10b981' : '#c8102e',
        fillOpacity: 0.9,
        weight: 2
      }).bindPopup(`${escapeHtml(driver.name)} · ${escapeHtml(driver.plate)}<br>${escapeHtml(statusLabel)}`).addTo(map);
      markers.set(String(driver.id), marker);
    });
    const fleetCount = document.getElementById('fleet-count');
    if (fleetCount) fleetCount.textContent = `${drivers.length} móviles con GPS`;

    document.getElementById('metric-available').textContent = String(drivers.filter(driver => driver.status === 'AVAILABLE').length);
    document.getElementById('metric-gps').textContent = drivers.length ? `${Math.round(gpsCount / drivers.length * 100)}%` : '0%';
    if (markers.size && map && !map.hasMoved) {
      map.fitBounds(L.featureGroup(Array.from(markers.values())).getBounds().pad(0.18));
      map.hasMoved = true;
    }
  }

  function renderRequests(services) {
    const pending = services.filter(service => ['REQUESTED', 'PENDING_ASSIGNMENT'].includes(service.status));
    badge.textContent = `${pending.length} pendiente${pending.length === 1 ? '' : 's'}`;
    if (pending.length === 0) {
      ticketContainer.innerHTML = '<p class="rounded-2xl border border-slate-800 bg-slate-950/70 p-4 text-sm text-slate-400">No hay solicitudes pendientes.</p>';
      return;
    }

    ticketContainer.innerHTML = pending.map(service => `
      <article class="rounded-2xl border border-slate-700 bg-slate-950/70 p-4 shadow-lg transition-all duration-300">
        <div class="mb-3 flex items-start justify-between"><span class="font-headline text-base font-black uppercase text-white">#${escapeHtml(service.public_id.slice(0, 8))}</span><span class="rounded-full border border-amber-500/30 bg-amber-500/10 px-2 py-0.5 font-mono text-[10px] font-bold text-amber-300">${escapeHtml(service.status)}</span></div>
        <div class="space-y-2 text-[11px] text-slate-300"><div class="flex items-start gap-2"><span class="material-symbols-outlined text-[16px] text-primary">trip_origin</span><p><span class="font-bold text-white">Origen:</span> ${escapeHtml(service.origin_address)}</p></div><div class="flex items-start gap-2"><span class="material-symbols-outlined text-[16px] text-secondary">location_on</span><p><span class="font-bold text-white">Destino:</span> ${escapeHtml(service.destination_address)}</p></div></div>
        <div class="mt-3 flex items-center justify-between rounded-xl border border-slate-800 bg-slate-900/80 px-3 py-2"><span class="text-[9px] font-bold uppercase tracking-[0.18em] text-slate-400">Tarifa</span><span class="font-mono text-sm font-black text-white">${formatCurrency(service.estimated_fare)} COP</span></div>
        <div class="mt-4 grid grid-cols-2 gap-2"><button data-auto="${Number(service.id)}" class="rounded-xl bg-primary px-3 py-3 text-[10px] font-black uppercase tracking-[0.18em] text-white shadow-[0_0_18px_rgba(200,16,46,0.25)] transition hover:bg-red-600">Despacho auto</button><button data-manual="${Number(service.id)}" class="rounded-xl border border-slate-700 bg-slate-900 px-3 py-3 text-[10px] font-black uppercase tracking-[0.18em] text-slate-200 transition hover:border-slate-500 hover:text-white">Asign. manual</button></div>
      </article>`).join('');
  }

  async function loadDashboard() {
    try {
      const [services, drivers, summary, operator] = await Promise.all([
        api.request('/services'),
        api.request('/drivers/available'),
        api.request('/dashboard/summary'),
        api.request('/auth/me')
      ]);
      renderRequests(services);
      renderDrivers(drivers);
      document.getElementById('operator-name').textContent = operator.name;
      document.getElementById('operator-code').textContent = `Operador #${operator.id}`;
      document.getElementById('metric-active').textContent = String(summary.active_services);
      connectionStatus.textContent = 'API conectada';
      connectionStatus.classList.remove('text-amber-300', 'text-red-300');
      connectionStatus.classList.add('text-emerald-300');
    } catch (error) {
      connectionStatus.textContent = 'API desconectada';
      connectionStatus.classList.remove('text-emerald-300', 'text-amber-300');
      connectionStatus.classList.add('text-red-300');
      notify(error.message);
    }
  }

  function chooseDriver(drivers) {
    return new Promise(resolve => {
      const dialog = document.createElement('dialog');
      dialog.className = 'w-[min(92vw,30rem)] rounded-2xl border border-slate-700 bg-slate-950 p-0 text-slate-100 shadow-2xl backdrop:bg-black/75';
      dialog.innerHTML = `<form method="dialog" class="space-y-4 p-5"><div class="flex items-center justify-between"><h2 class="font-headline text-base font-black uppercase text-white">Asignar conductor</h2><button type="button" data-close class="rounded-lg p-2 text-slate-400 hover:bg-slate-800" aria-label="Cerrar"><span class="material-symbols-outlined">close</span></button></div><div class="max-h-64 space-y-2 overflow-y-auto">${drivers.map((driver, index) => `<label class="flex cursor-pointer items-center gap-3 rounded-xl border border-slate-700 bg-slate-900 p-3 hover:border-slate-500"><input type="radio" name="driver" value="${Number(driver.id)}" ${index === 0 ? 'checked' : ''}><span class="min-w-0 flex-1"><span class="block font-bold text-white">${escapeHtml(driver.name)}</span><span class="block text-xs text-slate-400">${escapeHtml(driver.plate)} · ${escapeHtml(driver.driver_code)}</span></span><span class="material-symbols-outlined text-emerald-400">near_me</span></label>`).join('')}</div><div class="flex justify-end gap-2"><button type="button" data-cancel class="rounded-xl border border-slate-700 bg-slate-900 px-4 py-2 text-xs font-bold uppercase text-slate-300">Cancelar</button><button type="submit" class="rounded-xl bg-primary px-4 py-2 text-xs font-black uppercase text-white">Asignar</button></div></form>`;
      document.body.appendChild(dialog);
      const finish = value => { dialog.close(); dialog.remove(); resolve(value); };
      dialog.querySelector('[data-close]').addEventListener('click', () => finish(null));
      dialog.querySelector('[data-cancel]').addEventListener('click', () => finish(null));
      dialog.addEventListener('cancel', event => { event.preventDefault(); finish(null); });
      dialog.querySelector('form').addEventListener('submit', event => {
        event.preventDefault();
        finish(Number(dialog.querySelector('input[name="driver"]:checked')?.value) || null);
      });
      dialog.showModal();
    });
  }

  async function assign(serviceId, manual) {
    try {
      let path = `/services/${serviceId}/assign/auto`;
      let body = {};
      if (manual) {
        const drivers = await api.request('/drivers/available');
        if (!drivers.length) throw new Error('No hay conductores disponibles con GPS reciente.');
        const driverId = await chooseDriver(drivers);
        if (!driverId) return;
        path = `/services/${serviceId}/assign`;
        body = { driver_id: driverId };
      }
      await api.request(path, { method: 'POST', body });
      notify(manual ? 'Servicio asignado al conductor seleccionado.' : 'Servicio asignado automáticamente.');
      await loadDashboard();
    } catch (error) {
      notify(error.message);
    }
  }

  ticketContainer.addEventListener('click', event => {
    const autoButton = event.target.closest('[data-auto]');
    const manualButton = event.target.closest('[data-manual]');
    if (autoButton) assign(autoButton.dataset.auto, false);
    if (manualButton) assign(manualButton.dataset.manual, true);
  });

  initMap();
  loadDashboard();
  setInterval(loadDashboard, 20000);
  api.subscribe('private-operators', {
    'service.requested': loadDashboard,
    'service.offered': loadDashboard,
    'service.updated': loadDashboard,
    'driver.status.updated': loadDashboard,
    'driver.location.updated': loadDashboard
  }).then(client => {
    if (!client) return;
    client.connection.bind('connected', () => { connectionStatus.textContent = 'WebSocket conectado'; });
    client.connection.bind('disconnected', () => { connectionStatus.textContent = 'Reconectando'; });
  }).catch(() => {});
})();
