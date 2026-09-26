import { useEffect, useRef, useState } from 'react'
import { Navigate, useLocation, useNavigate, useParams, useSearchParams } from 'react-router'
import { IconCheck, IconLock } from '../components/icons'
import { PayMethodPicker } from '../components/inputs'
import { Sheet } from '../components/Sheet'
import { useToast } from '../components/Toast'
import { Button, Card, Row, Screen, ServiceLogo, StepBar, StickyAction, TopBar, cx } from '../components/ui'
import { DURATIONS, durationPrice, getMethod, getService, type PayMethodId, type PublicOffer } from '../lib/data'
import { api, getToken, type PendingPayment } from '../lib/api'
import { fcfa, haptic, maskPhone, shortDate, timeLeft } from '../lib/format'
import { isIOS, isStandalone, mmss, useOnline } from '../lib/hooks'
import { useBack } from '../lib/nav'
import { errorMessage, useStore } from '../lib/store'
import { NotFound, OfferOption } from './discover'

/* ---------- 08 · Checkout ---------- */

/** Un seul écran utile : durée + moyen. Dernier moyen pré-sélectionné, numéro pré-rempli. */
/**
 * iPhone, app installée : après un paiement dans Wave / Orange Money, le retour s'ouvre dans Safari
 * (sans session) au lieu de l'app. On garde donc l'app ouverte et la page de paiement s'ouvre à côté.
 */
function keepsAppOpen() {
  return isIOS() && isStandalone()
}

export function Checkout() {
  const { id = '' } = useParams()
  const [params] = useSearchParams()
  const loc = useLocation()
  const navigate = useNavigate()
  const { state, actions } = useStore()
  const toast = useToast()
  const online = useOnline()
  const s = getService(id)
  const offerId = params.get('offer')
  const [offer, setOffer] = useState<PublicOffer | null>((loc.state as { offer?: PublicOffer } | null)?.offer ?? null)
  const [months, setMonths] = useState(3)
  const [method, setMethod] = useState<PayMethodId>(state.lastMethod)
  const [phone, setPhone] = useState(state.user?.phone ?? '')
  const [loading, setLoading] = useState(false)
  const [appleId, setAppleId] = useState('')
  const current = state.subs.find((x) => x.serviceId === id && x.state !== 'expired')

  // Lien direct / rechargement : on retrouve l'offre choisie.
  useEffect(() => {
    if (current || !offerId || offer?.id === offerId) return
    api
      .offers(id)
      .then(({ data }) => {
        const found = data.find((o) => o.id === offerId)
        if (found) setOffer(found)
        else {
          toast({ tone: 'error', text: 'Cette offre n’est plus disponible. Choisis-en une autre.' })
          navigate(`/service/${id}`, { replace: true })
        }
      })
      .catch(() => {})
  }, [current, offerId, offer, id, navigate, toast])

  if (!s) return <NotFound />
  // Nouvel arrivant sans offre choisie (hors musique, attribuée par Sub.ci) : retour au choix.
  if (!current && !offerId && s.chooseOffer) return <Navigate to={`/service/${id}`} replace />

  const monthly = current ? current.price : s.chooseOffer ? offer?.price : s.price
  const subtotal = monthly ? durationPrice(monthly, months) : 0
  // Frais de service Sub.ci (offerts au filleul), moins le crédit parrainage (le serveur recalcule tout).
  const feeWaived = !!state.referral?.feeWaived
  // Frais plus bas à partir de 3 mois (un seul paiement pour plusieurs mois).
  const baseFee = months >= 3 ? state.serviceFeeLong : state.serviceFee
  const fee = feeWaived ? 0 : baseFee
  const credit = subtotal ? Math.max(0, Math.min(state.referral?.credit ?? 0, subtotal + fee - 200)) : 0
  const amount = subtotal ? subtotal + fee - credit : 0
  // Apple Music : l'hôte invite l'identifiant Apple du membre dans son Partage familial.
  const needsAppleId = !current && (offer?.invite ?? s.invite) === 'email'
  const appleIdOk = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(appleId.trim())

  const pay = async () => {
    if (!online) {
      toast({ tone: 'error', text: 'Pas de réseau — le paiement reprendra dès le retour de la connexion' })
      return
    }
    if (needsAppleId && !appleIdOk) {
      toast({ tone: 'error', text: 'Indique l’e-mail de ton identifiant Apple' })
      return
    }
    if (method !== 'card' && phone.length < 10) {
      toast({ tone: 'error', text: `Numéro ${getMethod(method).name} incomplet — il manque ${10 - phone.length} chiffres` })
      return
    }
    setLoading(true)
    try {
      const payment = await actions.checkout(s.id, months, method, phone, current ? undefined : offerId ?? undefined, needsAppleId ? appleId.trim() : undefined)
      navigate(`/pay/${payment.ref}`, { state: payment, viewTransition: !payment.checkoutUrl })
      // Passerelle avec page de paiement (GeniusPay) : on y part, retour automatique sur /pay/{ref}.
      // App installée sur iPhone : on reste dans l'app, la page s'ouvre à côté (bouton sur l'écran suivant).
      if (payment.checkoutUrl && !keepsAppOpen()) window.location.assign(payment.checkoutUrl)
    } catch (e) {
      toast({ tone: 'error', text: errorMessage(e) })
      setLoading(false)
    }
  }

  return (
    <Screen>
      <TopBar title={s.name} right={<span className="text-[13px] font-bold text-muted">2/3</span>} />
      <StepBar step={2} total={3} />
      <div className="flex flex-col gap-[22px] px-5 pt-5">
        {!current && !s.chooseOffer && (
          <section className="flex flex-col gap-2.5">
            <h2 className="t-section">Ton groupe famille</h2>
            <Card className="flex items-center gap-3 p-4">
              <ServiceLogo service={s} size={40} />
              <span className="flex flex-1 flex-col gap-0.5">
                <span className="text-[15px] font-bold">{s.name} · ton propre compte</span>
                <span className="text-[13px] font-semibold text-muted">Groupe attribué par Sub.ci · tous appareils</span>
              </span>
            </Card>
            <p className="px-1 text-[13px] leading-normal font-medium text-muted">
              Tu paies maintenant, l’hôte du groupe valide ta demande sous 24 h. Sinon, tu es remboursé automatiquement.
            </p>
          </section>
        )}
        {!current && offer && (
          <section className="flex flex-col gap-2.5">
            <h2 className="t-section">Ton offre</h2>
            <OfferOption offer={offer} />
            <p className="px-1 text-[13px] leading-normal font-medium text-muted">
              Tu paies maintenant, {offer.host.name} accepte ta demande sous 24 h. Sinon, tu es remboursé automatiquement.
            </p>
          </section>
        )}
        {needsAppleId && (
          <section className="flex flex-col gap-2.5">
            <h2 className="t-section">Ton identifiant Apple</h2>
            <input
              type="email"
              inputMode="email"
              autoComplete="email"
              placeholder="ton.email@icloud.com"
              aria-label="E-mail de ton identifiant Apple"
              value={appleId}
              onChange={(e) => setAppleId(e.target.value)}
              className="h-14 rounded-btn border-[1.5px] border-line bg-surface px-4 text-base font-semibold outline-none placeholder:font-medium placeholder:text-subtle focus:border-2 focus:border-ink"
            />
            <p className="px-1 text-[13px] leading-normal font-medium text-muted">
              L’e-mail de ton compte Apple (Réglages → ton nom). Ton hôte s’en sert pour t’inviter dans son Partage familial : il ne le voit qu’après t’avoir accepté.
            </p>
          </section>
        )}
        <section className="flex flex-col gap-2.5">
          <h2 className="t-section">Durée</h2>
          <div role="radiogroup" aria-label="Durée" className="grid grid-cols-3 gap-2">
            {DURATIONS.map((d) => {
              const on = d.months === months
              return (
                <button
                  key={d.months}
                  type="button"
                  role="radio"
                  aria-checked={on}
                  onClick={() => setMonths(d.months)}
                  className={cx('pressable relative flex flex-col gap-1 rounded-btn bg-surface p-3 text-left', on ? 'border-2 border-ink' : 'border-[1.5px] border-line')}
                >
                  <span className="text-sm font-bold">{d.months} mois</span>
                  <span className="font-display text-base font-extrabold">{monthly ? fcfa(durationPrice(monthly, d.months)) : '—'}</span>
                  {d.discount > 0 && (
                    <span className={cx('absolute -top-2.5 right-2 rounded-md px-[7px] py-0.5 text-[11px] font-extrabold', on ? 'bg-brand text-on-accent' : 'bg-brand-soft text-brand-ink')}>
                      -{d.discount * 100} %
                    </span>
                  )}
                </button>
              )
            })}
          </div>
        </section>
        <section className="flex flex-col gap-2.5">
          <h2 className="t-section">Payer avec</h2>
          <PayMethodPicker value={method} onChange={setMethod} phone={phone} onPhone={setPhone} />
        </section>
        <div className="flex gap-3 rounded-card bg-ok-soft p-4 text-[13px] leading-[1.45] font-semibold text-ok-ink">
          <IconLock size={18} className="mt-0.5 shrink-0" />
          <span>
            <b>Paiement protégé.</b> Sub.ci garde ton argent et le verse à l’hôte mois par mois. Si l’accès ne marche pas, signale un souci : tu es remboursé·e du temps restant.
          </span>
        </div>
      </div>
      <StickyAction className="flex flex-col gap-2.5">
        <div className="flex flex-col gap-1 text-sm font-semibold text-muted">
          <div className="flex justify-between">
            <span>Abonnement · {months} mois</span>
            <span className="tabular-nums">{fcfa(subtotal)} FCFA</span>
          </div>
          {baseFee > 0 && (
            <div className="flex justify-between">
              <span>Frais de service Sub.ci{feeWaived ? ' · offerts (parrainage)' : ''}</span>
              <span className="tabular-nums">{feeWaived ? <s>{fcfa(baseFee)} FCFA</s> : `${fcfa(fee)} FCFA`}</span>
            </div>
          )}
          {!feeWaived && months < 3 && state.serviceFee > state.serviceFeeLong && (
            <span className="text-[12px] text-ok-ink">Frais réduits à {fcfa(state.serviceFeeLong)} FCFA en payant 3 mois ou plus</span>
          )}
          {credit > 0 && (
            <div className="flex justify-between text-ok-ink">
              <span>Crédit parrainage</span>
              <span className="tabular-nums">−{fcfa(credit)} FCFA</span>
            </div>
          )}
          <div className="flex justify-between text-ink">
            <span className="flex items-center gap-1.5 font-bold">
              <IconLock size={14} />
              Total sécurisé
            </span>
            <span className="tabular-nums font-bold">{fcfa(amount)} FCFA</span>
          </div>
        </div>
        <Button onClick={pay} loading={loading} disabled={!monthly}>
          {loading ? 'Paiement…' : `Payer ${fcfa(amount)} FCFA`}
        </Button>
      </StickyAction>
    </Screen>
  )
}

/* ---------- 09 · Paiement en cours ---------- */

function secondsLeft(p?: PendingPayment) {
  return p?.expiresAt ? Math.max(0, Math.round((p.expiresAt - Date.now()) / 1000)) : 0
}

/** Pas de spinner muet : quoi faire, où, et combien de temps il reste. Interroge l'API toutes les 2 s. */
export function Paying() {
  const { ref = '' } = useParams()
  const loc = useLocation()
  const navigate = useNavigate()
  const back = useBack()
  const { actions } = useStore()
  const toast = useToast()
  const [p, setP] = useState<PendingPayment | undefined>(loc.state as PendingPayment | undefined)
  const [left, setLeft] = useState(() => secondsLeft(loc.state as PendingPayment | undefined))
  const [help, setHelp] = useState(false)
  const [resending, setResending] = useState(false)
  const done = useRef(false)
  const shownAt = useRef(Date.now())

  // Polling du statut côté opérateur.
  useEffect(() => {
    let alive = true
    const tick = async () => {
      try {
        const next = await actions.payment(ref)
        if (!alive || done.current) return
        setP(next)
        if (next.status === 'succeeded') {
          done.current = true
          navigate(`/success/${ref}`, { replace: true, state: next, viewTransition: true })
        } else if (next.status === 'expired' || next.status === 'failed') {
          done.current = true
          toast({ tone: 'error', text: 'Demande expirée — aucun montant débité. Réessaie.' })
          back()
        }
      } catch {
        /* réseau instable : on réessaie au prochain tour */
      }
    }
    tick()
    const t = setInterval(tick, 2000)
    // Retour dans l'app après avoir payé ailleurs : on relit tout de suite.
    const onVisible = () => document.visibilityState === 'visible' && tick()
    document.addEventListener('visibilitychange', onVisible)
    return () => {
      alive = false
      clearInterval(t)
      document.removeEventListener('visibilitychange', onVisible)
    }
  }, [ref, actions, navigate, toast, back])

  useEffect(() => {
    const t = setInterval(() => setLeft(secondsLeft(p)), 1000)
    setLeft(secondsLeft(p))
    return () => clearInterval(t)
  }, [p])

  // Retour depuis la page de paiement : on relit le statut avant d'afficher quoi que ce soit.
  if (!p)
    return (
      <Screen>
        <div className="flex flex-1 flex-col items-center justify-center gap-4 px-6 text-center" aria-live="polite">
          <span className="size-8 rounded-full border-[3px] border-line border-t-brand animate-spin-fast" aria-hidden />
          <p className="font-display text-xl font-bold">On vérifie ton paiement…</p>
          <p className="text-sm font-medium text-muted">Ne ferme pas l’app, ça prend quelques secondes.</p>
        </div>
      </Screen>
    )
  const s = getService(p.serviceId)
  if (!s) return <Navigate to="/explore" replace />
  const m = getMethod(p.method)
  // Étape 2 (« saisis ton code ») mise en avant après quelques secondes.
  const step = Date.now() - shownAt.current > 2500 ? 2 : 1

  const hosted = !!p.checkoutUrl
  const steps = hosted
    ? ['Ouvre la page de paiement sécurisée', `Valide avec ${m.name}`, 'On confirme automatiquement']
    : p.method === 'card'
      ? ['Confirme sur la page 3-D Secure', 'Valide avec ta banque', 'On confirme automatiquement']
      : [`Ouvre la demande ${m.name}`, 'Saisis ton code secret', 'On confirme automatiquement']

  return (
    <Screen>
      <div className="flex flex-col items-center gap-6 px-6 pt-10 text-center">
        <div className="relative grid size-[180px] place-items-center" aria-hidden>
          <span className="absolute inset-5 rounded-full animate-ring" style={{ background: m.color }} />
          <span className="absolute inset-5 rounded-full animate-ring [animation-delay:.9s]" style={{ background: m.color }} />
          <span className="relative grid size-24 place-items-center rounded-[28px] text-[28px] font-extrabold" style={{ background: m.color, color: m.fg }}>
            {m.mono}
          </span>
        </div>
        <div className="flex flex-col gap-2.5">
          <h1 className="t-title">{hosted ? 'On vérifie ton paiement' : 'Valide sur ton téléphone'}</h1>
          <p className="text-base leading-normal font-medium text-pretty text-muted">
            {hosted ? (
              <>
                Paiement de <b className="text-ink">{fcfa(p.amount)} FCFA</b> via {m.name}.{' '}
                {keepsAppOpen() ? 'Paie sur la page sécurisée, puis reviens ici : la confirmation s’affiche toute seule.' : 'Dès qu’il est validé, on continue automatiquement.'}
              </>
            ) : (
              <>
                Une demande de <b className="text-ink">{fcfa(p.amount)} FCFA</b> a été envoyée {p.method === 'card' ? 'à ta banque' : <>au {maskPhone(p.phone)}</>}.
              </>
            )}
          </p>
        </div>
        <Card className="w-full px-[18px] py-1.5 text-left">
          {steps.map((t, i) => {
            const n = i + 1
            return (
              <div key={t} className="flex items-center gap-3.5 border-b border-line-soft py-3 last:border-b-0">
                <span className={cx('grid size-7 shrink-0 place-items-center rounded-full text-[13px] font-extrabold transition-colors duration-300', n <= 2 ? 'bg-ink text-sand' : 'bg-line')}>
                  {n < step ? <IconCheck size={14} /> : n}
                </span>
                <span className={cx('text-[15px] font-semibold', n > 2 && 'text-muted')}>{t}</span>
              </div>
            )
          })}
        </Card>
        <div className="flex items-center gap-2.5 text-sm font-bold text-muted" aria-live="polite">
          <span className="size-4 rounded-full border-[2.5px] border-line border-t-brand animate-spin-fast" aria-hidden />
          En attente · expire dans {mmss(left)}
        </div>
      </div>
      <div className="mt-auto flex flex-col gap-1.5 px-6 pt-8 pb-[calc(env(safe-area-inset-bottom)+40px)]">
        {hosted ? (
          keepsAppOpen() ? (
            <Button onClick={() => window.open(p.checkoutUrl!, '_blank', 'noopener')}>Ouvrir la page de paiement</Button>
          ) : (
            <Button onClick={() => window.location.assign(p.checkoutUrl!)}>Reprendre le paiement</Button>
          )
        ) : (
          <Button variant="outline" size="md" onClick={() => setHelp(true)}>
            Je n’ai rien reçu
          </Button>
        )}
        <Button
          variant="text"
          size="link"
          onClick={() => {
            done.current = true
            actions.cancelPayment(ref)
            back()
          }}
        >
          Annuler
        </Button>
      </div>

      <Sheet open={help} onClose={() => setHelp(false)} label="Je n’ai rien reçu">
        <div className="flex flex-col gap-4">
          <h2 className="font-display text-2xl font-bold tracking-[-0.02em]">Pas de demande reçue ?</h2>
          <p className="text-[15px] leading-normal font-medium text-muted">
            Vérifie que ton téléphone a du réseau et que le numéro {maskPhone(p.phone)} est bien ton compte {m.name}.
          </p>
          {m.ussd && (
            <div className="flex items-center justify-between rounded-btn bg-sand p-4">
              <span className="flex flex-col gap-0.5">
                <span className="text-[13px] font-semibold text-muted">Ou compose le code</span>
                <span className="font-display text-2xl font-extrabold">{m.ussd}</span>
              </span>
              <a href={`tel:${encodeURIComponent(m.ussd)}`} className="pressable flex h-11 items-center rounded-[14px] bg-ink px-4 text-sm font-bold text-sand">
                Appeler
              </a>
            </div>
          )}
          <Button
            loading={resending}
            onClick={async () => {
              setResending(true)
              try {
                setP(await actions.resendPayment(ref))
                shownAt.current = Date.now()
                setHelp(false)
                toast({ text: 'Nouvelle demande envoyée' })
              } catch (e) {
                toast({ tone: 'error', text: errorMessage(e) })
              } finally {
                setResending(false)
              }
            }}
          >
            Renvoyer la demande
          </Button>
        </div>
      </Sheet>
    </Screen>
  )
}

/* ---------- 10 · Paiement réussi ---------- */

/** Écran Ink = moment fort. Le check se dessine (400 ms) + vibration 20 ms. */
export function Success() {
  const { ref = '' } = useParams()
  const loc = useLocation()
  const navigate = useNavigate()
  const { state, actions } = useStore()
  const [p, setP] = useState<PendingPayment | undefined>(loc.state as PendingPayment | undefined)

  useEffect(() => haptic(20), [])
  // Rechargement de la page : on relit le paiement.
  useEffect(() => {
    if (!p) actions.payment(ref).then(setP).catch(() => navigate('/home', { replace: true }))
  }, [p, ref, actions, navigate])

  if (!p) return <Screen dark><span /></Screen>
  const s = getService(p.serviceId)
  const pendingInvite = state.subs.find((x) => x.id === p.subscriptionId)?.state === 'pending'
  if (!s || p.status !== 'succeeded') return <Navigate to="/home" replace />
  const request = state.requests.find((r) => r.status === 'pending' && r.serviceId === p.serviceId)
  const awaitingHost = p.joinStatus === 'pending' || (!p.subscriptionId && !!p.hostName)

  // Nouvel arrivant : paiement reçu, l'hôte doit accepter.
  if (awaitingHost)
    return (
      <Screen dark>
        <div className="flex flex-col gap-7 px-6 pt-14">
          <div className="grid size-[88px] place-items-center rounded-full bg-brand animate-pop">
            <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="#16130F" strokeWidth="2.6" strokeLinecap="round" strokeLinejoin="round" aria-hidden>
              <path d="m5 12.5 4.5 4.5L19 7.5" strokeDasharray="24" className="animate-draw" />
            </svg>
          </div>
          <div className="flex flex-col gap-2.5">
            <span className="t-over tracking-[0.1em] text-brand">Paiement reçu</span>
            <h1 className="t-display">Demande envoyée à {p.hostName?.replace(/\.$/, '')}.</h1>
          </div>
          <div className="rounded-card bg-ink-2 px-[18px] py-1">
            <Row dark label="Offre" value={s.name} />
            <Row dark label="Montant" value={`${fcfa(p.amount)} FCFA · ${p.months} mois`} />
            <Row dark label="Réponse" value={request ? `d’ici ${timeLeft(request.expiresAt)}` : 'sous 24 h'} />
            <Row dark label="Référence" value={p.ref} />
          </div>
          <p className="flex items-start gap-3 text-[15px] leading-[1.4] font-semibold text-ink-soft">
            <span className="mt-1.5 size-2.5 shrink-0 rounded-full bg-warn" />
            Tu reçois une notification dès que {p.hostName} répond. Si {p.hostName} refuse ou ne répond pas sous 24 h, tu es remboursé automatiquement.
          </p>
        </div>
        <div className="mt-auto flex flex-col gap-1.5 px-6 pt-8 pb-[calc(env(safe-area-inset-bottom)+40px)]">
          <Button onClick={() => navigate('/subs', { replace: true })}>Suivre ma demande</Button>
          <Button variant="ghost-dark" size="link" onClick={() => navigate('/home', { replace: true })}>
            Retour à l’accueil
          </Button>
        </div>
      </Screen>
    )

  return (
    <Screen dark>
      <div className="flex flex-col gap-7 px-6 pt-14">
        <div className="grid size-[88px] place-items-center rounded-full bg-brand animate-pop">
          <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="#16130F" strokeWidth="2.6" strokeLinecap="round" strokeLinejoin="round" aria-hidden>
            <path d="m5 12.5 4.5 4.5L19 7.5" strokeDasharray="24" className="animate-draw" />
          </svg>
        </div>
        <div className="flex flex-col gap-2.5">
          <span className="t-over tracking-[0.1em] text-brand">Paiement confirmé</span>
          <h1 className="t-display">{p.subscriptionId && !pendingInvite ? 'C’est renouvelé.' : `Bienvenue dans ${s.name}.`}</h1>
        </div>
        <div className="rounded-card bg-ink-2 px-[18px] py-1">
          <Row dark label="Montant" value={`${fcfa(p.amount)} FCFA`} />
          {p.periodStart && p.periodEnd && <Row dark label="Période" value={`${shortDate(p.periodStart)} → ${shortDate(p.periodEnd)}`} />}
          <Row dark label="Référence" value={p.ref} />
        </div>
        <p className="flex items-center gap-3 text-[15px] leading-[1.4] font-semibold text-ink-soft">
          <span className="size-2.5 shrink-0 rounded-full bg-ok-glow" />
          {pendingInvite ? 'Ton hôte t’envoie l’invitation famille. On te prévient dès que c’est actif.' : 'Tes accès sont prêts. Ton reçu est dans Historique des paiements.'}
        </p>
      </div>
      <div className="mt-auto flex flex-col gap-1.5 px-6 pt-8 pb-[calc(env(safe-area-inset-bottom)+40px)]">
        <Button onClick={() => navigate(p.subscriptionId ? `/subs/${p.subscriptionId}` : '/subs', { replace: true, viewTransition: true })}>Voir mes accès</Button>
        <Button variant="ghost-dark" size="link" onClick={() => navigate('/home', { replace: true })}>
          Retour à l’accueil
        </Button>
      </div>
    </Screen>
  )
}

/**
 * Retour de la page de paiement (success_url). Sur iPhone, il s'ouvre souvent dans Safari, sans session :
 * on n'affiche pas l'écran de connexion, on invite à revenir dans l'app (qui confirme toute seule).
 */
export function PaymentReturn() {
  const { ref = '' } = useParams()
  if (isStandalone() || getToken()) return <Navigate to={`/pay/${ref}`} replace />
  return (
    <Screen>
      <div className="flex flex-1 flex-col items-center justify-center gap-5 px-6 text-center">
        <span className="grid size-20 place-items-center rounded-full bg-ok-soft text-ok-ink">
          <IconCheck size={36} />
        </span>
        <div className="flex flex-col gap-2">
          <h1 className="t-title">Paiement envoyé</h1>
          <p className="text-base leading-normal font-medium text-pretty text-muted">
            Retourne sur l’app <b className="text-ink">Sub.ci</b> depuis ton écran d’accueil : la confirmation s’y affiche toute seule.
          </p>
        </div>
        <p className="text-[13px] font-semibold text-muted">Tu peux fermer cette page.</p>
      </div>
    </Screen>
  )
}

