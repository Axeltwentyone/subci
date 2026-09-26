import { useSyncExternalStore } from 'react'

/** Choix d'apparence, gardé sur l'appareil : « auto » suit le réglage du téléphone. */
export type ThemePref = 'auto' | 'light' | 'dark'

const KEY = 'subci:theme'
const media = typeof window !== 'undefined' ? window.matchMedia('(prefers-color-scheme: dark)') : null
const listeners = new Set<() => void>()

function readPref(): ThemePref {
  try {
    const v = localStorage.getItem(KEY)
    return v === 'light' || v === 'dark' ? v : 'auto'
  } catch {
    return 'auto'
  }
}

let pref = readPref()

function isDark() {
  return pref === 'dark' || (pref === 'auto' && !!media?.matches)
}

function apply() {
  const html = document.documentElement
  if (isDark()) html.dataset.theme = 'dark'
  else delete html.dataset.theme
  listeners.forEach((l) => l())
}

if (typeof window !== 'undefined') {
  apply()
  media?.addEventListener('change', apply)
}

export function setThemePref(next: ThemePref) {
  pref = next
  try {
    if (next === 'auto') localStorage.removeItem(KEY)
    else localStorage.setItem(KEY, next)
  } catch {
    /* stockage indisponible : le choix vaut pour la session */
  }
  apply()
}

function subscribe(cb: () => void) {
  listeners.add(cb)
  return () => listeners.delete(cb)
}

export function useThemePref(): ThemePref {
  return useSyncExternalStore(subscribe, () => pref, () => 'auto')
}

export function useDark(): boolean {
  return useSyncExternalStore(subscribe, isDark, () => false)
}
