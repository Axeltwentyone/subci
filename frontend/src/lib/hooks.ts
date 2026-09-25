import { useEffect, useLayoutEffect, useState, useSyncExternalStore } from 'react'

export function useMedia(query: string): boolean {
  return useSyncExternalStore(
    (cb) => {
      const m = window.matchMedia(query)
      m.addEventListener('change', cb)
      return () => m.removeEventListener('change', cb)
    },
    () => window.matchMedia(query).matches,
    () => false,
  )
}

export function useOnline(): boolean {
  return useSyncExternalStore(
    (cb) => {
      window.addEventListener('online', cb)
      window.addEventListener('offline', cb)
      return () => {
        window.removeEventListener('online', cb)
        window.removeEventListener('offline', cb)
      }
    },
    () => navigator.onLine,
    () => true,
  )
}

export function useCountdown(seconds: number, key: unknown = 0) {
  const [left, setLeft] = useState(seconds)
  useEffect(() => {
    setLeft(seconds)
    const t = setInterval(() => setLeft((s) => (s > 0 ? s - 1 : 0)), 1000)
    return () => clearInterval(t)
  }, [seconds, key])
  return left
}

export function mmss(s: number) {
  return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`
}

/* ---------- Installation PWA ---------- */

type BIPEvent = Event & { prompt: () => Promise<void>; userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }> }

let deferred: BIPEvent | null = null
const listeners = new Set<() => void>()
const emit = () => listeners.forEach((l) => l())

if (typeof window !== 'undefined') {
  window.addEventListener('beforeinstallprompt', (e) => {
    e.preventDefault()
    deferred = e as BIPEvent
    emit()
  })
  window.addEventListener('appinstalled', () => {
    deferred = null
    emit()
  })
}

export function isStandalone() {
  return window.matchMedia('(display-mode: standalone)').matches || (navigator as Navigator & { standalone?: boolean }).standalone === true
}

export function isIOS() {
  return /iphone|ipad|ipod/i.test(navigator.userAgent)
}

export function useInstall() {
  const canPrompt = useSyncExternalStore(
    (cb) => {
      listeners.add(cb)
      return () => listeners.delete(cb)
    },
    () => deferred !== null,
    () => false,
  )
  return {
    canPrompt,
    ios: isIOS(),
    installed: isStandalone(),
    async prompt(): Promise<boolean> {
      if (!deferred) return false
      await deferred.prompt()
      const { outcome } = await deferred.userChoice
      deferred = null
      emit()
      return outcome === 'accepted'
    },
  }
}

/* ---------- Couleur du haut de l'écran ---------- */

export const SAND = '#F5F2EC'
export const INK = '#16130F'

/**
 * Couleur derrière l'heure et la batterie (iPhone : fond de la page ; Android : theme-color).
 * Chaque écran donne la couleur de son haut, sinon l'iPhone affiche un dégradé de la couleur de fond.
 */
export function useTopColor(color: string) {
  useLayoutEffect(() => {
    const meta = document.querySelector('meta[name="theme-color"]')
    const html = document.documentElement
    const before = { meta: meta?.getAttribute('content'), html: html.style.backgroundColor, body: document.body.style.backgroundColor }
    meta?.setAttribute('content', color)
    html.style.backgroundColor = color
    document.body.style.backgroundColor = color
    return () => {
      if (before.meta) meta?.setAttribute('content', before.meta)
      html.style.backgroundColor = before.html
      document.body.style.backgroundColor = before.body
    }
  }, [color])
}

/** Couleur de marque mélangée au blanc (ex. 12 %), en hexadécimal utilisable par theme-color. */
export function tintOf(hex: string, amount = 0.12) {
  const n = parseInt(hex.replace('#', ''), 16)
  const mix = (c: number) => Math.round(c * amount + 255 * (1 - amount))
  return '#' + [(n >> 16) & 255, (n >> 8) & 255, n & 255].map((c) => mix(c).toString(16).padStart(2, '0')).join('')
}
