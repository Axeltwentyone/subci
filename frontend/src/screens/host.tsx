import { useEffect, useId, useState } from 'react'
import { useNavigate, useParams } from 'react-router'
import { IconCheck, IconChevronLeft, IconMinus, IconPlus } from '../components/icons'
import { Sheet } from '../components/Sheet'
import { useToast } from '../components/Toast'
import { BackButton, Badge, Button, DeviceChip, Radio, RoundIconButton, Screen, SectionLabel, Skeleton, ServiceLogo, StepBar, StickyAction, Steps, TopBar, cx } from '../components/ui'
import { DEVICES, HOST_FEE, HOST_PLANS, SERVICES, getService, type Device, type HostPlan } from '../lib/data'
import { api } from '../lib/api'
import { fcfa, haptic, shortDate, since, timeLeft } from '../lib/format'
import { errorMessage, hostNet, useStore, type HostOffer, type HostRequest, type Member } from '../lib/store'
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
        <PayoutRules dark />
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
            ['Qui paie ?', 'Chaque membre paie sa place d’avance à Sub.ci en mobile money. L’argent est garanti : tu n’as jamais à relancer personne.'],
            ['Quand suis-je payé ?', 'Mois par mois : chaque mois payé arrive dans ton solde 48 h après son début (24 h quand tu deviens Hôte fiable). Un membre qui paie 3 mois te rapporte 3 versements, un par mois.'],
            ['Et pour retirer ?', 'Tu retires ton solde quand tu veux vers Wave, Orange Money, MTN ou Moov, à partir de 500 FCFA. Reçu sous 48 h.'],
            ['Pourquoi ce délai ?', 'Pour protéger les membres : si l’accès ne marche pas, ils signalent un souci et sont remboursés du temps pas encore versé. C’est ce qui leur donne confiance… et te ramène des membres.'],
            ['Et si un membre part ?', 'Sa place est remise en ligne automatiquement. Si c’est toi qui le retires, il est remboursé des mois pas encore versés.'],
            ['Mes identifiants ?', 'Chiffrés, visibles uniquement par les membres que tu acceptes.'],
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

/** Comment l'hôte est payé : à montrer avant qu'il se lance, pas après. */
function PayoutRules({ dark }: { dark?: boolean }) {
  const steps = [
    ['Le membre paie d’avance', 'Sub.ci encaisse et garde l’argent en sécurité.'],
    ['48 h après le début du mois', 'Le mois arrive dans ton solde. 3 mois payés = 3 versements, un par mois.'],
    ['Tu retires quand tu veux', 'Vers Wave, Orange Money, MTN ou Moov. Reçu sous 48 h.'],
  ]
  return (
    <section className={cx('flex flex-col gap-3 rounded-[20px] p-4', dark ? 'bg-ink-2 text-sand' : 'bg-white')}>
      <h2 className="text-[15px] font-bold">Comment tu es payé</h2>
      <ol className="flex flex-col gap-3">
        {steps.map(([t, d], i) => (
          <li key={t} className="flex gap-3">
            <span className={cx('grid size-6 shrink-0 place-items-center rounded-full text-[12px] font-extrabold', dark ? 'bg-brand text-ink' : 'bg-ink text-white')}>{i + 1}</span>
            <span className="flex flex-col gap-0.5">
              <span className="text-sm font-bold">{t}</span>
              <span className={cx('text-[13px] leading-snug font-semibold', dark ? 'text-ink-muted' : 'text-muted')}>{d}</span>
            </span>
          </li>
        ))}
      </ol>
      <p className={cx('text-[12px] leading-snug font-semibold', dark ? 'text-ink-muted' : 'text-muted')}>
        Ce délai protège tes membres : si l’accès ne marche pas, ils sont remboursés du temps pas encore versé. Sans souci pendant 3 mois, tu deviens <b>Hôte fiable</b> et tu es versé en 24 h.
      </p>
    </section>
  )
}

/* ---------- 22 · Configurer l'offre + 23 · Accès & publication ---------- */

export function HostSetup() {
  const navigate = useNavigate()
  const { actions } = useStore()
  const toast = useToast()
  const [plans, setPlans] = useState<Record<string, HostPlan[]> | null>(null)
  const [step, setStep] = useState<1 | 2>(1)
  const [svc, setSvc] = useState('netflix')
  const [planKey, setPlanKey] = useState<string | null>(null)
  const [seats, setSeats] = useState(1)
  const [price, setPrice] = useState(2500)
  const [devices, setDevices] = useState<Device[]>([])
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [showPwd, setShowPwd] = useState(false)
  const [proof, setProof] = useState<File | null>(null)
  const [agree, setAgree] = useState(false)
  const [agreePay, setAgreePay] = useState(false)
  const [loading, setLoading] = useState(false)
  const proofId = useId()

  const choosePlan = (p: HostPlan) => {
    setPlanKey(p.key)
    setSeats(p.max)
    setPrice(Math.round((p.reco[0] + p.reco[1]) / 2 / 100) * 100)
    setDevices(p.devices)
  }

  useEffect(() => {
    api
      .plans()
      .then(({ data }) => {
        setPlans(data)
        const first = data.netflix?.[data.netflix.length - 1]
        if (first) choosePlan(first)
      })
      .catch(() => toast({ tone: 'error', text: 'Impossible de charger les formules. Réessaie.' }))
  }, [toast])

  const servicePlans = plans?.[svc] ?? []
  const plan = servicePlans.find((p) => p.key === planKey) ?? null

  const pickService = (id: string) => {
    setSvc(id)
    const list = plans?.[id] ?? []
    if (list.length) choosePlan(list[list.length - 1])
  }

  const { net } = hostNet(price, seats)
  const credentials = plan?.mode === 'credentials'
  const canPublish = !!plan && agree && agreePay && !!proof && devices.length > 0 && (!credentials || (email.includes('@') && password.length >= 4))
  const service = getService(svc)

  const publish = async () => {
    if (!proof || !plan) return
    const form = new FormData()
    form.append('serviceId', svc)
    form.append('plan', plan.key)
    form.append('seats', String(seats))
    form.append('price', String(price))
    devices.forEach((d) => form.append('devices[]', d))
    if (credentials) {
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
              <h2 className="t-section">Quel abonnement ?</h2>
              <div role="radiogroup" aria-label="Service" className="grid grid-cols-4 gap-2">
                {/* Catalogue réel, limité aux services qui ont une formule partageable (config/plans.php). */}
                {SERVICES.filter((sv) => !plans || plans[sv.id]).map((sv) => {
                  const id = sv.id
                  const on = id === svc
                  return (
                    <button
                      key={id}
                      type="button"
                      role="radio"
                      aria-checked={on}
                      onClick={() => pickService(id)}
                      className={cx('pressable flex h-[76px] flex-col items-center justify-center gap-1.5 rounded-btn bg-white px-1', on ? 'border-2 border-ink' : 'border-[1.5px] border-line')}
                    >
                      <ServiceLogo service={sv} size={36} radius={10} />
                      <span className="w-full truncate text-center text-[11px] font-bold">{sv.name.replace(' Premium', '').replace(' Famille', '')}</span>
                    </button>
                  )
                })}
                <button
                  type="button"
                  onClick={() => toast({ text: 'Un autre service ? Écris-nous sur WhatsApp, on l’ajoute.' })}
                  className="pressable flex h-[76px] flex-col items-center justify-center gap-1.5 rounded-btn border-[1.5px] border-line bg-white"
                >
                  <ServiceLogo service={{ mono: '+', color: '#E7E1D6', fg: '#16130F' }} size={36} radius={10} />
                  <span className="text-[11px] font-bold">Autre</span>
                </button>
              </div>
            </section>

            {servicePlans.length > 0 && (
              <section className="flex flex-col gap-2.5">
                <h2 className="t-section">Quelle formule ?</h2>
                <div role="radiogroup" aria-label="Formule" className="flex flex-col gap-2">
                  {servicePlans.map((p) => (
                    <button
                      key={p.key}
                      type="button"
                      role="radio"
                      aria-checked={p.key === planKey}
                      onClick={() => choosePlan(p)}
                      className={cx('flex items-center gap-3 rounded-btn bg-white px-4 py-3.5 text-left', p.key === planKey ? 'border-2 border-ink' : 'border-[1.5px] border-line')}
                    >
                      <span className="flex flex-1 flex-col gap-0.5">
                        <span className="text-[15px] font-bold">
                          {p.label}
                          {p.quality && <span className="ml-2 rounded-md bg-sand px-1.5 py-0.5 text-[11px] font-extrabold">{p.quality}</span>}
                        </span>
                        <span className="text-[13px] font-semibold text-muted">
                          Jusqu’à {p.max} place{p.max > 1 ? 's' : ''} à partager · {p.mode === 'family' ? 'invitation famille' : 'identifiants partagés'}
                        </span>
                      </span>
                      <Radio checked={p.key === planKey} />
                    </button>
                  ))}
                </div>
              </section>
            )}

            {plan && (
              <>
                <DevicePicker allowed={plan.devices} value={devices} onChange={setDevices} />
                <SeatStepper value={seats} onChange={setSeats} min={1} max={plan.max} hint="Tu gardes ta propre place" />
                <PriceCard value={price} onChange={setPrice} reco={plan.reco} />
              </>
            )}
            {!plans && <Skeleton className="h-40 rounded-card" />}
          </div>
          <StickyAction className="flex flex-col gap-2.5">
            <div className="flex items-baseline justify-between">
              <span className="text-sm font-semibold text-muted">Tu reçois / mois (plein)</span>
              <span className="font-display text-xl font-extrabold text-ok-ink" aria-live="polite">
                {fcfa(net)} FCFA
              </span>
            </div>
            <Button onClick={() => setStep(2)} disabled={!plan}>
              Continuer
            </Button>
          </StickyAction>
        </>
      ) : (
        <>
          <div className="flex flex-col gap-4 px-5 pt-5">
            <h2 className="t-section">Comment tes membres accèdent ?</h2>

            {credentials ? (
              <div className="flex flex-col gap-3 rounded-card border-2 border-brand bg-brand-tint p-4">
                <span className="flex flex-col gap-0.5">
                  <span className="text-base font-bold">Identifiants + profil</span>
                  <span className="text-[13px] font-semibold text-muted">Chiffrés, visibles seulement par les membres que tu acceptes</span>
                </span>
                <input
                  type="email"
                  autoComplete="off"
                  aria-label={`Email du compte ${service?.name ?? ''}`}
                  placeholder={`Email du compte ${service?.name.split(' ')[0] ?? ''}`}
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
              </div>
            ) : (
              <div className="flex flex-col gap-1 rounded-card border-2 border-brand bg-brand-tint p-4">
                <span className="text-base font-bold">Invitation famille</span>
                <span className="text-[13px] leading-normal font-semibold text-muted">
                  Chaque membre garde son propre compte : tu l’invites depuis {service?.name.split(' ')[0]} après l’avoir accepté. Rien à partager.
                </span>
              </div>
            )}

            <div className="flex gap-2.5 rounded-[14px] bg-info-soft px-3.5 py-3 text-[13px] leading-[1.45] font-semibold text-info-ink">
              <span className="font-extrabold">i</span>
              Tu choisis qui entre : chaque membre paie d’abord, puis tu acceptes ou refuses sous 24 h (il est remboursé si tu refuses).
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

            <PayoutRules />

            <label className="flex cursor-pointer items-start gap-3 px-1">
              <input type="checkbox" checked={agreePay} onChange={(e) => setAgreePay(e.target.checked)} className="peer sr-only" />
              <span
                aria-hidden
                className={cx('grid size-[22px] shrink-0 place-items-center rounded-md text-[13px] font-extrabold text-white peer-focus-visible:outline-2 peer-focus-visible:outline-brand', agreePay ? 'bg-ink' : 'border-2 border-radio bg-white')}
              >
                {agreePay && '✓'}
              </span>
              <span className="text-sm leading-[1.45] font-semibold text-body">J’ai compris que je suis payé mois par mois, 48 h après le début de chaque mois payé.</span>
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
/** Aide pour trouver le lien d'invitation dans chaque service. */
const INVITE_HELP: Record<string, string> = {
  spotify: 'Spotify : spotify.com → ton compte → Premium Famille → Inviter → Copier le lien',
  'spotify-duo': 'Spotify : spotify.com → ton compte → Premium Duo → Inviter → Copier le lien',
  youtube: 'YouTube : ta photo → Achats et abonnements → Premium Famille → Modifier → Inviter → Copier le lien',
}

/** Invitation d'un membre accepté : lien du service (Spotify, YouTube) ou e-mail Apple à inviter. */
function InviteMember({ offer, member, onSend }: { offer: HostOffer; member: Member; onSend: (link?: string) => Promise<boolean> }) {
  const [link, setLink] = useState('')
  const [busy, setBusy] = useState(false)
  const send = async (value?: string) => {
    setBusy(true)
    try {
      if (await onSend(value)) setLink('')
    } finally {
      setBusy(false)
    }
  }

  if (offer.invite === 'email') {
    return (
      <div className="mb-3 flex flex-col gap-2.5 rounded-tile bg-info-soft p-3.5 text-[13px] leading-snug font-semibold text-info-ink">
        <span>
          Invite <b className="break-all">{member.inviteEmail ?? 'son identifiant Apple'}</b> depuis ton iPhone : Réglages → ton nom → Partage familial → Ajouter un membre.
        </span>
        <Button size="sm" variant="ink" loading={busy} onClick={() => send()}>
          C’est fait, invitation envoyée
        </Button>
      </div>
    )
  }

  return (
    <div className="mb-3 flex flex-col gap-2.5 rounded-tile bg-info-soft p-3.5">
      <span className="text-[13px] leading-snug font-semibold text-info-ink">{INVITE_HELP[offer.serviceId] ?? 'Copie le lien d’invitation famille depuis ton compte.'} Un lien par membre.</span>
      <input
        type="url"
        inputMode="url"
        autoComplete="off"
        placeholder="Colle le lien d’invitation"
        aria-label={`Lien d’invitation pour ${member.name}`}
        value={link}
        onChange={(e) => setLink(e.target.value.trim())}
        className="h-11 rounded-[12px] border-[1.5px] border-line bg-white px-3 text-[15px] font-semibold outline-none placeholder:font-medium placeholder:text-subtle focus:border-2 focus:border-ink"
      />
      <Button size="sm" variant="ink" loading={busy} disabled={!link.startsWith('https://')} onClick={() => send(link)}>
        Envoyer à {member.name}
      </Button>
    </div>
  )
}

/** Où en est un membre d'une offre famille (ou depuis quand il est là). */
function MemberStatus({ offer, member: m }: { offer: HostOffer; member: Member }) {
  if (offer.invite && m.inviteProblemAt)
    return <span className="text-[13px] font-bold text-err">{offer.invite === 'link' ? 'Le lien ne marche plus : renvoie-en un' : 'N’a pas reçu ton invitation'}</span>
  if (m.invitePending) return <span className="text-[13px] font-semibold text-info">Attend ton invitation famille</span>
  if (offer.invite && m.inviteSentAt && !m.inviteJoinedAt)
    return <span className="text-[13px] font-semibold text-warn">Invitation envoyée le {shortDate(Date.parse(m.inviteSentAt))} · pas encore rejoint</span>
  if (offer.invite && m.inviteJoinedAt) return <span className="text-[13px] font-semibold text-ok-ink">A rejoint ta famille</span>
  return <span className="text-[13px] font-semibold text-muted">{m.joinedAt ? `Membre depuis le ${shortDate(Date.parse(m.joinedAt))}` : 'Membre'}</span>
}

export function ManageOffer() {
  const { id = '' } = useParams()
  const navigate = useNavigate()
  const { state, actions } = useStore()
  const toast = useToast()
  const offer = state.offers.find((o) => o.id === id)
  const [price, setPrice] = useState(offer?.price ?? 2500)
  const [seats, setSeats] = useState(offer?.seats ?? 1)
  const [devices, setDevices] = useState<Device[]>(offer?.devices ?? [])
  const [email, setEmail] = useState(offer?.email ?? '')
  const [password, setPassword] = useState('')
  const [showPwd, setShowPwd] = useState(false)
  const [saving, setSaving] = useState(false)
  const [confirm, setConfirm] = useState<{ kind: 'remove'; member: Member } | { kind: 'close' } | { kind: 'decline'; request: HostRequest } | null>(null)
  const [deciding, setDeciding] = useState<string | null>(null)
  // Offre famille : membre à qui l'hôte renvoie une invitation.
  const [resend, setResend] = useState<string | null>(null)

  if (!offer) return <NotFound />
  const svc = getService(offer.serviceId)!
  const members = offer.members
  const sameDevices = devices.length === offer.devices.length && devices.every((d) => offer.devices.includes(d))
  const closed = offer.status === 'closed'
  const status = OFFER_STATUS[offer.status]
  const dirty = price !== offer.price || seats !== offer.seats || !sameDevices || (offer.mode === 'credentials' && (email !== (offer.email ?? '') || password !== ''))
  const { net } = hostNet(price, members.length)

  const save = async () => {
    const body: Parameters<typeof actions.updateOffer>[1] = {}
    if (price !== offer.price) body.price = price
    if (seats !== offer.seats) body.seats = seats
    if (!sameDevices) body.devices = devices
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
      return true
    } catch (e) {
      toast({ tone: 'error', text: errorMessage(e) })
      return false
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

        {offer.requests.length > 0 && (
          <section className="flex flex-col gap-2.5" aria-label="Demandes">
            <SectionLabel className="px-1">Demandes · à traiter</SectionLabel>
            {offer.requests.map((r) => (
              <div key={r.id} className="flex flex-col gap-3 rounded-card border-2 border-brand bg-brand-tint p-4">
                <div className="flex items-start gap-3">
                  <span className="grid size-10 shrink-0 place-items-center rounded-full bg-white font-display text-base font-extrabold">{r.member.name.charAt(0)}</span>
                  <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                    <span className="text-[15px] font-bold">{r.member.name}</span>
                    <span className="text-[13px] font-semibold text-muted">
                      Membre depuis {since(r.member.memberSince)} · {r.member.paidCount} paiement{r.member.paidCount > 1 ? 's' : ''}
                    </span>
                    <span className={cx('text-[13px] font-bold', r.member.removalsCount ? 'text-warn-ink' : 'text-ok-ink')}>
                      {r.member.removalsCount ? `Retiré·e ${r.member.removalsCount} fois d’un cercle` : 'Jamais retiré·e d’un cercle'}
                    </span>
                  </span>
                  <span className="flex flex-col items-end">
                    <span className="font-display text-base font-extrabold">{fcfa(r.amount)}</span>
                    <span className="text-[11px] font-semibold text-muted">{r.months} mois · payé</span>
                  </span>
                </div>
                <p className="text-[13px] font-semibold text-muted">Réponds d’ici {timeLeft(r.expiresAt)}, sinon {r.member.name} est remboursé·e.</p>
                <div className="grid grid-cols-[auto_1fr] gap-2">
                  <Button variant="outline" size="sm" block={false} className="px-4" disabled={deciding === r.id} onClick={() => setConfirm({ kind: 'decline', request: r })}>
                    Refuser
                  </Button>
                  <Button
                    size="sm"
                    loading={deciding === r.id}
                    onClick={async () => {
                      setDeciding(r.id)
                      await run(() => actions.acceptRequest(r.id), `${r.member.name} a rejoint ton cercle`)
                      setDeciding(null)
                    }}
                  >
                    Accepter
                  </Button>
                </div>
              </div>
            ))}
          </section>
        )}

        {closed ? (
          <div className="flex gap-2.5 rounded-[14px] bg-warn-soft px-3.5 py-3 text-[13px] leading-[1.45] font-semibold text-[#6B3F00]">
            <span className="font-extrabold">!</span>
            Tu as arrêté ce partage. Tes membres gardent leur accès jusqu’à leur échéance, sans renouvellement.
          </div>
        ) : (
          <>
            <PriceCard value={price} onChange={setPrice} reco={offer.reco} note="Le nouveau prix s’applique au prochain renouvellement de tes membres." />
            <DevicePicker allowed={offer.allowedDevices} value={devices} onChange={setDevices} />
            <SeatStepper
              value={seats}
              onChange={setSeats}
              min={Math.max(1, members.length + offer.requests.length)}
              max={offer.maxSeats}
              hint={[
                `${members.length} membre${members.length > 1 ? 's' : ''}`,
                offer.requests.length ? `${offer.requests.length} demande${offer.requests.length > 1 ? 's' : ''}` : null,
                `${Math.max(0, seats - members.length - offer.requests.length)} libre${seats - members.length - offer.requests.length > 1 ? 's' : ''}`,
              ].filter(Boolean).join(' · ')}
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
            <div key={m.id} className="border-b border-line-soft last:border-b-0">
            <div className="flex items-center gap-3 py-3">
              <span className="grid size-10 shrink-0 place-items-center rounded-full text-sm font-extrabold" style={{ background: m.color }}>
                {m.name.charAt(0)}
              </span>
              <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                <span className="truncate text-[15px] font-bold">{m.name}</span>
                <MemberStatus offer={offer} member={m} />
              </span>

              {!closed && (
                <button type="button" onClick={() => setConfirm({ kind: 'remove', member: m })} className="pressable h-9 rounded-[10px] px-2 text-[13px] font-bold text-err">
                  Retirer
                </button>
              )}
            </div>
            {!closed && offer.invite && (m.invitePending || m.inviteProblemAt || resend === m.id) && (
              <InviteMember
                offer={offer}
                member={m}
                onSend={async (link) => {
                  const ok = await run(() => actions.inviteMember(offer.id, m.id, link), `Invitation envoyée à ${m.name}`)
                  if (ok) setResend(null)
                  return ok
                }}
              />
            )}
            {!closed && offer.invite && m.inviteSentAt && !m.invitePending && !m.inviteProblemAt && !m.inviteJoinedAt && resend !== m.id && (
              <button type="button" onClick={() => setResend(m.id)} className="mb-3 text-[13px] font-bold text-info">
                {offer.invite === 'link' ? 'Renvoyer un nouveau lien' : 'Réinviter'}
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
                setDevices(offer.devices)
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
      {confirm?.kind === 'decline' && (
        <ConfirmModal
          title={`Refuser ${confirm.request.member.name}\u00a0?`}
          text={`${confirm.request.member.name} est remboursé·e de ${fcfa(confirm.request.amount)} FCFA et la place redevient libre.`}
          confirm="Refuser"
          onCancel={() => setConfirm(null)}
          onConfirm={async () => {
            const r = confirm.request
            setConfirm(null)
            setDeciding(r.id)
            await run(() => actions.declineRequest(r.id), `Demande refusée · ${r.member.name} est remboursé·e`)
            setDeciding(null)
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

/** Appareils autorisés pour les membres (parmi ceux permis par la formule). */
function DevicePicker({ allowed, value, onChange }: { allowed: Device[]; value: Device[]; onChange: (v: Device[]) => void }) {
  const list = DEVICES.map((d) => d.id).filter((d) => allowed.includes(d))
  return (
    <div className="flex flex-col gap-3 rounded-card bg-white p-[18px]">
      <div className="flex flex-col gap-0.5">
        <span className="text-base font-bold">Appareils des membres</span>
        <span className="text-[13px] font-semibold text-muted">Les membres choisissent ton offre selon ce qu’ils utilisent.</span>
      </div>
      <div className="flex flex-wrap gap-2" role="group" aria-label="Appareils">
        {list.map((d) => (
          <DeviceChip
            key={d}
            device={d}
            active={value.includes(d)}
            onClick={() => (value.includes(d) ? value.length > 1 && onChange(value.filter((x) => x !== d)) : onChange([...value, d]))}
          />
        ))}
      </div>
    </div>
  )
}
