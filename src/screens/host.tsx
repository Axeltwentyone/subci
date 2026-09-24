import { useId, useState } from 'react'
import { useNavigate, useParams } from 'react-router'
import { IconCheck, IconChevronLeft, IconMinus, IconPlus } from '../components/icons'
import { Sheet } from '../components/Sheet'
import { useToast } from '../components/Toast'
import { BackButton, Badge, Button, Radio, RoundIconButton, Screen, ServiceLogo, StepBar, StickyAction, Steps, TopBar, cx } from '../components/ui'
import { HOST_FEE, HOST_PLANS, getService } from '../lib/data'
import { fcfa, haptic, shortDate } from '../lib/format'
import { errorMessage, hostNet, useStore, type HostOffer, type Member } from '../lib/store'
import { NotFound } from './discover'
import { ConfirmModal } from './manage'

/* ---------- 21 · Devenir hôte ---------- */

/** Écran Ink = moment clé. Le gain est calculé avec son propre abonnement, frais inclus, avant tout formulaire. */
export function HostPitch() {
  const navigate = useNavigate()
  const [how, setHow] = useState(false)
  const plan = HOST_PLANS.netflix
  const seats = plan.maxShare
  const price = 2500
  const { gross, fee, net } = hostNet(price, seats)

  return (
    <Screen dark>
      <div className="flex items-center px-4 pt-1">
        <BackButton dark />
      </div>
      <div className="flex flex-col gap-[22px] px-6 pt-4">
        <div className="flex flex-col gap-3">
          <span className="t-over text-[13px] tracking-[0.1em] text-brand">Partager & gagner</span>
          <h1 className="font-display text-4xl leading-[1.04] font-bold tracking-[-0.03em] text-pretty">Tes places libres te rapportent chaque mois.</h1>
        </div>
        <div className="flex flex-col gap-3.5 rounded-[24px] bg-sand p-5 text-ink">
          <div className="flex items-center gap-3">
            <ServiceLogo service={getService('netflix')!} size={44} />
            <div className="flex flex-1 flex-col gap-0.5">
              <span className="text-base font-bold">Ton Netflix Premium</span>
              <span className="text-[13px] font-semibold text-muted">Tu paies {fcfa(plan.own)} FCFA/mois</span>
            </div>
          </div>
          <div className="flex justify-between border-t border-line pt-3 text-sm font-semibold text-muted">
            <span>
              {seats} places × {fcfa(price)} FCFA
            </span>
            <span className="font-bold text-ink">{fcfa(gross)}</span>
          </div>
          <div className="flex justify-between text-sm font-semibold text-muted">
            <span>Frais Sub.ci ({HOST_FEE * 100} %)</span>
            <span>– {fcfa(fee)}</span>
          </div>
          <div className="flex items-baseline justify-between border-t border-line pt-3">
            <span className="text-[15px] font-bold">Tu reçois</span>
            <span className="font-display text-[28px] leading-none font-extrabold">
              {fcfa(net)} <span className="font-sans text-[13px] font-bold">FCFA/mois</span>
            </span>
          </div>
        </div>
        <Steps
          items={['Tu choisis le service et le nombre de places', 'Sub.ci trouve les membres et encaisse pour toi', 'Tu retires sur Wave ou Orange Money']}
        />
      </div>
      <div className="mt-auto flex flex-col gap-1.5 px-6 pt-8 pb-[calc(env(safe-area-inset-bottom)+40px)]">
        <Button onClick={() => navigate('/host/new', { viewTransition: true })}>Partager un abonnement</Button>
        <Button variant="ghost-dark" size="link" onClick={() => setHow(true)}>
          Comment ça marche ?
        </Button>
      </div>
      <Sheet open={how} onClose={() => setHow(false)} label="Comment ça marche">
        <div className="flex flex-col gap-4">
          <h2 className="font-display text-2xl font-bold tracking-[-0.02em]">Comment ça marche ?</h2>
          {[
            ['Qui paie ?', 'Chaque membre paie sa place à Sub.ci en mobile money. Tu es crédité le 1er de chaque mois.'],
            ['Et si un membre part ?', 'Sa place est remise en ligne automatiquement. Tu n’as rien à faire.'],
            ['Mes identifiants ?', 'Chiffrés, visibles uniquement par les membres qui ont payé.'],
            ['Si l’accès coupe ?', 'Le membre est remboursé et tu n’es pas payé pour ce mois. Préviens tes membres avant tout changement.'],
          ].map(([q, a]) => (
            <div key={q} className="flex flex-col gap-1">
              <span className="text-[15px] font-bold">{q}</span>
              <span className="text-sm leading-normal font-medium text-muted">{a}</span>
            </div>
          ))}
          <Button variant="ink" size="md" onClick={() => setHow(false)}>
            Compris
          </Button>
        </div>
      </Sheet>
    </Screen>
  )
}

/* ---------- 22 · Configurer l'offre + 23 · Accès & publication ---------- */

const HOST_SERVICES = ['netflix', 'spotify', 'youtube', 'other'] as const
type HostSvc = (typeof HOST_SERVICES)[number]

export function HostSetup() {
  const navigate = useNavigate()
  const { actions } = useStore()
  const toast = useToast()
  const [step, setStep] = useState<1 | 2>(1)
  const [svc, setSvc] = useState<HostSvc>('netflix')
  const plan = HOST_PLANS[svc]
  const [seats, setSeats] = useState(plan.maxShare)
  const [price, setPrice] = useState(2500)
  const [mode, setMode] = useState(plan.mode)
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [showPwd, setShowPwd] = useState(false)
  const [proof, setProof] = useState<File | null>(null)
  const [agree, setAgree] = useState(false)
  const [loading, setLoading] = useState(false)
  const proofId = useId()

  const pick = (id: HostSvc) => {
    if (id === 'other') {
      toast({ text: 'Un autre service ? Écris-nous sur WhatsApp, on l’ajoute.' })
      return
    }
    setSvc(id)
    const p = HOST_PLANS[id]
    setSeats(p.maxShare)
    setPrice(Math.round((p.reco[0] + p.reco[1]) / 2 / 100) * 100)
    setMode(p.mode)
  }

  const { net } = hostNet(price, seats)
  const canPublish = agree && !!proof && (mode === 'family' || (email.includes('@') && password.length >= 4))
  const service = svc === 'other' ? { mono: '+', color: '#E7E1D6', fg: '#16130F', name: 'Autre' } : getService(svc)!

  const publish = async () => {
    if (!proof || svc === 'other') return
    const form = new FormData()
    form.append('serviceId', svc)
    form.append('seats', String(seats))
    form.append('price', String(price))
    form.append('mode', mode)
    if (mode === 'credentials') {
      form.append('email', email)
      form.append('password', password)
    }
    form.append('proof', proof)
    setLoading(true)
    try {
      await actions.publishOffer(form)
      haptic(20)
      toast({ tone: 'success', text: 'Offre envoyée. Vérification de la preuve sous 1 h.' })
      navigate('/subs?mode=host', { replace: true })
    } catch (e) {
      toast({ tone: 'error', text: errorMessage(e) })
      setLoading(false)
    }
  }

  return (
    <Screen>
      {step === 1 ? (
        <TopBar title="Partager" right={<span className="text-[13px] font-bold text-muted">1/2</span>} />
      ) : (
        <div className="flex items-center justify-between gap-3 px-4 pt-1 pb-1">
          <RoundIconButton label="Retour" onClick={() => setStep(1)}>
            <IconChevronLeft size={20} />
          </RoundIconButton>
          <span className="text-base font-bold">Partager</span>
          <span className="min-w-11 text-right text-[13px] font-bold text-muted">2/2</span>
        </div>
      )}
      <StepBar step={step} total={2} />

      {step === 1 ? (
        <>
          <div className="flex flex-col gap-[22px] px-5 pt-5">
            <section className="flex flex-col gap-2.5">
              <h2 className="t-section">Quel abonnement ?</h2>
              <div role="radiogroup" aria-label="Service" className="grid grid-cols-4 gap-2">
                {HOST_SERVICES.map((id) => {
                  const s = id === 'other' ? { mono: '+', color: '#E7E1D6', fg: '#16130F', name: 'Autre' } : getService(id)!
                  const on = id === svc
                  return (
                    <button
                      key={id}
                      type="button"
                      role="radio"
                      aria-checked={on}
                      onClick={() => pick(id)}
                      className={cx('pressable flex h-[76px] flex-col items-center justify-center gap-1.5 rounded-btn bg-white', on ? 'border-2 border-ink' : 'border-[1.5px] border-line')}
                    >
                      <ServiceLogo service={s} size={36} radius={10} />
                      <span className="text-[11px] font-bold">{s.name.split(' ')[0]}</span>
                    </button>
                  )
                })}
              </div>
              <div className="flex h-[52px] items-center justify-between rounded-[14px] bg-white px-4 text-[15px] font-bold">
                <span>Formule</span>
                <span className="font-semibold text-muted">{plan.label}</span>
              </div>
            </section>

            <SeatStepper
              value={seats}
              onChange={setSeats}
              min={1}
              max={plan.maxShare}
              hint={`Tu gardes ${svc === 'netflix' ? '1 écran' : '1 compte'}`}
            />

            <PriceCard value={price} onChange={setPrice} reco={plan.reco} />
          </div>
          <StickyAction className="flex flex-col gap-2.5">
            <div className="flex items-baseline justify-between">
              <span className="text-sm font-semibold text-muted">Tu reçois / mois</span>
              <span className="font-display text-xl font-extrabold text-ok-ink" aria-live="polite">
                {fcfa(net)} FCFA
              </span>
            </div>
            <Button onClick={() => setStep(2)}>Continuer</Button>
          </StickyAction>
        </>
      ) : (
        <>
          <div className="flex flex-col gap-4 px-5 pt-5">
            <h2 className="t-section">Comment tes membres accèdent ?</h2>

            <div role="radiogroup" aria-label="Mode d’accès" className="flex flex-col gap-4">
              <div className={cx('flex flex-col gap-3 rounded-card p-4', mode === 'credentials' ? 'border-2 border-brand bg-brand-tint' : 'bg-white')}>
                <button type="button" role="radio" aria-checked={mode === 'credentials'} onClick={() => setMode('credentials')} className="flex items-center gap-3 text-left">
                  <span className="flex flex-1 flex-col gap-0.5">
                    <span className="text-base font-bold">Identifiants + profil</span>
                    <span className="text-[13px] font-semibold text-muted">Chiffrés, visibles seulement après paiement</span>
                  </span>
                  <Radio checked={mode === 'credentials'} />
                </button>
                {mode === 'credentials' && (
                  <>
                    <input
                      type="email"
                      autoComplete="off"
                      aria-label={`Email du compte ${service.name}`}
                      placeholder={`Email du compte ${service.name.split(' ')[0]}`}
                      value={email}
                      onChange={(e) => setEmail(e.target.value)}
                      className="h-12 rounded-tile border-[1.5px] border-line bg-white px-3.5 text-[16px] font-semibold outline-none placeholder:font-medium placeholder:text-subtle focus:border-2 focus:border-ink"
                    />
                    <div className="flex h-12 items-center rounded-tile border-[1.5px] border-line bg-white pr-3.5 focus-within:border-2 focus-within:border-ink">
                      <input
                        type={showPwd ? 'text' : 'password'}
                        autoComplete="off"
                        aria-label="Mot de passe"
                        placeholder="Mot de passe"
                        value={password}
                        onChange={(e) => setPassword(e.target.value)}
                        className="h-full min-w-0 flex-1 bg-transparent px-3.5 text-[16px] font-semibold outline-none placeholder:font-medium placeholder:text-subtle"
                      />
                      <button type="button" onClick={() => setShowPwd((v) => !v)} className="text-[13px] font-bold text-muted">
                        {showPwd ? 'Masquer' : 'Afficher'}
                      </button>
                    </div>
                  </>
                )}
              </div>

              <button
                type="button"
                role="radio"
                aria-checked={mode === 'family'}
                onClick={() => setMode('family')}
                className={cx('flex items-center gap-3 rounded-card p-4 text-left', mode === 'family' ? 'border-2 border-brand bg-brand-tint' : 'bg-white')}
              >
                <span className="flex flex-1 flex-col gap-0.5">
                  <span className="text-base font-bold">Invitation famille</span>
                  <span className="text-[13px] font-semibold text-muted">Spotify, YouTube : tu invites chaque membre</span>
                </span>
                <Radio checked={mode === 'family'} />
              </button>
            </div>

            <label htmlFor={proofId} className="pressable flex items-center gap-3 rounded-card bg-white p-4">
              <span className={cx('grid size-11 shrink-0 place-items-center rounded-tile', proof ? 'bg-ok-soft text-ok-ink' : 'bg-brand-soft text-brand-ink')}>
                {proof ? <IconCheck size={20} /> : <IconPlus size={18} />}
              </span>
              <span className="flex flex-1 flex-col gap-0.5">
                <span className="text-[15px] font-bold">Preuve d’abonnement</span>
                <span className="text-[13px] font-semibold text-muted">{proof ? 'Capture ajoutée · vérif. sous 1 h' : 'Capture de ta page « Compte »'}</span>
              </span>
              <input
                id={proofId}
                type="file"
                accept="image/*"
                className="sr-only"
                onChange={(e) => {
                  const f = e.target.files?.[0]
                  if (f) setProof(f)
                }}
              />
            </label>

            <label className="flex cursor-pointer items-start gap-3 px-1">
              <input type="checkbox" checked={agree} onChange={(e) => setAgree(e.target.checked)} className="peer sr-only" />
              <span
                aria-hidden
                className={cx('grid size-[22px] shrink-0 place-items-center rounded-md text-[13px] font-extrabold text-white peer-focus-visible:outline-2 peer-focus-visible:outline-brand', agree ? 'bg-ink' : 'border-2 border-radio bg-white')}
              >
                {agree && '✓'}
              </span>
              <span className="text-sm leading-[1.45] font-semibold text-body">
                Je garde l’abonnement actif et je ne change pas le mot de passe sans prévenir mes membres.
              </span>
            </label>
          </div>
          <StickyAction>
            <Button onClick={publish} loading={loading} disabled={!canPublish}>
              Publier mon offre
            </Button>
          </StickyAction>
        </>
      )}
    </Screen>
  )
}

/* ---------- Gérer une offre partagée ---------- */

const OFFER_STATUS: Record<HostOffer['status'], { label: string; tone: 'active' | 'pending' | 'due' | 'expired' }> = {
  live: { label: 'En ligne', tone: 'active' },
  review: { label: 'Vérification · sous 1 h', tone: 'pending' },
  paused: { label: 'En pause', tone: 'due' },
  closed: { label: 'Arrêtée', tone: 'expired' },
}

/**
 * Modifier ce qu'on partage : prix (au prochain renouvellement), places,
 * identifiants (poussés dans le coffre des membres), membres, pause / arrêt.
 */
export function ManageOffer() {
  const { id = '' } = useParams()
  const navigate = useNavigate()
  const { state, actions } = useStore()
  const toast = useToast()
  const offer = state.offers.find((o) => o.id === id)
  const [price, setPrice] = useState(offer?.price ?? 2500)
  const [seats, setSeats] = useState(offer?.seats ?? 1)
  const [email, setEmail] = useState(offer?.email ?? '')
  const [password, setPassword] = useState('')
  const [showPwd, setShowPwd] = useState(false)
  const [saving, setSaving] = useState(false)
  const [confirm, setConfirm] = useState<{ kind: 'remove'; member: Member } | { kind: 'close' } | null>(null)

  if (!offer) return <NotFound />
  const svc = getService(offer.serviceId)!
  const plan = HOST_PLANS[offer.serviceId] ?? HOST_PLANS.other
  const members = offer.members
  const closed = offer.status === 'closed'
  const status = OFFER_STATUS[offer.status]
  const dirty = price !== offer.price || seats !== offer.seats || (offer.mode === 'credentials' && (email !== (offer.email ?? '') || password !== ''))
  const { net } = hostNet(price, members.length)

  const save = async () => {
    const body: Parameters<typeof actions.updateOffer>[1] = {}
    if (price !== offer.price) body.price = price
    if (seats !== offer.seats) body.seats = seats
    if (offer.mode === 'credentials' && email !== (offer.email ?? '')) body.email = email
    if (offer.mode === 'credentials' && password) body.password = password
    setSaving(true)
    try {
      await actions.updateOffer(offer.id, body)
      setPassword('')
      haptic(20)
      toast({ tone: 'success', text: body.email || body.password ? 'Enregistré · tes membres ont reçu les nouveaux accès' : 'Offre mise à jour' })
    } catch (e) {
      toast({ tone: 'error', text: errorMessage(e) })
    } finally {
      setSaving(false)
    }
  }

  const run = async (fn: () => Promise<unknown>, ok: string) => {
    try {
      await fn()
      toast({ text: ok })
    } catch (e) {
      toast({ tone: 'error', text: errorMessage(e) })
    }
  }

  return (
    <Screen>
      <TopBar title="Gérer l’offre" back="/subs?mode=host" />
      <div className="flex flex-col gap-4 px-5 pt-3">
        <div className="flex items-center gap-3.5">
          <ServiceLogo service={svc} size={56} />
          <div className="flex min-w-0 flex-1 flex-col gap-1.5">
            <h1 className="truncate font-display text-[22px] leading-none font-bold tracking-[-0.02em]">{svc.name}</h1>
            <Badge tone={status.tone} className="self-start py-1">{status.label}</Badge>
          </div>
        </div>

        <div className="flex items-center justify-between rounded-btn bg-ink px-4 py-3.5 text-sand">
          <div className="flex flex-col gap-0.5">
            <span className="text-xs font-semibold text-ink-muted">Tu reçois / mois</span>
            <span className="font-display text-xl font-extrabold">{fcfa(net)} FCFA</span>
          </div>
          <div className="flex flex-col gap-0.5 text-right">
            <span className="text-xs font-semibold text-ink-muted">Places</span>
            <span className="font-display text-xl font-extrabold">
              {members.length}/{seats}
            </span>
          </div>
        </div>

        {closed ? (
          <div className="flex gap-2.5 rounded-[14px] bg-warn-soft px-3.5 py-3 text-[13px] leading-[1.45] font-semibold text-[#6B3F00]">
            <span className="font-extrabold">!</span>
            Tu as arrêté ce partage. Tes membres gardent leur accès jusqu’à leur échéance, sans renouvellement.
          </div>
        ) : (
          <>
            <PriceCard value={price} onChange={setPrice} reco={plan.reco} note="Le nouveau prix s’applique au prochain renouvellement de tes membres." />
            <SeatStepper
              value={seats}
              onChange={setSeats}
              min={Math.max(1, members.length)}
              max={plan.maxShare}
              hint={`${members.length} membre${members.length > 1 ? 's' : ''} · ${Math.max(0, seats - members.length)} libre${seats - members.length > 1 ? 's' : ''}`}
            />

            <section className="flex flex-col gap-3 rounded-card bg-white p-[18px]">
              <span className="text-base font-bold">Accès des membres</span>
              {offer.mode === 'credentials' ? (
                <>
                  <input
                    type="email"
                    autoComplete="off"
                    aria-label={`Email du compte ${svc.name}`}
                    placeholder={`Email du compte ${svc.name.split(' ')[0]}`}
                    value={email}
                    onChange={(e) => setEmail(e.target.value)}
                    className="h-12 rounded-tile border-[1.5px] border-line bg-sand px-3.5 text-[16px] font-semibold outline-none placeholder:font-medium placeholder:text-subtle focus:border-2 focus:border-ink focus:bg-white"
                  />
                  <div className="flex h-12 items-center rounded-tile border-[1.5px] border-line bg-sand pr-3.5 focus-within:border-2 focus-within:border-ink focus-within:bg-white">
                    <input
                      type={showPwd ? 'text' : 'password'}
                      autoComplete="new-password"
                      aria-label="Nouveau mot de passe"
                      placeholder="Nouveau mot de passe (optionnel)"
                      value={password}
                      onChange={(e) => setPassword(e.target.value)}
                      className="h-full min-w-0 flex-1 bg-transparent px-3.5 text-[16px] font-semibold outline-none placeholder:font-medium placeholder:text-subtle"
                    />
                    <button type="button" onClick={() => setShowPwd((v) => !v)} className="text-[13px] font-bold text-muted">
                      {showPwd ? 'Masquer' : 'Afficher'}
                    </button>
                  </div>
                  <p className="text-[13px] leading-normal font-medium text-muted">
                    Tu as changé le mot de passe du compte ? Mets-le ici : tes membres le reçoivent dans leur coffre, avec une notification.
                  </p>
                </>
              ) : (
                <p className="text-[13px] leading-normal font-medium text-muted">Invitation famille : chaque membre rejoint avec son propre compte, rien à partager.</p>
              )}
            </section>
          </>
        )}

        <section className="flex flex-col gap-1 rounded-card bg-white px-[18px] py-2">
          <span className="pt-2 pb-1 text-base font-bold">Membres</span>
          {members.length === 0 && <p className="py-3 text-sm font-medium text-muted">Personne pour l’instant. On te prévient au 1er membre.</p>}
          {members.map((m) => (
            <div key={m.id} className="flex items-center gap-3 border-b border-line-soft py-3 last:border-b-0">
              <span className="grid size-10 shrink-0 place-items-center rounded-full text-sm font-extrabold" style={{ background: m.color }}>
                {m.name.charAt(0)}
              </span>
              <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                <span className="truncate text-[15px] font-bold">{m.name}</span>
                <span className={cx('text-[13px] font-semibold', m.invitePending ? 'text-info' : 'text-muted')}>
                  {m.invitePending ? 'Attend ton invitation famille' : m.joinedAt ? `Membre depuis le ${shortDate(Date.parse(m.joinedAt))}` : 'Membre'}
                </span>
              </span>
              {m.invitePending && !closed && (
                <button type="button" onClick={() => run(() => actions.invite(offer.id), `Invitation envoyée à ${m.name}`)} className="pressable h-9 rounded-[10px] bg-info-soft px-3 text-[13px] font-bold text-info">
                  Inviter
                </button>
              )}
              {!closed && (
                <button type="button" onClick={() => setConfirm({ kind: 'remove', member: m })} className="pressable h-9 rounded-[10px] px-2 text-[13px] font-bold text-err">
                  Retirer
                </button>
              )}
            </div>
          ))}
        </section>

        {offer.status === 'review' && (
          <div className="flex gap-2.5 rounded-[14px] bg-info-soft px-3.5 py-3 text-[13px] leading-[1.45] font-semibold text-info-ink">
            <span className="font-extrabold">i</span>
            On vérifie ta preuve d’abonnement. Tes places apparaîtront dans le catalogue dès qu’elle est validée.
          </div>
        )}

        {!closed && (
          <div className="flex flex-col gap-1 pt-1">
            {offer.status === 'review' ? null : offer.status === 'paused' ? (
              <Button variant="ink" size="md" onClick={() => run(() => actions.setOfferStatus(offer.id, 'resume'), 'Offre remise en ligne')}>
                Remettre en ligne
              </Button>
            ) : (
              <Button variant="outline" size="md" onClick={() => run(() => actions.setOfferStatus(offer.id, 'pause'), 'Offre en pause · plus de nouveaux membres')}>
                Mettre en pause
              </Button>
            )}
            {offer.status !== 'review' && <p className="px-2 pt-1 text-center text-[13px] font-medium text-muted">En pause, tes membres actuels gardent leur accès ; personne de nouveau ne rejoint.</p>}
            <button type="button" onClick={() => setConfirm({ kind: 'close' })} className="min-h-14 text-[15px] font-bold text-err">
              Arrêter de partager
            </button>
          </div>
        )}
      </div>

      {dirty && !closed ? (
        <StickyAction className="flex flex-col gap-2.5">
          <div className="flex items-baseline justify-between">
            <span className="text-sm font-semibold text-muted">Tu recevras / mois</span>
            <span className="font-display text-xl font-extrabold text-ok-ink">{fcfa(net)} FCFA</span>
          </div>
          <div className="grid grid-cols-[auto_1fr] gap-2.5">
            <Button
              variant="outline"
              size="lg"
              block={false}
              className="px-4"
              onClick={() => {
                setPrice(offer.price)
                setSeats(offer.seats)
                setEmail(offer.email ?? '')
                setPassword('')
              }}
            >
              Annuler
            </Button>
            <Button onClick={save} loading={saving} disabled={offer.mode === 'credentials' && password !== '' && password.length < 4}>
              Enregistrer
            </Button>
          </div>
        </StickyAction>
      ) : (
        <div className="h-10" />
      )}

      {confirm?.kind === 'remove' && (
        <ConfirmModal
          title={`Retirer ${confirm.member.name} ?`}
          text="Sa place est remise en ligne tout de suite. Pense à changer le mot de passe si tu partages des identifiants."
          confirm="Retirer"
          onCancel={() => setConfirm(null)}
          onConfirm={() => {
            const m = confirm.member
            setConfirm(null)
            run(() => actions.removeMember(offer.id, m.id), `${m.name} a été retiré·e`)
          }}
        />
      )}
      {confirm?.kind === 'close' && (
        <ConfirmModal
          title="Arrêter de partager ?"
          text="Plus personne ne pourra rejoindre. Tes membres gardent leur accès jusqu’à leur échéance, sans renouvellement."
          confirm="Arrêter"
          onCancel={() => setConfirm(null)}
          onConfirm={() => {
            setConfirm(null)
            run(() => actions.setOfferStatus(offer.id, 'close'), 'Partage arrêté').then(() => navigate('/subs?mode=host', { replace: true }))
          }}
        />
      )}
    </Screen>
  )
}

/* ---------- Contrôles partagés (création + gestion d'offre) ---------- */

function SeatStepper({ value, onChange, min, max, hint }: { value: number; onChange: (n: number) => void; min: number; max: number; hint: string }) {
  return (
    <div className="flex items-center justify-between rounded-card bg-white p-[18px]">
      <div className="flex flex-col gap-0.5">
        <span className="text-base font-bold">Places à partager</span>
        <span className="text-[13px] font-semibold text-muted">{hint}</span>
      </div>
      <div className="flex items-center gap-3.5">
        <button
          type="button"
          aria-label="Une place de moins"
          disabled={value <= min}
          onClick={() => onChange(Math.max(min, value - 1))}
          className="pressable grid size-11 place-items-center rounded-tile bg-sand disabled:bg-line disabled:text-[#8A8278]"
        >
          <IconMinus size={18} />
        </button>
        <span className="w-5 text-center font-display text-[26px] font-extrabold" aria-live="polite">
          {value}
        </span>
        <button
          type="button"
          aria-label="Une place de plus"
          disabled={value >= max}
          onClick={() => onChange(Math.min(max, value + 1))}
          className="pressable grid size-11 place-items-center rounded-tile bg-sand disabled:bg-line disabled:text-[#8A8278]"
        >
          <IconPlus size={18} />
        </button>
      </div>
    </div>
  )
}

const MIN_PRICE = 500
const MAX_PRICE = 5000

/** Curseur de prix avec la zone conseillée (repère vert). */
function PriceCard({ value, onChange, reco, note }: { value: number; onChange: (n: number) => void; reco: [number, number]; note?: string }) {
  const id = useId()
  const [lo, hi] = reco
  const pct = (v: number) => ((v - MIN_PRICE) / (MAX_PRICE - MIN_PRICE)) * 100
  const zone = value < lo ? 'low' : value > hi ? 'high' : 'ok'
  return (
    <div className="flex flex-col gap-3.5 rounded-card bg-white p-[18px]">
      <div className="flex items-baseline justify-between">
        <label htmlFor={id} className="text-base font-bold">
          Prix par place
        </label>
        <span className="font-display text-2xl leading-none font-extrabold">
          {fcfa(value)} <span className="font-sans text-xs font-bold">FCFA</span>
        </span>
      </div>
      <div className="relative flex h-7 items-center">
        <span className="absolute inset-x-0 h-1.5 rounded-[3px] bg-line" />
        <span className="absolute h-1.5 rounded-[3px] bg-ok/25" style={{ left: `${pct(lo)}%`, width: `${pct(hi) - pct(lo)}%` }} />
        <span className="absolute left-0 h-1.5 rounded-[3px] bg-ink" style={{ width: `${pct(value)}%` }} />
        <span className="absolute -top-1 h-9 w-0.5 bg-ok opacity-50" style={{ left: `${pct((lo + hi) / 2)}%` }} />
        <span className="pointer-events-none absolute size-7 rounded-full border-[3px] border-ink bg-white" style={{ left: `calc(${pct(value)}% - 14px)` }} />
        <input
          id={id}
          type="range"
          min={MIN_PRICE}
          max={MAX_PRICE}
          step={100}
          value={value}
          onChange={(e) => onChange(Number(e.target.value))}
          aria-valuetext={`${fcfa(value)} FCFA`}
          className="absolute inset-0 w-full cursor-pointer opacity-0"
        />
      </div>
      <div className={cx('flex items-center gap-2 text-[13px] font-bold', zone === 'ok' ? 'text-ok-ink' : 'text-warn-ink')}>
        <span className={cx('size-2 rounded-full', zone === 'ok' ? 'bg-ok' : 'bg-warn')} />
        {zone === 'ok' ? 'Dans le prix conseillé · se remplit vite' : zone === 'high' ? `Au-dessus du conseillé (${fcfa(lo)}–${fcfa(hi)}) · plus lent` : `Sous le conseillé (${fcfa(lo)}–${fcfa(hi)})`}
      </div>
      {note && <p className="text-[13px] leading-normal font-medium text-muted">{note}</p>}
    </div>
  )
}
