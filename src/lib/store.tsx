import { createContext, useContext, useEffect, useMemo, useReducer, useRef, type ReactNode } from 'react'
import { ApiError, UNAUTHORIZED_EVENT, api, getToken, setToken, toNotif, toOffer, toPayment, toPending, toSub, type ApiHost, type ApiUser, type Bootstrap, type PendingPayment } from './api'
import { getService, setCatalog, type PayMethodId, type Service } from './data'
import { daysLeft } from './format'

export type SubStatus = 'active' | 'due' | 'pending' | 'expired'

export type UserSub = {
  id: string
  serviceId: string
  state: 'active' | 'pending' | 'expired'
  startAt: number
  endAt: number
  activatesAt?: number
  autoRenew: boolean
  method: PayMethodId
  profile: string
  email: string
  password: string
  pin?: string
}

export type Notif = {
  id: string
  kind: 'ok' | 'pay' | 'due' | 'seat' | 'host'
  title: string
  body: string
  at: number
  unread: boolean
  action?: { label: string; to: string }
}

export type Payment = {
  id: string
  at: number
  label: string
  amount: number
  method: PayMethodId
  ref: string
  direction: 'out' | 'in'
}

export type Member = { id: string; name: string; color: string; invitePending?: boolean; joinedAt?: string | null }

export type HostOffer = {
  id: string
  serviceId: string
  planLabel: string
  seats: number
  price: number
  mode: 'credentials' | 'family'
  members: Member[]
  pendingInvite?: string
  status: 'review' | 'live' | 'paused' | 'closed'
  hasCredentials: boolean
  email?: string
}

export type Settings = {
  notifDue: boolean
  notifSeats: boolean
  notifPromo: boolean
  biometric: boolean
  hideAccess: 'always' | 'never'
  dataSaver: 'auto' | 'on' | 'off'
}

export type User = { id: string; name: string | null; firstName: string | null; lastName: string | null; phone: string; referralCode: string }

export type State = {
  /* Données serveur (mises en cache pour le hors-ligne) */
  user: User | null
  services: Service[]
  subs: UserSub[]
  notifs: Notif[]
  payments: Payment[]
  balance: number
  monthGain: number
  payout: { method: PayMethodId; phone: string }
  offers: HostOffer[]
  settings: Settings
  lastMethod: PayMethodId
  lastSync: number
  syncing: boolean
  /* Préférences de l'appareil */
  onboarded: boolean
  recent: string[]
  purchases: number
  installDismissedAt: number
  notifPromptDone: boolean
}

const KEY = 'subci:v2'

const DEFAULT_SETTINGS: Settings = { notifDue: true, notifSeats: true, notifPromo: false, biometric: true, hideAccess: 'always', dataSaver: 'auto' }

function empty(): State {
  return {
    user: null,
    services: [],
    subs: [],
    notifs: [],
    payments: [],
    balance: 0,
    monthGain: 0,
    payout: { method: 'wave', phone: '' },
    offers: [],
    settings: DEFAULT_SETTINGS,
    lastMethod: 'om',
    lastSync: 0,
    syncing: false,
    onboarded: false,
    recent: [],
    purchases: 0,
    installDismissedAt: 0,
    notifPromptDone: false,
  }
}

function load(): State {
  try {
    const raw = localStorage.getItem(KEY)
    if (raw) {
      const s = { ...empty(), ...JSON.parse(raw), syncing: false } as State
      // Session expirée côté appareil : on ne garde que les préférences.
      if (!getToken()) return { ...empty(), onboarded: s.onboarded, recent: s.recent, installDismissedAt: s.installDismissedAt, notifPromptDone: s.notifPromptDone }
      setCatalog(s.services)
      return s
    }
  } catch {
    /* stockage indisponible */
  }
  return empty()
}

function userPart(u: ApiUser): Partial<State> {
  return {
    user: { id: u.id, name: u.name, firstName: u.firstName, lastName: u.lastName, phone: u.phone, referralCode: u.referralCode },
    balance: u.balance,
    payout: u.payout,
    lastMethod: u.lastMethod,
    settings: u.settings,
  }
}

function hostPart(h: ApiHost): Partial<State> {
  return { balance: h.balance, monthGain: h.monthGain, offers: h.offers.map(toOffer) }
}

type Action =
  | { type: 'patch'; patch: Partial<State> }
  | { type: 'hydrate'; data: Bootstrap }
  | { type: 'signedOut' }
  | { type: 'archive'; id: string }
  | { type: 'restore'; notif: Notif; index: number }
  | { type: 'readAll' }
  | { type: 'read'; id: string }
  | { type: 'setting'; key: keyof Settings; value: Settings[keyof Settings] }
  | { type: 'autoRenew'; id: string; value: boolean }
  | { type: 'invited'; offerId: string }
  | { type: 'recent'; q: string }
  | { type: 'clearRecent' }
  | { type: 'installDismissed' }
  | { type: 'notifPromptDone' }

function reducer(s: State, a: Action): State {
  switch (a.type) {
    case 'patch':
      return { ...s, ...a.patch }
    case 'hydrate': {
      const d = a.data
      const services = d.services
      setCatalog(services)
      return {
        ...s,
        ...userPart(d.user),
        ...hostPart(d.host),
        services,
        subs: d.subscriptions.map(toSub),
        payments: d.payments.map(toPayment),
        notifs: d.notifications.map(toNotif),
        lastSync: Date.now(),
        syncing: false,
      }
    }
    case 'signedOut':
      return { ...empty(), services: s.services, onboarded: true, recent: s.recent, installDismissedAt: s.installDismissedAt, notifPromptDone: s.notifPromptDone }
    case 'archive':
      return { ...s, notifs: s.notifs.filter((n) => n.id !== a.id) }
    case 'restore': {
      const notifs = [...s.notifs]
      notifs.splice(a.index, 0, a.notif)
      return { ...s, notifs }
    }
    case 'readAll':
      return { ...s, notifs: s.notifs.map((n) => ({ ...n, unread: false })) }
    case 'read':
      return { ...s, notifs: s.notifs.map((n) => (n.id === a.id ? { ...n, unread: false } : n)) }
    case 'setting':
      return { ...s, settings: { ...s.settings, [a.key]: a.value } }
    case 'autoRenew':
      return { ...s, subs: s.subs.map((x) => (x.id === a.id ? { ...x, autoRenew: a.value } : x)) }
    case 'invited':
      return { ...s, offers: s.offers.map((o) => (o.id === a.offerId ? { ...o, pendingInvite: undefined } : o)) }
    case 'recent': {
      const q = a.q.trim()
      if (!q) return s
      return { ...s, recent: [q, ...s.recent.filter((r) => r.toLowerCase() !== q.toLowerCase())].slice(0, 5) }
    }
    case 'clearRecent':
      return { ...s, recent: [] }
    case 'installDismissed':
      return { ...s, installDismissedAt: Date.now() }
    case 'notifPromptDone':
      return { ...s, notifPromptDone: true }
  }
}

/* ---------- Actions (API + mise à jour optimiste) ---------- */

function makeActions(dispatch: (a: Action) => void, get: () => State) {
  const sync = async () => {
    if (!getToken()) return
    dispatch({ type: 'patch', patch: { syncing: true } })
    try {
      dispatch({ type: 'hydrate', data: await api.bootstrap() })
    } catch (e) {
      dispatch({ type: 'patch', patch: { syncing: false } })
      throw e
    }
  }

  const replaceOffer = (offer: HostOffer) => {
    dispatch({ type: 'patch', patch: { offers: get().offers.map((o) => (o.id === offer.id ? offer : o)) } })
    sync().catch(() => {})
  }

  /** Optimiste : applique tout de suite, annule si l'API refuse. */
  const optimistic = async (apply: Action, revert: Action, call: () => Promise<unknown>) => {
    dispatch(apply)
    try {
      await call()
    } catch (e) {
      dispatch(revert)
      throw e
    }
  }

  return {
    sync,
    onboarded: () => dispatch({ type: 'patch', patch: { onboarded: true } }),

    sendOtp: (phone: string) => api.sendOtp(phone),
    async verifyOtp(phone: string, code: string) {
      const res = await api.verifyOtp(phone, code)
      setToken(res.token)
      dispatch({ type: 'patch', patch: { ...userPart(res.user), onboarded: true } })
      await sync().catch(() => {})
      return res
    },
    async logout() {
      await api.logout().catch(() => {})
      setToken(null)
      dispatch({ type: 'signedOut' })
    },

    async checkout(serviceId: string, months: number, method: PayMethodId, phone: string): Promise<PendingPayment> {
      const { data } = await api.checkout({ serviceId, months, method, phone: method === 'card' ? undefined : phone })
      dispatch({ type: 'patch', patch: { lastMethod: method } })
      return toPending(data)
    },
    async payment(ref: string) {
      const p = toPending((await api.payment(ref)).data)
      if (p.status === 'succeeded') {
        dispatch({ type: 'patch', patch: { purchases: get().purchases + 1 } })
        await sync().catch(() => {})
      }
      return p
    },
    resendPayment: async (ref: string) => toPending((await api.resendPayment(ref)).data),
    cancelPayment: (ref: string) => api.cancelPayment(ref).catch(() => {}),

    read: (id: string) => {
      dispatch({ type: 'read', id })
      api.readNotif(id).catch(() => {})
    },
    readAll: () => optimistic({ type: 'readAll' }, { type: 'patch', patch: { notifs: get().notifs } }, api.readAllNotifs),
    archive: (n: Notif) => {
      const index = get().notifs.findIndex((x) => x.id === n.id)
      return optimistic({ type: 'archive', id: n.id }, { type: 'restore', notif: n, index }, () => api.archiveNotif(n.id)).then(() => index)
    },
    restore: (n: Notif, index: number) => optimistic({ type: 'restore', notif: n, index }, { type: 'archive', id: n.id }, () => api.restoreNotif(n.id)),

    async setNames(firstName: string, lastName: string) {
      const { data } = await api.updateMe({ firstName, lastName })
      dispatch({ type: 'patch', patch: userPart(data) })
    },
    setSetting<K extends keyof Settings>(key: K, value: Settings[K]) {
      const prev = get().settings[key]
      return optimistic({ type: 'setting', key, value }, { type: 'setting', key, value: prev }, () => api.updateMe({ settings: { [key]: value } }))
    },
    setAutoRenew: (id: string, value: boolean) =>
      optimistic({ type: 'autoRenew', id, value }, { type: 'autoRenew', id, value: !value }, () => api.setAutoRenew(id, value)),
    cancelSubscription: (id: string) =>
      optimistic({ type: 'autoRenew', id, value: false }, { type: 'autoRenew', id, value: true }, () => api.cancelSubscription(id)),

    async publishOffer(form: FormData) {
      const { data } = await api.publishOffer(form)
      dispatch({ type: 'patch', patch: { offers: [toOffer(data), ...get().offers] } })
      sync().catch(() => {})
    },
    /** Remplace l'offre par la version renvoyée par l'API, puis resynchronise (solde, catalogue). */
    async updateOffer(offerId: string, body: Parameters<typeof api.updateOffer>[1]) {
      replaceOffer(toOffer((await api.updateOffer(offerId, body)).data))
    },
    async removeMember(offerId: string, memberId: string) {
      replaceOffer(toOffer((await api.removeMember(offerId, memberId)).data))
    },
    async setOfferStatus(offerId: string, action: 'pause' | 'resume' | 'close') {
      replaceOffer(toOffer((await api.offerStatus(offerId, action)).data))
    },
    invite: (offerId: string) => {
      const offers = get().offers
      return optimistic({ type: 'invited', offerId }, { type: 'patch', patch: { offers } }, () => api.invite(offerId))
    },
    async withdraw(amount: number) {
      const { host } = await api.withdraw(amount)
      dispatch({ type: 'patch', patch: hostPart(host) })
      sync().catch(() => {})
    },

    recent: (q: string) => dispatch({ type: 'recent', q }),
    clearRecent: () => dispatch({ type: 'clearRecent' }),
    installDismissed: () => dispatch({ type: 'installDismissed' }),
    notifPromptDone: () => dispatch({ type: 'notifPromptDone' }),
  }
}

export type Actions = ReturnType<typeof makeActions>

type Ctx = { state: State; actions: Actions }
const StoreCtx = createContext<Ctx | null>(null)

export function StoreProvider({ children }: { children: ReactNode }) {
  const [state, dispatch] = useReducer(reducer, undefined, load)
  const ref = useRef(state)
  ref.current = state
  const actions = useMemo(() => makeActions(dispatch, () => ref.current), [])

  useEffect(() => {
    try {
      localStorage.setItem(KEY, JSON.stringify({ ...state, syncing: false }))
    } catch {
      /* quota / navigation privée */
    }
  }, [state])

  // Synchro : au démarrage, au retour en ligne, au retour sur l'app.
  useEffect(() => {
    const run = () => actions.sync().catch(() => {})
    run()
    const onVisible = () => document.visibilityState === 'visible' && Date.now() - ref.current.lastSync > 60_000 && run()
    const onUnauthorized = () => dispatch({ type: 'signedOut' })
    window.addEventListener('online', run)
    document.addEventListener('visibilitychange', onVisible)
    window.addEventListener(UNAUTHORIZED_EVENT, onUnauthorized)
    return () => {
      window.removeEventListener('online', run)
      document.removeEventListener('visibilitychange', onVisible)
      window.removeEventListener(UNAUTHORIZED_EVENT, onUnauthorized)
    }
  }, [actions])

  // Badge d'icône = notifications non lues (Badging API)
  const unread = state.notifs.filter((n) => n.unread).length
  useEffect(() => {
    const nav = navigator as Navigator & { setAppBadge?: (n: number) => Promise<void>; clearAppBadge?: () => Promise<void> }
    if (unread > 0) nav.setAppBadge?.(unread).catch(() => {})
    else nav.clearAppBadge?.().catch(() => {})
  }, [unread])

  const value = useMemo(() => ({ state, actions }), [state, actions])
  return <StoreCtx.Provider value={value}>{children}</StoreCtx.Provider>
}

export function useStore() {
  const ctx = useContext(StoreCtx)
  if (!ctx) throw new Error('useStore hors de StoreProvider')
  return ctx
}

/** Message lisible d'une erreur API (ou réseau). */
export function errorMessage(e: unknown, fallback = 'Un souci est survenu. Réessaie.') {
  if (e instanceof ApiError) return Object.values(e.errors)[0]?.[0] ?? e.message
  return fallback
}

export function subStatus(sub: UserSub, now = Date.now()): SubStatus {
  if (sub.state === 'expired') return 'expired'
  if (sub.state === 'pending') return 'pending'
  return daysLeft(sub.endAt, now) <= 5 ? 'due' : 'active'
}

/** Tri par urgence : à renouveler, activation, actifs (fin la plus proche d'abord). */
export function byUrgency(a: UserSub, b: UserSub) {
  const rank = { due: 0, pending: 1, active: 2, expired: 3 }
  const d = rank[subStatus(a)] - rank[subStatus(b)]
  return d !== 0 ? d : a.endAt - b.endAt
}

export function hostNet(price: number, seats: number) {
  const gross = price * seats
  return { gross, fee: Math.round(gross * 0.1), net: Math.round(gross * 0.9) }
}

export function useUnread() {
  return useStore().state.notifs.filter((n) => n.unread).length
}

export function useActiveSubs() {
  const { state } = useStore()
  return useMemo(() => state.subs.filter((s) => s.state !== 'expired').sort(byUrgency), [state.subs])
}

export function useSavings() {
  const { state } = useStore()
  return useMemo(() => {
    const active = state.subs.filter((s) => s.state !== 'expired')
    let monthly = 0
    let saved = 0
    for (const s of active) {
      const svc = getService(s.serviceId)
      if (!svc) continue
      monthly += svc.price
      saved += svc.fullPrice - svc.price
    }
    return { monthly, saved, count: active.length }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [state.subs, state.services])
}

