(() => {
  const serviceId = new URLSearchParams(window.location.search).get('id');
  const setText = (selector, value) => {
    const element = document.querySelector(selector);
    if (element) element.textContent = value;
  };
  const currency = value => new Intl.NumberFormat('es-CO', {
    style: 'currency', currency: 'COP', maximumFractionDigits: 0
  }).format(Number(value || 0));
  const showError = message => {
    setText('#detail-status', message);
    document.getElementById('detail-status')?.classList.add('text-danger');
  };

  if (!/^\d+$/.test(serviceId || '')) {
    showError('No se indicó un servicio.');
    return;
  }

  window.HalconesApi.request(`/services/${serviceId}`).then(service => {
    const publicId = service.public_id || serviceId;
    const statusLabels = {
      REQUESTED: 'Solicitado', PENDING_ASSIGNMENT: 'Buscando conductor', OFFERED: 'Ofrecido',
      ACCEPTED: 'Aceptado', EN_ROUTE: 'En camino', ARRIVED: 'En punto de recogida',
      IN_PROGRESS: 'En viaje', COMPLETED: 'Completado', CANCELLED: 'Cancelado',
      EXPIRED: 'Expirado', REJECTED: 'Rechazado'
    };
    const fare = service.payment_amount || service.final_fare || service.estimated_fare;
    setText('#detail-service-id', `Servicio #${publicId.slice(0, 8)}`);
    setText('#detail-status', statusLabels[service.status] || service.status);
    setText('#detail-fare', currency(fare));
    setText('#detail-net', currency(fare));
    setText('#detail-origin', service.origin_address || 'Sin origen');
    setText('#detail-destination', service.destination_address || 'Sin destino');
    setText('#detail-customer', service.customer_name || 'Cliente');
    setText('#detail-payment-method', service.payment_method === 'TRANSFER' ? 'Transferencia' : service.payment_method === 'CASH' ? 'Efectivo' : 'Pendiente');

    const startedAt = service.started_at ? new Date(`${service.started_at.replace(' ', 'T')}Z`) : null;
    const completedAt = service.completed_at ? new Date(`${service.completed_at.replace(' ', 'T')}Z`) : null;
    if (startedAt && completedAt && Number.isFinite(startedAt.getTime()) && Number.isFinite(completedAt.getTime())) {
      const minutes = Math.max(0, Math.round((completedAt - startedAt) / 60000));
      setText('#detail-duration', `${minutes} min`);
    } else {
      setText('#detail-duration', 'No disponible');
    }

    const initials = String(service.customer_name || 'H')
      .split(/\s+/).slice(0, 2).map(part => part.charAt(0)).join('').toUpperCase();
    const avatar = document.querySelector('#detail-customer')?.parentElement?.nextElementSibling;
    if (avatar) avatar.textContent = initials;

    const mapLink = document.getElementById('detail-map');
    if (mapLink) {
      const destination = service.destination_latitude && service.destination_longitude
        ? `${service.destination_latitude},${service.destination_longitude}`
        : service.destination_address;
      mapLink.href = `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(destination)}`;
    }
  }).catch(error => showError(error.message));
})();
