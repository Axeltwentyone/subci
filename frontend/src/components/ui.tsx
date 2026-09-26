import type { ButtonHTMLAttributes, CSSProperties, ReactNode } from 'react'
import { DEVICES, type Device, type Service } from '../lib/data'
import { fcfa } from '../lib/format'
import { INK, SAND, useTopColor } from '../lib/hooks'
import { useBack } from '../lib/nav'
import type { SubStatus } from '../lib/store'
import { IconChevronLeft, IconLaptop, IconPhone, IconTablet, IconTv } from './icons'

export function cx(...c: (string | false | null | undefined)[]) {
  return c.filter(Boolean).join(' ')
}

/* ---------- Button ---------- */

type Variant = 'primary' | 'ink' | 'outline' | 'text' | 'plain' | 'ghost-dark' | 'soft' | 'danger'
type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: Variant
  size?: 'lg' | 'md' | 'link' | 'sm' | 'xs'
  loading?: boolean
  block?: boolean
}

const variants: Record<Variant, string> = {
  primary: 'bg-brand text-on-accent',
  ink: 'bg-ink text-sand',
  outline: 'border-[1.5px] border-line-strong text-ink bg-transparent',
  text: 'text-muted bg-transparent',
  plain: 'text-ink bg-transparent',
  'ghost-dark': 'text-ink-soft bg-transparent',
  soft: 'bg-sand text-ink',
  danger: 'bg-err text-white',
}
const sizes = {
  lg: 'h-14 rounded-btn text-base',
  md: 'h-[52px] rounded-btn text-[15px]',
  link: 'h-12 rounded-[14px] text-[15px]',
  sm: 'h-11 rounded-tile text-sm px-4',
  xs: 'h-10 rounded-tile text-sm px-4',
}

export function Button({ variant = 'primary', size = 'lg', loading, block = true, disabled, className, children, ...rest }: ButtonProps) {
  const isDisabled = disabled || loading
  return (
    <button
      {...rest}
      disabled={isDisabled}
      aria-busy={loading || undefined}
      className={cx(
        'pressable inline-flex items-center justify-center gap-2.5 font-sans font-bold select-none',
        sizes[size],
        block && 'w-full',
        disabled && !loading ? 'bg-line text-disabled' : variants[variant],
        className,
      )}
    >
      {loading && (
        <span className="size-[18px] rounded-full border-[2.5px] border-ink/25 border-t-ink animate-spin-fast" aria-hidden />
      )}
      {children}
    </button>
  )
}

/* ---------- Brand ---------- */

export function LogoMark({ size = 56, tone = 'ink' }: { size?: number; tone?: 'ink' | 'sand' | 'brand' }) {
  const bg = tone === 'ink' ? '#16130F' : tone === 'sand' ? '#F5F2EC' : '#FF5A1F'
  const fg = tone === 'ink' ? '#F5F2EC' : '#16130F'
  const dot = tone === 'brand' ? '#16130F' : '#FF5A1F'
  return (
    <span
      className="relative grid shrink-0 place-items-center"
      style={{ width: size, height: size, borderRadius: size * 0.286, background: bg }}
      aria-hidden
    >
      <span className="font-display font-extrabold leading-none tracking-[-0.04em]" style={{ fontSize: size * 0.64, color: fg, marginTop: -size * 0.09 }}>
        s
      </span>
      <span className="absolute rounded-full" style={{ width: size * 0.143, height: size * 0.143, right: size * 0.214, bottom: size * 0.232, background: dot }} />
    </span>
  )
}

export function Wordmark({ className, dark }: { className?: string; dark?: boolean }) {
  return (
    <span className={cx('font-display font-extrabold leading-none tracking-[-0.03em]', dark ? 'text-sand' : 'text-ink', className)}>
      sub<span className="text-brand">.</span>ci
    </span>
  )
}

/* ---------- Service logo ---------- */

export function ServiceLogo({ service, size = 44, radius, className }: { service: Pick<Service, 'mono' | 'color' | 'fg'>; size?: number; radius?: number; className?: string }) {
  const r = radius ?? (size >= 64 ? size * 0.28 : size >= 52 ? 14 : 12)
  const fs = service.mono.length > 1 ? size * 0.36 : size * 0.45
  return (
    <span
      className={cx('grid shrink-0 place-items-center font-display font-extrabold', className)}
      style={{ width: size, height: size, borderRadius: r, background: service.color, color: service.fg, fontSize: fs }}
      aria-hidden
    >
      {service.mono}
    </span>
  )
}

export function MethodLogo({ method, size = 40 }: { method: { mono: string; color: string; fg: string }; size?: number }) {
  return (
    <span
      className="grid shrink-0 place-items-center rounded-tile font-sans font-extrabold"
      style={{ width: size, height: size, background: method.color, color: method.fg, fontSize: method.mono.length > 2 ? 12 : method.mono.length > 1 ? 13 : 15 }}
      aria-hidden
    >
      {method.mono}
    </span>
  )
}

/* ---------- Price ---------- */

export function Price({ value, unit = 'FCFA', size = 18, unitClass, className }: { value: number; unit?: string; size?: number; unitClass?: string; className?: string }) {
  return (
    <span className={cx('font-display font-extrabold leading-none whitespace-nowrap', className)} style={{ fontSize: size }}>
      {fcfa(value)}{' '}
      {unit && <span className={cx('font-sans text-[11px] font-semibold', unitClass ?? 'text-muted')}>{unit}</span>}
    </span>
  )
}

/* ---------- Badges & chips ---------- */

const badgeTones: Record<string, string> = {
  active: 'bg-ok-soft text-ok-ink',
  due: 'bg-warn-soft text-warn-ink',
  pending: 'bg-info-soft text-info',
  expired: 'bg-err-soft text-err-ink',
  brand: 'bg-brand text-on-accent',
  soft: 'bg-brand-soft text-brand-ink',
}

export function Badge({ tone, children, className }: { tone: keyof typeof badgeTones | SubStatus; children: ReactNode; className?: string }) {
  return (
    <span className={cx('inline-flex items-center gap-1.5 rounded-full px-[9px] py-[5px] text-xs font-extrabold whitespace-nowrap', badgeTones[tone], className)}>
      {children}
    </span>
  )
}

export function StatusBadge({ status, days }: { status: SubStatus; days?: number }) {
  if (status === 'due') return <Badge tone="due">J-{Math.max(0, days ?? 0)}</Badge>
  if (status === 'pending')
    return (
      <Badge tone="pending">
        <span className="size-2.5 rounded-full border-2 border-[#B8C8F5] border-t-info animate-spin-fast" />
        En cours
      </Badge>
    )
  if (status === 'expired') return <Badge tone="expired">Expiré</Badge>
  return <Badge tone="active">Actif</Badge>
}

export function Chip({ active, tone, surface, children, onClick, size = 'md', className }: { active?: boolean; tone?: 'ok'; surface?: boolean; children: ReactNode; onClick?: () => void; size?: 'md' | 'sm'; className?: string }) {
  return (
    <button
      type="button"
      onClick={onClick}
      aria-pressed={active}
      className={cx(
        'pressable inline-flex shrink-0 items-center gap-1.5 rounded-full font-bold whitespace-nowrap',
        size === 'md' ? 'h-10 px-4 text-sm' : 'h-9 px-3 text-[13px]',
        active ? (tone === 'ok' ? 'bg-ok-soft text-ok-ink' : 'bg-ink text-sand') : surface ? 'bg-surface' : 'border-[1.5px] border-line-strong bg-transparent',
        className,
      )}
    >
      {children}
    </button>
  )
}

/* ---------- Segmented & tabs ---------- */

export function Segmented<T extends string>({ value, options, onChange, className }: { value: T; options: { value: T; label: ReactNode }[]; onChange: (v: T) => void; className?: string }) {
  return (
    <div role="tablist" className={cx('grid rounded-[14px] bg-line p-1 text-sm font-bold', className)} style={{ gridTemplateColumns: `repeat(${options.length}, 1fr)` }}>
      {options.map((o) => (
        <button
          key={o.value}
          role="tab"
          type="button"
          aria-selected={o.value === value}
          onClick={() => onChange(o.value)}
          className={cx('h-10 rounded-[10px] transition-colors duration-150', o.value === value ? 'bg-surface text-ink shadow-[0_1px_2px_rgba(22,19,15,.06)]' : 'text-muted')}
        >
          {o.label}
        </button>
      ))}
    </div>
  )
}

export function UnderlineTabs<T extends string>({ value, options, onChange }: { value: T; options: { value: T; label: string }[]; onChange: (v: T) => void }) {
  return (
    <div role="tablist" className="no-scrollbar -mx-5 flex gap-[22px] overflow-x-auto border-b border-line px-5 text-[15px] font-bold">
      {options.map((o) => (
        <button
          key={o.value}
          role="tab"
          type="button"
          aria-selected={o.value === value}
          onClick={() => onChange(o.value)}
          className={cx('-mb-px shrink-0 border-b-[3px] pt-2 pb-3', o.value === value ? 'border-ink text-ink' : 'border-transparent text-subtle')}
        >
          {o.label}
        </button>
      ))}
    </div>
  )
}

/* ---------- Toggle & radio ---------- */

export function Toggle({ checked, onChange, label }: { checked: boolean; onChange: (v: boolean) => void; label: string }) {
  return (
    <button
      type="button"
      role="switch"
      aria-checked={checked}
      aria-label={label}
      onClick={() => onChange(!checked)}
      className={cx('relative h-8 w-[52px] shrink-0 rounded-2xl transition-colors duration-200', checked ? 'bg-ok' : 'bg-line-strong')}
    >
      <span className={cx('absolute top-[3px] size-[26px] rounded-full bg-surface shadow-sm transition-[left] duration-200 ease-app', checked ? 'left-[23px]' : 'left-[3px]')} />
    </button>
  )
}

export function Radio({ checked }: { checked: boolean }) {
  return (
    <span
      aria-hidden
      className={cx('size-[22px] shrink-0 rounded-full transition-[border] duration-150', checked ? 'border-[7px] border-brand' : 'border-2 border-radio')}
    />
  )
}

/* ---------- Layout helpers ---------- */

export function RoundIconButton({ label, onClick, children, dark, className }: { label: string; onClick?: () => void; children: ReactNode; dark?: boolean; className?: string }) {
  return (
    <button
      type="button"
      aria-label={label}
      onClick={onClick}
      className={cx('pressable grid size-11 shrink-0 place-items-center rounded-full', dark ? 'bg-ink-3 text-sand' : 'bg-surface text-ink', className)}
    >
      {children}
    </button>
  )
}

export function BackButton({ dark, to }: { dark?: boolean; to?: string }) {
  const back = useBack()
  return (
    <RoundIconButton label="Retour" dark={dark} onClick={() => back(to)}>
      <IconChevronLeft size={20} />
    </RoundIconButton>
  )
}

/** Barre du haut d'un écran plein écran : ‹  Titre  (droite) */
export function TopBar({ title, right, dark, back }: { title?: ReactNode; right?: ReactNode; dark?: boolean; back?: string }) {
  return (
    <div className="flex items-center justify-between gap-3 px-4 pt-1 pb-1">
      <BackButton dark={dark} to={back} />
      {title ? <span className="truncate text-base font-bold">{title}</span> : <span />}
      <span className="flex min-w-11 justify-end">{right}</span>
    </div>
  )
}

export function StepBar({ step, total }: { step: number; total: number }) {
  return (
    <div className="grid gap-1.5 px-5 pt-2.5" style={{ gridTemplateColumns: `repeat(${total}, 1fr)` }} aria-label={`Étape ${step} sur ${total}`}>
      {Array.from({ length: total }, (_, i) => (
        <span key={i} className={cx('h-1 rounded-sm transition-colors duration-300', i < step ? 'bg-ink' : 'bg-line-strong')} />
      ))}
    </div>
  )
}

/**
 * Écran plein écran (sans bottom nav). Sur tablette/desktop,
 * reste une colonne centrée de 440 px, comme le checkout.
 */
export function Screen({ children, dark, className, style }: { children: ReactNode; dark?: boolean; className?: string; style?: CSSProperties }) {
  useTopColor(dark ? INK : SAND)
  return (
    <div className={cx('min-h-dvh', dark ? 'scheme-dark bg-ink text-sand' : 'bg-sand text-ink')}>
      <div className={cx('pt-safe relative mx-auto flex min-h-dvh w-full max-w-[440px] flex-col', className)} style={style}>
        {children}
      </div>
    </div>
  )
}

/** CTA collant en bas, au-dessus de la safe-area. */
export function StickyAction({ children, className }: { children: ReactNode; className?: string }) {
  return (
    <>
      <div className="h-32 shrink-0" aria-hidden />
      <div className="fixed inset-x-0 bottom-0 z-30">
        <div className={cx('pb-safe mx-auto max-w-[440px] border-t border-line bg-surface px-5 pt-3 md:rounded-t-card md:border-x', className)}>{children}</div>
      </div>
    </>
  )
}

export function Card({ children, className, dark, onClick }: { children: ReactNode; className?: string; dark?: boolean; onClick?: () => void }) {
  const cls = cx('rounded-card', dark ? 'scheme-card bg-ink text-sand' : 'bg-surface', className)
  if (onClick)
    return (
      <button type="button" onClick={onClick} className={cx('pressable block w-full text-left', cls)}>
        {children}
      </button>
    )
  return <div className={cls}>{children}</div>
}

export function Progress({ value, color, track = 'bg-line-soft' }: { value: number; color: string; track?: string }) {
  return (
    <div className={cx('h-1.5 overflow-hidden rounded-[3px]', track)} role="progressbar" aria-valuenow={Math.round(value * 100)} aria-valuemin={0} aria-valuemax={100}>
      <div className="h-full rounded-[3px] transition-[width] duration-500 ease-app" style={{ width: `${Math.max(4, Math.min(100, value * 100))}%`, background: color }} />
    </div>
  )
}

export function Avatars({ members, size = 32, ring = 'var(--color-surface)', extra }: { members: { name: string; color: string }[]; size?: number; ring?: string; extra?: ReactNode }) {
  return (
    <div className="flex">
      {members.map((m, i) => (
        <span
          key={m.name + i}
          title={m.name}
          className="grid place-items-center rounded-full text-xs font-extrabold text-on-accent"
          style={{ width: size, height: size, background: m.color, border: `${size > 32 ? 3 : 2}px solid ${ring}`, marginLeft: i ? -size / 4 : 0 }}
        >
          {m.name.charAt(0)}
        </span>
      ))}
      {extra}
    </div>
  )
}

export function Skeleton({ className, shimmer = true }: { className?: string; shimmer?: boolean }) {
  return <span className={cx('block', shimmer ? 'skeleton' : 'bg-line', className)} aria-hidden />
}

export function Spinner({ size = 16, className }: { size?: number; className?: string }) {
  return (
    <span
      className={cx('inline-block rounded-full border-line border-t-brand animate-spin-fast', className)}
      style={{ width: size, height: size, borderWidth: size > 20 ? 3 : 2.5 }}
      aria-hidden
    />
  )
}

export function Row({ label, value, dark, strong = true }: { label: ReactNode; value: ReactNode; dark?: boolean; strong?: boolean }) {
  return (
    <div className={cx('flex justify-between gap-4 border-b py-3.5 text-[15px] font-semibold last:border-b-0', dark ? 'border-ink-line' : 'border-line-soft')}>
      <span className={dark ? 'text-ink-muted' : 'text-muted'}>{label}</span>
      <span className={cx('text-right', strong && 'font-bold')}>{value}</span>
    </div>
  )
}

export function ListLink({ label, hint, onClick, danger }: { label: ReactNode; hint?: ReactNode; onClick?: () => void; danger?: boolean }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className="pressable flex min-h-14 w-full items-center justify-between gap-3 border-b border-line-soft text-left text-[15px] font-bold last:border-b-0"
    >
      <span className={danger ? 'text-err' : ''}>{label}</span>
      <span className="font-semibold text-muted">
        {hint}
        {hint ? ' ' : ''}
        <span className="text-subtle">›</span>
      </span>
    </button>
  )
}

export function SectionLabel({ children, className }: { children: ReactNode; className?: string }) {
  return <span className={cx('t-over block text-muted', className)}>{children}</span>
}

export function Steps({ items, tone = 'brand' }: { items: string[]; tone?: 'brand' | 'ink' }) {
  return (
    <ol className="flex flex-col gap-3.5">
      {items.map((t, i) => (
        <li key={t} className="flex items-center gap-3.5">
          <span className={cx('grid size-7 shrink-0 place-items-center rounded-full text-[13px] font-extrabold', tone === 'brand' ? 'bg-brand text-on-accent' : 'bg-ink text-sand')}>{i + 1}</span>
          <span className="text-[15px] leading-[1.4] font-semibold">{t}</span>
        </li>
      ))}
    </ol>
  )
}

/* ---------- Appareils ---------- */

const DEVICE_ICON = { phone: IconPhone, tablet: IconTablet, computer: IconLaptop, tv: IconTv }

/** Appareils autorisés par une offre : icônes + libellés courts. */
export function DeviceList({ devices, className }: { devices: Device[]; className?: string }) {
  const list = DEVICES.filter((d) => devices.includes(d.id))
  if (!list.length) return null
  return (
    <span className={cx('flex flex-wrap items-center gap-x-3 gap-y-1 text-[13px] font-semibold text-muted', className)}>
      {list.map(({ id, label }) => {
        const Icon = DEVICE_ICON[id]
        return (
          <span key={id} className="inline-flex items-center gap-1">
            <Icon size={15} />
            {label}
          </span>
        )
      })}
    </span>
  )
}

export function DeviceChip({ device, active, onClick }: { device: Device; active: boolean; onClick: () => void }) {
  const Icon = DEVICE_ICON[device]
  const label = DEVICES.find((d) => d.id === device)!.label
  return (
    <Chip size="sm" active={active} onClick={onClick}>
      <Icon size={15} />
      {label}
    </Chip>
  )
}
