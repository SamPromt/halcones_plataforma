(() => {
  const links = [...document.querySelectorAll('a[href^="https://wa.me/"]')];
  if (!links.length || !window.HalconesApi) return;

  window.HalconesApi.publicConfig().then(config => {
    const phone = String(config.whatsapp_phone || '').replace(/\D/g, '');
    if (phone.length < 10 || phone === '573000000000') return;
    links.forEach(link => {
      const url = new URL(link.href);
      const prefilledMessage = url.searchParams.get('text');
      url.pathname = `/${phone}`;
      url.search = '';
      if (prefilledMessage) url.searchParams.set('text', prefilledMessage);
      link.href = url.toString();
    });
  }).catch(error => console.error('No se pudo cargar la configuración de contacto:', error.message));
})();
