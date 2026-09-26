import { useCallback, useEffect, useState } from 'react'
import { api } from './api'
import { isIOS, isStandalone } from './hooks'

/*
 * Notifications push (Web Push + VAPID).
 * Le service worker n'existe qu'en build (npm run build / preview / prod) :
 * en `npm run dev`, tout est silencieusement ignoré.
 */

export function pushSupported(): boolean {
  return 'serviceWorker' in navigator && 'PushManager' in window && typeof Notification !== 'undefined'
}

function urlBase64ToUint8Array(base64: string): Uint8Array<ArrayBuffer> {
  const padding = '='.repeat((4 - (base64.length % 4)) % 4)
  const raw = atob((base64 + padding).replace(/-/g, '+').replace(/_/g, '/'))
  const out = new Uint8Array(new ArrayBuffer(raw.length))
  for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i)
  return out
}

async function registration(): Promise<ServiceWorkerRegistration | null> {
  if (!pushSupported()) return null
  const reg = await navigator.serviceWorker.getRegistration()
  if (!reg) return null
  return navigator.serviceWorker.ready
}

/** Abonne cet appareil et l'enregistre côté API. À appeler une fois la permission accordée. */
export async function subscribePush(): Promise<boolean> {
  const reg = await registration()
  if (!reg || Notification.permission !== 'granted') return false

  let sub = await reg.pushManager.getSubscription()
  if (!sub) {
    const { publicKey } = await api.pushKey()
    if (!publicKey) return false
    sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlBase64ToUint8Array(publicKey) })
  }

  const json = sub.toJSON()
  const encodings = (PushManager as unknown as { supportedContentEncodings?: string[] }).supportedContentEncodings
  await api.savePush({
    endpoint: sub.endpoint,
    keys: { p256dh: json.keys?.p256dh ?? '', auth: json.keys?.auth ?? '' },
    contentEncoding: encodings?.includes('aes128gcm') === false ? 'aesgcm' : 'aes128gcm',
  })
  return true
}

/** Demande la permission (pré-prompt déjà affiché) puis abonne l'appareil. */
export async function enablePush(): Promise<NotificationPermission> {
  if (typeof Notification === 'undefined') return 'denied'
  const permission = Notification.permission === 'default' ? await Notification.requestPermission() : Notification.permission
  if (permission === 'granted') await subscribePush().catch(() => false)
  return permission
}

/** Déconnexion : l'appareil ne doit plus recevoir les notifications de ce compte. */
export async function unsubscribePush(): Promise<void> {
  const reg = await registration().catch(() => null)
  const sub = await reg?.pushManager.getSubscription()
  if (!sub) return
  await api.deletePush(sub.endpoint).catch(() => {})
  await sub.unsubscribe().catch(() => {})
}

/**
 * Où en sont les notifications sur cet appareil :
 * on (abonné) · off (jamais demandé) · denied (refusé dans le navigateur / le téléphone)
 * · install (iPhone : il faut d'abord installer l'app) · unsupported.
 */
export type PushState = 'on' | 'off' | 'denied' | 'install' | 'unsupported'

export async function pushState(): Promise<PushState> {
  if (isIOS() && !isStandalone()) return 'install'
  if (!pushSupported()) return 'unsupported'
  if (Notification.permission === 'denied') return 'denied'
  if (Notification.permission === 'default') return 'off'
  // Autorisé : on (ré)enregistre l'abonnement pour être sûr que le serveur le connaît.
  return (await subscribePush().catch(() => false)) ? 'on' : 'off'
}

export function usePushState(): [PushState | null, () => void] {
  const [state, setState] = useState<PushState | null>(null)
  const refresh = useCallback(() => {
    pushState().then(setState).catch(() => setState('unsupported'))
  }, [])
  useEffect(() => {
    refresh()
    const onVisible = () => document.visibilityState === 'visible' && refresh()
    document.addEventListener('visibilitychange', onVisible)
    return () => document.removeEventListener('visibilitychange', onVisible)
  }, [refresh])
  return [state, refresh]
}
