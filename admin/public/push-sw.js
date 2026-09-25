/* Sub.ci Admin — alertes push (paiement reçu, offre à valider, versement à faire). Importé par le SW Workbox. */

self.addEventListener('push', (event) => {
  let data = {}
  try {
    data = event.data ? event.data.json() : {}
  } catch {
    data = { title: 'Sub.ci Admin', body: event.data ? event.data.text() : '' }
  }

  event.waitUntil(
    self.registration.showNotification(data.title || 'Sub.ci Admin', {
      body: data.body || '',
      icon: '/pwa-192.png',
      badge: '/badge-96.png',
      tag: data.tag,
      renotify: Boolean(data.tag),
      lang: 'fr',
      data: { url: data.url || '/' },
    }),
  )
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const url = new URL(event.notification.data?.url || '/', self.location.origin).href

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
      const open = windows.find((w) => new URL(w.url).origin === self.location.origin)
      if (open) return open.focus().then((w) => w.navigate(url))
      return self.clients.openWindow(url)
    }),
  )
})
