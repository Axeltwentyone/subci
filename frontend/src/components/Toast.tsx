import { createContext, useCallback, useContext, useEffect, useRef, useState, type ReactNode } from 'react'
import { cx } from './ui'

type Tone = 'ink' | 'error' | 'update' | 'warn' | 'success'
type ToastInput = { text: string; tone?: Tone; action?: { label: string; onClick: () => void }; duration?: number }
type ToastItem = ToastInput & { id: number }

const Ctx = createContext<(t: ToastInput) => void>(() => {})

export function useToast() {
  return useContext(Ctx)
}

/** Toasts en haut, 3 s, un à la fois, swipe haut pour fermer. */
export function ToastProvider({ children }: { children: ReactNode }) {
  const [toast, setToast] = useState<ToastItem | null>(null)
  const [dy, setDy] = useState(0)
  const start = useRef<number | null>(null)
  const timer = useRef<ReturnType<typeof setTimeout>>(undefined)

  const show = useCallback((t: ToastInput) => {
    setDy(0)
    setToast({ ...t, id: Date.now() })
  }, [])

  useEffect(() => {
    if (!toast) return
    clearTimeout(timer.current)
    const d = toast.duration ?? 3000
    if (d > 0) timer.current = setTimeout(() => setToast(null), d)
    return () => clearTimeout(timer.current)
  }, [toast])

  const tone = toast?.tone ?? 'ink'
  const styles: Record<Tone, string> = {
    ink: 'scheme-card bg-ink text-sand',
    update: 'scheme-card bg-ink text-sand shadow-[0_12px_30px_-10px_rgba(22,19,15,.5)]',
    error: 'bg-err-soft text-err-ink font-bold',
    warn: 'bg-surface border-[1.5px] border-err/30',
    success: 'bg-surface',
  }
  const icons: Record<Tone, ReactNode> = {
    ink: <span className="text-ok-glow">✓</span>,
    update: <span className="grid size-8 place-items-center rounded-[10px] bg-brand font-extrabold text-on-accent">↑</span>,
    error: <span>!</span>,
    warn: <span className="grid size-8 place-items-center rounded-[10px] bg-err-soft font-extrabold text-err">!</span>,
    success: <span className="grid size-8 place-items-center rounded-[10px] bg-ok-soft font-extrabold text-ok-ink">✓</span>,
  }

  return (
    <Ctx.Provider value={show}>
      {children}
      <div className="pointer-events-none fixed inset-x-0 top-0 z-[60] flex justify-center px-4 pt-[calc(env(safe-area-inset-top)+10px)]" aria-live="polite">
        {toast && (
          <div
            key={toast.id}
            role="status"
            className={cx(
              'pointer-events-auto flex w-full max-w-[420px] touch-none items-center gap-3 rounded-btn px-3.5 py-3 text-sm leading-[1.35] font-semibold shadow-[0_10px_30px_-12px_rgba(22,19,15,.35)] animate-toast-in',
              styles[tone],
            )}
            style={{ transform: dy ? `translateY(${dy}px)` : undefined, opacity: dy ? 1 + dy / 80 : undefined }}
            onPointerDown={(e) => {
              start.current = e.clientY
              ;(e.currentTarget as HTMLElement).setPointerCapture(e.pointerId)
            }}
            onPointerMove={(e) => start.current !== null && setDy(Math.min(0, e.clientY - start.current))}
            onPointerUp={() => {
              start.current = null
              if (dy < -24) setToast(null)
              else setDy(0)
            }}
          >
            {icons[tone]}
            <span className="flex-1">{toast.text}</span>
            {toast.action && (
              <button
                type="button"
                className="px-1 py-2 font-extrabold text-brand"
                onPointerDown={(e) => e.stopPropagation()}
                onClick={() => {
                  toast.action?.onClick()
                  setToast(null)
                }}
              >
                {toast.action.label}
              </button>
            )}
          </div>
        )}
      </div>
    </Ctx.Provider>
  )
}
