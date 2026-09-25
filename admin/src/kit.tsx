import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { Button, ServiceLogo, cx } from '../../src/components/ui'
import { fcfa } from '../../src/lib/format'
import { errorText, type Brand } from './api'

export { Button, ServiceLogo, cx, fcfa }

/* ---------- Dates ---------- */

const dtf = new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
const df = new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'short', year: 'numeric' })

export const dateTime = (iso?: string | null) => (iso ? dtf.format(new Date(iso)) : '—')
export const date = (iso?: string | null) => (iso ? df.format(new Date(iso)) : '—')

/** « il y a 3 h », « dans 22 h » */
export function ago(iso?: string | null, now = Date.now()): string {
  if (!iso) return '—'
  const diff = Date.parse(iso) - now
  const abs = Math.abs(diff)
  const [n, unit] =
    abs < 60e3 ? [0, 's'] : abs < 3600e3 ? [Math.round(abs / 60e3), 'min'] : abs < 86400e3 ? [Math.round(abs / 3600e3), 'h'] : [Math.round(abs / 86400e3), 'j']
  if (n === 0) return 'à l’instant'
  return diff < 0 ? `il y a ${n} ${unit}` : `dans ${n} ${unit}`
}

/* ---------- Données asynchrones ---------- */

export function useAsync<T>(fn: () => Promise<T>, deps: unknown[]) {
  const [data, setData] = useState<T | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(true)
  const alive = useRef(true)
  const run = useCallback(() => {
    setLoading(true)
    return fn()
      .then((d) => alive.current && (setData(d), setError(null)))
      .catch((e) => alive.current && setError(errorText(e)))
      .finally(() => alive.current && setLoading(false))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, deps)
  useEffect(() => {
    alive.current = true
    run()
    return () => {
      alive.current = false
    }
  }, [run])
  return { data, error, loading, reload: run, setData }
}

/* ---------- Mise en page ---------- */

export function PageHeader({ title, subtitle, actions }: { title: string; subtitle?: ReactNode; actions?: ReactNode }) {
  return (
    <header className="flex flex-wrap items-end justify-between gap-4">
      <div className="flex flex-col gap-1">
        <h1 className="font-display text-[28px] leading-tight font-bold tracking-[-0.025em]">{title}</h1>
        {subtitle && <p className="text-sm font-medium text-muted">{subtitle}</p>}
      </div>
      {actions && <div className="flex items-center gap-2">{actions}</div>}
    </header>
  )
}

export function Panel({ title, action, children, className, pad = true }: { title?: ReactNode; action?: ReactNode; children: ReactNode; className?: string; pad?: boolean }) {
  return (
    <section className={cx('flex flex-col rounded-card bg-white', className)}>
      {title && (
        <div className="flex items-center justify-between gap-3 px-5 pt-4 pb-3">
          <h2 className="text-[15px] font-bold">{title}</h2>
          {action}
        </div>
      )}
      <div className={cx(pad && 'px-5 pb-5', !title && pad && 'pt-5')}>{children}</div>
    </section>
  )
}

/** Indicateur clé : valeur en Bricolage + évolution vs mois précédent. */
export function Stat({ label, value, unit, previous, current, hint, tone = 'default' }: { label: string; value: string; unit?: string; previous?: number; current?: number; hint?: ReactNode; tone?: 'default' | 'ink' }) {
  const delta = previous !== undefined && current !== undefined ? (previous === 0 ? (current > 0 ? 100 : 0) : Math.round(((current - previous) / previous) * 100)) : null
  return (
    <div className={cx('flex flex-col gap-2 rounded-card p-5', tone === 'ink' ? 'bg-ink text-sand' : 'bg-white')}>
      <span className={cx('text-[13px] font-semibold', tone === 'ink' ? 'text-ink-muted' : 'text-muted')}>{label}</span>
      <span className="tabular font-display text-[30px] leading-none font-extrabold tracking-[-0.02em]">
        {value}
        {unit && <span className={cx('ml-1 font-sans text-sm font-bold', tone === 'ink' ? 'text-ink-muted' : 'text-muted')}>{unit}</span>}
      </span>
      <span className={cx('text-[12px] font-semibold', tone === 'ink' ? 'text-ink-muted' : 'text-muted')}>
        {delta !== null && (
          <b className={cx('mr-1', delta > 0 ? (tone === 'ink' ? 'text-ok-glow' : 'text-ok-ink') : delta < 0 ? 'text-err' : '')}>
            {delta > 0 ? '▲' : delta < 0 ? '▼' : '='} {Math.abs(delta)} %
          </b>
        )}
        {hint}
      </span>
    </div>
  )
}

/* ---------- Statuts ---------- */

const TONES = {
  ok: 'bg-ok-soft text-ok-ink',
  warn: 'bg-warn-soft text-warn-ink',
  info: 'bg-info-soft text-info',
  err: 'bg-err-soft text-err-ink',
  muted: 'bg-line text-muted',
  brand: 'bg-brand text-ink',
} as const

export function Pill({ tone, children }: { tone: keyof typeof TONES; children: ReactNode }) {
  return <span className={cx('inline-flex items-center rounded-full px-2 py-0.5 text-[12px] font-extrabold whitespace-nowrap', TONES[tone])}>{children}</span>
}

export const OFFER_STATUS: Record<string, [string, keyof typeof TONES]> = {
  review: ['À vérifier', 'warn'],
  live: ['En ligne', 'ok'],
  paused: ['En pause', 'muted'],
  closed: ['Arrêtée', 'muted'],
  rejected: ['Refusée', 'err'],
}
export const PAYMENT_STATUS: Record<string, [string, keyof typeof TONES]> = {
  pending: ['En attente', 'warn'],
  succeeded: ['Payé', 'ok'],
  failed: ['Échoué', 'err'],
  expired: ['Expiré', 'muted'],
}
export const PAYMENT_TYPE: Record<string, string> = {
  subscription: 'Abonnement',
  earning: 'Gain hôte',
  withdrawal: 'Retrait hôte',
  refund: 'Remboursement',
}
export const REQUEST_STATUS: Record<string, [string, keyof typeof TONES]> = {
  pending: ['En attente', 'warn'],
  accepted: ['Acceptée', 'ok'],
  declined: ['Refusée', 'err'],
  expired: ['Expirée', 'muted'],
  cancelled: ['Annulée', 'muted'],
}

export const StatusPill = ({ map, value }: { map: Record<string, [string, keyof typeof TONES]>; value: string }) => {
  const [label, tone] = map[value] ?? [value, 'muted']
  return <Pill tone={tone}>{label}</Pill>
}

/* ---------- Tableaux ---------- */

export function Table({ head, children, empty }: { head: ReactNode[]; children: ReactNode; empty?: boolean }) {
  return (
    <div className="overflow-x-auto">
      <table className="w-full min-w-[720px] border-collapse text-left text-sm">
        <thead>
          <tr className="border-b border-line text-[12px] font-bold tracking-wide text-muted uppercase">
            {head.map((h, i) => (
              <th key={i} className="px-4 py-3 font-bold first:pl-5 last:pr-5">
                {h}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>{children}</tbody>
      </table>
      {empty && <p className="px-5 py-10 text-center text-sm font-medium text-muted">Rien à afficher.</p>}
    </div>
  )
}

export function Tr({ children, onClick, active }: { children: ReactNode; onClick?: () => void; active?: boolean }) {
  return (
    <tr
      onClick={onClick}
      className={cx('border-b border-line-soft last:border-b-0', onClick && 'cursor-pointer hover:bg-sand/70', active && 'bg-brand-tint')}
    >
      {children}
    </tr>
  )
}

export const Td = ({ children, className }: { children?: ReactNode; className?: string }) => (
  <td className={cx('px-4 py-3 align-middle first:pl-5 last:pr-5', className)}>{children}</td>
)

export function Pager({ page, pages, onPage }: { page: number; pages: number; onPage: (p: number) => void }) {
  if (pages <= 1) return null
  return (
    <div className="flex items-center justify-end gap-2 px-5 py-3 text-sm font-semibold text-muted">
      <button type="button" disabled={page <= 1} onClick={() => onPage(page - 1)} className="rounded-tile px-3 py-1.5 hover:bg-sand disabled:opacity-40">
        ← Précédent
      </button>
      <span className="tabular">
        {page} / {pages}
      </span>
      <button type="button" disabled={page >= pages} onClick={() => onPage(page + 1)} className="rounded-tile px-3 py-1.5 hover:bg-sand disabled:opacity-40">
        Suivant →
      </button>
    </div>
  )
}

/* ---------- Petits éléments ---------- */

export const Brandmark = ({ s, size = 32 }: { s: Brand; size?: number }) => <ServiceLogo service={s} size={size} radius={Math.round(size / 3.5)} />

export function Avatar({ name, size = 32, color = '#FFE6DA' }: { name: string; size?: number; color?: string }) {
  return (
    <span className="grid shrink-0 place-items-center rounded-full font-display font-extrabold" style={{ width: size, height: size, background: color, fontSize: size * 0.42 }}>
      {name.charAt(0).toUpperCase()}
    </span>
  )
}

export const Spinner = () => <span className="inline-block size-5 rounded-full border-[2.5px] border-line border-t-brand animate-spin-fast" aria-label="Chargement" />

export function Loading() {
  return (
    <div className="grid min-h-40 place-items-center">
      <Spinner />
    </div>
  )
}

export function ErrorBox({ message, onRetry }: { message: string; onRetry?: () => void }) {
  return (
    <div className="flex items-center justify-between gap-3 rounded-card bg-err-soft px-5 py-4 text-sm font-semibold text-err-ink">
      {message}
      {onRetry && (
        <button type="button" onClick={onRetry} className="font-extrabold underline">
          Réessayer
        </button>
      )}
    </div>
  )
}

export function Segments<T extends string>({ value, options, onChange }: { value: T; options: { value: T; label: string; count?: number }[]; onChange: (v: T) => void }) {
  return (
    <div role="tablist" className="flex flex-wrap gap-1 rounded-[14px] bg-white p-1">
      {options.map((o) => (
        <button
          key={o.value}
          role="tab"
          type="button"
          aria-selected={o.value === value}
          onClick={() => onChange(o.value)}
          className={cx('flex items-center gap-1.5 rounded-[10px] px-3 py-2 text-sm font-bold transition-colors', o.value === value ? 'bg-ink text-white' : 'text-muted hover:bg-sand')}
        >
          {o.label}
          {o.count !== undefined && (
            <span className={cx('tabular rounded-full px-1.5 text-[11px] font-extrabold', o.value === value ? 'bg-white/15' : 'bg-sand')}>{o.count}</span>
          )}
        </button>
      ))}
    </div>
  )
}

export function SearchInput({ value, onChange, placeholder }: { value: string; onChange: (v: string) => void; placeholder: string }) {
  return (
    <input
      type="search"
      value={value}
      onChange={(e) => onChange(e.target.value)}
      placeholder={placeholder}
      className="h-10 w-72 max-w-full rounded-[12px] border-[1.5px] border-line bg-white px-3.5 text-sm font-semibold outline-none placeholder:font-medium placeholder:text-subtle focus:border-ink"
    />
  )
}

export function useDebounced<T>(value: T, ms = 250) {
  const [v, setV] = useState(value)
  useEffect(() => {
    const t = setTimeout(() => setV(value), ms)
    return () => clearTimeout(t)
  }, [value, ms])
  return v
}

/* ---------- Panneau latéral & confirmation ---------- */

export function Drawer({ open, onClose, children, title }: { open: boolean; onClose: () => void; children: ReactNode; title: ReactNode }) {
  useEffect(() => {
    if (!open) return
    const k = (e: KeyboardEvent) => e.key === 'Escape' && onClose()
    window.addEventListener('keydown', k)
    return () => window.removeEventListener('keydown', k)
  }, [open, onClose])
  if (!open) return null
  return createPortal(
    <div className="fixed inset-0 z-40">
      <div className="absolute inset-0 animate-fade-in bg-ink/40" onClick={onClose} aria-hidden />
      <aside role="dialog" aria-modal="true" className="absolute inset-y-0 right-0 flex w-[560px] max-w-full animate-panel-in flex-col bg-sand shadow-2xl">
        <div className="flex items-center justify-between gap-3 border-b border-line bg-white px-6 py-4">
          <div className="min-w-0 flex-1">{title}</div>
          <button type="button" onClick={onClose} aria-label="Fermer" className="grid size-9 place-items-center rounded-full bg-sand text-lg font-bold hover:bg-line">
            ×
          </button>
        </div>
        <div className="flex-1 overflow-y-auto p-6">{children}</div>
      </aside>
    </div>,
    document.body,
  )
}

/** Confirmation d'action sensible, avec motif facultatif ou obligatoire. */
export function Confirm({
  title,
  text,
  confirm,
  danger,
  reason,
  onCancel,
  onConfirm,
}: {
  title: string
  text: ReactNode
  confirm: string
  danger?: boolean
  reason?: { label: string; required?: boolean; placeholder?: string }
  onCancel: () => void
  onConfirm: (reason: string) => Promise<unknown>
}) {
  const [value, setValue] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState<string | null>(null)
  const blocked = !!reason?.required && value.trim().length < 5

  return createPortal(
    <div className="fixed inset-0 z-50 grid place-items-center p-6">
      <div className="absolute inset-0 animate-fade-in bg-ink/50" onClick={onCancel} aria-hidden />
      <form
        role="alertdialog"
        aria-modal="true"
        className="relative flex w-full max-w-[440px] animate-fade-in flex-col gap-3 rounded-sheet bg-white p-6"
        onSubmit={async (e) => {
          e.preventDefault()
          if (blocked) return
          setBusy(true)
          setErr(null)
          try {
            await onConfirm(value.trim())
          } catch (x) {
            setErr(errorText(x))
            setBusy(false)
          }
        }}
      >
        <h2 className="font-display text-[22px] leading-tight font-bold">{title}</h2>
        <div className="text-[15px] leading-normal font-medium text-muted">{text}</div>
        {reason && (
          <label className="flex flex-col gap-1.5">
            <span className="text-[13px] font-bold text-muted">{reason.label}</span>
            <textarea
              autoFocus
              rows={3}
              value={value}
              placeholder={reason.placeholder}
              onChange={(e) => setValue(e.target.value)}
              className="rounded-tile border-[1.5px] border-line p-3 text-[15px] font-medium outline-none focus:border-ink"
            />
          </label>
        )}
        {err && <p className="text-[13px] font-semibold text-err-ink">{err}</p>}
        <div className="mt-2 grid grid-cols-2 gap-2">
          <Button type="button" variant="outline" size="md" onClick={onCancel}>
            Annuler
          </Button>
          <Button type="submit" variant={danger ? 'danger' : 'ink'} size="md" loading={busy} disabled={blocked}>
            {confirm}
          </Button>
        </div>
      </form>
    </div>,
    document.body,
  )
}

export function Copy({ text }: { text: string }) {
  const [done, setDone] = useState(false)
  return (
    <button
      type="button"
      onClick={(e) => {
        e.stopPropagation()
        navigator.clipboard?.writeText(text).then(() => {
          setDone(true)
          setTimeout(() => setDone(false), 1200)
        })
      }}
      className="rounded-md px-1.5 py-0.5 text-[12px] font-bold text-muted hover:bg-sand"
      title="Copier"
    >
      {done ? 'Copié' : 'Copier'}
    </button>
  )
}

export const phone = (p?: string | null) => (p ? p.replace(/(\d{2})(?=\d)/g, '$1 ') : '—')
