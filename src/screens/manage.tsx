import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router'
import { PullToRefresh } from '../components/gestures'
import { IconEye, IconEyeOff, IconMore } from '../components/icons'
import { Sheet } from '../components/Sheet'
import { useToast } from '../components/Toast'
import { Avatars, Badge, Button, Card, Chip, ListLink, MethodLogo, Progress, RoundIconButton, Screen, SectionLabel, Segmented, ServiceLogo, StatusBadge, StickyAction, Toggle, TopBar, cx } from '../components/ui'
import { getMethod, getService } from '../lib/data'
import { daysLeft, fcfa, haptic, maskPhone, shortDate, timeLeft } from '../lib/format'
import { byUrgency, errorMessage, hostNet, subStatus, useSavings, useStore, type HostOffer, type JoinRequest, type UserSub } from '../lib/store'
import { NotFound } from './discover'

const BAR_COLORS = { active: '#0F8A5F', due: '#E08A00', pending: '#2446A8', expired: '#C8322B' }

/* ---------- 11 · Mes abonnements (+ 16 empty, 24 hôte) ---------- */

export function MySubs() {
  const { state, actions } = useStore()
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const mode = params.get('mode') === 'host' ? 'host' : 'join'
  const [filter, setFilter] = useState<'active' | 'expired'>('active')

  const active = state.subs.filter((s) => s.state !== 'expired').sort(byUrgency)
  const expired = state.subs.filter((s) => s.state === 'expired').sort((a, b) => b.endAt - a.endAt)

  // Raccourci PWA « Renouveler » : ouvre directement l'échéance la plus proche.
  useEffect(() => {
    if (params.get('renew') !== '1') return
    const due = active.find((s) => subStatus(s) === 'due') ?? active[0]
    if (due) navigate(`/checkout/${due.serviceId}`, { replace: true })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const setMode = (m: 'join' | 'host') => setParams(m === 'host' ? { mode: 'host' } : {}, { replace: true })

  return (
    <PullToRefresh onRefresh={() => actions.sync().catch(() => {})}>
      <div className="mx-auto flex max-w-[960px] flex-col gap-4 px-5 pt-2 md:px-8 md:pt-7 desk:px-10 desk:pt-9">
        <h1 className="t-title">Mes abonnements</h1>

        {state.offers.length > 0 ? (
          <Segmented
            value={mode}
            onChange={setMode}
            options={[
              { value: 'join', label: `Je rejoins · ${active.length}` },
              { value: 'host', label: `Je partage · ${state.offers.length}` },
            ]}
            className="md:max-w-[420px]"
          />
        ) : (
          <Segmented
            value={filter}
            onChange={setFilter}
            options={[
              { value: 'active', label: `Actifs · ${active.length}` },
              { value: 'expired', label: `Expirés · ${expired.length}` },
            ]}
            className="md:max-w-[420px]"
          />
        )}

        {mode === 'host' ? (
          <HostDashboard />
        ) : (
          <>
            {state.offers.length > 0 && (
              <div className="flex gap-2">
                <Chip size="sm" active={filter === 'active'} onClick={() => setFilter('active')}>Actifs · {active.length}</Chip>
                <Chip size="sm" active={filter === 'expired'} onClick={() => setFilter('expired')}>Expirés · {expired.length}</Chip>
              </div>
            )}
            {filter === 'active' && <PendingRequests />}
            {filter === 'active' ? <MemberList subs={active} hasRequests={state.requests.length > 0} /> : <ExpiredList subs={expired} />}
            {state.offers.length === 0 && active.length > 0 && (
              <Link to="/host" viewTransition className="pressable mt-1 flex items-center gap-3 rounded-card border-[1.5px] border-dashed border-line-strong p-4">
                <span className="flex flex-1 flex-col gap-0.5">
                  <span className="text-[15px] font-bold">Partager mon abonnement</span>
                  <span className="text-[13px] font-semibold text-muted">Tes places libres te rapportent chaque mois.</span>
                </span>
                <span className="text-subtle">›</span>
              </Link>
            )}
          </>
        )}
        <div className="h-2" />
      </div>
    </PullToRefresh>
  )
}

/** Demandes payées en attente de la réponse d'un hôte (remboursées sinon). */
function PendingRequests() {
  const { state, actions } = useStore()
  const toast = useToast()
  const [cancel, setCancel] = useState<JoinRequest | null>(null)
  const pending = state.requests.filter((r) => r.status === 'pending')
  if (!pending.length) return null

  return (
    <section className="flex flex-col gap-2.5" aria-label="Demandes en attente">
      <SectionLabel>Demandes en attente</SectionLabel>
      {pending.map((r) => {
        const svc = getService(r.serviceId)
        return (
          <div key={r.id} className="flex flex-col gap-3 rounded-card border-[1.5px] border-dashed border-info/40 bg-white p-4">
            <div className="flex items-center gap-3">
              {svc && <ServiceLogo service={svc} />}
              <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                <span className="truncate text-base font-bold">{svc?.name ?? r.serviceId}</span>
                <span className="text-[13px] font-semibold text-muted">
                  Chez {r.hostName} · {r.plan}
                </span>
              </span>
              <StatusBadge status="pending" />
            </div>
            <div className="flex items-center justify-between gap-3">
              <span className="text-[13px] leading-snug font-semibold text-muted">
                Réponse d’ici {timeLeft(r.expiresAt)} · {fcfa(r.amount)} FCFA remboursés sinon
              </span>
              <button type="button" onClick={() => setCancel(r)} className="pressable h-9 shrink-0 rounded-[10px] px-2 text-[13px] font-bold text-err">
                Annuler
              </button>
            </div>
          </div>
        )
      })}
      {cancel && (
        <ConfirmModal
          title="Annuler ta demande ?"
          text={`Tu es remboursé de ${fcfa(cancel.amount)} FCFA et ta place chez ${cancel.hostName} est libérée.`}
          confirm="Annuler la demande"
          onCancel={() => setCancel(null)}
          onConfirm={() => {
            const r = cancel
            setCancel(null)
            actions
              .cancelRequest(r.id)
              .then(() => toast({ text: `Demande annulée · ${fcfa(r.amount)} FCFA remboursés` }))
              .catch((e) => toast({ tone: 'error', text: errorMessage(e) }))
          }}
        />
      )}
    </section>
  )
}

/** Tri par urgence. Barre = temps restant, colorée par statut. */
function MemberList({ subs, hasRequests }: { subs: UserSub[]; hasRequests?: boolean }) {
  const navigate = useNavigate()
  const { monthly, saved } = useSavings()

  if (subs.length === 0) return hasRequests ? null : <EmptySubs />

  return (
    <div className="flex flex-col gap-4">
      <div className="flex items-center justify-between rounded-btn bg-ink px-4 py-3.5 text-sand md:max-w-[420px]">
        <div className="flex flex-col gap-0.5">
          <span className="text-xs font-semibold text-ink-muted">Ce mois-ci</span>
          <span className="font-display text-xl font-extrabold">{fcfa(monthly)} FCFA</span>
        </div>
        <div className="flex flex-col gap-0.5 text-right">
          <span className="text-xs font-semibold text-ink-muted">Économisé</span>
          <span className="font-display text-xl font-extrabold text-brand">+{fcfa(saved)}</span>
        </div>
      </div>
      <div className="grid gap-4 md:grid-cols-2 md:gap-3 desk:grid-cols-3">
        {subs.map((sub) => {
          const svc = getService(sub.serviceId)!
          const status = subStatus(sub)
          const days = daysLeft(sub.endAt)
          const elapsed = Math.min(1, Math.max(0, (Date.now() - sub.startAt) / Math.max(1, sub.endAt - sub.startAt)))

          if (status === 'pending')
            return (
              <Card key={sub.id} onClick={() => navigate(`/subs/${sub.id}`, { viewTransition: true })} className="flex items-center gap-3 p-4">
                <ServiceLogo service={svc} />
                <span className="flex flex-1 flex-col gap-0.5">
                  <span className="text-base font-bold">{svc.name}</span>
                  <span className="text-[13px] font-semibold text-muted">
                    {sub.activatesAt ? `Activation · ~${Math.max(1, Math.ceil((sub.activatesAt - Date.now()) / 60e3))} min` : 'En attente de l’invitation famille'}
                  </span>
                </span>
                <StatusBadge status="pending" />
              </Card>
            )

          return (
            <div key={sub.id} className="flex flex-col gap-3.5 rounded-card bg-white p-4">
              <Link to={`/subs/${sub.id}`} viewTransition className="flex items-center gap-3">
                <ServiceLogo service={svc} />
                <span className="flex flex-1 flex-col gap-0.5">
                  <span className="text-base font-bold">{svc.name}</span>
                  <span className="text-[13px] font-semibold text-muted">
                    {status === 'due' || !sub.autoRenew ? `Expire le ${shortDate(sub.endAt)}` : `Renouv. auto · ${shortDate(sub.endAt)}`}
                  </span>
                </span>
                <StatusBadge status={status} days={days} />
              </Link>
              <Progress value={elapsed} color={BAR_COLORS[status]} />
              {status === 'due' && (
                <div className="grid grid-cols-2 gap-2">
                  <Button variant="soft" size="sm" onClick={() => navigate(`/subs/${sub.id}`, { viewTransition: true })}>
                    Accès
                  </Button>
                  <Button size="sm" onClick={() => navigate(`/checkout/${svc.id}`, { viewTransition: true })}>
                    Renouveler
                  </Button>
                </div>
              )}
            </div>
          )
        })}
      </div>
    </div>
  )
}

function ExpiredList({ subs }: { subs: UserSub[] }) {
  const navigate = useNavigate()
  if (subs.length === 0)
    return <p className="py-10 text-center text-[15px] font-medium text-muted">Aucun abonnement expiré.</p>
  return (
    <div className="grid gap-2.5 md:grid-cols-2 desk:grid-cols-3">
      {subs.map((sub) => {
        const svc = getService(sub.serviceId)!
        return (
          <div key={sub.id} className="flex items-center gap-3 rounded-card bg-white p-4">
            <ServiceLogo service={svc} />
            <span className="flex flex-1 flex-col gap-0.5">
              <span className="text-base font-bold">{svc.name}</span>
              <span className="text-[13px] font-semibold text-muted">Expiré le {shortDate(sub.endAt)}</span>
            </span>
            <Button size="xs" block={false} onClick={() => navigate(`/service/${svc.id}`, { viewTransition: true })}>
              Reprendre
            </Button>
          </div>
        )
      })}
    </div>
  )
}

/** 16 · Un empty state = une promesse chiffrée + un seul CTA. */
function EmptySubs() {
  const navigate = useNavigate()
  return (
    <div className="flex flex-col items-center gap-[22px] px-1 pt-16 text-center">
      <div className="relative h-[130px] w-[200px]" aria-hidden>
        <span className="absolute top-[30px] left-2.5 h-20 w-[120px] -rotate-8 rounded-[18px] border-2 border-dashed border-radio" />
        <span className="absolute top-[18px] right-2.5 h-20 w-[120px] rotate-7 rounded-[18px] border-2 border-dashed border-radio" />
        <span className="absolute top-6 left-10 grid h-[84px] w-[120px] place-items-center rounded-[18px] bg-white font-display text-[32px] font-extrabold text-brand">+</span>
      </div>
      <h2 className="font-display text-[26px] leading-[1.15] font-bold tracking-[-0.02em]">Aucun abonnement pour l’instant</h2>
      <p className="text-base leading-normal font-medium text-muted">Netflix dès 2 500 FCFA, Spotify dès 1 500 FCFA.</p>
      <Button block={false} className="px-7" onClick={() => navigate('/explore')}>
        Explorer le catalogue
      </Button>
    </div>
  )
}

/* ---------- 24 · Dashboard hôte + 25 · Retrait ---------- */

function HostDashboard() {
  const { state, actions } = useStore()
  const navigate = useNavigate()
  const toast = useToast()
  const [withdraw, setWithdraw] = useState(false)

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-col gap-3.5 rounded-[24px] bg-ink p-5 text-sand md:max-w-[420px]">
        <div className="flex items-start justify-between">
          <div className="flex flex-col gap-1">
            <span className="text-[13px] font-semibold text-ink-muted">Solde disponible</span>
            <span className="font-display text-[34px] leading-none font-extrabold tracking-[-0.02em]">
              {fcfa(state.balance)} <span className="font-sans text-sm font-bold">FCFA</span>
            </span>
          </div>
          {state.monthGain > 0 && <span className="rounded-full bg-[#1F3A2E] px-[9px] py-[5px] text-xs font-extrabold text-ok-glow">+{fcfa(state.monthGain)} ce mois</span>}
        </div>
        <div className="grid grid-cols-2 gap-2">
          <button
            type="button"
            disabled={state.balance === 0}
            onClick={() => setWithdraw(true)}
            className="pressable h-11 rounded-[14px] bg-brand text-[15px] font-bold text-ink disabled:bg-ink-3 disabled:text-ink-muted"
          >
            Retirer
          </button>
          <button type="button" onClick={() => navigate('/activity?tab=payments')} className="pressable h-11 rounded-[14px] bg-ink-3 text-[15px] font-bold">
            Historique
          </button>
        </div>
      </div>

      <div className="grid gap-4 md:grid-cols-2 md:gap-3 desk:grid-cols-3">
        {state.offers.map((o) => (
          <OfferCard
            key={o.id}
            offer={o}
            onInvite={() => {
              const name = o.pendingInvite
              actions
                .invite(o.id)
                .then(() => toast({ text: `Invitation envoyée à ${name}` }))
                .catch((e) => toast({ tone: 'error', text: errorMessage(e) }))
            }}
          />
        ))}
      </div>

      <Button variant="outline" size="md" onClick={() => navigate('/host/new', { viewTransition: true })} className="md:max-w-[420px]">
        + Partager un autre abonnement
      </Button>

      <WithdrawSheet open={withdraw} onClose={() => setWithdraw(false)} />
    </div>
  )
}

function OfferCard({ offer, onInvite }: { offer: HostOffer; onInvite: () => void }) {
  const navigate = useNavigate()
  const svc = getService(offer.serviceId)!
  const free = offer.seats - offer.members.length
  const { net } = hostNet(offer.price, offer.members.length)
  const manage = () => navigate(`/host/offers/${offer.id}`, { viewTransition: true })
  const requests = offer.requests.length
  const badge =
    requests > 0 ? <Badge tone="brand">{requests} demande{requests > 1 ? 's' : ''}</Badge>
    : offer.status === 'closed' ? <Badge tone="expired">Arrêtée</Badge>
    : offer.status === 'paused' ? <Badge tone="due">En pause</Badge>
    : offer.status === 'review' ? <Badge tone="pending">Vérification</Badge>
    : free > 0 ? <Badge tone="soft">{free} libre{free > 1 ? 's' : ''}</Badge>
    : <Badge tone="active">Complet</Badge>
  return (
    <div className={cx('flex flex-col gap-3 rounded-card bg-white p-4', offer.status === 'closed' && 'opacity-70')}>
      <button type="button" onClick={manage} className="flex items-center gap-3 text-left">
        <ServiceLogo service={svc} />
        <span className="flex flex-1 flex-col gap-0.5">
          <span className="text-base font-bold">{svc.name}</span>
          <span className="text-[13px] font-semibold text-muted">
            {free > 0 ? `${offer.members.length} / ${offer.seats} places · ` : ''}
            {fcfa(net)} FCFA/mois
          </span>
        </span>
        {badge}
      </button>
      <div className="flex items-center justify-between">
        <Avatars members={offer.members} />
        <button type="button" className="pressable -mr-1 px-1 py-2 text-sm font-bold" onClick={manage}>
          Gérer ›
        </button>
      </div>
      {offer.pendingInvite && offer.status !== 'closed' && (
        <div className="flex items-center gap-2.5 rounded-tile bg-info-soft px-3 py-2.5 text-[13px] leading-[1.4] font-semibold text-info-ink">
          <span className="font-extrabold">i</span>
          <span className="flex-1">{offer.pendingInvite} attend ton invitation famille.</span>
          <button type="button" onClick={onInvite} className="font-extrabold whitespace-nowrap">
            Inviter
          </button>
        </div>
      )}
    </div>
  )
}

/** Bottom sheet, pas une nouvelle page. Montants rapides en chips, compte mobile money pré-rempli. */
function WithdrawSheet({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { state, actions } = useStore()
  const toast = useToast()
  const [amount, setAmount] = useState(state.balance)
  const [loading, setLoading] = useState(false)
  const m = getMethod(state.payout.method)

  useEffect(() => {
    if (open) setAmount(state.balance)
  }, [open, state.balance])

  const chips = [5000, 10000].filter((v) => v < state.balance)

  return (
    <Sheet open={open} onClose={onClose} label="Retirer mes gains">
      <div className="flex flex-col gap-[18px]">
        <h2 className="font-display text-2xl font-bold tracking-[-0.02em]">Retirer mes gains</h2>
        <div className="flex flex-col items-center gap-1.5 py-2">
          <span className="font-display text-5xl leading-none font-extrabold tracking-[-0.03em]">{fcfa(amount)}</span>
          <span className="text-sm font-bold text-muted">FCFA{amount === state.balance ? ' · tout le solde' : ''}</span>
        </div>
        <div className="flex justify-center gap-2">
          {chips.map((v) => (
            <Chip key={v} size="sm" active={amount === v} onClick={() => setAmount(v)}>
              {fcfa(v)}
            </Chip>
          ))}
          <Chip size="sm" active={amount === state.balance} onClick={() => setAmount(state.balance)}>
            Tout
          </Chip>
        </div>
        <div className="flex items-center gap-3 rounded-btn bg-sand p-3.5">
          <MethodLogo method={m} />
          <span className="flex flex-1 flex-col gap-0.5">
            <span className="text-[15px] font-bold">{m.name}</span>
            <span className="text-[13px] font-semibold text-muted">{maskPhone(state.payout.phone)}</span>
          </span>
          <button type="button" className="text-sm font-bold" onClick={() => toast({ text: 'Changement de compte : bientôt disponible' })}>
            Changer
          </button>
        </div>
        <div className="flex justify-between text-sm font-semibold text-muted">
          <span>Frais de retrait : 0 FCFA</span>
          <span>Reçu en ~5 min</span>
        </div>
        <Button
          loading={loading}
          disabled={amount <= 0}
          onClick={async () => {
            setLoading(true)
            try {
              await actions.withdraw(amount)
              onClose()
              haptic(20)
              toast({ tone: 'ink', text: `Retrait de ${fcfa(amount)} FCFA envoyé` })
            } catch (e) {
              toast({ tone: 'error', text: errorMessage(e) })
            } finally {
              setLoading(false)
            }
          }}
        >
          Retirer {fcfa(amount)} FCFA
        </Button>
      </div>
    </Sheet>
  )
}

/* ---------- 12 · Détail d'un abonnement ---------- */

/** Carte Ink = coffre des accès, mis en cache pour le hors-ligne. Copier → toast + vibration. */
export function SubDetail() {
  const { id = '' } = useParams()
  const navigate = useNavigate()
  const { state, actions } = useStore()
  const toast = useToast()
  const [reveal, setReveal] = useState(state.settings.hideAccess === 'never')
  const [menu, setMenu] = useState(false)
  const [confirm, setConfirm] = useState(false)
  const sub = state.subs.find((s) => s.id === id)
  if (!sub) return <NotFound />
  const svc = getService(sub.serviceId)!
  const status = subStatus(sub)
  const m = getMethod(sub.method)

  const copy = async (text: string, what: string) => {
    try {
      await navigator.clipboard.writeText(text)
    } catch {
      /* presse-papiers indisponible */
    }
    haptic(20)
    toast({ tone: 'ink', text: `${what} copié`, action: { label: 'OK', onClick: () => {} } })
  }

  const statusLabel =
    status === 'pending' ? 'Activation en cours' : status === 'expired' ? `Expiré le ${shortDate(sub.endAt)}` : `${status === 'due' ? `J-${daysLeft(sub.endAt)}` : 'Actif'} · jusqu’au ${shortDate(sub.endAt)}`

  return (
    <Screen>
      <TopBar
        right={
          <RoundIconButton label="Plus d’options" onClick={() => setMenu(true)}>
            <IconMore size={20} />
          </RoundIconButton>
        }
      />
      <div className="flex flex-col gap-3.5 px-5 pt-3">
        <div className="flex items-center gap-3.5">
          <ServiceLogo service={svc} size={60} />
          <div className="flex flex-col gap-1.5">
            <h1 className="font-display text-2xl leading-none font-bold tracking-[-0.02em]">{svc.name}</h1>
            <Badge tone={status} className="self-start py-1">{statusLabel}</Badge>
          </div>
        </div>

        {status === 'pending' ? (
          <div className="flex flex-col gap-3 rounded-card bg-ink p-5 text-sand">
            <span className="flex items-center gap-2.5 text-[15px] font-bold">
              <span className="size-4 rounded-full border-[2.5px] border-ink-3 border-t-brand animate-spin-fast" />
              On prépare tes accès
            </span>
            <span className="text-sm font-medium text-ink-muted">
              {sub.activatesAt
                ? `Tu recevras une notification dès qu’ils sont prêts (~${svc.activation} min). Remboursé si non activé.`
                : 'Ton hôte t’envoie l’invitation famille par e-mail. On te prévient dès que c’est actif. Remboursé si non activé sous 24 h.'}
            </span>
          </div>
        ) : (
          <div className="rounded-card bg-ink px-[18px] py-1.5 text-sand">
            <div className="flex items-center gap-2.5 border-b border-ink-line py-3.5">
              <div className="flex min-w-0 flex-1 flex-col gap-[3px]">
                <span className="text-xs font-semibold text-ink-muted">Email</span>
                <span className="truncate text-[15px] font-bold">{sub.email}</span>
              </div>
              <button type="button" onClick={() => copy(sub.email, 'Email')} className="pressable flex h-9 items-center rounded-[10px] bg-ink-3 px-3 text-[13px] font-bold">
                Copier
              </button>
            </div>
            <div className="flex items-center gap-2.5 border-b border-ink-line py-3.5">
              <div className="flex min-w-0 flex-1 flex-col gap-[3px]">
                <span className="text-xs font-semibold text-ink-muted">Mot de passe</span>
                <span className={cx('truncate text-[15px] font-bold', !reveal && 'tracking-[0.2em]')}>{reveal ? sub.password : '••••••••••'}</span>
              </div>
              <button
                type="button"
                aria-label={reveal ? 'Masquer le mot de passe' : 'Afficher le mot de passe'}
                aria-pressed={reveal}
                onClick={() => setReveal((r) => !r)}
                className="pressable grid size-9 place-items-center rounded-[10px] bg-ink-3"
              >
                {reveal ? <IconEyeOff size={18} /> : <IconEye size={18} />}
              </button>
              <button type="button" onClick={() => copy(sub.password, 'Mot de passe')} className="pressable flex h-9 items-center rounded-[10px] bg-ink-3 px-3 text-[13px] font-bold">
                Copier
              </button>
            </div>
            <div className="flex justify-between py-3.5">
              <div className="flex flex-col gap-[3px]">
                <span className="text-xs font-semibold text-ink-muted">Ton profil</span>
                <span className="text-[15px] font-bold">{sub.profile}</span>
              </div>
              {sub.pin && (
                <div className="flex flex-col gap-[3px] text-right">
                  <span className="text-xs font-semibold text-ink-muted">PIN</span>
                  <span className="text-[15px] font-bold">{reveal ? sub.pin.split('').join(' ') : '• • • •'}</span>
                </div>
              )}
            </div>
          </div>
        )}

        <div className="flex gap-2.5 rounded-[14px] bg-warn-soft px-3.5 py-3 text-[13px] leading-[1.45] font-semibold text-[#6B3F00]">
          <span className="font-extrabold">!</span>
          Ne modifie pas le mot de passe ni les autres profils.
        </div>

        <Card className="px-[18px]">
          <div className="flex items-center justify-between gap-3 border-b border-line-soft py-4">
            <div className="flex flex-col gap-0.5">
              <span className="text-[15px] font-bold">Renouvellement auto</span>
              <span className="text-[13px] font-semibold text-muted">
                {m.name} · {fcfa(svc.price)} FCFA
              </span>
            </div>
            <Toggle
              label="Renouvellement auto"
              checked={sub.autoRenew}
              onChange={(v) => {
                actions
                  .setAutoRenew(sub.id, v)
                  .then(() => toast({ text: v ? 'Renouvellement auto activé' : 'Renouvellement auto désactivé' }))
                  .catch((e) => toast({ tone: 'error', text: errorMessage(e) }))
              }}
            />
          </div>
          <ListLink label="Historique des paiements" onClick={() => navigate('/activity?tab=payments')} />
        </Card>
      </div>

      <StickyAction className="grid grid-cols-[auto_1fr] gap-2.5">
        <a
          href={`https://wa.me/2250700000000?text=${encodeURIComponent(`Bonjour, j’ai un souci avec ${svc.name}`)}`}
          target="_blank"
          rel="noreferrer"
          className="pressable flex h-14 items-center rounded-btn border-[1.5px] border-line-strong px-4 text-sm font-bold"
        >
          Un souci ?
        </a>
        <Button onClick={() => navigate(`/checkout/${svc.id}`, { viewTransition: true })}>Renouveler · {fcfa(svc.price)}</Button>
      </StickyAction>

      <Sheet open={menu} onClose={() => setMenu(false)} label="Options">
        <div className="flex flex-col">
          <ListLink label="Voir l’offre" onClick={() => navigate(`/service/${svc.id}`, { viewTransition: true })} />
          <ListLink label="Historique des paiements" onClick={() => navigate('/activity?tab=payments')} />
          <ListLink
            label="Annuler l’abonnement"
            danger
            onClick={() => {
              setMenu(false)
              setConfirm(true)
            }}
          />
        </div>
      </Sheet>

      {confirm && (
        <ConfirmModal
          title="Annuler l’abonnement ?"
          text={`Tu gardes l’accès jusqu’au ${shortDate(sub.endAt)}. Ta place sera ensuite libérée.`}
          confirm="Oui, annuler"
          onCancel={() => setConfirm(false)}
          onConfirm={() => {
            setConfirm(false)
            actions
              .cancelSubscription(sub.id)
              .then(() => toast({ text: `Abonnement annulé · accès jusqu’au ${shortDate(sub.endAt)}` }))
              .catch((e) => toast({ tone: 'error', text: errorMessage(e) }))
          }}
        />
      )}
    </Screen>
  )
}

/** Modale centrée réservée aux confirmations destructives. */
export function ConfirmModal({ title, text, confirm, onCancel, onConfirm }: { title: string; text: string; confirm: string; onCancel: () => void; onConfirm: () => void }) {
  useEffect(() => {
    const k = (e: KeyboardEvent) => e.key === 'Escape' && onCancel()
    window.addEventListener('keydown', k)
    return () => window.removeEventListener('keydown', k)
  }, [onCancel])
  return (
    <div className="fixed inset-0 z-50 grid place-items-center p-6" role="presentation">
      <div className="absolute inset-0 animate-fade-in bg-ink/55" onClick={onCancel} aria-hidden />
      <div role="alertdialog" aria-modal="true" aria-labelledby="cm-title" className="relative flex w-full max-w-[360px] animate-fade-in flex-col gap-3 rounded-sheet bg-white p-6">
        <h2 id="cm-title" className="font-display text-[22px] leading-[1.15] font-bold">{title}</h2>
        <p className="text-[15px] leading-normal font-medium text-muted">{text}</p>
        <div className="mt-2 grid grid-cols-2 gap-2">
          <Button variant="outline" size="md" onClick={onCancel}>
            Garder
          </Button>
          <Button variant="danger" size="md" onClick={onConfirm} autoFocus>
            {confirm}
          </Button>
        </div>
      </div>
    </div>
  )
}
