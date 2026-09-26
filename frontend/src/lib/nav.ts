import { useCallback } from 'react'
import { useNavigate } from 'react-router'

/*
 * Direction des transitions « vraie app ».
 * React Router rejoue la View Transition d'un push quand on revient en arrière :
 * on marque seulement le sens (html[data-nav=back]) pour que le CSS inverse le
 * mouvement — y compris pour le bouton retour du téléphone (popstate).
 */
let timer: ReturnType<typeof setTimeout> | undefined
/** Retour déclenché par notre bouton « ‹ » (animation de l'app) plutôt que par le geste / bouton du téléphone. */
let inAppBack = false
/** Prochaine transition à sauter : le téléphone anime déjà son propre geste de retour. */
let skipTransition = false

function markBack() {
  const root = document.documentElement
  root.dataset.nav = 'back'
  clearTimeout(timer)
  timer = setTimeout(() => delete root.dataset.nav, 600)
}

const idx = () => (window.history.state?.idx as number | undefined) ?? 0

if (typeof window !== 'undefined') {
  let last = idx()
  window.addEventListener('popstate', () => {
    // « Suivant » garde le sens push ; seul un vrai retour inverse le mouvement.
    if (idx() < last) markBack()
    last = idx()
    // Geste de retour de l'iPhone (app installée) ou bouton retour Android : le système anime déjà.
    // Rejouer notre transition par-dessus donnait des images doublées et un onglet décalé.
    if (!inAppBack) skipTransition = true
    inAppBack = false
  })

  // React Router rejoue la View Transition au retour : on la saute quand le téléphone anime lui-même.
  const native = document.startViewTransition?.bind(document)
  if (native) {
    document.startViewTransition = ((arg?: ViewTransitionUpdateCallback | StartViewTransitionOptions) => {
      if (!skipTransition) return native(arg as ViewTransitionUpdateCallback)
      skipTransition = false
      const update = typeof arg === 'function' ? arg : arg?.update
      const done = Promise.resolve(update?.()).then(() => undefined)
      return { finished: done, ready: done, updateCallbackDone: done, skipTransition() {}, types: new Set<string>() } as unknown as ViewTransition
    }) as typeof document.startViewTransition
  }
  // Suit aussi les pushes/replace faits par le routeur.
  for (const m of ['pushState', 'replaceState'] as const) {
    const orig = window.history[m].bind(window.history)
    window.history[m] = (...args: Parameters<History['pushState']>) => {
      orig(...args)
      last = idx()
    }
  }
}

export function useBack() {
  const navigate = useNavigate()
  return useCallback(
    (fallback = '/home') => {
      markBack()
      if (idx() > 0) {
        inAppBack = true
        navigate(-1)
      }
      else navigate(fallback, { replace: true, viewTransition: true })
    },
    [navigate],
  )
}
