const CACHE_NAME = 'alerta-wifi-v3-1-0-2';
const DEVICE_CONFIG_CACHE = 'alerta-wifi-device-config-v2';
const DEVICE_CONFIG_KEY = new URL('__alerta_wifi_device_config__', self.registration.scope).href;
const scopedUrl = (path = '') => new URL(path, self.registration.scope).href;
const clientLogUrl = () => scopedUrl('api/client_log.php');

async function swLog(event, context = {}) {
  try {
    const config = await readDeviceConfig().catch(() => null);
    await fetch(clientLogUrl(), { method: 'POST', headers: { 'Content-Type': 'application/json' }, cache: 'no-store', credentials: 'omit', body: JSON.stringify({ event, event_id: config?.eventId, ...context }) });
  } catch (_) {}
}

self.addEventListener('install', (event) => event.waitUntil((async () => { await swLog('service_worker_install', { version: CACHE_NAME, scope: self.registration.scope }); await self.skipWaiting(); })()));
self.addEventListener('activate', (event) => event.waitUntil((async () => { await self.clients.claim(); await swLog('service_worker_activate', { version: CACHE_NAME, scope: self.registration.scope }); })()));
self.addEventListener('pushsubscriptionchange', (event) => event.waitUntil(swLog('subscription_changed')));

self.addEventListener('message', (event) => {
  if (event.data?.type !== 'ALERTA_WIFI_CONFIG') return;
  event.waitUntil((async () => {
    try {
      const cache = await caches.open(DEVICE_CONFIG_CACHE);
      await cache.put(DEVICE_CONFIG_KEY, new Response(JSON.stringify({ eventId: event.data.eventId, participantToken: event.data.participantToken, pullUrl: event.data.pullUrl }), { headers: { 'Content-Type': 'application/json' } }));
      event.ports?.[0]?.postMessage({ success: true });
    } catch (error) { event.ports?.[0]?.postMessage({ success: false, error: error?.message || 'Falha ao salvar configuração.' }); }
  })());
});

async function readDeviceConfig() { const cache = await caches.open(DEVICE_CONFIG_CACHE); const response = await cache.match(DEVICE_CONFIG_KEY); return response ? response.json() : null; }

async function pullPayloads() {
  const config = await readDeviceConfig();
  if (!config?.pullUrl || !config?.participantToken || !config?.eventId) return [];
  const subscription = await self.registration.pushManager.getSubscription();
  if (!subscription) return [];
  await swLog('payload_fetch_started');
  const response = await fetch(config.pullUrl, { method: 'POST', headers: { 'Content-Type': 'application/json' }, cache: 'no-store', credentials: 'omit', body: JSON.stringify({ event_id: config.eventId, participant_token: config.participantToken, endpoint: subscription.endpoint }) });
  if (!response.ok) { await swLog('payload_fetch_failed', { http_code: response.status }); return []; }
  const data = await response.json();
  await swLog('payload_fetch_success', { count: Array.isArray(data?.payloads) ? data.payloads.length : 0 });
  return Array.isArray(data?.payloads) ? data.payloads : [];
}

async function resolvePushData(event) {
  if (event.data) { try { return [event.data.json()]; } catch (_) { return [{ title: 'Novo aviso', body: event.data.text() }]; } }
  return await pullPayloads();
}

async function showPushNotification(data) {
  await swLog('notification_show_started', { correlation_id: data.correlation_id, message_id: data.message_id });
  const options = { body: data.body || 'Você recebeu uma nova mensagem.', icon: data.icon || scopedUrl('assets/icons/icon-192.png'), badge: data.badge || scopedUrl('assets/icons/badge-96.png'), image: data.image || undefined, tag: data.tag || undefined, renotify: Boolean(data.tag), data: { url: data.url || scopedUrl(''), message_id: data.message_id || null, correlation_id: data.correlation_id || null }, timestamp: Date.now() };
  await self.registration.showNotification(data.title || 'Novo aviso', options);
  await swLog('notification_show_success', { correlation_id: data.correlation_id, message_id: data.message_id });
}

async function handlePush(event) {
  await swLog('push_received');
  let payloads = [];
  try { payloads = await resolvePushData(event); } catch (error) { await swLog('payload_fetch_failed', { error: error?.message }); }
  if (!payloads.length) payloads = [{ title: 'Novo aviso', body: 'Há uma nova mensagem disponível. Abra a página do evento para conferir.', url: scopedUrl('') }];
  for (const payload of payloads) { try { await showPushNotification(payload); } catch (error) { await swLog('notification_show_failed', { correlation_id: payload.correlation_id, message_id: payload.message_id, error: error?.message }); } }
}
self.addEventListener('push', (event) => event.waitUntil(handlePush(event)));
self.addEventListener('notificationclick', (event) => {
  event.notification.close(); const target = event.notification.data?.url || scopedUrl('');
  event.waitUntil((async () => { await swLog('notification_clicked', { correlation_id: event.notification.data?.correlation_id, message_id: event.notification.data?.message_id }); const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true }); for (const client of windows) { if ('focus' in client && client.url === target) { await swLog('client_opened', { mode: 'focus' }); return client.focus(); } } await swLog('client_opened', { mode: 'openWindow' }); return self.clients.openWindow(target); })());
});
