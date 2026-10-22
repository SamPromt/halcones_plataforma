(function () {
  const apiBase = `${window.location.origin}/api/v1`;
  const appScope = window.location.pathname.includes('3_conductor.html') ? 'driver' : 'operator';
  const accessKey = `halcones.${appScope}.access_token`;
  const refreshKey = `halcones.${appScope}.refresh_token`;
  let credentialsPromise;

  function collectCredentials() {
    return new Promise((resolve, reject) => {
      const dialog = document.createElement('dialog');
      dialog.className = 'w-[min(92vw,24rem)] rounded-2xl border border-slate-700 bg-slate-950 p-0 text-slate-100 shadow-2xl backdrop:bg-black/75';
      dialog.innerHTML = `<form class="space-y-4 p-6" method="dialog"><div class="flex items-center justify-between"><h2 class="font-headline text-lg font-black uppercase tracking-wider text-white">Acceso Halcones</h2><button type="button" data-close class="rounded-lg p-2 text-slate-400 hover:bg-slate-800 hover:text-white" aria-label="Cerrar"><span class="material-symbols-outlined">close</span></button></div><label class="block text-xs font-bold uppercase tracking-wider text-slate-400">Teléfono<input name="phone" type="tel" autocomplete="username" required class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-900 px-4 py-3 text-base text-white outline-none focus:border-red-500"></label><label class="block text-xs font-bold uppercase tracking-wider text-slate-400">Contraseña<input name="password" type="password" autocomplete="current-password" required class="mt-2 w-full rounded-xl border border-slate-700 bg-slate-900 px-4 py-3 text-base text-white outline-none focus:border-red-500"></label><p data-error class="min-h-5 text-sm text-red-300" role="alert"></p><button class="w-full rounded-xl bg-primary-container px-4 py-3 font-headline text-sm font-black uppercase tracking-wider text-white hover:bg-red-700" type="submit">Iniciar sesión</button></form>`;
      document.body.appendChild(dialog);
      const form = dialog.querySelector('form');
      const cleanup = () => { dialog.close(); dialog.remove(); };
      const cancel = () => { cleanup(); reject(new Error('Inicio de sesión cancelado.')); };
      dialog.querySelector('[data-close]').addEventListener('click', cancel);
      dialog.addEventListener('cancel', event => { event.preventDefault(); cancel(); });
      form.addEventListener('submit', async event => {
        event.preventDefault();
        const submit = form.querySelector('button[type="submit"]');
        const error = form.querySelector('[data-error]');
        submit.disabled = true;
        submit.textContent = 'Verificando...';
        error.textContent = '';
        try {
          const response = await fetch(`${apiBase}/auth/login`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              phone: form.elements.phone.value.trim(),
              password: form.elements.password.value
            })
          });
          const result = await response.json();
          if (!response.ok) throw new Error(result.error?.message || 'No se pudo iniciar sesión.');
          localStorage.setItem(accessKey, result.data.access_token);
          localStorage.setItem(refreshKey, result.data.refresh_token);
          cleanup();
          resolve(result.data.access_token);
        } catch (loginError) {
          error.textContent = loginError.message;
          submit.disabled = false;
          submit.textContent = 'Iniciar sesión';
        }
      });
      dialog.showModal();
      form.elements.phone.focus();
    });
  }

  async function login() {
    if (!credentialsPromise) {
      credentialsPromise = collectCredentials().finally(() => { credentialsPromise = null; });
    }
    return credentialsPromise;
  }

  async function refresh() {
    const refreshToken = localStorage.getItem(refreshKey);
    if (!refreshToken) return login();
    const response = await fetch(`${apiBase}/auth/refresh`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ refresh_token: refreshToken })
    });
    const result = await response.json();
    if (!response.ok) {
      localStorage.removeItem(accessKey);
      localStorage.removeItem(refreshKey);
      return login();
    }
    localStorage.setItem(accessKey, result.data.access_token);
    localStorage.setItem(refreshKey, result.data.refresh_token);
    return result.data.access_token;
  }

  async function request(path, options = {}) {
    const headers = new Headers(options.headers || {});
    if (options.body && typeof options.body !== 'string') {
      headers.set('Content-Type', 'application/json');
      options.body = JSON.stringify(options.body);
    }
    let token = localStorage.getItem(accessKey) || await login();
    headers.set('Authorization', `Bearer ${token}`);
    let response = await fetch(`${apiBase}${path}`, { ...options, headers });
    if (response.status === 401) {
      token = await refresh();
      headers.set('Authorization', `Bearer ${token}`);
      response = await fetch(`${apiBase}${path}`, { ...options, headers });
    }
    const result = await response.json().catch(() => ({}));
    if (!response.ok) throw new Error(result.error?.message || `Error HTTP ${response.status}`);
    return result.data;
  }

  async function publicConfig() {
    const response = await fetch(`${apiBase}/public/config`);
    const result = await response.json();
    if (!response.ok) throw new Error(result.error?.message || 'No se pudo cargar la configuración.');
    return result.data;
  }

  async function subscribe(channelName, bindings) {
    const config = await publicConfig();
    if (!config.realtime?.enabled || !config.realtime?.key || !window.Pusher) return null;
    const token = localStorage.getItem(accessKey) || await login();
    const client = new window.Pusher(config.realtime.key, {
      wsHost: config.realtime.host,
      wsPort: Number(config.realtime.port),
      wssPort: Number(config.realtime.port),
      forceTLS: config.realtime.scheme === 'https',
      enabledTransports: ['ws', 'wss'],
      channelAuthorization: {
        customHandler: async ({ socketId, channelName: requestedChannel }, callback) => {
          try {
            const currentToken = localStorage.getItem(accessKey) || token;
            const response = await fetch(`${apiBase}/broadcasting/auth`, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${currentToken}` },
              body: JSON.stringify({ socket_id: socketId, channel_name: requestedChannel })
            });
            const result = await response.json();
            callback(response.ok ? null : new Error(result.error?.message || 'Canal no autorizado.'), result);
          } catch (error) {
            callback(error, null);
          }
        }
      }
    });
    const channel = client.subscribe(channelName);
    Object.entries(bindings).forEach(([event, handler]) => channel.bind(event, handler));
    return client;
  }

  window.HalconesApi = { apiBase, login, request, publicConfig, subscribe };
})();
