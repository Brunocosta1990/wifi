(() => {
  const cfg = window.ALERTA_WIFI_CONFIG;
  const $ = (selector) => document.querySelector(selector);
  const alertBox = $('#global-alert');
  let participantToken = localStorage.getItem(cfg.storageKey) || '';
  let serviceWorkerRegistration = null;

  function showAlert(message, type = 'error') {
    alertBox.textContent = message;
    alertBox.className = `alert ${type === 'success' ? 'alert-success' : 'alert-error'}`;
  }
  function clearAlert() { alertBox.className = 'alert hidden'; alertBox.textContent = ''; }
  const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

  async function api(url, options = {}) {
    const response = await fetch(url, {
      headers: { 'Content-Type': 'application/json', ...(options.headers || {}) },
      cache: 'no-store',
      ...options
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.success === false) throw new Error(data.error || 'Não foi possível concluir a operação.');
    return data;
  }

  function urlBase64ToUint8Array(value) {
    const padding = '='.repeat((4 - value.length % 4) % 4);
    const base64 = (value + padding).replace(/-/g, '+').replace(/_/g, '/');
    return Uint8Array.from(atob(base64), c => c.charCodeAt(0));
  }
  function isIos() { return /iphone|ipad|ipod/i.test(navigator.userAgent); }
  function isStandalone() { return window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true; }

  function updateSteps(registered) {
    $('#registration-step')?.classList.toggle('hidden', registered);
    $('#notification-step')?.classList.toggle('hidden', !registered);
    if (registered && isIos() && !isStandalone()) $('#ios-guide')?.classList.remove('hidden');
  }

  async function setupServiceWorker() {
    if (!('serviceWorker' in navigator)) throw new Error('Este navegador não suporta Service Worker.');
    await navigator.serviceWorker.register(cfg.serviceWorkerUrl, { scope: cfg.serviceWorkerScope || '/' });
    serviceWorkerRegistration = await navigator.serviceWorker.ready;
    return serviceWorkerRegistration;
  }

  async function configureServiceWorker() {
    if (!participantToken) return;
    await setupServiceWorker();
    const worker = serviceWorkerRegistration.active || serviceWorkerRegistration.waiting || serviceWorkerRegistration.installing;
    if (!worker) throw new Error('O Service Worker ainda não está ativo. Atualize a página e tente novamente.');
    const channel = new MessageChannel();
    const configured = new Promise((resolve, reject) => {
      const timeout = setTimeout(() => reject(new Error('Não foi possível configurar o recebimento em segundo plano.')), 4000);
      channel.port1.onmessage = (event) => {
        clearTimeout(timeout);
        event.data?.success ? resolve(true) : reject(new Error(event.data?.error || 'Falha ao configurar o Service Worker.'));
      };
    });
    worker.postMessage({
      type: 'ALERTA_WIFI_CONFIG',
      eventId: cfg.eventId,
      participantToken,
      pullUrl: cfg.pullUrl
    }, [channel.port2]);
    await configured;
  }

  function currentPermission() {
    return ('Notification' in window) ? Notification.permission : 'default';
  }

  function setPushState(active, permission = currentPermission()) {
    const dot = $('#status-dot'), title = $('#status-title'), text = $('#status-text');
    dot.classList.toggle('on', active);
    $('#enable-push').classList.toggle('hidden', active);
    $('#test-push').classList.toggle('hidden', !active);
    $('#disable-push').classList.toggle('hidden', !active);
    $('#ready-step').classList.toggle('hidden', !active);
    if (active) {
      title.textContent = 'Notificações ativas';
      text.textContent = 'Este aparelho está pronto para receber avisos.';
    } else if (permission === 'denied') {
      title.textContent = 'Permissão bloqueada';
      text.textContent = 'Libere as notificações nas configurações do navegador.';
    } else {
      title.textContent = 'Notificações ainda não ativadas';
      text.textContent = 'Aguardando autorização deste aparelho.';
    }
  }

  async function restoreStatus() {
    if (!participantToken) { updateSteps(false); return; }
    try {
      const status = await api(`${cfg.statusUrl}?event_id=${cfg.eventId}&token=${encodeURIComponent(participantToken)}`);
      updateSteps(true);
      await configureServiceWorker();
      const subscription = await serviceWorkerRegistration.pushManager.getSubscription();
      setPushState(Boolean(subscription && status.active_subscriptions > 0));
    } catch (_) {
      localStorage.removeItem(cfg.storageKey);
      participantToken = '';
      updateSteps(false);
    }
  }

  async function loadHistory() {
    try {
      const data = await api(`${cfg.historyUrl}?event_id=${cfg.eventId}`);
      const list = $('#history-list');
      if (!data.messages?.length) return;
      list.innerHTML = data.messages.map(item => `<article class="history-item"><strong>${escapeHtml(item.title)}</strong><div>${escapeHtml(item.body)}</div><small>${escapeHtml(item.sent_at)}</small></article>`).join('');
    } catch (_) {}
  }
  function escapeHtml(value) { const d = document.createElement('div'); d.textContent = value || ''; return d.innerHTML; }

  $('#registration-form')?.addEventListener('submit', async (event) => {
    event.preventDefault(); clearAlert();
    const form = event.currentTarget, button = form.querySelector('button'); button.disabled = true;
    try {
      const values = Object.fromEntries(new FormData(form).entries());
      const data = await api(cfg.registerUrl, { method: 'POST', body: JSON.stringify({ ...values, event_id: cfg.eventId, terms: Boolean(values.terms), privacy: Boolean(values.privacy) }) });
      participantToken = data.participant_token;
      localStorage.setItem(cfg.storageKey, participantToken);
      updateSteps(true);
      await configureServiceWorker();
      showAlert('Cadastro concluído. Agora autorize as notificações.', 'success');
    } catch (error) { showAlert(error.message); }
    finally { button.disabled = false; }
  });

  $('#enable-push')?.addEventListener('click', async () => {
    clearAlert(); const button = $('#enable-push'); button.disabled = true;
    try {
      if (!window.isSecureContext) throw new Error('As notificações exigem HTTPS.');
      if (!('Notification' in window) || !('PushManager' in window)) throw new Error('Este navegador não oferece suporte a Web Push.');
      if (isIos() && !isStandalone()) throw new Error('No iPhone, adicione esta página à Tela de Início e abra pelo ícone antes de ativar.');
      const permission = await Notification.requestPermission();
      if (permission !== 'granted') throw new Error('A permissão de notificações não foi concedida.');
      await setupServiceWorker();
      let subscription = await serviceWorkerRegistration.pushManager.getSubscription();
      if (!subscription) {
        subscription = await serviceWorkerRegistration.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: urlBase64ToUint8Array(cfg.vapidPublicKey)
        });
      }
      await api(cfg.subscribeUrl, {
        method: 'POST',
        body: JSON.stringify({
          event_id: cfg.eventId,
          participant_token: participantToken,
          subscription: subscription.toJSON(),
          platform: navigator.userAgentData?.platform || navigator.platform || ''
        })
      });
      await configureServiceWorker();
      setPushState(true);
      showAlert('Notificações ativadas neste aparelho.', 'success');
    } catch (error) {
      showAlert(error.message);
      setPushState(false, currentPermission());
    } finally { button.disabled = false; }
  });

  $('#test-push')?.addEventListener('click', async () => {
    clearAlert(); const button = $('#test-push'); button.disabled = true;
    try {
      await configureServiceWorker();
      const result = await api(cfg.testPushUrl, {
        method: 'POST',
        body: JSON.stringify({ event_id: cfg.eventId, participant_token: participantToken })
      });
      showAlert(`Sinal enviado ao serviço Push (HTTP ${result.http_status}). Aguardando confirmação do aparelho...`, 'success');

      let confirmed = false;
      for (let attempt = 0; attempt < 6; attempt++) {
        await delay(1500);
        const status = await api(`${cfg.testStatusUrl}?event_id=${cfg.eventId}&token=${encodeURIComponent(participantToken)}&sent_at=${encodeURIComponent(result.sent_at)}`);
        if (status.received) {
          confirmed = true;
          showAlert('Teste concluído: o aparelho recebeu o sinal e buscou a notificação.', 'success');
          break;
        }
      }
      if (!confirmed) {
        showAlert('O serviço Push aceitou o envio, mas o aparelho ainda não confirmou o recebimento. Verifique economia de bateria, notificações do navegador e conexão do celular.');
      }
    } catch (error) { showAlert(error.message); }
    finally { button.disabled = false; }
  });

  $('#disable-push')?.addEventListener('click', async () => {
    if (!confirm('Desativar as notificações deste aparelho?')) return;
    clearAlert();
    try {
      await setupServiceWorker();
      const subscription = await serviceWorkerRegistration.pushManager.getSubscription();
      if (subscription) {
        await api(cfg.unsubscribeUrl, {
          method: 'POST',
          body: JSON.stringify({ event_id: cfg.eventId, participant_token: participantToken, endpoint: subscription.endpoint })
        });
        await subscription.unsubscribe();
      }
      setPushState(false);
      showAlert('Notificações desativadas neste aparelho.', 'success');
    } catch (error) { showAlert(error.message); }
  });

  restoreStatus();
  loadHistory();
})();
