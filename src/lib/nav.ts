import { useCallback } from 'react'
import { useNavigate } from 'react-router'

/*
 * Direction des transitions « vraie app ».
 * React Router rejoue la View Transition d'un push quand on revient en arrière :
 * on marque seulement le sens (html[data-nav=back]) pour que le CSS inverse le
 * mouvement — y compris pour le bouton retour du téléphone (popstate).
 */
let timer: ReturnType<typeof setTimeout> | undefined

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
  })
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
      if (idx() > 0) navigate(-1)
      else navigate(fallback, { replace: true, viewTransition: true })
    },
    [navigate],
  )
}
