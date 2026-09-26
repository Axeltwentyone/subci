import { useId, useState, type ReactNode } from 'react'
import { errorMessage, useStore } from '../lib/store'
import { ApiError } from '../lib/api'
import { Button, cx } from './ui'

function Field({ label, value, onChange, error, autoComplete, autoFocus, placeholder }: {
  label: string
  value: string
  onChange: (v: string) => void
  error?: string
  autoComplete: string
  autoFocus?: boolean
  placeholder: string
}) {
  const id = useId()
  return (
    <div className="flex flex-col gap-2">
      <label htmlFor={id} className="text-[13px] font-bold text-muted">
        {label}
      </label>
      <input
        id={id}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        autoComplete={autoComplete}
        autoCapitalize="words"
        autoFocus={autoFocus}
        enterKeyHint="next"
        maxLength={40}
        placeholder={placeholder}
        aria-invalid={!!error}
        className={cx(
          'h-[60px] rounded-btn bg-surface px-4 text-lg font-semibold caret-brand outline-none placeholder:font-medium placeholder:text-subtle',
          error ? 'border-2 border-err' : 'border-[1.5px] border-line focus:border-2 focus:border-ink',
        )}
      />
      {error && <span className="text-[13px] font-semibold text-err-ink">{error}</span>}
    </div>
  )
}

/** Prénom + nom — validés et mis en forme côté API (« n'guessan » → « N'Guessan »). */
export function NameForm({ submitLabel, onDone, footer, extra, className }: { submitLabel: string; onDone: () => void; footer?: ReactNode; extra?: ReactNode; className?: string }) {
  const { state, actions } = useStore()
  const [first, setFirst] = useState(state.user?.firstName ?? '')
  const [last, setLast] = useState(state.user?.lastName ?? '')
  const [errors, setErrors] = useState<{ first?: string; last?: string; global?: string }>({})
  const [loading, setLoading] = useState(false)

  const submit = async () => {
    const f = first.trim()
    const l = last.trim()
    const local = { first: f ? undefined : 'Ton prénom est obligatoire.', last: l ? undefined : 'Ton nom est obligatoire.' }
    if (local.first || local.last) return setErrors(local)
    setLoading(true)
    setErrors({})
    try {
      await actions.setNames(f, l)
      onDone()
    } catch (e) {
      if (e instanceof ApiError) setErrors({ first: e.field('firstName'), last: e.field('lastName'), global: e.field('firstName') || e.field('lastName') ? undefined : e.message })
      else setErrors({ global: errorMessage(e) })
    } finally {
      setLoading(false)
    }
  }

  return (
    <form
      className={cx('flex flex-col', className)}
      onSubmit={(e) => {
        e.preventDefault()
        submit()
      }}
    >
      <div className="flex flex-col gap-4">
        <Field label="Prénom" value={first} onChange={(v) => { setFirst(v); setErrors((x) => ({ ...x, first: undefined })) }} error={errors.first} autoComplete="given-name" placeholder="Aya" autoFocus />
        <Field label="Nom" value={last} onChange={(v) => { setLast(v); setErrors((x) => ({ ...x, last: undefined })) }} error={errors.last} autoComplete="family-name" placeholder="Koné" />
        {errors.global && <span className="text-[13px] font-semibold text-err-ink">{errors.global}</span>}
        {extra}
      </div>
      <div className={cx('flex flex-col gap-3.5', footer !== undefined ? 'mt-auto pt-8' : 'pt-5')}>
        <Button type="submit" loading={loading} disabled={!first.trim() || !last.trim()}>
          {submitLabel}
        </Button>
        {footer}
      </div>
    </form>
  )
}
