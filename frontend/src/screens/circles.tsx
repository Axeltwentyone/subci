import { useCallback, useEffect, useState, type ReactNode } from 'react'
import { useNavigate, useParams, useSearchParams } from 'react-router'
import { PullToRefresh } from '../components/gestures'
import { IconChevronRight, IconClose, IconMinus, IconPeople, IconPlus } from '../components/icons'
import { Sheet } from '../components/Sheet'
import { useToast } from '../components/Toast'
import { Badge, Button, Card, Radio, RoundIconButton, Screen, ServiceLogo, Skeleton, StickyAction, Toggle, TopBar, cx } from '../components/ui'
import { ApiError, api, type Circle } from '../lib/api'
import { AVATAR_COLORS, SERVICES, getService } from '../lib/data'
import { fcfa } from '../lib/format'
import { errorMessage } from '../lib/store'
import { ConfirmModal } from './manage'

/*
 * Partage : des amis forment un cercle et partagent le coût d'un abonnement.
 * Cercle privé (sur invitation, avec le code) ou ouvert (les places libres sont
 * proposées aux autres utilisateurs, au prix fixé par le créateur).
 * Revendre des places (Revends), c'est /host.
 */

const MAX_SEATS = 10

function avatarColor(name: string) {
  const sum = [...name.trim()].reduce((a, ch) => a + ch.charCodeAt(0), 0)
  return AVATAR_COLORS[sum % AVATAR_COLORS.length]
}

export function MemberAvatar({ name, size = 34, ring = 'var(--color-surface)' }: { name: string; size?: number; ring?: string }) {
  return (
    <span
      className="grid shrink-0 place-items-center rounded-full font-extrabold text-on-accent"
      style={{ width: size, height: size, background: avatarColor(name), border: `2px solid ${ring}`, fontSize: size * 0.38 }}
      aria-hidden
    >
      {name.trim().charAt(0).toUpperCase() || '?'}
    </span>
  )
}

/** Lien + message d'invitation à un cercle. */
async function inviteToCircle(circle: Circle): Promise<'shared' | 'copied' | null> {
  const svc = getService(circle.serviceId)
  const url = `${window.location.origin}/circles/${circle.code}`
  const text =
    `Rejoins mon cercle « ${circle.name} » sur Sub.ci pour partager ${svc?.name ?? 'un abonnement'} : ` +
    `${fcfa(circle.memberPrice)} FCFA/mois la place. Code : ${circle.code}`
  try {
    if (navigator.share) {
      await navigator.share({ title: 'Sub.ci', text, url })
      return 'shared'
    }
    await navigator.clipboard.writeText(`${text} ${url}`)
    return 'copied'
  } catch {
    return null
  }
}

/* ---------- Onglet Partage ---------- */

export function ShareTab() {
  const navigate = useNavigate()
  const [data, setData] = useState<{ mine: Circle[]; open: Circle[] } | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [codeOpen, setCodeOpen] = useState(false)

  const load = useCallback(
    () =>
      api
        .circles()
        .then((d) => {
          setData(d)
          setError(null)
        })
        .catch((e) => setError(errorMessage(e))),
    [],
  )
  useEffect(() => {
    load()
  }, [load])

  return (
    <PullToRefresh onRefresh={load}>
      <div className="mx-auto flex max-w-[960px] flex-col gap-4 px-5 pt-2 md:px-8 md:pt-7 desk:px-10 desk:pt-9">
        <div className="flex flex-col gap-1.5">
          <h1 className="t-title">Partage</h1>
          <p className="text-[15px] leading-normal font-medium text-muted">Forme un cercle avec tes amis et partagez le prix d’un abonnement.</p>
        </div>

        <div className="grid grid-cols-2 gap-2.5 md:max-w-[420px]">
          <Button size="md" onClick={() => navigate('/circles/new', { viewTransition: true })}>
            Créer un cercle
          </Button>
          <Button size="md" variant="ink" onClick={() => setCodeOpen(true)}>
            J’ai un code
          </Button>
        </div>

        {error && !data ? (
          <Card className="flex flex-col items-center gap-3 p-6 text-center">
            <span className="text-[15px] font-semibold text-muted">{error}</span>
            <Button size="xs" block={false} variant="ink" onClick={load}>
              Réessayer
            </Button>
          </Card>
        ) : !data ? (
          <>
            <Skeleton className="mt-4 h-[118px] rounded-card" />
            <Skeleton className="h-[118px] rounded-card" />
          </>
        ) : (
          <>
            <section className="mt-3 flex flex-col gap-2.5">
              <h2 className="t-section">Mes cercles</h2>
              {data.mine.length === 0 ? (
                <Card className="flex items-center gap-3 p-4">
                  <span className="grid size-11 shrink-0 place-items-center rounded-tile bg-sand text-muted">
                    <IconPeople />
                  </span>
                  <span className="text-sm leading-snug font-medium text-muted">Tu n’es dans aucun cercle. Crée-en un ou rejoins celui d’un ami avec son code.</span>
                </Card>
              ) : (
                <div className="grid gap-2.5 md:grid-cols-2">
                  {data.mine.map((c) => (
                    <CircleCard key={c.code} circle={c} />
                  ))}
                </div>
              )}
            </section>

            <section className="mt-3 flex flex-col gap-2.5">
              <div className="flex flex-col gap-0.5">
                <h2 className="t-section">Cercles ouverts</h2>
                <span className="text-sm font-medium text-muted">Des utilisateurs de Sub.ci qui cherchent des membres.</span>
              </div>
              {data.open.length === 0 ? (
                <Card className="p-4 text-sm leading-normal font-medium text-muted">
                  Aucun cercle ouvert pour l’instant. Crée le tien et rends-le ouvert : d’autres pourront le rejoindre.
                </Card>
              ) : (
                <div className="grid gap-2.5 md:grid-cols-2">
                  {data.open.map((c) => (
                    <CircleCard key={c.code} circle={c} />
                  ))}
                </div>
              )}
            </section>
          </>
        )}

        {/* Revends (revendeurs qui publient une offre) reste à part, plus discret. */}
        <button
          type="button"
          onClick={() => navigate('/host', { viewTransition: true })}
          className="pressable mt-3 flex items-center gap-3 rounded-card border-[1.5px] border-line p-4 text-left md:max-w-[460px]"
        >
          <span className="flex flex-1 flex-col gap-0.5">
            <span className="text-[15px] font-bold">Tu revends des accès ?</span>
            <span className="text-[13px] font-medium text-muted">Publie une offre : Sub.ci trouve les clients et encaisse.</span>
          </span>
          <span className="text-muted">
            <IconChevronRight size={18} />
          </span>
        </button>
      </div>

      <CodeSheet open={codeOpen} onClose={() => setCodeOpen(false)} />
    </PullToRefresh>
  )
}

/** Rejoindre le cercle d'un ami : il t'a envoyé son code. */
function CodeSheet({ open, onClose }: { open: boolean; onClose: () => void }) {
  const navigate = useNavigate()
  const [code, setCode] = useState('')
  const clean = code.trim().toUpperCase().replace(/\s/g, '')
  const go = () => {
    if (clean.length < 6) return
    onClose()
    navigate(`/circles/${clean}`, { viewTransition: true })
  }
  return (
    <Sheet open={open} onClose={onClose} label="Code du cercle">
      <form
        className="flex flex-col gap-3"
        onSubmit={(e) => {
          e.preventDefault()
          go()
        }}
      >
        <h2 className="font-display text-2xl font-bold tracking-[-0.02em]">Code du cercle</h2>
        <p className="text-sm font-medium text-muted">Ton ami le trouve dans son cercle, bouton « Inviter ».</p>
        <input
          value={code}
          onChange={(e) => setCode(e.target.value)}
          autoFocus
          autoCapitalize="characters"
          autoComplete="off"
          aria-label="Code du cercle"
          placeholder="EX. 7KQ4MZ2P"
          maxLength={12}
          className="mt-1 h-14 rounded-btn bg-surface px-4 text-xl font-extrabold tracking-[0.12em] uppercase outline-none placeholder:font-bold placeholder:tracking-normal placeholder:text-subtle focus:outline-2 focus:outline-ink"
        />
        <Button type="submit" disabled={clean.length < 6}>
          Voir le cercle
        </Button>
      </form>
    </Sheet>
  )
}

/** Carte d'un cercle : service, membres, part de chacun. */
function CircleCard({ circle }: { circle: Circle }) {
  const navigate = useNavigate()
  const svc = getService(circle.serviceId)
  const count = circle.members.length
  return (
    <Card className="flex flex-col gap-3.5 p-4" onClick={() => navigate(`/circles/${circle.code}`, { viewTransition: true })}>
      <div className="flex items-center gap-3">
        {svc ? <ServiceLogo service={svc} size={44} /> : <span className="size-11" />}
        <span className="flex min-w-0 flex-1 flex-col gap-0.5">
          <span className="truncate text-base font-bold">{circle.name}</span>
          <span className="text-[13px] font-medium text-muted">
            {svc?.name ?? circle.serviceId} · {count} membre{count > 1 ? 's' : ''}
          </span>
        </span>
        {circle.free > 0 ? <Badge tone="due">{circle.free} place{circle.free > 1 ? 's' : ''}</Badge> : <Badge tone="active">Complet</Badge>}
      </div>
      <div className="flex items-center">
        <span className="flex">
          {circle.members.slice(0, 6).map((m, i) => (
            <span key={m.id} style={{ marginLeft: i ? -8 : 0 }}>
              <MemberAvatar name={m.name} />
            </span>
          ))}
        </span>
        <span className="ml-auto">
          <span className="font-display text-[17px] font-extrabold">{fcfa(circle.memberPrice)}</span>
          <span className="text-xs font-semibold text-muted"> FCFA/mois {circle.open ? 'la place' : 'chacun'}</span>
        </span>
      </div>
    </Card>
  )
}

/* ---------- Créer un cercle ---------- */

export function CreateCircle() {
  const navigate = useNavigate()
  const toast = useToast()
  const [service, setService] = useState<string | null>(null)
  const [name, setName] = useState('')
  const [seats, setSeats] = useState(4)
  const [price, setPrice] = useState('')
  const [open, setOpen] = useState(false)
  const [seatPrice, setSeatPrice] = useState('')
  const [busy, setBusy] = useState(false)

  const priceValue = Number(price) || 0
  const seatPriceValue = Number(seatPrice) || 0
  const share = open ? seatPriceValue : priceValue > 0 ? Math.ceil(priceValue / seats) : 0

  const pick = (id: string) => {
    const s = getService(id)
    setService(id)
    if (s?.fullPrice) setPrice(String(s.fullPrice))
    if (!name.trim() && s) setName(`${s.name} entre amis`)
  }

  const problem = !service
    ? 'Choisis l’abonnement à partager.'
    : name.trim().length < 2
      ? 'Donne un nom au cercle.'
      : priceValue < 500
        ? 'Indique le prix réel de l’abonnement.'
        : open && seatPriceValue < 200
          ? 'Fixe le prix d’une place pour ton cercle ouvert.'
          : open && seatPriceValue > priceValue
            ? 'Le prix d’une place ne peut pas dépasser celui de l’abonnement.'
            : null

  const create = async () => {
    if (problem) {
      toast({ text: problem, tone: 'error' })
      return
    }
    setBusy(true)
    try {
      const { data } = await api.createCircle({ service: service!, name: name.trim(), seats, price: priceValue, open, ...(open ? { seat_price: seatPriceValue } : {}) })
      navigate(`/circles/${data.code}?new=1`, { replace: true, viewTransition: true })
    } catch (e) {
      toast({ text: errorMessage(e), tone: 'error' })
      setBusy(false)
    }
  }

  return (
    <Screen>
      <TopBar title="Nouveau cercle" back="/share" />
      <div className="flex flex-col gap-[22px] px-5 pt-4">
        <Field label="Quel abonnement ?">
          <div className="flex flex-wrap gap-2">
            {SERVICES.map((s) => (
              <button
                key={s.id}
                type="button"
                aria-pressed={service === s.id}
                onClick={() => pick(s.id)}
                className={cx('pressable flex items-center gap-2 rounded-full bg-surface py-1.5 pr-3.5 pl-1.5 text-sm font-bold', service === s.id ? 'outline-2 outline-ink' : 'outline-0')}
              >
                <ServiceLogo service={s} size={30} />
                {s.name}
              </button>
            ))}
          </div>
        </Field>

        <Field label="Nom du cercle">
          <TextInput value={name} onChange={setName} placeholder="Famille Koné, Coloc Riviera…" maxLength={40} />
        </Field>

        <Field label="Combien de places en tout ?">
          <Card className="flex items-center gap-2 px-4 py-2.5">
            <span className="flex-1 text-[15px] font-semibold text-muted">Toi compris</span>
            <RoundIconButton label="Une place de moins" onClick={() => setSeats((n) => Math.max(2, n - 1))} className="!bg-sand">
              <IconMinus size={18} />
            </RoundIconButton>
            <span className="w-11 text-center font-display text-[22px] font-bold" aria-live="polite">
              {seats}
            </span>
            <RoundIconButton label="Une place de plus" onClick={() => setSeats((n) => Math.min(MAX_SEATS, n + 1))} className="!bg-sand">
              <IconPlus size={18} />
            </RoundIconButton>
          </Card>
        </Field>

        <Field label="Prix réel de l’abonnement">
          <TextInput value={price} onChange={(v) => setPrice(v.replace(/\D/g, ''))} placeholder="4 200" suffix="FCFA/mois" number />
        </Field>

        <Field label="Qui peut rejoindre ?">
          <div className="flex flex-col gap-2" role="radiogroup">
            <Visibility selected={!open} title="Cercle privé" body="Seules les personnes que tu invites avec le code." onClick={() => setOpen(false)} />
            <Visibility
              selected={open}
              title="Cercle ouvert"
              body="Tes places libres sont aussi proposées aux autres utilisateurs de Sub.ci."
              onClick={() => {
                setOpen(true)
                // Point de départ : la part égale, que le créateur ajuste.
                if (!seatPrice && priceValue > 0) setSeatPrice(String(Math.ceil(priceValue / seats)))
              }}
            />
          </div>
        </Field>

        {open && (
          <Field label="Prix d’une place">
            <TextInput value={seatPrice} onChange={(v) => setSeatPrice(v.replace(/\D/g, ''))} placeholder="2 500" suffix="FCFA/mois" number />
            <p className="text-[13px] leading-snug font-medium text-muted">C’est toi qui le fixes : c’est ce que paiera chaque personne qui rejoint ton cercle.</p>
          </Field>
        )}

        {share > 0 && (
          <div className="flex items-center gap-3 rounded-card bg-ink p-[18px] text-sand">
            <span className="flex-1 text-sm leading-snug font-semibold text-ink-muted">{open ? 'Chaque membre paiera' : 'Contribution prévue par personne'}</span>
            <span className="font-display text-[26px] leading-none font-extrabold">{fcfa(share)}</span>
            <span className="text-[13px] font-bold">FCFA/mois</span>
          </div>
        )}
      </div>
      <StickyAction>
        <Button loading={busy} onClick={create}>
          Créer le cercle
        </Button>
      </StickyAction>
    </Screen>
  )
}

function Field({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex flex-col gap-2.5">
      <span className="text-base font-bold">{label}</span>
      {children}
    </div>
  )
}

function TextInput({ value, onChange, placeholder, suffix, maxLength, number }: { value: string; onChange: (v: string) => void; placeholder: string; suffix?: string; maxLength?: number; number?: boolean }) {
  return (
    <label className="flex h-14 items-center gap-2 rounded-btn bg-surface px-4 focus-within:outline-2 focus-within:outline-ink">
      <input
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder}
        maxLength={maxLength}
        inputMode={number ? 'numeric' : 'text'}
        autoCapitalize={number ? 'off' : 'sentences'}
        className="min-w-0 flex-1 bg-transparent text-[17px] font-bold outline-none placeholder:font-semibold placeholder:text-subtle"
      />
      {suffix && <span className="text-sm font-semibold text-muted">{suffix}</span>}
    </label>
  )
}

function Visibility({ selected, title, body, onClick }: { selected: boolean; title: string; body: string; onClick: () => void }) {
  return (
    <button
      type="button"
      role="radio"
      aria-checked={selected}
      onClick={onClick}
      className={cx('pressable flex items-center gap-3 rounded-card bg-surface p-4 text-left', selected ? 'outline-2 outline-ink' : 'outline-0')}
    >
      <span className="flex flex-1 flex-col gap-0.5">
        <span className="text-[15px] font-bold">{title}</span>
        <span className="text-[13px] leading-snug font-medium text-muted">{body}</span>
      </span>
      <Radio checked={selected} />
    </button>
  )
}

/* ---------- Un cercle ---------- */

export function CircleDetail() {
  const { code: raw = '' } = useParams()
  const code = raw.toUpperCase()
  const [params] = useSearchParams()
  const justCreated = params.get('new') === '1'
  const navigate = useNavigate()
  const toast = useToast()
  const [circle, setCircle] = useState<Circle | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [confirm, setConfirm] = useState<null | { title: string; text: string; action: string; run: () => Promise<void> }>(null)
  const [priceOpen, setPriceOpen] = useState(false)

  const load = useCallback(
    () =>
      api
        .circle(code)
        .then(({ data }) => {
          setCircle(data)
          setError(null)
        })
        .catch((e) => setError(e instanceof ApiError && e.status === 404 ? 'Aucun cercle avec ce code. Vérifie-le avec ton ami.' : errorMessage(e))),
    [code],
  )
  useEffect(() => {
    load()
  }, [load])

  const run = async (action: () => Promise<unknown>, done?: string) => {
    setBusy(true)
    try {
      await action()
      await load()
      if (done) toast({ text: done })
      return true
    } catch (e) {
      toast({ text: errorMessage(e), tone: 'error' })
      return false
    } finally {
      setBusy(false)
    }
  }

  const copyCode = async () => {
    try {
      await navigator.clipboard.writeText(code)
      toast({ text: `Code ${code} copié` })
    } catch {
      /* presse-papiers indisponible */
    }
  }

  const codeChip = (
    <button type="button" onClick={copyCode} className="pressable rounded-full bg-surface px-3 py-2 text-[13px] font-extrabold tracking-[0.04em]">
      Code {code}
    </button>
  )

  if (!circle)
    return (
      <Screen>
        <TopBar back="/share" right={codeChip} />
        <div className="flex flex-col gap-3 px-5 pt-4">
          {error ? (
            <Card className="flex flex-col items-center gap-3 p-6 text-center">
              <span className="text-[15px] font-semibold text-muted">{error}</span>
              <Button size="xs" block={false} variant="ink" onClick={load}>
                Réessayer
              </Button>
            </Card>
          ) : (
            <>
              <Skeleton className="h-16 rounded-card" />
              <Skeleton className="h-[150px] rounded-card" />
              <Skeleton className="h-[200px] rounded-card" />
            </>
          )}
        </div>
      </Screen>
    )

  const svc = getService(circle.serviceId)
  const serviceName = svc?.name ?? circle.serviceId
  const owner = circle.isOwner

  return (
    <Screen>
      <TopBar back="/share" right={codeChip} />
      <div className="flex flex-col gap-4 px-5 pt-3">
        <div className="flex items-center gap-3.5">
          {svc && <ServiceLogo service={svc} size={56} />}
          <div className="flex min-w-0 flex-col gap-0.5">
            <h1 className="font-display text-[26px] leading-tight font-bold tracking-[-0.02em]">{circle.name}</h1>
            <span className="text-sm font-medium text-muted">
              {serviceName} · créé par {owner ? 'toi' : circle.owner}
            </span>
          </div>
        </div>
        <div>
          <Badge tone={circle.open ? 'pending' : 'active'}>{circle.open ? 'Cercle ouvert' : 'Cercle privé'}</Badge>
        </div>

        {justCreated && <Note>Ton cercle est prêt. Invite tes amis : ils le rejoignent avec le code {circle.code}.</Note>}

        <div className="flex flex-col gap-1 rounded-card bg-ink p-5 text-sand">
          <div className="flex items-center gap-3">
            <span className="flex-1 text-[13px] font-semibold text-ink-muted">
              {circle.open ? `Prix d’une place · fixé par ${owner ? 'toi' : circle.owner}` : 'Contribution prévue par personne'}
            </span>
            {circle.open && owner && (
              <button type="button" disabled={busy} onClick={() => setPriceOpen(true)} className="text-sm font-bold text-brand">
                Modifier
              </button>
            )}
          </div>
          <span>
            <span className="font-display text-[34px] leading-none font-extrabold">{fcfa(circle.memberPrice)}</span>
            <span className="text-sm font-bold"> FCFA/mois</span>
          </span>
          <span className="mt-1.5 text-sm leading-snug font-medium text-ink-muted">
            {circle.open
              ? `Abonnement à ${fcfa(circle.price)} FCFA/mois. Chaque personne qui rejoint paie ce prix.`
              : `Abonnement à ${fcfa(circle.price)} FCFA/mois partagé en ${circle.seats} parts égales.`}
          </span>
        </div>

        <h2 className="t-section mt-2">
          Membres · {circle.members.length}/{circle.seats}
        </h2>
        <Card className="flex flex-col divide-y divide-line-soft px-4">
          {circle.members.map((m) => (
            <div key={m.id} className="flex items-center gap-3 py-3">
              <MemberAvatar name={m.name} size={38} />
              <span className="flex min-w-0 flex-1 flex-col">
                <span className="text-[15px] font-bold">{m.me ? `${m.name} (toi)` : m.name}</span>
                <span className="text-[13px] font-medium text-muted">
                  {m.owner ? (circle.open ? 'A créé le cercle' : `A créé le cercle · ${fcfa(circle.share)} FCFA`) : `${fcfa(circle.memberPrice)} FCFA/mois`}
                </span>
              </span>
              {owner && !m.owner && (
                <button
                  type="button"
                  aria-label={`Retirer ${m.name}`}
                  disabled={busy}
                  onClick={() =>
                    setConfirm({
                      title: `Retirer ${m.name} ?`,
                      text: 'Sa place redevient libre.',
                      action: 'Retirer',
                      run: async () => {
                        await run(() => api.removeCircleMember(circle.code, m.id), `${m.name} retiré du cercle`)
                      },
                    })
                  }
                  className="pressable grid size-9 place-items-center rounded-full text-muted"
                >
                  <IconClose size={16} />
                </button>
              )}
            </div>
          ))}
          {Array.from({ length: circle.free }, (_, i) => (
            <div key={`free-${i}`} className="flex items-center gap-3 py-3">
              <span className="grid size-[38px] place-items-center rounded-full border-[1.5px] border-line text-muted">
                <IconPlus size={16} />
              </span>
              <span className="text-[15px] font-semibold text-muted">Place libre</span>
            </div>
          ))}
        </Card>

        {owner && (
          <Card className="mt-2 flex items-center gap-3 py-3 pr-3 pl-4">
            <span className="flex flex-1 flex-col gap-0.5">
              <span className="text-[15px] font-bold">Proposer les places libres</span>
              <span className="text-[13px] leading-snug font-medium text-muted">Les autres utilisateurs de Sub.ci verront ton cercle et pourront le rejoindre.</span>
            </span>
            <Toggle
              label="Proposer les places libres"
              checked={circle.open}
              onChange={(v) => !busy && run(() => api.updateCircle(circle.code, { open: v }), v ? 'Cercle ouvert à tous' : 'Cercle privé')}
            />
          </Card>
        )}

        <Note>La collecte des parts par Sub.ci arrive bientôt. En attendant, mettez-vous d’accord entre vous pour payer chacun sa part.</Note>

        {circle.isMember && (
          <Button
            variant="text"
            size="link"
            className="!text-err-ink"
            disabled={busy}
            onClick={() =>
              setConfirm({
                title: owner ? 'Fermer le cercle ?' : 'Quitter le cercle ?',
                text: owner ? 'Les membres ne le verront plus.' : 'Ta place sera libérée.',
                action: owner ? 'Fermer' : 'Quitter',
                run: async () => {
                  setBusy(true)
                  try {
                    await api.circleAction(circle.code, owner ? 'close' : 'leave')
                    toast({ text: owner ? 'Cercle fermé' : 'Tu as quitté le cercle' })
                    navigate('/share', { replace: true })
                  } catch (e) {
                    toast({ text: errorMessage(e), tone: 'error' })
                    setBusy(false)
                  }
                },
              })
            }
          >
            {owner ? 'Fermer le cercle' : 'Quitter le cercle'}
          </Button>
        )}
      </div>

      <StickyAction>
        {circle.isMember ? (
          <Button
            onClick={async () => {
              const r = await inviteToCircle(circle)
              if (r === 'copied') toast({ text: 'Invitation copiée : colle-la dans WhatsApp' })
            }}
          >
            {circle.free > 0 ? 'Inviter des amis' : 'Partager le cercle'}
          </Button>
        ) : circle.free > 0 ? (
          <Button loading={busy} onClick={() => run(() => api.circleAction(circle.code, 'join'), `Bienvenue dans ${circle.name}`)}>
            Rejoindre · {fcfa(circle.memberPrice)} FCFA/mois
          </Button>
        ) : (
          <Button disabled>Cercle complet</Button>
        )}
      </StickyAction>

      <SeatPriceSheet
        open={priceOpen}
        circle={circle}
        onClose={() => setPriceOpen(false)}
        onSave={async (value) => {
          setPriceOpen(false)
          if (value !== circle.memberPrice) await run(() => api.updateCircle(circle.code, { seat_price: value }), `Prix d’une place : ${fcfa(value)} FCFA`)
        }}
      />

      {confirm && (
        <ConfirmModal
          title={confirm.title}
          text={confirm.text}
          confirm={confirm.action}
          onCancel={() => setConfirm(null)}
          onConfirm={() => {
            const c = confirm
            setConfirm(null)
            c.run()
          }}
        />
      )}
    </Screen>
  )
}

/** Le créateur d'un cercle ouvert fixe lui-même le prix d'une place. */
function SeatPriceSheet({ open, circle, onClose, onSave }: { open: boolean; circle: Circle; onClose: () => void; onSave: (v: number) => void }) {
  const [value, setValue] = useState(String(circle.memberPrice))
  useEffect(() => {
    if (open) setValue(String(circle.memberPrice))
  }, [open, circle.memberPrice])
  const n = Number(value) || 0
  const ok = n >= 200 && n <= circle.price
  return (
    <Sheet open={open} onClose={onClose} label="Prix d’une place">
      <form
        className="flex flex-col gap-3"
        onSubmit={(e) => {
          e.preventDefault()
          if (ok) onSave(n)
        }}
      >
        <h2 className="font-display text-2xl font-bold tracking-[-0.02em]">Prix d’une place</h2>
        <p className="text-sm font-medium text-muted">Au plus {fcfa(circle.price)} FCFA, le prix de l’abonnement.</p>
        <TextInput value={value} onChange={(v) => setValue(v.replace(/\D/g, ''))} placeholder="2 500" suffix="FCFA/mois" number />
        <Button type="submit" disabled={!ok}>
          Enregistrer
        </Button>
      </form>
    </Sheet>
  )
}

function Note({ children }: { children: ReactNode }) {
  return <p className="rounded-tile bg-info-soft p-3.5 text-[13px] leading-[1.45] font-semibold text-info-ink">{children}</p>
}

/* ---------- Page d'un service : places proposées par des utilisateurs ---------- */

/** Cercles ouverts d'un service, au prix fixé par celui qui propose ses places. */
export function OpenCircleOffers({ serviceId, title, onCount }: { serviceId: string; title?: string; onCount?: (n: number) => void }) {
  const navigate = useNavigate()
  const [list, setList] = useState<Circle[] | null>(null)
  useEffect(() => {
    let alive = true
    api
      .circles(serviceId)
      .then((d) => {
        if (!alive) return
        const open = d.open.filter((c) => c.free > 0)
        setList(open)
        onCount?.(open.length)
      })
      .catch(() => alive && setList([]))
    return () => {
      alive = false
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [serviceId])

  if (!list?.length) return null
  return (
    <section className="flex flex-col gap-2.5" aria-label="Places proposées par des utilisateurs">
      {title && <h2 className="t-section px-1">{title}</h2>}
      {list.map((c) => (
        <button
          key={c.code}
          type="button"
          onClick={() => navigate(`/circles/${c.code}`, { viewTransition: true })}
          className="pressable flex flex-col gap-3 rounded-card bg-surface p-4 text-left"
        >
          <div className="flex items-start gap-3">
            <span className="grid size-10 shrink-0 place-items-center rounded-full bg-sand font-display text-base font-extrabold">{c.owner.charAt(0)}</span>
            <span className="flex min-w-0 flex-1 flex-col gap-0.5">
              <span className="text-[15px] font-bold">{c.owner}</span>
              <span className="truncate text-[13px] font-semibold text-muted">{c.name}</span>
            </span>
            <span className="flex flex-col items-end">
              <span className="font-display text-xl leading-none font-extrabold">{fcfa(c.memberPrice)}</span>
              <span className="text-[11px] font-semibold text-muted">FCFA/mois</span>
            </span>
          </div>
          <div className="flex items-center justify-between border-t border-line-soft pt-3">
            <span className="text-[13px] font-bold text-ok">
              {c.free} place{c.free > 1 ? 's' : ''} sur {c.seats}
            </span>
            <span className="flex items-center gap-0.5 text-[13px] font-bold">
              Voir l’offre <IconChevronRight size={14} />
            </span>
          </div>
        </button>
      ))}
    </section>
  )
}
