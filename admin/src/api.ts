/* Client de l'API d'administration (/api/v1/admin). Jeton séparé de celui des membres. */

const BASE = (import.meta.env.VITE_API_URL as string | undefined)?.replace(/\/$/, '') ?? '/api/v1'
const TOKEN_KEY = 'subci:admin-token'

export const getToken = () => {
  try {
    return sessionStorage.getItem(TOKEN_KEY)
  } catch {
    return null
  }
}
export const setToken = (t: string | null) => {
  try {
    if (t) sessionStorage.setItem(TOKEN_KEY, t)
    else sessionStorage.removeItem(TOKEN_KEY)
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
}

export const LOGOUT_EVENT = 'subci-admin:logout'

async function call<T>(method: string, path: string, body?: unknown, raw = false): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  const token = getToken()
  if (token) headers.Authorization = `Bearer ${token}`
  if (body !== undefined) headers['Content-Type'] = 'application/json'

  let res: Response
  try {
    res = await fetch(`${BASE}/admin${path}`, { method, headers, body: body === undefined ? undefined : JSON.stringify(body) })
  } catch {
    throw new ApiError(0, 'Serveur injoignable. Vérifie que l’API tourne.')
  }
  if (res.status === 401) {
    setToken(null)
    window.dispatchEvent(new Event(LOGOUT_EVENT))
  }
  if (!res.ok) {
    const data = await res.json().catch(() => null)
    throw new ApiError(res.status, data?.message ?? 'Erreur inattendue.', data?.errors ?? {})
  }
  return (raw ? res.blob() : res.json()) as Promise<T>
}

export const errorText = (e: unknown) =>
  e instanceof ApiError ? Object.values(e.errors)[0]?.[0] ?? e.message : e instanceof Error ? e.message : 'Erreur inattendue.'

/* ---------- Types ---------- */

export type Brand = { id: string; name: string; color: string; fg: string; mono: string }

export type AdminUser = {
  id: number
  name: string | null
  shortName: string
  phone: string
  referralCode: string
  balance: number
  createdAt: string
  suspendedAt: string | null
  suspensionReason: string | null
  removalsCount: number
  activeSubs?: number
  liveOffers?: number
  totalPaid?: number
}

export type Reliability = { memberSince: string | null; paidCount: number; removalsCount: number }

export type PaymentRow = {
  id: number
  ref: string
  providerRef: string | null
  type: 'subscription' | 'earning' | 'withdrawal' | 'refund'
  direction: 'in' | 'out'
  status: 'pending' | 'succeeded' | 'failed' | 'expired'
  label: string
  amount: number
  months: number | null
  method: string
  methodLabel: string
  phone: string | null
  service: Brand | null
  user: { id: number; name: string; phone: string } | null
  joinStatus: string | null
  refundedAt: string | null
  confirmedAt: string | null
  createdAt: string
}

export type OfferRow = {
  id: number
  service: Brand
  host: AdminUser & Reliability
  plan: string
  quality: string | null
  devices: string[]
  mode: 'credentials' | 'family'
  seats: number
  price: number
  members: { id: number; name: string; color: string; userId: number | null; invitePending: boolean; joinedAt: string | null }[]
  pendingRequests: number
  status: 'review' | 'live' | 'paused' | 'closed' | 'rejected'
  hasProof: boolean
  rejectionReason: string | null
  approvedAt: string | null
  createdAt: string
  monthlyNet: number
}

export type RequestRow = {
  id: number
  status: 'pending' | 'accepted' | 'declined' | 'expired' | 'cancelled'
  member: { id: number; name: string; phone: string } & Reliability
  host: { id: number; name: string }
  offerId: number
  service: Brand
  plan: string
  amount: number
  months: number
  paymentRef: string
  expiresAt: string
  decidedAt: string | null
  createdAt: string
}

export type SubRow = {
  id: number
  service: Brand
  status: string
  startsAt: string
  endsAt: string
  autoRenew: boolean
  host: { id: number; name: string } | null
  offerId: number | null
}

export type UserDetail = AdminUser &
  Reliability & {
    payout: { method: string | null; phone: string | null }
    stats: { totalPaid: number; totalEarned: number; totalWithdrawn: number }
    subscriptions: SubRow[]
    offers: OfferRow[]
    payments: PaymentRow[]
    requests: RequestRow[]
    devices: number
  }

export type Overview = {
  kpis: {
    gmv: { value: number; previous: number }
    commission: { value: number; previous: number }
    members: { value: number }
    hosts: { value: number }
    newUsers: { value: number; previous: number }
  }
  money: { held: number; hostBalances: number; payoutsPending: number }
  todo: { offersToReview: number; payouts: number; requests: number; requestsExpiringSoon: number; paymentsPending: number }
  series: { date: string; gmv: number; payments: number; users: number }[]
  services: { id: string; name: string; color: string; gmv: number; members: number; offers: number }[]
  activity: { kind: string; at: string; title: string; amount: number | null; status: string | null; userId: number }[]
}

export type ServiceRow = {
  id: number
  slug: string
  name: string
  mono: string
  color: string
  fg: string
  category: string
  meta: string
  description: string
  price: number
  fullPrice: number
  seats: number
  isActive: boolean
  isPopular: boolean
  position: number
  fromPrice: number
  freeSeats: number
  liveOffers: number
  members: number
  gmvMonth: number
}

export type AuditRow = { id: number; admin: string; action: string; subjectType: string | null; subjectId: number | null; meta: Record<string, unknown> | null; ip: string | null; at: string }

export type PushState = { publicKey: string | null; devices: number; alerts: { kind: string; label: string; on: boolean }[] }

type Page<T> = { data: T[]; meta: { total: number; page: number; pages: number } }

const qs = (p: Record<string, string | number | undefined | null>) => {
  const u = new URLSearchParams()
  Object.entries(p).forEach(([k, v]) => v !== undefined && v !== null && v !== '' && u.set(k, String(v)))
  const s = u.toString()
  return s ? `?${s}` : ''
}

export const api = {
  login: (email: string, password: string) => call<{ token: string; admin: { name: string; email: string } }>('POST', '/auth/login', { email, password }),
  me: () => call<{ data: { name: string; email: string } }>('GET', '/auth/me'),
  logout: () => call<unknown>('POST', '/auth/logout'),

  overview: () => call<Overview>('GET', '/overview'),
  search: (q: string) => call<{ users: AdminUser[]; payments: PaymentRow[]; offers: OfferRow[] }>('GET', `/search${qs({ q })}`),

  offers: (p: { status?: string; q?: string; page?: number }) => call<Page<OfferRow> & { counts: Record<string, number> }>('GET', `/offers${qs(p)}`),
  offer: (id: number) => call<{ data: OfferRow }>('GET', `/offers/${id}`),
  proof: (id: number) => call<Blob>('GET', `/offers/${id}/proof`, undefined, true),
  approve: (id: number) => call<{ data: OfferRow }>('POST', `/offers/${id}/approve`),
  reject: (id: number, reason: string) => call<{ data: OfferRow }>('POST', `/offers/${id}/reject`, { reason }),
  toggleOffer: (id: number) => call<{ data: OfferRow }>('POST', `/offers/${id}/toggle`),

  payments: (p: { status?: string; type?: string; q?: string; page?: number; userId?: number }) => call<Page<PaymentRow>>('GET', `/payments${qs(p)}`),
  payouts: () => call<{ pending: PaymentRow[]; done: PaymentRow[]; total: number }>('GET', '/payouts'),
  markPaid: (id: number, note?: string) => call<{ data: PaymentRow }>('POST', `/payments/${id}/paid`, { note }),
  reconcile: (id: number) => call<{ data: PaymentRow }>('POST', `/payments/${id}/reconcile`),

  users: (p: { q?: string; filter?: string; page?: number }) => call<Page<AdminUser>>('GET', `/users${qs(p)}`),
  user: (id: number) => call<{ data: UserDetail }>('GET', `/users/${id}`),
  suspend: (id: number, reason: string) => call<{ data: UserDetail }>('POST', `/users/${id}/suspend`, { reason }),
  unsuspend: (id: number) => call<{ data: UserDetail }>('POST', `/users/${id}/unsuspend`),

  requests: (status?: string) => call<{ data: RequestRow[]; counts: Record<string, number> }>('GET', `/requests${qs({ status })}`),
  declineRequest: (id: number) => call<unknown>('POST', `/requests/${id}/decline`),

  services: () => call<{ data: ServiceRow[] }>('GET', '/services'),
  updateService: (id: number, body: Partial<Pick<ServiceRow, 'name' | 'meta' | 'description' | 'price' | 'fullPrice' | 'isActive' | 'isPopular' | 'position'>>) =>
    call<unknown>('PATCH', `/services/${id}`, body),

  push: () => call<PushState>('GET', '/push'),
  savePush: (body: { endpoint: string; keys: { p256dh: string; auth: string }; contentEncoding: string }) => call<PushState>('POST', '/push/subscriptions', body),
  deletePush: (endpoint: string) => call<PushState>('DELETE', '/push/subscriptions', { endpoint }),
  setAlerts: (alerts: Record<string, boolean>) => call<PushState>('PATCH', '/push/alerts', { alerts }),
  testPush: () => call<{ ok: boolean; devices: number }>('POST', '/push/test'),

  audit: (page = 1) => call<Page<AuditRow>>('GET', `/audit${qs({ page })}`),
}
