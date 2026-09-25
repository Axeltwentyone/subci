import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router'
import { LogoMark, Wordmark } from '../components/ui'
import { INK, useTopColor } from '../lib/hooks'
import { useStore } from '../lib/store'

/* ---------- 01 · Splash ---------- */

/** Fond Ink, icône Sand + point Orange. 1,2 s max : la barre reflète le chargement réel du shell. */
export function Splash() {
  useTopColor(INK)
  const navigate = useNavigate()
  const { state } = useStore()
  const [progress, setProgress] = useState(0.15)

  useEffect(() => {
    const t0 = performance.now()
    let raf = 0
    const fontsReady = document.fonts?.ready ?? Promise.resolve()
    let loaded = false
    fontsReady.then(() => (loaded = true))
    const step = () => {
      const t = performance.now() - t0
      const target = loaded ? Math.min(1, t / 700) : Math.min(0.8, t / 1200)
      setProgress((p) => Math.max(p, target))
      if ((loaded && t >= 700) || t >= 1200) {
        const dest = !state.onboarded ? '/welcome' : !state.user ? '/login' : '/home'
        navigate(dest, { replace: true, viewTransition: true })
        return
      }
      raf = requestAnimationFrame(step)
    }
    raf = requestAnimationFrame(step)
    return () => cancelAnimationFrame(raf)
  }, [navigate, state.onboarded, state.user])

  return (
    <div className="relative grid min-h-dvh place-items-center bg-ink">
      <div className="flex flex-col items-center gap-[22px]">
        <LogoMark size={112} tone="sand" />
        <Wordmark dark className="text-[40px]" />
      </div>
      <div className="absolute inset-x-0 bottom-16 flex flex-col items-center gap-[18px]">
        <div className="h-1 w-[120px] overflow-hidden rounded-sm bg-ink-3">
          <div className="h-full rounded-sm bg-brand transition-[width] duration-150" style={{ width: `${progress * 100}%` }} />
        </div>
        <p className="text-sm font-semibold text-ink-muted">Tes abonnements, à plusieurs.</p>
      </div>
    </div>
  )
}
