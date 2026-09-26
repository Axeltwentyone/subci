import { useRef, useState } from 'react'
import { Link, Navigate, useNavigate } from 'react-router'
import { OtpInput, PhoneInput } from '../components/inputs'
import { NameForm } from '../components/NameForm'
import { useToast } from '../components/Toast'
import { Button, Screen, Wordmark, cx } from '../components/ui'
import { useCountdown, mmss } from '../lib/hooks'
import { REF_KEY, errorMessage, takeNext, useStore } from '../lib/store'

/* ---------- 02 · Onboarding ---------- */

const SLIDES = [
  { title: 'Le premium, à prix partagé.', text: 'Rejoins un abonnement existant et paie seulement ta place, en mobile money.' },
  { title: 'Paie en 2 gestes.', text: 'Orange Money, Wave, MTN ou Moov. Tu valides sur ton téléphone, c’est tout.' },
  { title: 'Tes accès, toujours sous la main.', text: 'Identifiants disponibles même hors ligne, et un rappel avant chaque échéance.' },
]

function SlideVisual({ i }: { i: number }) {
  return (
    <div className="relative mx-6 mt-2 h-[min(360px,42dvh)] overflow-hidden rounded-[32px] bg-ink">
      {i === 0 && (
        <>
          <div className="absolute top-12 left-[34px] flex w-[150px] -rotate-6 flex-col gap-2.5 rounded-card bg-white p-3.5">
            <span className="grid size-10 place-items-center rounded-tile bg-[#E50914] font-display text-xl font-extrabold text-white">N</span>
            <span className="text-sm font-bold">Netflix Premium</span>
            <span className="font-display text-base font-extrabold">2 500 <span className="font-sans text-[11px] font-semibold text-muted">FCFA</span></span>
          </div>
          <div className="absolute top-[120px] right-[30px] flex w-[150px] rotate-[5deg] flex-col gap-2.5 rounded-card bg-brand p-3.5">
            <span className="grid size-10 place-items-center rounded-tile bg-[#1DB954] font-display text-xl font-extrabold text-[#0B0B0B]">S</span>
            <span className="text-sm font-bold">Spotify Famille</span>
            <span className="font-display text-base font-extrabold">1 500 <span className="font-sans text-[11px] font-semibold">FCFA</span></span>
          </div>
          <div className="absolute bottom-10 left-[60px] flex items-center gap-2.5 rounded-full bg-sand py-2.5 pr-3.5 pl-2.5">
            <div className="flex">
              {['#FFB38F', '#9FD7BE', '#C9B8F2'].map((c, k) => (
                <span key={c} className="size-7 rounded-full border-2 border-sand" style={{ background: c, marginLeft: k ? -8 : 0 }} />
              ))}
              <span className="-ml-2 size-7 rounded-full border-2 border-dashed border-ink bg-sand" />
            </div>
            <span className="text-[13px] font-bold">1 place libre</span>
          </div>
        </>
      )}
      {i === 1 && (
        <>
          <div className="absolute top-10 left-1/2 flex w-[230px] -translate-x-1/2 flex-col gap-3 rounded-card bg-white p-4">
            {[
              { m: 'OM', c: '#FF7900', f: '#fff', n: 'Orange Money', on: true },
              { m: 'W', c: '#1DC8F2', f: '#0B0B0B', n: 'Wave' },
              { m: 'MTN', c: '#FFCC00', f: '#0B0B0B', n: 'MTN MoMo' },
            ].map((x) => (
              <div key={x.n} className="flex items-center gap-2.5">
                <span className="grid size-9 place-items-center rounded-[10px] text-[12px] font-extrabold" style={{ background: x.c, color: x.f }}>{x.m}</span>
                <span className="flex-1 text-sm font-bold">{x.n}</span>
                <span className={cx('size-5 rounded-full', x.on ? 'border-[6px] border-brand' : 'border-2 border-radio')} />
              </div>
            ))}
          </div>
          <div className="absolute right-8 bottom-10 left-8 grid h-14 place-items-center rounded-btn bg-brand text-base font-bold text-ink">Payer 2 500 FCFA</div>
        </>
      )}
      {i === 2 && (
        <div className="absolute inset-x-8 top-1/2 flex -translate-y-1/2 flex-col gap-0 rounded-card bg-ink-2 px-[18px] py-1.5 text-sand">
          {[
            ['Email', 'aya.n3@sub.ci'],
            ['Mot de passe', '••••••••••'],
            ['Ton profil', 'Profil 3 · « Aya »'],
          ].map(([k, v]) => (
            <div key={k} className="flex items-center justify-between border-b border-ink-line py-3.5 last:border-b-0">
              <div className="flex flex-col gap-0.5">
                <span className="text-xs font-semibold text-ink-muted">{k}</span>
                <span className="text-[15px] font-bold">{v}</span>
              </div>
              <span className="rounded-[10px] bg-ink-3 px-3 py-2 text-[13px] font-bold">Copier</span>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

/** 3 slides swipables, « Passer » toujours visible. */
export function Onboarding() {
  const navigate = useNavigate()
  const { actions } = useStore()
  const [i, setI] = useState(0)
  const touch = useRef<number | null>(null)

  const finish = (to: string) => {
    actions.onboarded()
    navigate(to, { replace: true, viewTransition: true })
  }
  const go = (n: number) => setI(Math.max(0, Math.min(SLIDES.length - 1, n)))

  return (
    <Screen>
      <div
        className="flex flex-1 flex-col"
        onTouchStart={(e) => (touch.current = e.touches[0].clientX)}
        onTouchEnd={(e) => {
          if (touch.current === null) return
          const d = e.changedTouches[0].clientX - touch.current
          if (Math.abs(d) > 40) go(i + (d < 0 ? 1 : -1))
          touch.current = null
        }}
      >
        <div className="flex justify-end px-6 pt-1">
          <button type="button" onClick={() => finish('/login')} className="px-1 py-3 text-[15px] font-bold text-muted">
            Passer
          </button>
        </div>
        <div key={i} className="animate-fade-in">
          <SlideVisual i={i} />
        </div>
        <div className="flex flex-col gap-3.5 px-7 pt-8">
          <div className="flex gap-1.5" role="tablist" aria-label="Diapositives">
            {SLIDES.map((_, k) => (
              <button
                key={k}
                type="button"
                role="tab"
                aria-selected={k === i}
                aria-label={`Diapositive ${k + 1}`}
                onClick={() => go(k)}
                className={cx('h-1.5 rounded-[3px] transition-[width,background] duration-200', k === i ? 'w-6 bg-ink' : 'w-1.5 bg-radio')}
              />
            ))}
          </div>
          <h1 key={`t${i}`} className="t-hero animate-fade-in">{SLIDES[i].title}</h1>
          <p className="text-base leading-normal font-medium text-muted">{SLIDES[i].text}</p>
        </div>
        <div className="mt-auto flex flex-col gap-2 px-6 pt-6 pb-[calc(env(safe-area-inset-bottom)+40px)]">
          <Button variant="ink" onClick={() => (i < SLIDES.length - 1 ? go(i + 1) : finish('/login'))}>
            {i < SLIDES.length - 1 ? 'Continuer' : 'Commencer'}
          </Button>
          <Button variant="plain" size="link" onClick={() => finish('/login?existing=1')}>
            J’ai déjà un compte
          </Button>
        </div>
      </div>
    </Screen>
  )
}

/* ---------- 03 · Login ---------- */

/** Connexion = inscription. OTP auto-rempli (WebOTP API). */
export function Login() {
  const navigate = useNavigate()
  const { actions } = useStore()
  const toast = useToast()
  const [phone, setPhone] = useState('')
  const [error, setError] = useState<string>()
  const [codeError, setCodeError] = useState<string>()
  const [sent, setSent] = useState(false)
  const [code, setCode] = useState('')
  const [loading, setLoading] = useState(false)
  const [resendKey, setResendKey] = useState(0)
  const left = useCountdown(30, resendKey)

  const sendCode = async () => {
    if (phone.length < 10) {
      setError(`Il manque ${10 - phone.length} chiffre${10 - phone.length > 1 ? 's' : ''}`)
      return
    }
    setError(undefined)
    setLoading(true)
    try {
      const res = await actions.sendOtp(phone)
      setSent(true)
      setCode('')
      setResendKey((k) => k + 1)
      // En local, l'API renvoie le code (pas encore de passerelle SMS).
      toast({ tone: 'ink', text: res.debugCode ? `Code envoyé · démo : ${res.debugCode}` : 'Code envoyé par SMS', duration: res.debugCode ? 8000 : 3000 })
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setLoading(false)
    }
  }

  const verify = async (c = code) => {
    if (c.length < 6 || loading) return
    setLoading(true)
    setCodeError(undefined)
    try {
      await actions.verifyOtp(phone, c)
      navigate(takeNext(), { replace: true })
    } catch (e) {
      setCodeError(errorMessage(e))
      setCode('')
      setLoading(false)
    }
  }

  return (
    <Screen>
      <form
        className="flex flex-1 flex-col"
        onSubmit={(e) => {
          e.preventDefault()
          if (sent) verify()
          else sendCode()
        }}
      >
        <div className="flex flex-col gap-7 px-6 pt-6">
          <Wordmark className="text-[26px]" />
          <div className="flex flex-col gap-2.5">
            <h1 className="t-hero">Ton numéro, c’est tout.</h1>
            <p className="text-base leading-normal font-medium text-muted">Pas de mot de passe. On t’envoie un code par SMS.</p>
          </div>
          <PhoneInput value={phone} onChange={(v) => { setPhone(v); setError(undefined) }} error={error} autoFocus />
          {sent && (
            <div className="flex animate-fade-in flex-col gap-2.5">
              <span className="text-[13px] font-bold text-muted">Code reçu par SMS</span>
              <OtpInput value={code} onChange={(v) => { setCode(v); setCodeError(undefined) }} onComplete={verify} />
              {codeError && <span className="text-[13px] font-semibold text-err-ink">{codeError}</span>}
              {left > 0 ? (
                <span className="text-sm font-semibold text-muted">Renvoyer dans {mmss(left)}</span>
              ) : (
                <button type="button" className="self-start text-sm font-bold underline decoration-brand underline-offset-4" onClick={sendCode}>
                  Renvoyer le code
                </button>
              )}
            </div>
          )}
        </div>
        <div className="mt-auto flex flex-col gap-3.5 px-6 pt-8 pb-[calc(env(safe-area-inset-bottom)+40px)]">
          <Button type="submit" loading={loading} disabled={sent && code.length < 6}>
            Continuer
          </Button>
          <p className="text-center text-xs leading-normal font-medium text-muted">
            En continuant, tu acceptes les{' '}
            <Link to="/conditions" className="underline decoration-brand hover:text-brand-ink">conditions</Link> de Sub.ci.
          </p>
        </div>
      </form>
    </Screen>
  )
}

/* ---------- 03 bis · Prénom et nom ---------- */

/** Juste après la vérification du numéro : on sait à qui on parle. */
export function NameSetup() {
  const navigate = useNavigate()
  const toast = useToast()
  const { state, actions } = useStore()
  const [ref, setRef] = useState(() => {
    try {
      return localStorage.getItem(REF_KEY) ?? ''
    } catch {
      return ''
    }
  })
  if (state.user?.firstName && state.user?.lastName) return <Navigate to="/home" replace />

  const done = async () => {
    const code = ref.trim()
    if (code && state.referral?.canApply !== false) {
      try {
        await actions.applyReferral(code)
        toast({ tone: 'success', text: 'Code appliqué : tes frais de service sont offerts' })
      } catch (e) {
        toast({ tone: 'error', text: `Code de parrainage : ${errorMessage(e)}` })
      }
    }
    navigate(takeNext(), { replace: true })
  }

  return (
    <Screen>
      <div className="flex flex-1 flex-col px-6 pt-6 pb-[calc(env(safe-area-inset-bottom)+40px)]">
        <div className="flex flex-col gap-7">
          <Wordmark className="text-[26px]" />
          <div className="flex flex-col gap-2.5">
            <h1 className="t-hero">Comment tu t’appelles&nbsp;?</h1>
            <p className="text-base leading-normal font-medium text-muted">Ton nom apparaît auprès des membres de tes groupes et sur tes reçus.</p>
          </div>
        </div>
        <NameForm
          className="flex-1 pt-7"
          submitLabel="C’est parti"
          footer={null}
          extra={
            <label className="flex flex-col gap-1.5">
              <span className="text-[13px] font-bold text-muted">Code d’un ami (facultatif)</span>
              <input
                value={ref}
                onChange={(e) => setRef(e.target.value.toUpperCase().slice(0, 16))}
                placeholder="AYA-7K2"
                autoCapitalize="characters"
                className="h-12 rounded-tile border-[1.5px] border-line bg-white px-3.5 text-base font-bold tracking-[0.06em] outline-none placeholder:font-medium placeholder:tracking-normal placeholder:text-subtle focus:border-2 focus:border-ink"
              />
              <span className="text-[12px] font-semibold text-muted">Tes frais de service sont offerts ; ton ami gagne du crédit.</span>
            </label>
          }
          onDone={done}
        />
      </div>
    </Screen>
  )
}
