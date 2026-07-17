const CACHE_NAME = 'alerta-wifi-v2';
const DEVICE_CONFIG_CACHE = 'alerta-wifi-device-config-v1';
const DEVICE_CONFIG_KEY = new URL('__alerta_wifi_device_config__', self.registration.scope).href;
const scopedUrl = (path = '') => new URL(path, self.registration.scope).href;

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

self.addEventListener('message', (event) => {
  if (event.data?.type !== 'ALERTA_WIFI_CONFIG') return;
  event.waitUntil((async () => {
    try {
      const cache = await caches.open(DEVICE_CONFIG_CACHE);
      await cache.put(DEVICE_CONFIG_KEY, new Response(JSON.stringify({
        eventId: event.data.eventId,
        participantToken: event.data.participantToken,
        pullUrl: event.data.pullUrl
      }), { headers: { 'Content-Type': 'application/json' } }));
      event.ports?.[0]?.postMessage({ success: true });
    } catch (error) {
      event.ports?.[0]?.postMessage({ success: false, error: error?.message || 'Falha ao salvar configuração.' });
    }
  })());
});

async function readDeviceConfig() {
  const cache = await caches.open(DEVICE_CONFIG_CACHE);
  const response = await cache.match(DEVICE_CONFIG_KEY);
  return response ? response.json() : null;
}

async function pullPayloads() {
  const config = await readDeviceConfig();
  if (!config?.pullUrl || !config?.participantToken || !config?.eventId) return [];
  const subscription = await self.registration.pushManager.getSubscription();
  if (!subscription) return [];

  const response = await fetch(config.pullUrl, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    cache: 'no-store',
    credentials: 'omit',
    body: JSON.stringify({
      event_id: config.eventId,
      participant_token: config.participantToken,
      endpoint: subscription.endpoint
    })
  });
  if (!response.ok) return [];
  const data = await response.json();
  return Array.isArray(data?.payloads) ? data.payloads : [];
}

async function resolvePushData(event) {
  if (event.data) {
    try { return [event.data.json()]; }
    catch (_) { return [{ title: 'Novo aviso', body: event.data.text() }]; }
  }
  return await pullPayloads();
}

async function showPushNotification(data) {
  const options = {
    body: data.body || 'Você recebeu uma nova mensagem.',
    icon: data.icon || scopedUrl('assets/icons/icon-192.png'),
    badge: data.badge || scopedUrl('assets/icons/badge-96.png'),
    image: data.image || undefined,
    tag: data.tag || undefined,
    renotify: Boolean(data.tag),
    data: { url: data.url || scopedUrl(''), message_id: data.message_id || null },
    timestamp: Date.now()
  };
  await self.registration.showNotification(data.title || 'Novo aviso', options);
}

async function handlePush(event) {
  let payloads = [];
  try { payloads = await resolvePushData(event); } catch (_) {}
  if (!payloads.length) {
    payloads = [{
      title: 'Novo aviso',
      body: 'Há uma nova mensagem disponível. Abra a página do evento para conferir.',
      url: scopedUrl('')
    }];
  }
  for (const payload of payloads) {
    await showPushNotification(payload);
  }
}

self.addEventListener('push', (event) => {
  event.waitUntil(handlePush(event));
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const target = event.notification.data?.url || scopedUrl('');
  event.waitUntil((async () => {
    const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const client of windows) {
      if ('focus' in client && client.url === target) return client.focus();
    }
    return self.clients.openWindow(target);
  })());
});
