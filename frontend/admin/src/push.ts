import { api } from './api'

/*
 * Alertes push de l'admin (Web Push + VAPID), même principe que la PWA membre.
 * Le service worker n'existe qu'en build (npm run admin:build / preview / prod).
 */

export const pushSupported = () => 'serviceWorker' in navigator && 'PushManager' in window && typeof Notification !== 'undefined'

/** iPhone / iPad : le push n'existe que dans l'app ajoutée à l'écran d'accueil. */
export const needsInstall = () =>
  /iPhone|iPad|iPod/.test(navigator.userAgent) && !(window.matchMedia('(display-mode: standalone)').matches || (navigator as { standalone?: boolean }).standalone)

function key(base64: string): Uint8Array<ArrayBuffer> {
  const raw = atob((base64 + '='.repeat((4 - (base64.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/'))
  const out = new Uint8Array(new ArrayBuffer(raw.length))
  for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i)
  return out
}

async function registration() {
  if (!pushSupported()) return null
  const reg = await navigator.serviceWorker.getRegistration()
  return reg ? navigator.serviceWorker.ready : null
}

export async function currentSubscription() {
  const reg = await registration().catch(() => null)
  return (await reg?.pushManager.getSubscription()) ?? null
}

export type EnableResult = 'ok' | 'denied' | 'no-sw' | 'no-key' | 'unsupported'

export async function enablePush(publicKey: string | null): Promise<EnableResult> {
  if (!pushSupported()) return 'unsupported'
  if (!publicKey) return 'no-key'
  const reg = await registration()
  if (!reg) return 'no-sw'
  const permission = Notification.permission === 'default' ? await Notification.requestPermission() : Notification.permission
  if (permission !== 'granted') return 'denied'

  const sub = (await reg.pushManager.getSubscription()) ?? (await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key(publicKey) }))
  const json = sub.toJSON()
  const encodings = (PushManager as unknown as { supportedContentEncodings?: string[] }).supportedContentEncodings
  await api.savePush({
    endpoint: sub.endpoint,
    keys: { p256dh: json.keys?.p256dh ?? '', auth: json.keys?.auth ?? '' },
    contentEncoding: encodings?.includes('aes128gcm') === false ? 'aesgcm' : 'aes128gcm',
  })
  return 'ok'
}

export async function disablePush(): Promise<void> {
  const sub = await currentSubscription()
  if (!sub) return
  await api.deletePush(sub.endpoint).catch(() => {})
  await sub.unsubscribe().catch(() => {})
}
