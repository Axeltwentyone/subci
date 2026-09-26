import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router'
import { PullToRefresh } from '../components/gestures'
import { IconCheck, IconEye, IconEyeOff, IconMore } from '../components/icons'
import { InstallSheet, NotifSheet } from '../components/pwa'
import { Sheet } from '../components/Sheet'
import { useToast } from '../components/Toast'
import { OtpInput, PayMethodPicker } from '../components/inputs'
import { Avatars, Badge, Button, Card, Chip, ListLink, MethodLogo, Progress, Radio, RoundIconButton, Screen, SectionLabel, Segmented, ServiceLogo, StatusBadge, StickyAction, Toggle, TopBar, cx } from '../components/ui'
import type { IssueReason } from '../lib/api'
import { getMethod, getService, type PayMethodId } from '../lib/data'
import { shareOffer } from '../lib/share'
import { supportWhatsApp } from '../lib/support'
import { daysLeft, fcfa, haptic, maskPhone, shortDate, timeLeft } from '../lib/format'
import { usePushState } from '../lib/push'
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
          <div key={r.id} className="flex flex-col gap-3 rounded-card border-[1.5px] border-dashed border-info/40 bg-surface p-4">
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
      <div className="flex items-center justify-between scheme-card rounded-btn bg-ink px-4 py-3.5 text-sand md:max-w-[420px]">
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
            <div key={sub.id} className="flex flex-col gap-3.5 rounded-card bg-surface p-4">
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
          <div key={sub.id} className="flex items-center gap-3 rounded-card bg-surface p-4">
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
        <span className="absolute top-6 left-10 grid h-[84px] w-[120px] place-items-center rounded-[18px] bg-surface font-display text-[32px] font-extrabold text-brand">+</span>
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
  const { state } = useStore()
  const navigate = useNavigate()
  const [withdraw, setWithdraw] = useState(false)
  const [push, refreshPush] = usePushState()
  const [params, setParams] = useSearchParams()
  const [notifSheet, setNotifSheet] = useState(false)
  const [installSheet, setInstallSheet] = useState(false)

  // Juste après la publication d'une offre : proposer les notifications (une fois).
  useEffect(() => {
    if (params.get('notif') !== '1' || push === null) return
    if (push === 'off') setNotifSheet(true)
    if (push === 'install') setInstallSheet(true)
    params.delete('notif')
    setParams(params, { replace: true })
  }, [push, params, setParams])

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-col gap-3.5 scheme-card rounded-[24px] bg-ink p-5 text-sand md:max-w-[420px]">
        <div className="flex items-start justify-between">
          <div className="flex flex-col gap-1">
            <span className="flex items-center gap-2 text-[13px] font-semibold text-ink-muted">
              Solde disponible
              {state.trusted && <span className="rounded-full bg-[#1F3A2E] px-2 py-0.5 text-[11px] font-extrabold text-ok-glow">Hôte fiable · versé en 24 h</span>}
            </span>
            <span className="font-display text-[34px] leading-none font-extrabold tracking-[-0.02em]">
              {fcfa(state.balance)} <span className="font-sans text-sm font-bold">FCFA</span>
            </span>
          </div>
          {state.monthGain > 0 && <span className="rounded-full bg-[#1F3A2E] px-[9px] py-[5px] text-xs font-extrabold text-ok-glow">+{fcfa(state.monthGain)} ce mois</span>}
        </div>
        {state.pending > 0 && (
          <div className="flex flex-col gap-0.5 rounded-[14px] bg-ink-3 px-3.5 py-2.5 text-[13px] font-semibold text-ink-muted">
            <span>
              <b className="text-sand">+{fcfa(state.pending)} FCFA à venir</b>
              {state.nextRelease && <> · prochain versement le {shortDate(state.nextRelease)}</>}
            </span>
            <span>Chaque mois payé par un membre arrive dans ton solde {state.holdHours} h après son début.</span>
            {state.held > 0 && <span className="text-warn">{fcfa(state.held)} FCFA en pause : un membre a signalé un souci.</span>}
          </div>
        )}
        {state.withdrawLockedUntil && state.withdrawLockedUntil > Date.now() && (
          <span className="text-[13px] font-semibold text-warn">Numéro de retrait modifié : retraits possibles à partir du {shortDate(state.withdrawLockedUntil)}.</span>
        )}
        <div className="grid grid-cols-2 gap-2">
          <button
            type="button"
            disabled={state.balance === 0 || (state.withdrawLockedUntil ?? 0) > Date.now()}
            onClick={() => setWithdraw(true)}
            className="pressable h-11 rounded-[14px] bg-brand text-[15px] font-bold text-on-accent disabled:bg-ink-3 disabled:text-ink-muted"
          >
            Retirer
          </button>
          <button type="button" onClick={() => navigate('/activity?tab=payments')} className="pressable h-11 rounded-[14px] bg-ink-3 text-[15px] font-bold">
            Historique
          </button>
        </div>
      </div>

      {state.offers.length > 0 && push && push !== 'on' && push !== 'unsupported' && (
        <div className="flex items-start gap-3 rounded-card bg-warn-soft p-4 text-[13px] leading-snug font-semibold text-warn-deep md:max-w-[420px]">
          <span className="font-extrabold">!</span>
          <span className="flex flex-1 flex-col gap-2">
            <span>
              {push === 'install'
                ? 'Sur iPhone, installe l’app pour être prévenu·e des demandes : tu as 24 h pour répondre à chacune.'
                : push === 'denied'
                  ? 'Notifications bloquées : tu ne sauras pas quand un membre veut rejoindre. Réactive-les dans les réglages de ton téléphone (Réglages → Notifications → Sub.ci).'
                  : 'Active les notifications : tu as 24 h pour accepter chaque demande, sinon le membre est remboursé.'}
            </span>
            {push !== 'denied' && (
              <button
                type="button"
                onClick={() => (push === 'install' ? setInstallSheet(true) : setNotifSheet(true))}
                className="self-start rounded-[10px] bg-ink px-3 py-2 text-[13px] font-bold text-sand"
              >
                {push === 'install' ? 'Installer l’app' : 'Activer les notifications'}
              </button>
            )}
          </span>
        </div>
      )}

      <div className="grid gap-4 md:grid-cols-2 md:gap-3 desk:grid-cols-3">
        {state.offers.map((o) => (
          <OfferCard key={o.id} offer={o} />
        ))}
      </div>

      <Button variant="outline" size="md" onClick={() => navigate('/host/new', { viewTransition: true })} className="md:max-w-[420px]">
        + Partager un autre abonnement
      </Button>

      <WithdrawSheet open={withdraw} onClose={() => setWithdraw(false)} />
      <NotifSheet host open={notifSheet} onClose={() => (setNotifSheet(false), refreshPush())} />
      <InstallSheet open={installSheet} onClose={() => setInstallSheet(false)} />
    </div>
  )
}

function OfferCard({ offer }: { offer: HostOffer }) {
  const navigate = useNavigate()
  const { state } = useStore()
  const toast = useToast()
  const share = async () => {
    const r = await shareOffer(offer, state.referral?.code)
    if (r === 'copied') toast({ tone: 'ink', text: 'Lien copié : colle-le dans WhatsApp' })
  }
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
    <div className={cx('flex flex-col gap-3 rounded-card bg-surface p-4', offer.status === 'closed' && 'opacity-70')}>
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
        {offer.status === 'live' && free > 0 && (
          <button type="button" className="pressable ml-auto px-2 py-2 text-sm font-bold text-brand-ink" onClick={share}>
            Partager
          </button>
        )}
        <button type="button" className="pressable -mr-1 px-1 py-2 text-sm font-bold" onClick={manage}>
          Gérer ›
        </button>
      </div>
      {offer.pendingInvite && offer.status !== 'closed' && (
        <div className="flex items-center gap-2.5 rounded-tile bg-info-soft px-3 py-2.5 text-[13px] leading-[1.4] font-semibold text-info-ink">
          <span className="font-extrabold">i</span>
          <span className="flex-1">{offer.pendingInvite} attend ton invitation famille.</span>
          <button type="button" onClick={manage} className="font-extrabold whitespace-nowrap">
            Inviter ›
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
  const [change, setChange] = useState(false)

  if (change) return <PayoutSheet open={open} onClose={() => (setChange(false), onClose())} onDone={() => setChange(false)} />

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
          <button type="button" className="text-sm font-bold" onClick={() => setChange(true)}>
            Changer
          </button>
        </div>
        <div className="flex justify-between text-sm font-semibold text-muted">
          <span>Frais de retrait : 0 FCFA</span>
          <span>Reçu sous 48 h</span>
        </div>
        <Button
          loading={loading}
          disabled={amount <= 0}
          onClick={async () => {
            setLoading(true)
            try {
              const instant = await actions.withdraw(amount)
              onClose()
              haptic(20)
              toast({ tone: 'ink', text: instant ? `Retrait de ${fcfa(amount)} FCFA envoyé` : `Retrait de ${fcfa(amount)} FCFA demandé · reçu sous 48 h` })
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

/**
 * Changer le numéro qui reçoit les gains : code SMS envoyé au numéro du compte,
 * puis retraits bloqués 24 h (si ce n'était pas toi, tu as le temps de réagir).
 */
function PayoutSheet({ open, onClose, onDone }: { open: boolean; onClose: () => void; onDone: () => void }) {
  const { state, actions } = useStore()
  const toast = useToast()
  const [method, setMethod] = useState<PayMethodId>(state.payout.method === 'card' ? 'wave' : state.payout.method)
  const [phone, setPhone] = useState(state.payout.phone)
  const [step, setStep] = useState<'form' | 'code'>('form')
  const [code, setCode] = useState('')
  const [loading, setLoading] = useState(false)

  const sendCode = async () => {
    setLoading(true)
    try {
      const r = await actions.payoutCode()
      setStep('code')
      if (r.debugCode) toast({ text: `Code (dev) : ${r.debugCode}` })
    } catch (e) {
      toast({ tone: 'error', text: errorMessage(e) })
    } finally {
      setLoading(false)
    }
  }
  const confirm = async (value = code) => {
    if (value.length !== 6) return
    setLoading(true)
    try {
      await actions.updatePayout(method, phone, value)
      haptic(20)
      toast({ tone: 'ink', text: 'Numéro de retrait mis à jour · retraits possibles dans 24 h' })
      onDone()
    } catch (e) {
      setCode('')
      toast({ tone: 'error', text: errorMessage(e) })
    } finally {
      setLoading(false)
    }
  }

  return (
    <Sheet open={open} onClose={onClose} label="Où recevoir tes gains">
      <div className="flex flex-col gap-4">
        <h2 className="font-display text-2xl font-bold tracking-[-0.02em]">Où recevoir tes gains</h2>
        {step === 'form' ? (
          <>
            <PayMethodPicker value={method} onChange={setMethod} phone={phone} onPhone={setPhone} methods={['wave', 'om', 'mtn', 'moov']} />
            <p className="text-[13px] leading-snug font-semibold text-muted">
              Par sécurité, on t’envoie un code par SMS au {maskPhone(state.user?.phone ?? '')}, et les retraits sont bloqués 24 h après le changement.
            </p>
            <Button loading={loading} disabled={phone.length !== 10} onClick={sendCode}>
              Recevoir le code
            </Button>
          </>
        ) : (
          <>
            <p className="text-[15px] font-semibold text-muted">Code envoyé au {maskPhone(state.user?.phone ?? '')}.</p>
            <OtpInput value={code} onChange={setCode} onComplete={confirm} />
            <Button loading={loading} disabled={code.length !== 6} onClick={() => confirm()}>
              Confirmer le changement
            </Button>
            <button type="button" className="text-sm font-bold text-muted" onClick={() => (setStep('form'), setCode(''))}>
              Modifier le numéro
            </button>
          </>
        )}
      </div>
    </Sheet>
  )
}

const ISSUES: { id: IssueReason; label: string; hint: string }[] = [
  { id: 'wrong_password', label: 'Le mot de passe ne marche plus', hint: 'L’hôte l’a peut-être changé' },
  { id: 'no_access', label: 'Je n’ai pas accès', hint: 'Profil introuvable, invitation jamais reçue…' },
  { id: 'removed', label: 'J’ai été retiré·e du compte', hint: 'Déconnecté·e ou profil supprimé' },
  { id: 'other', label: 'Autre souci', hint: 'Explique-nous en quelques mots' },
]

/** « Un souci ? » : l'hôte est prévenu et ses gains pour toi sont en pause jusqu'à ce que ce soit réglé. */
function IssueSheet({ sub, open, onClose }: { sub: UserSub; open: boolean; onClose: () => void }) {
  const { actions } = useStore()
  const toast = useToast()
  const [reason, setReason] = useState<IssueReason>('wrong_password')
  const [message, setMessage] = useState('')
  const [loading, setLoading] = useState(false)
  const svc = getService(sub.serviceId)!

  return (
    <Sheet open={open} onClose={onClose} label="Signaler un souci">
      <div className="flex flex-col gap-4">
        <div className="flex flex-col gap-1">
          <h2 className="font-display text-2xl font-bold tracking-[-0.02em]">Un souci avec {svc.name} ?</h2>
          <p className="text-sm font-semibold text-muted">{sub.hostName ?? 'Ton hôte'} est prévenu·e et ses gains pour toi sont mis en pause le temps que ce soit réglé.</p>
        </div>
        <div role="radiogroup" aria-label="Quel souci ?" className="overflow-hidden rounded-card bg-surface">
          {ISSUES.map((i) => (
            <button
              key={i.id}
              type="button"
              role="radio"
              aria-checked={reason === i.id}
              onClick={() => setReason(i.id)}
              className="flex w-full items-center gap-3 border-b border-line-soft px-4 py-3.5 text-left last:border-b-0"
            >
              <span className="flex flex-1 flex-col gap-0.5">
                <span className="text-[15px] font-bold">{i.label}</span>
                <span className="text-[13px] font-semibold text-muted">{i.hint}</span>
              </span>
              <Radio checked={reason === i.id} />
            </button>
          ))}
        </div>
        <textarea
          value={message}
          onChange={(e) => setMessage(e.target.value.slice(0, 500))}
          rows={3}
          placeholder="Détails (facultatif)"
          className="rounded-btn border-[1.5px] border-line bg-surface p-3.5 text-[15px] font-medium outline-none focus:border-2 focus:border-ink"
        />
        <Button
          loading={loading}
          onClick={async () => {
            setLoading(true)
            try {
              await actions.reportIssue(sub.id, reason, message.trim() || undefined)
              haptic(20)
              toast({ tone: 'ink', text: 'Souci signalé · on revient vers toi vite' })
              onClose()
            } catch (e) {
              toast({ tone: 'error', text: errorMessage(e) })
            } finally {
              setLoading(false)
            }
          }}
        >
          Signaler le souci
        </Button>
        {supportWhatsApp() && (
          <a href={supportWhatsApp(`Bonjour, j’ai un souci avec ${svc.name}`)!} target="_blank" rel="noreferrer" className="text-center text-sm font-bold text-muted">
            Ou écris-nous sur WhatsApp
          </a>
        )}
      </div>
    </Sheet>
  )
}

/**
 * Offre famille (Spotify, YouTube, Apple Music) : le membre rejoint avec **son propre compte**.
 * Lien de l'hôte à ouvrir, ou invitation Apple à accepter sur l'iPhone.
 */
function FamilyInvite({ sub, serviceName }: { sub: UserSub; serviceName: string }) {
  const { actions } = useStore()
  const toast = useToast()
  const [busy, setBusy] = useState<'joined' | 'broken' | null>(null)
  const invite = sub.invite!
  const brand = serviceName.split(' ')[0]
  const host = sub.hostName ?? 'Ton hôte'

  const mark = async (status: 'joined' | 'broken') => {
    setBusy(status)
    try {
      await actions.inviteStatus(sub.id, status)
      haptic(20)
      toast({ tone: 'ink', text: status === 'joined' ? 'Bienvenue dans la famille !' : `${host} est prévenu·e : il t’envoie une nouvelle invitation` })
    } catch (e) {
      toast({ tone: 'error', text: errorMessage(e) })
    } finally {
      setBusy(null)
    }
  }

  if (invite.joinedAt) {
    return (
      <div className="flex items-center gap-3 scheme-card rounded-card bg-ink p-5 text-sand">
        <span className="grid size-10 shrink-0 place-items-center rounded-full bg-ok text-white">
          <IconCheck size={20} />
        </span>
        <span className="flex flex-col gap-0.5">
          <span className="text-[15px] font-bold">Tu fais partie de la famille {brand}</span>
          <span className="text-[13px] font-semibold text-ink-muted">Avec ton propre compte. Un souci plus tard ? Touche « Un souci ? ».</span>
        </span>
      </div>
    )
  }

  const steps =
    invite.type === 'link'
      ? [
          'Touche « Rejoindre la famille » ci-dessous',
          `Connecte-toi avec TON compte ${brand} (ou crée-le, c’est gratuit)`,
          'Confirme, puis reviens ici toucher « J’ai rejoint »',
        ]
      : [
          `Sur ton iPhone : Réglages → ton nom → Partage familial`,
          `Accepte l’invitation de ${host} (tu peux aussi l’accepter depuis Messages)`,
          'Reviens ici toucher « J’ai rejoint »',
        ]
  return (
    <div className="flex flex-col gap-4 scheme-card rounded-card bg-ink p-5 text-sand">
      <div className="flex flex-col gap-1">
        <span className="text-xs font-semibold text-ink-muted">Invitation famille</span>
        <span className="text-[15px] leading-snug font-bold">
          {invite.type === 'link' ? `${host} t’a envoyé son lien d’invitation` : `${host} a invité ${invite.email ?? 'ton identifiant Apple'}`}
        </span>
      </div>
      {invite.problemAt ? (
        <p className="rounded-tile bg-ink-3 px-3.5 py-3 text-[13px] leading-snug font-semibold">
          On a prévenu {host} : tu reçois une notification dès qu’{invite.type === 'link' ? 'un nouveau lien arrive' : 'il t’a réinvité·e'}.
        </p>
      ) : (
        <ol className="flex flex-col gap-2.5">
          {steps.map((t, i) => (
            <li key={i} className="flex items-start gap-3 text-sm leading-snug font-semibold">
              <span className="grid size-6 shrink-0 place-items-center rounded-full bg-brand text-[12px] font-extrabold text-on-accent">{i + 1}</span>
              <span>{t}</span>
            </li>
          ))}
        </ol>
      )}
      {invite.type === 'link' && invite.link && !invite.problemAt && (
        <a href={invite.link} target="_blank" rel="noopener noreferrer" className="pressable flex h-12 items-center justify-center rounded-btn bg-brand text-[15px] font-bold text-on-accent">
          Rejoindre la famille {brand}
        </a>
      )}
      <div className="grid grid-cols-2 gap-2">
        <button type="button" disabled={busy !== null} onClick={() => mark('joined')} className="pressable h-11 rounded-[14px] bg-ink-3 text-sm font-bold disabled:opacity-60">
          {busy === 'joined' ? '…' : 'J’ai rejoint ✓'}
        </button>
        <button
          type="button"
          disabled={busy !== null || !!invite.problemAt}
          onClick={() => mark('broken')}
          className="pressable h-11 rounded-[14px] border-[1.5px] border-ink-3 text-sm font-bold text-ink-soft disabled:opacity-50"
        >
          {busy === 'broken' ? '…' : invite.type === 'link' ? 'Le lien ne marche plus' : 'Je n’ai rien reçu'}
        </button>
      </div>
      <p className="text-[12px] leading-snug font-semibold text-ink-muted">
        {invite.type === 'link' ? 'Le lien expire au bout de 7 jours environ. ' : ''}Ne le partage pas : il est réservé à ta place.
      </p>
    </div>
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
  const [issue, setIssue] = useState(false)
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
          <div className="flex flex-col gap-3 scheme-card rounded-card bg-ink p-5 text-sand">
            <span className="flex items-center gap-2.5 text-[15px] font-bold">
              <span className="size-4 rounded-full border-[2.5px] border-ink-3 border-t-brand animate-spin-fast" />
              On prépare tes accès
            </span>
            <span className="text-sm font-medium text-ink-muted">
              {sub.activatesAt
                ? `Tu recevras une notification dès qu’ils sont prêts (~${svc.activation} min). Remboursé si non activé.`
                : sub.invite?.type === 'email'
                  ? `${sub.hostName ?? 'Ton hôte'} va inviter ${sub.invite.email ?? 'ton identifiant Apple'} dans son Partage familial. On te prévient dès que c’est envoyé.`
                  : `${sub.hostName ?? 'Ton hôte'} va t’envoyer son lien d’invitation famille. On te prévient dès qu’il arrive.`}
            </span>
          </div>
        ) : sub.invite ? (
          <FamilyInvite sub={sub} serviceName={svc.name} />
        ) : (
          <div className="scheme-card rounded-card bg-ink px-[18px] py-1.5 text-sand">
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

        {sub.issue ? (
          <div className="flex flex-col gap-2 rounded-[14px] bg-info-soft px-3.5 py-3 text-[13px] leading-[1.45] font-semibold text-info">
            <span>
              <b>Souci signalé le {shortDate(sub.issue.at)}.</b> Ton hôte est prévenu·e et l’équipe Sub.ci suit ton dossier. Si ça ne s’arrange pas, tu es remboursé·e du temps restant.
            </span>
            <button
              type="button"
              className="self-start font-extrabold underline"
              onClick={() =>
                actions
                  .solveIssue(sub.id)
                  .then(() => toast({ text: 'Merci ! Souci marqué comme réglé' }))
                  .catch((e) => toast({ tone: 'error', text: errorMessage(e) }))
              }
            >
              C’est réglé
            </button>
          </div>
        ) : sub.invite ? null : (
          <div className="flex gap-2.5 rounded-[14px] bg-warn-soft px-3.5 py-3 text-[13px] leading-[1.45] font-semibold text-warn-deep">
            <span className="font-extrabold">!</span>
            Ne modifie pas le mot de passe ni les autres profils.
          </div>
        )}

        <Card className="px-[18px]">
          <div className="flex items-center justify-between gap-3 border-b border-line-soft py-4">
            <div className="flex flex-col gap-0.5">
              <span className="text-[15px] font-bold">Renouvellement auto</span>
              <span className="text-[13px] font-semibold text-muted">
                {m.name} · {fcfa(sub.price)} FCFA/mois
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
        <button
          type="button"
          disabled={!!sub.issue}
          onClick={() => setIssue(true)}
          className="pressable flex h-14 items-center rounded-btn border-[1.5px] border-line-strong px-4 text-sm font-bold disabled:opacity-50"
        >
          Un souci ?
        </button>
        <Button onClick={() => navigate(`/checkout/${svc.id}`, { viewTransition: true })}>Renouveler · {fcfa(sub.price)}/mois</Button>
      </StickyAction>

      <IssueSheet sub={sub} open={issue} onClose={() => setIssue(false)} />

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
      <div className="absolute inset-0 animate-fade-in bg-scrim/55" onClick={onCancel} aria-hidden />
      <div role="alertdialog" aria-modal="true" aria-labelledby="cm-title" className="relative flex w-full max-w-[360px] animate-fade-in flex-col gap-3 rounded-sheet bg-surface p-6">
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
