/* Sub.ci — notifications push, importé par le service worker Workbox (vite.config.ts). */

self.addEventListener('push', (event) => {
  let data = {}
  try {
    data = event.data ? event.data.json() : {}
  } catch {
    data = { title: 'Sub.ci', body: event.data ? event.data.text() : '' }
  }

  const title = data.title || 'Sub.ci'
  event.waitUntil(
    Promise.all([
      // App ouverte : elle se met à jour tout de suite (demande acceptée, gains versés…).
      self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => windows.forEach((w) => w.postMessage({ type: 'push' }))),
      self.registration.showNotification(title, {
        body: data.body || '',
        icon: '/pwa-192.png',
        badge: '/badge-96.png',
        tag: data.tag,
        renotify: Boolean(data.tag),
        lang: 'fr',
        data: { url: data.url || '/activity' },
      }),
      // Pastille sur l'icône = notifications non lues (Badging API).
      typeof data.unread === 'number' && self.navigator.setAppBadge
        ? self.navigator.setAppBadge(data.unread).catch(() => {})
        : Promise.resolve(),
    ]),
  )
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const url = new URL(event.notification.data?.url || '/activity', self.location.origin).href

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
      // Réutilise la fenêtre de l'app si elle est ouverte.
      const open = windows.find((w) => new URL(w.url).origin === self.location.origin)
      if (open) return open.focus().then((w) => w.navigate(url))
      return self.clients.openWindow(url)
    }),
  )
})
