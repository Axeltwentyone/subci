import { useRef, useState, type ReactNode } from 'react'
import { haptic } from '../lib/format'
import { cx } from './ui'

const THRESHOLD = 72

/** Pull-to-refresh : loader Orange qui tourne proportionnellement à la traction (seuil 72 px). */
export function PullToRefresh({ onRefresh, children }: { onRefresh: () => Promise<unknown> | void; children: ReactNode }) {
  const [pull, setPull] = useState(0)
  const [refreshing, setRefreshing] = useState(false)
  const start = useRef<number | null>(null)

  const onTouchStart = (e: React.TouchEvent) => {
    if (window.scrollY > 0 || refreshing) return
    start.current = e.touches[0].clientY
  }
  const onTouchMove = (e: React.TouchEvent) => {
    if (start.current === null) return
    const d = e.touches[0].clientY - start.current
    if (d <= 0) return setPull(0)
    setPull(Math.min(110, d * 0.6))
  }
  const onTouchEnd = async () => {
    if (start.current === null) return
    start.current = null
    if (pull >= THRESHOLD) {
      setRefreshing(true)
      setPull(56)
      haptic(10)
      await Promise.all([onRefresh(), new Promise((r) => setTimeout(r, 700))])
      setRefreshing(false)
    }
    setPull(0)
  }

  const progress = Math.min(1, pull / THRESHOLD)

  return (
    <div onTouchStart={onTouchStart} onTouchMove={onTouchMove} onTouchEnd={onTouchEnd}>
      <div className="flex justify-center overflow-hidden transition-[height] duration-200 ease-app" style={{ height: pull }} aria-hidden={!refreshing}>
        <span
          className={cx('mt-4 size-7 rounded-full border-[3px] border-line border-t-brand', refreshing && 'animate-spin-fast')}
          style={{ transform: refreshing ? undefined : `rotate(${progress * 360}deg)`, opacity: progress }}
        />
      </div>
      {children}
    </div>
  )
}

/** Swipe gauche : révèle « Archiver ». Au-delà de 50 % de largeur : archive. */
export function SwipeToArchive({ onArchive, children, className }: { onArchive: () => void; children: ReactNode; className?: string }) {
  const [dx, setDx] = useState(0)
  const [gone, setGone] = useState(false)
  const start = useRef<{ x: number; y: number; lock?: 'x' | 'y' } | null>(null)
  const box = useRef<HTMLDivElement>(null)

  const end = () => {
    if (!start.current) return
    start.current = null
    const w = box.current?.offsetWidth ?? 320
    if (-dx > w * 0.5) {
      setGone(true)
      setDx(-w)
      haptic(15)
      setTimeout(onArchive, 180)
    } else if (-dx > 64) setDx(-96)
    else setDx(0)
  }

  return (
    <div ref={box} className={cx('relative overflow-hidden rounded-card transition-opacity duration-200', dx < 0 || gone ? 'bg-err' : 'bg-surface', gone && 'opacity-0', className)}>
      <button
        type="button"
        onClick={() => {
          setGone(true)
          setTimeout(onArchive, 150)
        }}
        className="absolute inset-y-0 right-0 flex w-24 items-center justify-center text-sm font-bold text-white"
        tabIndex={dx < 0 ? 0 : -1}
        aria-hidden={dx === 0}
      >
        Archiver
      </button>
      <div
        className="relative touch-pan-y bg-surface"
        style={{ transform: `translateX(${dx}px)`, transition: start.current ? 'none' : 'transform 200ms cubic-bezier(.2,.8,.2,1)', borderRadius: 20 }}
        onPointerDown={(e) => {
          if (e.pointerType === 'mouse' && e.button !== 0) return
          start.current = { x: e.clientX - dx, y: e.clientY }
        }}
        onPointerMove={(e) => {
          const s = start.current
          if (!s) return
          const mx = e.clientX - s.x
          const my = e.clientY - s.y
          if (!s.lock && Math.abs(mx) + Math.abs(my) > 8) {
            s.lock = Math.abs(mx) > Math.abs(my) ? 'x' : 'y'
            if (s.lock === 'x') (e.currentTarget as HTMLElement).setPointerCapture(e.pointerId)
          }
          if (s.lock === 'x') setDx(Math.min(0, mx))
        }}
        onPointerUp={end}
        onPointerCancel={end}
      >
        {children}
      </div>
    </div>
  )
}
