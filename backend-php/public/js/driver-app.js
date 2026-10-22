(() => {
  const api = window.HalconesApi;
  const accessKey = 'halcones.access_token';
  let driverProfile;
  let locationWatchId;
  let offerPoll;
  let historyLoaded = false;
  let renderStatus;
  let paymentKey;

  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, character => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[character]);
  }

  function normalizedRide(service) {
    return {
      id: service.public_id,
      databaseId: Number(service.id),
      origin: service.origin_address,
      dest: service.destination_address,
      customerName: service.customer_name || 'Pasajero',
      customerPhone: service.customer_phone || '',
      price: String(Math.round(Number(service.final_fare || service.estimated_fare || 0))).replace(/\B(?=(\d{3})+(?!\d))/g, '.'),
      dist: 'GPS activo',
      lat: service.destination_latitude || service.origin_latitude || '4.3370',
      lng: service.destination_longitude || service.origin_longitude || '-74.3630',
      status: service.status
    };
  }

  function todayBounds() {
    const parts = new Intl.DateTimeFormat('en-CA', {
      timeZone: 'America/Bogota', year: 'numeric', month: '2-digit', day: '2-digit'
    }).formatToParts(new Date()).reduce((result, part) => {
      if (part.type !== 'literal') result[part.type] = part.value;
      return result;
    }, {});
    const today = `${parts.year}-${parts.month}-${parts.day}`;
    const tomorrow = new Date(`${today}T12:00:00-05:00`);
    tomorrow.setDate(tomorrow.getDate() + 1);
    const to = new Intl.DateTimeFormat('en-CA', {
      timeZone: 'America/Bogota', year: 'numeric', month: '2-digit', day: '2-digit'
    }).format(tomorrow);
    return { from: today, to };
  }

  async function refreshFinancials() {
    const { from, to } = todayBounds();
    const rows = await api.request(`/reports/financial?from=${from}&to=${to}`);
    finanzas.efectivo = Number(rows.find(row => row.method === 'CASH')?.total || 0);
    finanzas.transferencia = Number(rows.find(row => row.method === 'TRANSFER')?.total || 0);
    actualizarBalance();
  }

  async function refreshHistory() {
    const services = await api.request('/services?status=COMPLETED');
    const container = document.getElementById('historial-list');
    container.replaceChildren();
    services.slice().reverse().forEach(service => {
      const ride = normalizedRide(service);
      const method = service.payment_method === 'TRANSFER' ? 'Transferencia' : 'Efectivo';
      buildHistoryItem(ride, method);
    });
    if (services.length === 0) {
      const empty = document.createElement('p');
      empty.className = 'rounded-2xl border border-surface-variant bg-surface-container p-4 text-sm text-gray-400';
      empty.textContent = 'Aún no hay servicios completados.';
      container.appendChild(empty);
    }
    historyLoaded = true;
  }

  async function loadOffers() {
    if (driverStatus !== 'DISPONIBLE' || currentRide?.databaseId) return;
    try {
      const offers = await api.request('/services?status=OFFERED');
      if (!offers.length) {
        switchState(0);
        return;
      }
      currentRide = normalizedRide(offers[0]);
      tripPhase = 0;
      document.getElementById('ui-ride-id').textContent = `Servicio #${currentRide.id.slice(0, 8)}`;
      document.getElementById('ui-ride-price').textContent = `$${currentRide.price}`;
      document.getElementById('ui-ride-dist').textContent = `${currentRide.dist} · Auto`;
      document.getElementById('ui-ride-origin').textContent = currentRide.origin;
      document.getElementById('ui-ride-dest').textContent = currentRide.dest;
      document.getElementById('ui-final-id').textContent = currentRide.id;
      document.getElementById('ui-final-price').textContent = `$${currentRide.price}`;
      switchState(1);
      startTimer();
    } catch (error) {
      showToast(error.message);
    }
  }

  async function expireOffer() {
    clearInterval(timerInterval);
    if (currentRide?.databaseId) {
      try {
        await api.request(`/services/${currentRide.databaseId}/actions/reject`, { method: 'POST', body: { reason: 'Oferta expirada en el dispositivo' } });
      } catch (_) {
        showToast('No se pudo liberar la oferta; sincronizando con la central.');
      }
    }
    currentRide = null;
    driverStatus = 'DISPONIBLE';
    switchState(0);
    showToast('Tiempo agotado. Buscando otra carrera.');
    setTimeout(loadOffers, 500);
  }

  async function persistStatus(status, reason) {
    const apiStatus = status === 'DISPONIBLE' ? 'AVAILABLE' : 'OUT_OF_SERVICE';
    await api.request('/drivers/me/status', {
      method: 'PATCH',
      body: { status: apiStatus, reason: reason || null }
    });
    driverStatus = status;
    renderStatus(status, true);
    if (status === 'DISPONIBLE') {
      switchState(0);
      await loadOffers();
    } else {
      clearInterval(timerInterval);
      switchState(2);
    }
  }

  async function startLocationTracking() {
    if (!navigator.geolocation) {
      showToast('Este dispositivo no ofrece geolocalización.');
      return;
    }
    const sendPosition = async position => {
      try {
        await api.request('/drivers/me/location', {
          method: 'POST',
          body: {
            latitude: position.coords.latitude,
            longitude: position.coords.longitude,
            accuracy: position.coords.accuracy,
            speed: position.coords.speed,
            heading: position.coords.heading
          }
        });
      } catch (error) {
        if (error.message !== 'rate_limited') showToast(error.message);
      }
    };
    locationWatchId = navigator.geolocation.watchPosition(sendPosition, error => {
      if (error.code === error.PERMISSION_DENIED) showToast('Activa el permiso de ubicación para recibir servicios.');
    }, { enableHighAccuracy: true, maximumAge: 3000, timeout: 15000 });
  }

  function buildHistoryItem(ride, method) {
    const origin = escapeHtml(ride.origin.split(',')[0]);
    const destination = escapeHtml(ride.dest.split(',')[0]);
    const color = method === 'Efectivo' ? 'text-secondary' : 'text-blue-400';
    const icon = method === 'Efectivo' ? 'payments' : 'account_balance';
    const item = document.createElement('div');
    item.className = 'bg-surface-container p-4 rounded-2xl border border-surface-variant flex items-center justify-between shadow-md mb-3 animate-fade-in';
    item.innerHTML = `<div class="flex items-center gap-3"><div class="w-12 h-12 rounded-full bg-surface-container-highest flex items-center justify-center border border-gray-600"><span class="material-symbols-outlined ${color} text-[20px]">${icon}</span></div><div class="flex flex-col gap-1"><div class="flex items-center gap-1 text-xs text-white font-bold"><span class="material-symbols-outlined text-[14px] text-secondary">trip_origin</span>${origin}</div><div class="flex items-center gap-1 text-xs text-white font-bold"><span class="material-symbols-outlined text-[14px] text-primary">location_on</span>${destination}</div><span class="text-[10px] text-gray-400 font-mono mt-0.5">Registrado · Pago: ${escapeHtml(method)}</span></div></div><span class="font-mono font-black ${color} text-base">+$${escapeHtml(ride.price)}</span>`;
    document.getElementById('historial-list').prepend(item);
  }

  function updateActiveContact(ride) {
    const name = document.getElementById('ui-active-customer');
    const whatsapp = document.getElementById('ui-active-whatsapp');
    const call = document.getElementById('ui-active-call');
    if (name) name.textContent = ride.customerName || 'Pasajero';
    const phone = String(ride.customerPhone || '').replace(/[^0-9]/g, '');
    if (whatsapp) whatsapp.href = phone ? `https://wa.me/${phone}?text=${encodeURIComponent('Hola, soy su conductor de Halcones.')}` : '#';
    if (call) call.href = phone ? `tel:+${phone}` : '#';
  }

  function updatePdf() {
    const cash = finanzas.efectivo;
    const transfer = finanzas.transferencia;
    const total = cash + transfer;
    document.getElementById('pdf-total').textContent = formatoMoneda(total);
    document.getElementById('pdf-cash').textContent = formatoMoneda(cash);
    document.getElementById('pdf-transfer').textContent = formatoMoneda(transfer);
    document.getElementById('pdf-date').textContent = new Date().toLocaleDateString('es-CO', {
      year: 'numeric', month: 'long', day: 'numeric', timeZone: 'America/Bogota'
    });
  }

  async function registerPayment(method) {
    if (!currentRide?.databaseId) return showToast('No hay un servicio activo para cobrar.');
    try {
      const payment = await api.request(`/services/${currentRide.databaseId}/payments`, {
        method: 'POST',
        headers: { 'Idempotency-Key': paymentKey || (paymentKey = crypto.randomUUID()) },
        body: { method: method === 'Efectivo' ? 'CASH' : 'TRANSFER' }
      });
      buildHistoryItem(currentRide, method);
      paymentKey = null;
      await refreshFinancials();
      await refreshHistory();
      showToast(`Pago registrado: ${formatoMoneda(Number(payment.amount))}`);
      const completedRide = currentRide;
      currentRide = null;
      driverStatus = 'DISPONIBLE';
      switchSection('turno');
      switchState(0);
      setTimeout(loadOffers, 3000);
    } catch (error) {
      showToast(error.message);
    }
  }

  async function acceptService() {
    if (driverStatus !== 'DISPONIBLE' || !currentRide?.databaseId) return;
    try {
      clearInterval(timerInterval);
      await api.request(`/services/${currentRide.databaseId}/actions/accept`, { method: 'POST', body: {} });
      await api.request(`/services/${currentRide.databaseId}/actions/en-route`, { method: 'POST', body: {} });
      driverStatus = 'OCUPADO';
      renderStatus('OCUPADO', true);
      tripPhase = 0;
      document.getElementById('ui-active-title').textContent = 'EN CAMINO';
      document.getElementById('ui-active-id').textContent = `#${currentRide.id.slice(0, 8)}`;
      document.getElementById('ui-active-origin').textContent = currentRide.origin;
      document.getElementById('ui-active-dest').textContent = currentRide.dest;
      updateActiveContact(currentRide);
      switchState(3);
      showToast('Carrera aceptada. Navega al punto de recogida.');
    } catch (error) {
      showToast(error.message);
      currentRide = null;
      driverStatus = 'DISPONIBLE';
      switchState(0);
      setTimeout(loadOffers, 500);
    }
  }

  async function advanceTripFlow() {
    if (!currentRide?.databaseId) return;
    const button = document.getElementById('btn-action-trip');
    button.disabled = true;
    try {
      if (tripPhase === 0) {
        await api.request(`/services/${currentRide.databaseId}/actions/arrive`, { method: 'POST', body: {} });
        await api.request(`/services/${currentRide.databaseId}/actions/start`, { method: 'POST', body: {} });
        tripPhase = 1;
        document.getElementById('ui-active-title').textContent = 'EN VIAJE';
        button.innerHTML = '<span class="material-symbols-outlined text-[26px]">stop_circle</span> FINALIZAR SERVICIO';
        showToast('Viaje iniciado.');
      } else {
        await api.request(`/services/${currentRide.databaseId}/actions/complete`, {
          method: 'POST', body: { final_fare: Number(currentRide.price.replace(/\./g, '')) }
        });
        switchState(4);
        showToast('Destino alcanzado. Registra el pago.');
      }
    } catch (error) {
      showToast(error.message);
    } finally {
      button.disabled = false;
    }
  }

  async function cancelCurrentService() {
    closeCustomModal('modal-cancelar');
    if (!currentRide?.databaseId) {
      showToast('No hay un servicio activo para cancelar.');
      return;
    }
    try {
      await api.request(`/services/${currentRide.databaseId}/actions/cancel`, {
        method: 'POST',
        body: { reason: 'Cancelado por el conductor' }
      });
      anadirCanceladoAlHistorial(currentRide);
      currentRide = null;
      driverStatus = 'DISPONIBLE';
      renderStatus('DISPONIBLE', true);
      switchState(0);
      showToast('Servicio cancelado y central notificada.');
      setTimeout(loadOffers, 500);
    } catch (error) {
      showToast(error.message);
    }
  }

  async function confirmFueraServicio() {
    const select = document.getElementById('motivo-select');
    const reason = select.options[select.selectedIndex].text;
    closeCustomModal('modal-fuera-servicio');
    try {
      await persistStatus('FUERA_SERVICIO', reason);
      showToast(`Reportado: ${reason}`);
    } catch (error) {
      showToast(error.message);
    }
  }

  async function setDriverStatus(status) {
    try {
      await persistStatus(status, null);
      if (status === 'DISPONIBLE') showToast('Disponible para servicios.');
    } catch (error) {
      showToast(error.message);
    }
  }

  function installOverrides() {
    renderStatus = window.setStatus;
    window.setStatus = setDriverStatus;
    window.confirmFueraServicio = confirmFueraServicio;
    window.ejecutarCancelacion = cancelCurrentService;
    window.acceptService = acceptService;
    window.advanceTripFlow = advanceTripFlow;
    window.registerPayment = registerPayment;
    window.anadirAlHistorial = (ride, method) => buildHistoryItem(ride, method);
    window.resetFlow = () => {
      currentRide = null;
      switchState(0);
      loadOffers();
    };
    window.simulateNewRandomRide = loadOffers;
    window.startTimer = function () {
      clearInterval(timerInterval);
      timeLeft = 15;
      document.getElementById('timer-val-1').textContent = `${timeLeft}s`;
      document.getElementById('timer-bar-1').style.width = '100%';
      timerInterval = setInterval(() => {
        timeLeft--;
        document.getElementById('timer-val-1').textContent = `${timeLeft}s`;
        document.getElementById('timer-bar-1').style.width = `${Math.max(0, timeLeft / 15 * 100)}%`;
        if (timeLeft <= 0) expireOffer();
      }, 1000);
    };
    window.downloadPDF = async function () {
      try {
        await refreshFinancials();
        updatePdf();
        const PDFLib = window.jspdf?.jsPDF || window.jsPDF;
        if (!PDFLib) throw new Error('No se pudo cargar el generador de reportes.');
        const doc = new PDFLib({ unit: 'pt', format: 'a4', orientation: 'portrait' });
        const total = finanzas.efectivo + finanzas.transferencia;
        doc.setFont('helvetica', 'bold');
        doc.setFontSize(22);
        doc.text('HALCONES · BALANCE DIARIO', 42, 52);
        doc.setFont('helvetica', 'normal');
        doc.setFontSize(11);
        doc.text(`Conductor: ${driverProfile.name} · Móvil ${driverProfile.driver_code}`, 42, 86);
        doc.text(`Fecha: ${new Date().toLocaleDateString('es-CO', { timeZone: 'America/Bogota' })}`, 42, 104);
        doc.text(`Efectivo: ${formatoMoneda(finanzas.efectivo)}`, 42, 150);
        doc.text(`Transferencias: ${formatoMoneda(finanzas.transferencia)}`, 42, 174);
        doc.setFont('helvetica', 'bold');
        doc.text(`TOTAL: ${formatoMoneda(total)}`, 42, 212);
        doc.save(`Halcones-Balance-${new Date().toISOString().slice(0, 10)}.pdf`);
        showToast('Reporte descargado.');
      } catch (error) {
        showToast(error.message);
      }
    };
  }

  async function initialize() {
    try {
      currentRide = null;
      document.getElementById('historial-list').replaceChildren();
      const user = await api.request('/auth/me');
      if (user.role !== 'DRIVER') throw new Error('Esta sesión no corresponde a un conductor.');
      driverProfile = await api.request('/drivers/me');
      document.getElementById('driver-code').textContent = `MÓVIL ${driverProfile.driver_code}`;
      document.title = `Móvil ${driverProfile.driver_code} | Halcones PWA`;
      const stateMap = { AVAILABLE: 'DISPONIBLE', BUSY: 'OCUPADO', OUT_OF_SERVICE: 'FUERA_SERVICIO' };
      driverStatus = stateMap[driverProfile.status] || 'FUERA_SERVICIO';
      renderStatus(driverStatus, true);
      await refreshFinancials();
      await refreshHistory();
      await startLocationTracking();
      if (driverStatus === 'BUSY') {
        const services = await api.request('/services?status=OFFERED');
        const active = services[0] || (await api.request('/services?status=ACCEPTED'))[0]
          || (await api.request('/services?status=EN_ROUTE'))[0]
          || (await api.request('/services?status=ARRIVED'))[0]
          || (await api.request('/services?status=IN_PROGRESS'))[0];
        if (active) {
          currentRide = normalizedRide(active);
          document.getElementById('ui-active-id').textContent = `#${currentRide.id.slice(0, 8)}`;
          document.getElementById('ui-active-origin').textContent = currentRide.origin;
          document.getElementById('ui-active-dest').textContent = currentRide.dest;
          updateActiveContact(currentRide);
          tripPhase = active.status === 'IN_PROGRESS' ? 1 : 0;
          if (tripPhase === 1) {
            document.getElementById('btn-action-trip').innerHTML = '<span class="material-symbols-outlined text-[26px]">stop_circle</span> FINALIZAR SERVICIO';
          }
          switchState(3);
        }
      } else if (driverStatus === 'AVAILABLE') {
        await loadOffers();
      }
      if (!offerPoll) offerPoll = setInterval(loadOffers, 10000);
      api.subscribe(`private-driver.${user.id}`, {
        'service.offered': loadOffers,
        'service.updated': loadOffers
      }).catch(() => {});
    } catch (error) {
      driverStatus = 'FUERA_SERVICIO';
      renderStatus('FUERA_SERVICIO', true);
      showToast(error.message);
    }
  }

  window.onload = () => {
    installOverrides();
    actualizarBalance();
    document.getElementById('input-new-goal').value = finanzas.meta.toLocaleString('es-CO');
    initialize();
  };
})();
