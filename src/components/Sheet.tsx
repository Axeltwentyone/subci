import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { useMedia } from '../lib/hooks'
import { cx } from './ui'
import { IconClose } from './icons'

type Props = {
  open: boolean
  onClose: () => void
  children: ReactNode
  label: string
  /** Fond derrière la sheet (voile) */
  className?: string
}

/**
 * Bottom sheet (mobile) — translateY 100 % → 0 en 280 ms.
 * Fermeture si glissée > 30 % ou vitesse > 0,5 px/ms.
 * ≥ 768 px : devient un panneau latéral.
 */
export function Sheet({ open, onClose, children, label, className }: Props) {
  const wide = useMedia('(min-width: 768px)')
  const [mounted, setMounted] = useState(open)
  const [leaving, setLeaving] = useState(false)
  const [dy, setDy] = useState(0)
  const [dragging, setDragging] = useState(false)
  const drag = useRef<{ y: number; t: number; h: number } | null>(null)
  const panel = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (open) {
      setMounted(true)
      setLeaving(false)
      setDy(0)
    } else if (mounted) {
      setLeaving(true)
      const t = setTimeout(() => setMounted(false), 220)
      return () => clearTimeout(t)
    }
  }, [open, mounted])

  const close = useCallback(() => onClose(), [onClose])

  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && close()
    window.addEventListener('keydown', onKey)
    const prev = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    panel.current?.focus()
    return () => {
      window.removeEventListener('keydown', onKey)
      document.body.style.overflow = prev
    }
  }, [open, close])

  if (!mounted) return null

  const onPointerDown = (e: React.PointerEvent) => {
    if (wide) return
    drag.current = { y: e.clientY, t: performance.now(), h: panel.current?.offsetHeight ?? 400 }
    setDragging(true)
    ;(e.target as HTMLElement).setPointerCapture?.(e.pointerId)
  }
  const onPointerMove = (e: React.PointerEvent) => {
    if (!drag.current) return
    setDy(Math.max(0, e.clientY - drag.current.y))
  }
  const onPointerUp = (e: React.PointerEvent) => {
    if (!drag.current) return
    const d = Math.max(0, e.clientY - drag.current.y)
    const v = d / Math.max(1, performance.now() - drag.current.t)
    const h = drag.current.h
    drag.current = null
    setDragging(false)
    if (d > h * 0.3 || v > 0.5) close()
    else setDy(0)
  }

  const transform = leaving ? (wide ? 'translateX(100%)' : 'translateY(100%)') : dy ? `translateY(${dy}px)` : undefined

  return createPortal(
    <div className="fixed inset-0 z-50" role="presentation">
      <div
        className={cx('absolute inset-0 bg-ink/55 transition-opacity duration-200', leaving ? 'opacity-0' : 'animate-fade-in', className)}
        onClick={close}
        aria-hidden
      />
      <div
        ref={panel}
        role="dialog"
        aria-modal="true"
        aria-label={label}
        tabIndex={-1}
        className={cx(
          'absolute bg-white text-ink outline-none',
          wide
            ? 'inset-y-0 right-0 flex w-[420px] max-w-full flex-col overflow-y-auto px-6 pt-6 pb-8 animate-panel-in'
            : 'inset-x-0 bottom-0 max-h-[92dvh] overflow-y-auto rounded-t-sheet px-6 pt-2.5 pb-[calc(env(safe-area-inset-bottom)+28px)] animate-sheet-in',
        )}
        style={{ transform, transition: dragging ? 'none' : 'transform 220ms cubic-bezier(.2,.8,.2,1)' }}
      >
        {wide ? (
          <button type="button" onClick={close} aria-label="Fermer" className="pressable mb-2 grid size-11 place-items-center self-end rounded-full bg-sand">
            <IconClose size={18} />
          </button>
        ) : (
          <div
            className="-mx-6 -mt-2.5 flex cursor-grab touch-none justify-center pt-2.5 pb-4"
            onPointerDown={onPointerDown}
            onPointerMove={onPointerMove}
            onPointerUp={onPointerUp}
            onPointerCancel={onPointerUp}
          >
            <span className="h-[5px] w-10 rounded-[3px] bg-line-strong" />
          </div>
        )}
        {children}
      </div>
    </div>,
    document.body,
  )
}
