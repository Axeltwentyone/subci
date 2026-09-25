import type { Category, Device, HostPlan, PayMethodId, PublicOffer, Service } from './data'
import type { HostOffer, HostRequest, JoinRequest, Notif, Payment, Settings, UserSub } from './store'

/** En dev, Vite proxifie /api vers Laravel (voir vite.config.ts). */
const BASE = (import.meta.env.VITE_API_URL as string | undefined) ?? '/api/v1'
const TOKEN_KEY = 'subci:token'

export function getToken(): string | null {
  try {
    return localStorage.getItem(TOKEN_KEY)
  } catch {
    return null
  }
}

export function setToken(token: string | null) {
  try {
    if (token) localStorage.setItem(TOKEN_KEY, token)
    else localStorage.removeItem(TOKEN_KEY)
  } catch {
    /* stockage indisponible */
  }
}

export class ApiError extends Error {
  status: number
  errors: Record<string, string[]>
  constructor(status: number, message: string, errors: Record<string, string[]> = {}) {
    super(message)
    this.status = status
    this.errors = errors
  }
  /** Premier message d'un champ, ou le message global. */
  field(name: string) {
    return this.errors[name]?.[0]
  }
}

/** Émis quand le jeton est refusé : le store déconnecte. */
export const UNAUTHORIZED_EVENT = 'subci:unauthorized'

async function request<T>(method: string, path: string, body?: unknown): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  const token = getToken()
  if (token) headers.Authorization = `Bearer ${token}`
  const isForm = body instanceof FormData
  if (body !== undefined && !isForm) headers['Content-Type'] = 'application/json'

  let res: Response
  try {
    res = await fetch(BASE + path, { method, headers, body: isForm ? body : body === undefined ? undefined : JSON.stringify(body) })
  } catch {
    throw new ApiError(0, 'Pas de réseau. Réessaie dès que la connexion revient.')
  }

  const data = res.status === 204 ? null : await res.json().catch(() => null)
  if (!res.ok) {
    if (res.status === 401 && token) {
      setToken(null)
      window.dispatchEvent(new Event(UNAUTHORIZED_EVENT))
    }
    const message = data?.message ?? (res.status >= 500 ? 'Un souci de notre côté. Réessaie dans un instant.' : 'Requête refusée.')
    throw new ApiError(res.status, message, data?.errors ?? {})
  }
  return data as T
}

/* ---------- Formats API → store ---------- */

const ms = (iso: string | null | undefined) => (iso ? Date.parse(iso) : undefined)

type ApiSub = {
  id: string; serviceId: string; price: number; hostName: string | null; state: UserSub['state']; startAt: string; endAt: string; activatesAt: string | null
  autoRenew: boolean; method: PayMethodId; profile: string | null; email: string | null; password: string | null; pin: string | null
}
type ApiPayment = {
  id: string; ref: string; type: 'subscription' | 'earning' | 'withdrawal'; direction: 'in' | 'out'
  status: 'pending' | 'succeeded' | 'failed' | 'expired'; label: string; amount: number; months: number | null
  method: PayMethodId; phone: string | null; serviceId: string | null; subscriptionId: string | null
  hostName: string | null; joinStatus: JoinRequest['status'] | null
  periodStart: string | null; periodEnd: string | null; expiresAt: string | null; at: string; checkoutUrl: string | null
}
type ApiNotif = { id: string; kind: Notif['kind']; title: string; body: string; action: Notif['action'] | null; at: string; unread: boolean }
type ApiOffer = Omit<HostOffer, 'pendingInvite' | 'email' | 'requests'> & { pendingInvite: string | null; monthlyNet: number; email: string | null; requests?: ApiRequest[] }
type ApiRequest = {
  id: string; offerId: string; serviceId: string; status: JoinRequest['status']; amount: number; months: number; expiresAt: string; at: string
  member: { name: string; memberSince: string | null; paidCount: number; removalsCount: number } | null
  host: { name: string; plan: string } | null
}
export type ApiUser = {
  id: string; name: string | null; firstName: string | null; lastName: string | null; phone: string; referralCode: string; balance: number
  payout: { method: PayMethodId; phone: string }; lastMethod: PayMethodId; settings: Settings
}
type ApiService = Omit<Service, 'category'> & { category: Category }
export type ApiHost = { balance: number; monthGain: number; offers: ApiOffer[] }

export const toSub = (s: ApiSub): UserSub => ({
  id: s.id,
  serviceId: s.serviceId,
  price: s.price,
  hostName: s.hostName ?? undefined,
  state: s.state,
  startAt: ms(s.startAt)!,
  endAt: ms(s.endAt)!,
  activatesAt: ms(s.activatesAt),
  autoRenew: s.autoRenew,
  method: s.method,
  profile: s.profile ?? '',
  email: s.email ?? '',
  password: s.password ?? '',
  pin: s.pin ?? undefined,
})

export const toPayment = (p: ApiPayment): Payment => ({
  id: p.id,
  at: ms(p.at)!,
  label: p.label,
  amount: p.amount,
  method: p.method,
  ref: p.ref,
  direction: p.direction,
  pending: p.status === 'pending',
})

export const toNotif = (n: ApiNotif): Notif => ({
  id: n.id,
  kind: n.kind,
  title: n.title,
  body: n.body,
  at: ms(n.at)!,
  unread: n.unread,
  action: n.action ?? undefined,
})

export const toOffer = (o: ApiOffer): HostOffer => ({
  id: o.id,
  serviceId: o.serviceId,
  planLabel: o.planLabel,
  seats: o.seats,
  price: o.price,
  mode: o.mode,
  members: o.members,
  pendingInvite: o.pendingInvite ?? undefined,
  status: o.status,
  hasCredentials: o.hasCredentials,
  email: o.email ?? undefined,
  plan: o.plan,
  devices: o.devices ?? [],
  quality: o.quality,
  maxSeats: o.maxSeats,
  allowedDevices: o.allowedDevices ?? [],
  reco: o.reco,
  requests: (o.requests ?? []).map(toHostRequest),
})

export const toHostRequest = (r: ApiRequest): HostRequest => ({
  id: r.id,
  amount: r.amount,
  months: r.months,
  expiresAt: ms(r.expiresAt)!,
  at: ms(r.at)!,
  member: {
    name: r.member?.name ?? 'Membre',
    memberSince: ms(r.member?.memberSince),
    paidCount: r.member?.paidCount ?? 0,
    removalsCount: r.member?.removalsCount ?? 0,
  },
})

export const toJoinRequest = (r: ApiRequest): JoinRequest => ({
  id: r.id,
  offerId: r.offerId,
  serviceId: r.serviceId,
  status: r.status,
  amount: r.amount,
  months: r.months,
  expiresAt: ms(r.expiresAt)!,
  hostName: r.host?.name ?? '',
  plan: r.host?.plan ?? '',
})

/** Paiement en cours (écran « Valide sur ton téléphone » / succès). */
export type PendingPayment = {
  ref: string
  status: ApiPayment['status']
  amount: number
  months: number
  method: PayMethodId
  phone: string
  serviceId: string
  subscriptionId?: string
  /** Nouvel arrivant : l'hôte doit encore accepter. */
  hostName?: string
  joinStatus?: JoinRequest['status']
  periodStart?: number
  periodEnd?: number
  expiresAt?: number
  /** Page de la passerelle (GeniusPay) où valider le paiement */
  checkoutUrl?: string
}

export const toPending = (p: ApiPayment): PendingPayment => ({
  ref: p.ref,
  status: p.status,
  amount: p.amount,
  months: p.months ?? 1,
  method: p.method,
  phone: p.phone ?? '',
  serviceId: p.serviceId ?? '',
  subscriptionId: p.subscriptionId ?? undefined,
  hostName: p.hostName ?? undefined,
  joinStatus: p.joinStatus ?? undefined,
  periodStart: ms(p.periodStart),
  periodEnd: ms(p.periodEnd),
  expiresAt: ms(p.expiresAt),
  checkoutUrl: p.checkoutUrl ?? undefined,
})

/* ---------- Endpoints ---------- */

export type Bootstrap = {
  user: ApiUser
  services: ApiService[]
  subscriptions: ApiSub[]
  payments: ApiPayment[]
  notifications: ApiNotif[]
  host: ApiHost
  requests: ApiRequest[]
}

type Data<T> = { data: T }

export const api = {
  sendOtp: (phone: string) => request<{ sent: boolean; ttl: number; debugCode: string | null }>('POST', '/auth/otp', { phone }),
  verifyOtp: (phone: string, code: string) => request<{ token: string; user: ApiUser; isNew: boolean }>('POST', '/auth/verify', { phone, code }),
  logout: () => request<{ ok: boolean }>('POST', '/auth/logout'),

  bootstrap: () => request<Bootstrap>('GET', '/bootstrap'),
  services: () => request<Data<ApiService[]>>('GET', '/services'),
  offers: (serviceId: string) => request<Data<PublicOffer[]>>('GET', `/services/${serviceId}/offers`),
  plans: () => request<Data<Record<string, HostPlan[]>>>('GET', '/host/plans'),
  updateMe: (body: { firstName?: string; lastName?: string; settings?: Partial<Settings> }) => request<Data<ApiUser>>('PATCH', '/me', body),

  setAutoRenew: (id: string, autoRenew: boolean) => request<Data<ApiSub>>('PATCH', `/subscriptions/${id}`, { autoRenew }),
  cancelSubscription: (id: string) => request<Data<ApiSub>>('POST', `/subscriptions/${id}/cancel`),

  checkout: (body: { serviceId: string; months: number; method: PayMethodId; phone?: string; offerId?: string }) =>
    request<Data<ApiPayment>>('POST', '/payments', body),
  payment: (ref: string) => request<Data<ApiPayment>>('GET', `/payments/${ref}`),
  resendPayment: (ref: string) => request<Data<ApiPayment>>('POST', `/payments/${ref}/resend`),
  cancelPayment: (ref: string) => request<Data<ApiPayment>>('POST', `/payments/${ref}/cancel`),

  readNotif: (id: string) => request<unknown>('POST', `/notifications/${id}/read`),
  readAllNotifs: () => request<unknown>('POST', '/notifications/read-all'),
  archiveNotif: (id: string) => request<unknown>('POST', `/notifications/${id}/archive`),
  restoreNotif: (id: string) => request<unknown>('POST', `/notifications/${id}/restore`),

  pushKey: () => request<{ publicKey: string | null }>('GET', '/push/key'),
  savePush: (body: { endpoint: string; keys: { p256dh: string; auth: string }; contentEncoding: string }) =>
    request<{ ok: boolean }>('POST', '/push/subscriptions', body),
  deletePush: (endpoint: string) => request<{ ok: boolean }>('DELETE', '/push/subscriptions', { endpoint }),

  publishOffer: (form: FormData) => request<Data<ApiOffer>>('POST', '/host/offers', form),
  updateOffer: (offerId: string, body: { price?: number; seats?: number; devices?: Device[]; email?: string; password?: string }) =>
    request<Data<ApiOffer>>('PATCH', `/host/offers/${offerId}`, body),
  removeMember: (offerId: string, memberId: string) => request<Data<ApiOffer>>('DELETE', `/host/offers/${offerId}/members/${memberId}`),
  offerStatus: (offerId: string, action: 'pause' | 'resume' | 'close') => request<Data<ApiOffer>>('POST', `/host/offers/${offerId}/${action}`),
  acceptRequest: (id: string) => request<Data<ApiOffer>>('POST', `/host/requests/${id}/accept`),
  declineRequest: (id: string) => request<Data<ApiOffer>>('POST', `/host/requests/${id}/decline`),
  cancelRequest: (id: string) => request<Data<ApiRequest>>('POST', `/join-requests/${id}/cancel`),
  invite: (offerId: string) => request<Data<ApiOffer>>('POST', `/host/offers/${offerId}/invite`),
  withdraw: (amount: number) => request<{ host: ApiHost; payment: ApiPayment }>('POST', '/host/withdrawals', { amount }),
}
