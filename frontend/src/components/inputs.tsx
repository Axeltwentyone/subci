import { useEffect, useId, useRef, useState } from 'react'
import { PAY_METHODS, type PayMethodId } from '../lib/data'
import { formatPhone } from '../lib/format'
import { MethodLogo, Radio, cx } from './ui'

/** Champ téléphone CI (+225) — focus : bordure Ink 2 px ; erreur : Rouge. */
export function PhoneInput({
  value,
  onChange,
  error,
  autoFocus,
  label = 'Numéro de téléphone',
  compact,
}: {
  value: string
  onChange: (digits: string) => void
  error?: string
  autoFocus?: boolean
  label?: string
  compact?: boolean
}) {
  const id = useId()
  const [focus, setFocus] = useState(false)
  return (
    <div className="flex flex-col gap-2">
      {!compact && (
        <label htmlFor={id} className="text-[13px] font-bold text-muted">
          {label}
        </label>
      )}
      <div
        className={cx(
          'flex items-center gap-3 rounded-btn bg-surface px-4 transition-[border-color] duration-150',
          compact ? 'h-12 rounded-tile' : 'h-[60px]',
          error ? 'border-2 border-err' : focus ? 'border-2 border-ink' : 'border-[1.5px] border-line',
        )}
      >
        <span className={cx('flex h-7 items-center gap-2 font-bold', compact ? 'text-[16px] font-semibold text-muted' : 'border-r border-line pr-3 text-base')}>
          {!compact && (
            <span className="h-3.5 w-5 rounded-[2px] border border-line" style={{ background: 'linear-gradient(90deg,#FF8200 33%,#fff 33% 66%,#009E60 66%)' }} aria-hidden />
          )}
          +225
        </span>
        <input
          id={id}
          aria-label={compact ? label : undefined}
          type="tel"
          inputMode="numeric"
          autoComplete="tel-national"
          autoFocus={autoFocus}
          placeholder="07 00 00 00 00"
          value={formatPhone(value)}
          onFocus={() => setFocus(true)}
          onBlur={() => setFocus(false)}
          onChange={(e) => onChange(e.target.value.replace(/\D/g, '').slice(0, 10))}
          className={cx('min-w-0 flex-1 bg-transparent font-semibold tracking-[0.04em] caret-brand outline-none placeholder:text-subtle placeholder:font-medium', compact ? 'text-base' : 'text-lg')}
          aria-invalid={!!error}
        />
      </div>
      {error && <span className="text-[13px] font-semibold text-err-ink">{error}</span>}
    </div>
  )
}

/** OTP 6 chiffres, auto-rempli via WebOTP API quand dispo. */
export function OtpInput({ value, onChange, onComplete }: { value: string; onChange: (v: string) => void; onComplete?: (v: string) => void }) {
  const ref = useRef<HTMLInputElement>(null)
  const [focus, setFocus] = useState(false)

  useEffect(() => {
    if (!('OTPCredential' in window)) return
    const ac = new AbortController()
    navigator.credentials
      .get({ otp: { transport: ['sms'] }, signal: ac.signal } as CredentialRequestOptions)
      .then((cred) => {
        const code = (cred as unknown as { code?: string })?.code
        if (code) {
          onChange(code)
          onComplete?.(code)
        }
      })
      .catch(() => {})
    return () => ac.abort()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  return (
    <div className="relative" onClick={() => ref.current?.focus()}>
      <input
        ref={ref}
        aria-label="Code reçu par SMS"
        inputMode="numeric"
        autoComplete="one-time-code"
        pattern="\d{6}"
        maxLength={6}
        value={value}
        onFocus={() => setFocus(true)}
        onBlur={() => setFocus(false)}
        onChange={(e) => {
          const v = e.target.value.replace(/\D/g, '').slice(0, 6)
          onChange(v)
          if (v.length === 6) onComplete?.(v)
        }}
        className="absolute inset-0 z-10 w-full text-transparent caret-transparent opacity-0"
      />
      <div className="grid grid-cols-6 gap-2" aria-hidden>
        {Array.from({ length: 6 }, (_, i) => {
          const active = focus && i === Math.min(value.length, 5)
          return (
            <div
              key={i}
              className={cx(
                'grid h-14 place-items-center rounded-[14px] bg-surface font-display text-[22px] font-bold transition-[border-color] duration-150',
                active ? 'border-2 border-brand' : 'border-[1.5px] border-line',
              )}
            >
              {value[i] ?? (active ? <span className="h-6 w-0.5 animate-pulse bg-brand" /> : '')}
            </div>
          )
        })}
      </div>
    </div>
  )
}

/** Moyen de paiement : mobile money d'abord, carte en dernier. */
export function PayMethodPicker({
  value,
  onChange,
  phone,
  onPhone,
  methods = ['om', 'wave', 'mtn', 'moov', 'card'],
}: {
  value: PayMethodId
  onChange: (m: PayMethodId) => void
  phone: string
  onPhone: (p: string) => void
  methods?: PayMethodId[]
}) {
  const list = PAY_METHODS.filter((m) => methods.includes(m.id))
  return (
    <div role="radiogroup" aria-label="Moyen de paiement" className="overflow-hidden rounded-card bg-surface">
      {list.map((m) => {
        const selected = m.id === value
        return (
          <div
            key={m.id}
            className={cx(
              'transition-colors duration-150',
              selected ? 'rounded-card border-2 border-brand bg-brand-tint' : 'border-b border-line-soft last:border-b-0',
            )}
          >
            <button
              type="button"
              role="radio"
              aria-checked={selected}
              onClick={() => onChange(m.id)}
              className="flex w-full items-center gap-3 px-4 py-3.5 text-left"
            >
              <MethodLogo method={m} />
              <span className="flex-1 text-base font-bold">{m.name}</span>
              <Radio checked={selected} />
            </button>
            {selected && m.id !== 'card' && (
              <div className="px-4 pb-3.5">
                <PhoneInput compact value={phone} onChange={onPhone} label={`Numéro ${m.name}`} />
              </div>
            )}
            {selected && m.id === 'card' && (
              <div className="flex flex-col gap-2 px-4 pb-3.5">
                <input
                  aria-label="Numéro de carte"
                  inputMode="numeric"
                  autoComplete="cc-number"
                  placeholder="Numéro de carte"
                  className="h-12 rounded-tile border-[1.5px] border-line bg-surface px-3.5 text-base font-semibold outline-none focus:border-2 focus:border-ink"
                />
                <div className="grid grid-cols-2 gap-2">
                  <input aria-label="Expiration" autoComplete="cc-exp" placeholder="MM/AA" inputMode="numeric" className="h-12 rounded-tile border-[1.5px] border-line bg-surface px-3.5 text-base font-semibold outline-none focus:border-2 focus:border-ink" />
                  <input aria-label="Cryptogramme" autoComplete="cc-csc" placeholder="CVC" inputMode="numeric" className="h-12 rounded-tile border-[1.5px] border-line bg-surface px-3.5 text-base font-semibold outline-none focus:border-2 focus:border-ink" />
                </div>
              </div>
            )}
          </div>
        )
      })}
    </div>
  )
}
