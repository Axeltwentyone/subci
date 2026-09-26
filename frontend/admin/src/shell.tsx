import { createContext, useContext, useEffect, useRef, useState, type ReactNode } from 'react'
import { Link, NavLink, Outlet, useLocation, useNavigate } from 'react-router'
import { LogoMark } from '../../src/components/ui'
import QRCode from 'qrcode'
import { LOGOUT_EVENT, api, errorText, getToken, setToken, type AdminMe, type AdminUser, type Overview, type PaymentRow } from './api'
import { Button, cx, fcfa, phone, useDebounced } from './kit'
import { disablePush } from './push'

/* ---------- Session admin ---------- */

const SETUP_KEY = 'subci:admin-setup'
const flag = {
  get: () => {
    try {
      return sessionStorage.getItem(SETUP_KEY) === '1'
    } catch {
      return false
    }
  },
  set: (on: boolean) => {
    try {
      if (on) sessionStorage.setItem(SETUP_KEY, '1')
      else sessionStorage.removeItem(SETUP_KEY)
    } catch {
      /* stockage indisponible */
    }
  },
}

type Session = {
  admin: AdminMe | null
  /** Jeton « admin-setup » : il faut d'abord configurer la double authentification. */
  setup: boolean
  /** Étape 1 : renvoie le défi si un code à 6 chiffres est demandé. */
  signIn: (email: string, password: string) => Promise<{ challenge: string } | null>
  verify: (challenge: string, code: string) => Promise<void>
  finishSetup: (code: string) => Promise<void>
  signOut: () => void
}
const SessionCtx = createContext<Session | null>(null)
export const useSession = () => useContext(SessionCtx)!

export function SessionProvider({ children }: { children: ReactNode }) {
  const [admin, setAdmin] = useState<AdminMe | null>(null)
  const [setup, setSetup] = useState(flag.get())
  const [ready, setReady] = useState(false)

  useEffect(() => {
    const out = () => (setAdmin(null), setSetup(false), flag.set(false))
    window.addEventListener(LOGOUT_EVENT, out)
    if (!getToken()) setReady(true)
    else
      api
        .me()
        .then(({ data }) => setAdmin(data))
        .catch(() => setToken(null))
        .finally(() => setReady(true))
    return () => window.removeEventListener(LOGOUT_EVENT, out)
  }, [])

  const open = (token: string, me: AdminMe, needsSetup = false) => {
    setToken(token)
    flag.set(needsSetup)
    setSetup(needsSetup)
    setAdmin(me)
  }

  const value: Session = {
    admin,
    setup,
    async signIn(email, password) {
      const res = await api.login(email, password)
      if ('challenge' in res) return { challenge: res.challenge }
      if (!res?.token) throw new Error('Réponse inattendue du serveur. Recharge la page et réessaie.')
      open(res.token, res.admin, !!res.setup)
      return null
    },
    async verify(challenge, code) {
      const res = await api.twoFactor(challenge, code)
      open(res.token, res.admin)
    },
    async finishSetup(code) {
      const res = await api.confirmTwoFactor(code)
      open(res.token, res.admin)
    },
    signOut() {
      disablePush()
        .catch(() => {})
        .finally(() => {
          api.logout().catch(() => {})
          setToken(null)
          flag.set(false)
          setSetup(false)
          setAdmin(null)
        })
    },
  }
  if (!ready) return null
  return <SessionCtx.Provider value={value}>{children}</SessionCtx.Provider>
}

function AuthCard({ children, onSubmit, footer }: { children: ReactNode; onSubmit: () => Promise<void>; footer?: ReactNode }) {
  const [err, setErr] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  return (
    <div className="scheme-dark grid min-h-dvh place-items-center bg-ink p-4 lg:p-6">
      <form
        className="flex w-full max-w-[400px] flex-col gap-5 rounded-sheet bg-sand p-6 text-ink lg:p-8"
        onSubmit={async (e) => {
          e.preventDefault()
          setBusy(true)
          setErr(null)
          try {
            await onSubmit()
          } catch (x) {
            setErr(errorText(x))
          } finally {
            setBusy(false)
          }
        }}
      >
        <div className="flex items-center gap-3">
          <LogoMark size={44} tone="brand" />
          <div className="flex flex-col">
            <span className="font-display text-2xl leading-none font-extrabold tracking-[-0.03em]">
              sub<span className="text-brand">.</span>ci
            </span>
            <span className="text-[13px] font-bold text-muted">Administration</span>
          </div>
        </div>
        {children}
        {err && (
          <p role="alert" className="text-[13px] font-semibold text-err-ink">
            {err}
          </p>
        )}
        <SubmitSlot busy={busy} />
        {footer}
      </form>
    </div>
  )
}

const SubmitCtx = createContext<{ label: string; disabled?: boolean }>({ label: 'Continuer' })
function SubmitSlot({ busy }: { busy: boolean }) {
  const { label, disabled } = useContext(SubmitCtx)
  return (
    <Button type="submit" loading={busy} disabled={disabled}>
      {label}
    </Button>
  )
}

const field = 'h-12 rounded-tile border-[1.5px] border-line bg-surface px-3.5 font-semibold outline-none focus:border-ink'

function CodeField({ value, onChange }: { value: string; onChange: (v: string) => void }) {
  return (
    <label className="flex flex-col gap-1.5">
      <span className="text-[13px] font-bold text-muted">Code à 6 chiffres</span>
      <input
        autoFocus
        inputMode="numeric"
        autoComplete="one-time-code"
        maxLength={6}
        value={value}
        onChange={(e) => onChange(e.target.value.replace(/\D/g, '').slice(0, 6))}
        className={cx(field, 'tabular h-14 text-center font-display text-[26px] tracking-[0.4em]')}
      />
    </label>
  )
}

export function Login() {
  const { signIn, verify } = useSession()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [challenge, setChallenge] = useState<string | null>(null)
  const [code, setCode] = useState('')

  if (challenge) {
    return (
      <SubmitCtx.Provider value={{ label: 'Valider', disabled: code.length !== 6 }}>
        <AuthCard
          onSubmit={() => verify(challenge, code).catch((e) => (setCode(''), Promise.reject(e)))}
          footer={
            <button type="button" className="text-sm font-bold text-muted" onClick={() => (setChallenge(null), setCode(''))}>
              ← Autre compte
            </button>
          }
        >
          <p className="text-[15px] font-semibold text-muted">Ouvre ton application d’authentification (Google Authenticator…) et saisis le code « Sub.ci Admin ».</p>
          <CodeField value={code} onChange={setCode} />
        </AuthCard>
      </SubmitCtx.Provider>
    )
  }

  return (
    <SubmitCtx.Provider value={{ label: 'Se connecter' }}>
      <AuthCard
        onSubmit={async () => {
          const res = await signIn(email, password)
          if (res) setChallenge(res.challenge)
          setPassword('')
        }}
      >
        <label className="flex flex-col gap-1.5">
          <span className="text-[13px] font-bold text-muted">E-mail</span>
          <input type="email" autoComplete="username" required value={email} onChange={(e) => setEmail(e.target.value)} className={field} />
        </label>
        <label className="flex flex-col gap-1.5">
          <span className="text-[13px] font-bold text-muted">Mot de passe</span>
          <input type="password" autoComplete="current-password" required value={password} onChange={(e) => setPassword(e.target.value)} className={field} />
        </label>
        <p className="text-center text-[12px] font-medium text-muted">Accès réservé à l’équipe Sub.ci. Double authentification obligatoire. Toutes les actions sont journalisées.</p>
      </AuthCard>
    </SubmitCtx.Provider>
  )
}

/** Première connexion : configurer la double authentification avant tout accès. */
export function TwoFactorSetup() {
  const { finishSetup, signOut, admin } = useSession()
  const [data, setData] = useState<{ secret: string; qr: string } | null>(null)
  const [err, setErr] = useState<string | null>(null)
  const [code, setCode] = useState('')

  useEffect(() => {
    api
      .setupTwoFactor()
      .then(async ({ secret, uri }) => setData({ secret, qr: await QRCode.toDataURL(uri, { margin: 1, width: 220, color: { dark: '#16130F', light: '#FFFFFF' } }) }))
      .catch((e) => setErr(errorText(e)))
  }, [])

  return (
    <SubmitCtx.Provider value={{ label: 'Activer et entrer', disabled: code.length !== 6 || !data }}>
      <AuthCard
        onSubmit={() => finishSetup(code).catch((e) => (setCode(''), Promise.reject(e)))}
        footer={
          <button type="button" className="text-sm font-bold text-muted" onClick={signOut}>
            Annuler
          </button>
        }
      >
        <div className="flex flex-col gap-1">
          <h1 className="font-display text-[22px] leading-tight font-bold">Protège ton compte</h1>
          <p className="text-sm font-semibold text-muted">
            {admin?.email} · la double authentification est obligatoire pour l’administration.
          </p>
        </div>
        <ol className="flex list-decimal flex-col gap-1 pl-5 text-sm font-semibold text-muted">
          <li>Installe Google Authenticator (ou Microsoft Authenticator, 1Password…)</li>
          <li>Scanne ce QR code avec l’application</li>
          <li>Saisis le code à 6 chiffres affiché</li>
        </ol>
        {err && <ErrorText text={err} />}
        {data ? (
          <div className="flex flex-col items-center gap-2">
            <img src={data.qr} alt="QR code à scanner avec l’application d’authentification" width={220} height={220} className="rounded-tile bg-white p-2" />
            <details className="w-full text-center text-[12px] font-semibold text-muted">
              <summary className="cursor-pointer">Impossible de scanner ? Clé à saisir</summary>
              <code className="mt-1 block font-mono text-[13px] tracking-wider break-all text-ink">{data.secret.match(/.{1,4}/g)?.join(' ')}</code>
            </details>
          </div>
        ) : (
          !err && <div className="grid h-[236px] place-items-center text-sm font-semibold text-muted">Préparation…</div>
        )}
        <CodeField value={code} onChange={setCode} />
      </AuthCard>
    </SubmitCtx.Provider>
  )
}

const ErrorText = ({ text }: { text: string }) => <p className="text-[13px] font-semibold text-err-ink">{text}</p>

/* ---------- Ossature ---------- */

type Counts = Overview['todo'] | null
const CountsCtx = createContext<{ counts: Counts; refresh: () => void }>({ counts: null, refresh: () => {} })
export const useCounts = () => useContext(CountsCtx)

type Nav = { to: string; label: string; short?: string; icon: string; count?: (c: NonNullable<Counts>) => number; urgent?: boolean; tab?: boolean }
const NAV: Nav[] = [
  { to: '/', label: 'Vue d’ensemble', short: 'Accueil', icon: '◎', tab: true },
  { to: '/offers', label: 'Offres à valider', short: 'Offres', icon: '✓', count: (c) => c.offersToReview, urgent: true, tab: true },
  { to: '/payouts', label: 'Versements', icon: '↗', count: (c) => c.payouts, urgent: true, tab: true },
  { to: '/payments', label: 'Paiements', icon: '₣', tab: true },
  { to: '/disputes', label: 'Soucis signalés', short: 'Soucis', icon: '⚑', count: (c) => c.disputes, urgent: true },
  { to: '/requests', label: 'Demandes', icon: '⧗', count: (c) => c.requests },
  { to: '/users', label: 'Utilisateurs', icon: '◉' },
  { to: '/catalog', label: 'Catalogue', icon: '▦' },
  { to: '/audit', label: 'Journal', icon: '≡' },
  { to: '/notifications', label: 'Notifications', icon: '◔' },
]

function Badge({ n, urgent }: { n: number; urgent?: boolean }) {
  if (n <= 0) return null
  return <span className={cx('tabular min-w-5 rounded-full px-1.5 text-center text-[11px] leading-5 font-extrabold', urgent ? 'bg-brand text-on-accent' : 'bg-white/15')}>{n}</span>
}

export function Shell() {
  const { admin, signOut } = useSession()
  const [counts, setCounts] = useState<Counts>(null)
  const [more, setMore] = useState(false)
  const { pathname } = useLocation()
  const refresh = () => api.overview().then((o) => setCounts(o.todo)).catch(() => {})
  useEffect(() => {
    refresh()
    const t = setInterval(refresh, 30_000)
    // Retour dans l'app installée : compteurs à jour tout de suite.
    const vis = () => document.visibilityState === 'visible' && refresh()
    document.addEventListener('visibilitychange', vis)
    return () => (clearInterval(t), document.removeEventListener('visibilitychange', vis))
  }, [])
  useEffect(() => {
    setMore(false)
    window.scrollTo(0, 0)
  }, [pathname])

  const count = (n: Nav) => (counts && n.count ? n.count(counts) || 0 : 0)
  const moreCount = NAV.filter((n) => !n.tab).reduce((a, n) => a + count(n), 0)
  const moreActive = NAV.some((n) => !n.tab && pathname.startsWith(n.to))

  return (
    <CountsCtx.Provider value={{ counts, refresh }}>
      <div className="min-h-dvh bg-canvas lg:grid lg:grid-cols-[248px_1fr]">
        {/* Barre latérale (ordinateur) */}
        <nav className="scheme-dark sticky top-0 flex h-dvh flex-col gap-1 bg-ink px-3 py-5 text-sand max-lg:hidden" aria-label="Administration">
          <div className="flex items-center gap-2.5 px-3 pb-6">
            <LogoMark size={34} tone="sand" />
            <span className="flex flex-col">
              <span className="font-display text-[20px] leading-none font-extrabold tracking-[-0.03em]">
                sub<span className="text-brand">.</span>ci
              </span>
              <span className="text-[11px] font-bold tracking-wider text-ink-muted uppercase">Admin</span>
            </span>
          </div>
          {NAV.map((n) => (
            <NavLink
              key={n.to}
              to={n.to}
              end={n.to === '/'}
              className={({ isActive }) => cx('flex h-11 items-center gap-3 rounded-[12px] px-3 text-[14px] font-bold transition-colors', isActive ? 'bg-white/10 text-white' : 'text-ink-soft hover:bg-white/5')}
            >
              {({ isActive }) => (
                <>
                  <span className={cx('grid w-5 place-items-center text-base', isActive && 'text-brand')} aria-hidden>
                    {n.icon}
                  </span>
                  <span className="flex-1">{n.label}</span>
                  <Badge n={count(n)} urgent={n.urgent} />
                </>
              )}
            </NavLink>
          ))}
          <div className="mt-auto flex items-center gap-2.5 rounded-[12px] bg-white/5 px-3 py-3">
            <span className="grid size-9 place-items-center rounded-full bg-brand font-display font-extrabold text-ink">{admin?.name.charAt(0).toUpperCase()}</span>
            <span className="flex min-w-0 flex-1 flex-col">
              <span className="truncate text-[13px] font-bold">{admin?.name}</span>
              <span className="truncate text-[11px] font-semibold text-ink-muted">{admin?.email}</span>
            </span>
            <button type="button" onClick={signOut} className="rounded-md px-2 py-1 text-[12px] font-bold text-ink-soft hover:bg-white/10" title="Se déconnecter">
              Sortir
            </button>
          </div>
        </nav>

        <div className="min-w-0">
          <div className="sticky top-0 z-30 flex items-center gap-3 border-b border-line bg-canvas/90 px-4 pt-[env(safe-area-inset-top)] backdrop-blur lg:gap-4 lg:px-8">
            <div className="flex h-14 w-full items-center gap-3 lg:h-16 lg:gap-4">
              <Link to="/" className="lg:hidden" aria-label="Accueil">
                <LogoMark size={32} tone="brand" />
              </Link>
              <GlobalSearch />
              <span className="ml-auto text-[13px] font-semibold text-muted max-lg:hidden">{new Intl.DateTimeFormat('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' }).format(new Date())}</span>
            </div>
          </div>
          <main className="mx-auto flex max-w-[1320px] flex-col gap-5 px-4 pt-5 pb-[calc(88px+env(safe-area-inset-bottom))] lg:gap-6 lg:px-8 lg:py-7">
            <Outlet />
          </main>
        </div>

        {/* Barre d'onglets (mobile) */}
        <nav className="scheme-dark fixed inset-x-0 bottom-0 z-30 grid grid-cols-5 bg-ink px-1 pt-1.5 pb-[max(0.5rem,env(safe-area-inset-bottom))] text-sand lg:hidden" aria-label="Administration">
          {NAV.filter((n) => n.tab).map((n) => (
            <NavLink key={n.to} to={n.to} end={n.to === '/'} className={({ isActive }) => cx('flex flex-col items-center gap-0.5 rounded-[12px] py-1.5', isActive ? 'text-white' : 'text-ink-soft')}>
              {({ isActive }) => (
                <>
                  <span className="relative text-[19px] leading-6">
                    <span className={cx(isActive && 'text-brand')}>{n.icon}</span>
                    <span className="absolute -top-1.5 left-3.5">
                      <Badge n={count(n)} urgent={n.urgent} />
                    </span>
                  </span>
                  <span className="text-[11px] font-bold">{n.short ?? n.label}</span>
                </>
              )}
            </NavLink>
          ))}
          <button type="button" onClick={() => setMore(true)} className={cx('flex flex-col items-center gap-0.5 rounded-[12px] py-1.5', moreActive ? 'text-white' : 'text-ink-soft')}>
            <span className="relative text-[19px] leading-6">
              <span className={cx(moreActive && 'text-brand')}>⋯</span>
              <span className="absolute -top-1.5 left-3.5">
                <Badge n={moreCount} />
              </span>
            </span>
            <span className="text-[11px] font-bold">Plus</span>
          </button>
        </nav>

        {more && (
          <div className="fixed inset-0 z-40 lg:hidden">
            <div className="absolute inset-0 animate-fade-in bg-scrim/50" onClick={() => setMore(false)} aria-hidden />
            <div role="dialog" aria-modal="true" aria-label="Plus" className="absolute inset-x-0 bottom-0 flex animate-sheet-in flex-col gap-1 rounded-t-sheet bg-sand px-3 pt-3 pb-[max(1rem,env(safe-area-inset-bottom))]">
              <span className="mx-auto mb-2 h-1 w-10 rounded-full bg-line" aria-hidden />
              {NAV.filter((n) => !n.tab).map((n) => (
                <NavLink key={n.to} to={n.to} className={({ isActive }) => cx('flex h-13 items-center gap-3 rounded-[14px] px-4 text-[15px] font-bold', isActive ? 'bg-surface' : 'active:bg-surface/60')}>
                  <span className="grid w-6 place-items-center text-lg text-muted" aria-hidden>
                    {n.icon}
                  </span>
                  <span className="flex-1">{n.label}</span>
                  {count(n) > 0 && <span className="tabular rounded-full bg-ink px-2 text-[12px] leading-6 font-extrabold text-sand">{count(n)}</span>}
                </NavLink>
              ))}
              <div className="mt-2 flex items-center gap-3 rounded-[14px] bg-surface px-4 py-3">
                <span className="grid size-9 place-items-center rounded-full bg-brand font-display font-extrabold text-ink">{admin?.name.charAt(0).toUpperCase()}</span>
                <span className="flex min-w-0 flex-1 flex-col">
                  <span className="truncate text-[14px] font-bold">{admin?.name}</span>
                  <span className="truncate text-[12px] font-semibold text-muted">{admin?.email}</span>
                </span>
                <button type="button" onClick={signOut} className="rounded-[10px] px-3 py-2 text-[13px] font-bold text-err-ink active:bg-err-soft">
                  Se déconnecter
                </button>
              </div>
            </div>
          </div>
        )}
      </div>
    </CountsCtx.Provider>
  )
}

/** Recherche partout : nom, numéro, code parrain, référence de paiement. Raccourci « / ». */
function GlobalSearch() {
  const navigate = useNavigate()
  const [q, setQ] = useState('')
  const [open, setOpen] = useState(false)
  const [res, setRes] = useState<{ users: AdminUser[]; payments: PaymentRow[] } | null>(null)
  const debounced = useDebounced(q.trim(), 200)
  const input = useRef<HTMLInputElement>(null)

  useEffect(() => {
    const k = (e: KeyboardEvent) => {
      if (e.key === '/' && document.activeElement?.tagName !== 'INPUT' && document.activeElement?.tagName !== 'TEXTAREA') {
        e.preventDefault()
        input.current?.focus()
      }
    }
    window.addEventListener('keydown', k)
    return () => window.removeEventListener('keydown', k)
  }, [])

  useEffect(() => {
    if (debounced.length < 2) return setRes(null)
    let alive = true
    api.search(debounced).then((r) => alive && setRes(r)).catch(() => {})
    return () => {
      alive = false
    }
  }, [debounced])

  const go = (to: string) => {
    setOpen(false)
    setQ('')
    navigate(to)
  }

  return (
    <div className="relative min-w-0 flex-1 lg:w-[420px] lg:flex-none">
      <input
        ref={input}
        type="search"
        value={q}
        onChange={(e) => {
          setQ(e.target.value)
          setOpen(true)
        }}
        onFocus={() => setOpen(true)}
        onBlur={() => setTimeout(() => setOpen(false), 150)}
        placeholder="Nom, numéro, référence…"
        className="h-10 w-full rounded-[12px] border-[1.5px] border-line bg-surface px-3.5 text-sm font-semibold outline-none placeholder:font-medium placeholder:text-subtle focus:border-ink"
      />
      {open && res && (
        <div className="absolute top-12 z-40 flex max-h-[min(420px,70dvh)] max-lg:fixed max-lg:inset-x-3 max-lg:top-[calc(env(safe-area-inset-top)+3.75rem)] lg:inset-x-0 flex-col overflow-y-auto rounded-card border border-line bg-surface p-2 shadow-xl">
          {res.users.length === 0 && res.payments.length === 0 && <p className="px-3 py-4 text-sm font-medium text-muted">Aucun résultat.</p>}
          {res.users.length > 0 && <span className="px-3 pt-2 pb-1 text-[11px] font-extrabold tracking-wider text-muted uppercase">Utilisateurs</span>}
          {res.users.map((u) => (
            <button key={u.id} type="button" onMouseDown={() => go(`/users/${u.id}`)} className="flex items-center gap-3 rounded-[10px] px-3 py-2 text-left hover:bg-sand">
              <span className="flex-1 text-sm font-bold">{u.name ?? 'Sans nom'}</span>
              <span className="tabular text-[13px] font-semibold text-muted">{phone(u.phone)}</span>
              {u.suspendedAt && <span className="text-[11px] font-extrabold text-err">Suspendu</span>}
            </button>
          ))}
          {res.payments.length > 0 && <span className="px-3 pt-3 pb-1 text-[11px] font-extrabold tracking-wider text-muted uppercase">Paiements</span>}
          {res.payments.map((p) => (
            <button key={p.id} type="button" onMouseDown={() => go(`/payments?q=${encodeURIComponent(p.ref)}`)} className="flex items-center gap-3 rounded-[10px] px-3 py-2 text-left hover:bg-sand">
              <span className="tabular text-sm font-bold">{p.ref}</span>
              <span className="flex-1 truncate text-[13px] font-semibold text-muted">{p.label}</span>
              <span className="tabular text-sm font-bold">{fcfa(p.amount)}</span>
            </button>
          ))}
        </div>
      )}
    </div>
  )
}
