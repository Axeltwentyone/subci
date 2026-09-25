import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router'
import { IconClose, IconFilter, IconHistory, IconSearch, IconShare } from '../components/icons'
import { Sheet } from '../components/Sheet'
import { useToast } from '../components/Toast'
import { Avatars, Button, Card, Chip, DeviceChip, DeviceList, RoundIconButton, BackButton, Row, ServiceLogo, Skeleton, StickyAction, UnderlineTabs, cx } from '../components/ui'
import { CATEGORIES, DEVICES, SERVICES, availLabel, getService, savingPct, type Category, type Device, type PublicOffer, type Service } from '../lib/data'
import { api } from '../lib/api'
import { fcfa, since, timeLeft } from '../lib/format'
import { useOnline } from '../lib/hooks'
import { useStore } from '../lib/store'
import { OfflineScreen } from './system'
import { useBack } from '../lib/nav'

type Filters = { available: boolean; maxPrice: number | null; period: 'month' | '3m' | null }

function applyFilters(list: Service[], cat: Category | 'all', f: Filters) {
  return list.filter(
    (s) => (cat === 'all' || s.category === cat) && (!f.available || s.free > 0) && (f.maxPrice === null || s.price <= f.maxPrice),
  )
}

/* ---------- 05 · Catalogue ---------- */

/** Carte = 3 lignes scannables : quoi / combien de places / prix. Prix toujours à droite. */
export function Explore() {
  const online = useOnline()
  const [params, setParams] = useSearchParams()
  const cat = (params.get('cat') as Category | null) ?? 'all'
  const [filters, setFilters] = useState<Filters>({ available: true, maxPrice: null, period: 'month' })
  const [draft, setDraft] = useState<Filters>(filters)
  const [sheet, setSheet] = useState(false)

  useStore() // re-rendu quand le catalogue est resynchronisé
  const all = SERVICES
  const list = applyFilters(all, cat, filters)
  const activeCount = Number(filters.available) + Number(filters.maxPrice !== null) + Number(filters.period !== null)

  if (!online) return <OfflineScreen embedded />

  return (
    <div className="mx-auto flex max-w-[960px] flex-col gap-4 px-5 pt-2 md:px-8 md:pt-7 desk:px-10 desk:pt-9">
      <h1 className="t-title">Explorer</h1>
      <Link to="/explore/search" viewTransition className="flex h-[52px] items-center gap-2.5 rounded-btn bg-white px-4 text-base font-semibold text-subtle">
        <IconSearch size={20} strokeWidth={2} />
        Netflix, Spotify, ChatGPT…
      </Link>
      <UnderlineTabs
        value={cat}
        options={CATEGORIES.map((c) => ({ value: c.id, label: c.label }))}
        onChange={(v) => setParams(v === 'all' ? {} : { cat: v }, { replace: true })}
      />
      <div className="no-scrollbar -mx-5 flex gap-2 overflow-x-auto px-5">
        <Chip size="sm" active onClick={() => { setDraft(filters); setSheet(true) }}>
          <IconFilter size={14} />
          Filtres{activeCount ? ` · ${activeCount}` : ''}
        </Chip>
        <Chip size="sm" active={filters.available} tone="ok" onClick={() => setFilters((f) => ({ ...f, available: !f.available }))}>
          {filters.available && '✓ '}Disponible
        </Chip>
        <Chip size="sm" active={filters.maxPrice === 3000} tone="ok" onClick={() => setFilters((f) => ({ ...f, maxPrice: f.maxPrice === 3000 ? null : 3000 }))}>
          {filters.maxPrice === 3000 && '✓ '}– 3 000 FCFA
        </Chip>
        <Chip size="sm" active={filters.period === 'month'} tone="ok" onClick={() => setFilters((f) => ({ ...f, period: f.period === 'month' ? null : 'month' }))}>
          {filters.period === 'month' && '✓ '}Mensuel
        </Chip>
      </div>

      {list.length === 0 ? (
        <div className="flex flex-col items-center gap-4 py-12 text-center">
          <p className="font-display text-xl font-bold">Rien avec ces filtres</p>
          <p className="text-[15px] font-medium text-muted">Retire « Disponible » pour voir les listes d’attente.</p>
          <Button variant="ink" size="md" block={false} className="px-6" onClick={() => setFilters({ available: false, maxPrice: null, period: null })}>
            Tout afficher
          </Button>
        </div>
      ) : (
        <div className="grid gap-2.5 md:grid-cols-2 md:gap-3 desk:grid-cols-3">
          {list.map((s) => (
            <CatalogCard key={s.id} s={s} />
          ))}
        </div>
      )}
      <div className="h-2" />

      <Sheet open={sheet} onClose={() => setSheet(false)} label="Filtres">
        <div className="flex flex-col gap-4">
          <h2 className="font-display text-lg font-bold">Filtres</h2>
          <div className="flex flex-col gap-2.5">
            <span className="text-[13px] font-bold text-muted">Durée</span>
            <div className="flex gap-2">
              <Chip size="sm" active={draft.period === 'month'} onClick={() => setDraft({ ...draft, period: draft.period === 'month' ? null : 'month' })}>Mensuel</Chip>
              <Chip size="sm" active={draft.period === '3m'} onClick={() => setDraft({ ...draft, period: draft.period === '3m' ? null : '3m' })}>3 mois</Chip>
            </div>
          </div>
          <div className="flex flex-col gap-2.5">
            <span className="text-[13px] font-bold text-muted">Prix max / mois</span>
            <div className="flex flex-wrap gap-2">
              {[null, 1500, 3000, 5000].map((p) => (
                <Chip key={String(p)} size="sm" active={draft.maxPrice === p} onClick={() => setDraft({ ...draft, maxPrice: p })}>
                  {p === null ? 'Tous' : `– ${fcfa(p)}`}
                </Chip>
              ))}
            </div>
          </div>
          <div className="flex flex-col gap-2.5">
            <span className="text-[13px] font-bold text-muted">Disponibilité</span>
            <div className="flex gap-2">
              <Chip size="sm" active={draft.available} onClick={() => setDraft({ ...draft, available: true })}>Place libre</Chip>
              <Chip size="sm" active={!draft.available} onClick={() => setDraft({ ...draft, available: false })}>Tout, liste d’attente incluse</Chip>
            </div>
          </div>
          <Button
            variant="ink"
            size="link"
            className="mt-1"
            onClick={() => {
              setFilters(draft)
              setSheet(false)
            }}
          >
            Voir {applyFilters(all, cat, draft).length} résultats
          </Button>
        </div>
      </Sheet>
    </div>
  )
}

function CatalogCard({ s }: { s: Service }) {
  return (
    <Link to={`/service/${s.id}`} viewTransition className="pressable flex items-center gap-3.5 rounded-card bg-white p-3.5">
      <ServiceLogo service={s} size={52} />
      <span className="flex min-w-0 flex-1 flex-col gap-1">
        <span className="truncate text-base font-bold">{s.name}</span>
        <span className="truncate text-[13px] font-semibold text-muted">{s.meta}</span>
        <span className={cx('text-xs font-bold', s.free ? 'text-ok' : 'text-wait')}>● {availLabel(s)}</span>
      </span>
      <span className="flex flex-col items-end gap-1.5">
        <span className="rounded-md bg-brand-soft px-[7px] py-[3px] text-[11px] font-extrabold text-brand-ink">-{savingPct(s)} %</span>
        <span className="font-display text-lg leading-none font-extrabold">{fcfa(s.price)}</span>
        <span className="text-[11px] font-semibold text-muted">FCFA/mois</span>
      </span>
    </Link>
  )
}

/* ---------- 06 · Recherche ---------- */

function Highlight({ text, q }: { text: string; q: string }) {
  const i = text.toLowerCase().indexOf(q.toLowerCase())
  if (!q || i < 0) return <>{text}</>
  return (
    <>
      {text.slice(0, i)}
      <mark className="bg-brand-soft text-ink">{text.slice(i, i + q.length)}</mark>
      {text.slice(i + q.length)}
    </>
  )
}

/** Plein écran, clavier ouvert, résultats dès 2 lettres (debounce 150 ms). */
export function Search() {
  const navigate = useNavigate()
  const back = useBack()
  const { state, actions } = useStore()
  const [q, setQ] = useState('')
  const [debounced, setDebounced] = useState('')
  const input = useRef<HTMLInputElement>(null)

  useEffect(() => {
    const t = setTimeout(() => setDebounced(q.trim()), 150)
    return () => clearTimeout(t)
  }, [q])

  useEffect(() => input.current?.focus(), [])

  const results = debounced.length >= 2 ? SERVICES.filter((s) => s.name.toLowerCase().includes(debounced.toLowerCase())) : []

  const open = (s: Service) => {
    actions.recent(s.name)
    navigate(`/service/${s.id}`, { viewTransition: true })
  }

  return (
    <div className="min-h-dvh bg-sand">
      <div className="pt-safe mx-auto flex max-w-[640px] flex-col gap-[22px] px-5 pt-2">
        <form
          className="flex items-center gap-2.5 pt-2"
          role="search"
          onSubmit={(e) => {
            e.preventDefault()
            if (results[0]) open(results[0])
          }}
        >
          <label className="flex h-[52px] flex-1 items-center gap-2.5 rounded-btn border-2 border-ink bg-white px-3.5">
            <IconSearch size={20} strokeWidth={2} />
            <input
              ref={input}
              type="search"
              enterKeyHint="search"
              aria-label="Rechercher un service"
              placeholder="Netflix, Spotify, ChatGPT…"
              value={q}
              onChange={(e) => setQ(e.target.value)}
              className="min-w-0 flex-1 bg-transparent text-base font-semibold caret-brand outline-none placeholder:font-medium placeholder:text-subtle [&::-webkit-search-cancel-button]:hidden"
            />
            {q && (
              <button type="button" aria-label="Effacer" onClick={() => { setQ(''); input.current?.focus() }} className="grid size-[22px] place-items-center rounded-full bg-line">
                <IconClose size={12} />
              </button>
            )}
          </label>
          <button type="button" onClick={() => back()} className="px-0.5 py-3 text-[15px] font-bold">
            Annuler
          </button>
        </form>

        {debounced.length >= 2 && (
          <section className="flex flex-col gap-1" aria-live="polite">
            <span className="t-over text-muted">Résultats</span>
            {results.length === 0 && <p className="py-4 text-[15px] font-medium text-muted">Aucun service pour « {debounced} ». Essaie « Netflix » ou « Spotify ».</p>}
            {results.map((s) => (
              <button key={s.id} type="button" onClick={() => open(s)} className="flex items-center gap-3.5 border-b border-line py-3 text-left last:border-b-0">
                <ServiceLogo service={s} size={44} />
                <span className="flex flex-1 flex-col gap-0.5">
                  <span className="text-base font-bold">
                    <Highlight text={s.name} q={debounced} />
                  </span>
                  <span className={cx('text-[13px] font-semibold', s.free ? 'text-ok' : 'text-wait')}>
                    {s.free ? availLabel(s, false) : 'Liste d’attente'} · {fcfa(s.price)} FCFA
                  </span>
                </span>
                <span className="text-lg font-bold text-subtle">›</span>
              </button>
            ))}
          </section>
        )}

        {state.recent.length > 0 && (
          <section className="flex flex-col gap-3">
            <div className="flex items-baseline justify-between">
              <span className="t-over text-muted">Récent</span>
              <button type="button" className="text-[13px] font-bold text-muted" onClick={() => actions.clearRecent()}>
                Effacer
              </button>
            </div>
            <div className="flex flex-wrap gap-2">
              {state.recent.map((r) => (
                <button key={r} type="button" onClick={() => setQ(r)} className="pressable flex h-10 items-center gap-2 rounded-full bg-white px-3.5 text-sm font-bold">
                  <IconHistory size={14} />
                  {r}
                </button>
              ))}
            </div>
          </section>
        )}
      </div>
    </div>
  )
}

/* ---------- 07 · Page abonnement : choisir son offre ---------- */

/**
 * Plusieurs hôtes proposent le même service : le membre choisit selon ses
 * appareils et son budget. Il paie, puis l'hôte accepte (sinon remboursé).
 */
export function ServicePage() {
  const { id = '' } = useParams()
  const navigate = useNavigate()
  const toast = useToast()
  const { state } = useStore()
  const s = getService(id)
  const [offers, setOffers] = useState<PublicOffer[] | null>(null)
  const [device, setDevice] = useState<Device | null>(null)
  const [selected, setSelected] = useState<string | null>(null)

  useEffect(() => {
    let alive = true
    setOffers(null)
    if (s && !s.chooseOffer) return
    api
      .offers(id)
      .then(({ data }) => alive && setOffers(data))
      .catch(() => alive && setOffers([]))
    return () => {
      alive = false
    }
  }, [id, s])

  if (!s) return <NotFound />

  const tint = `color-mix(in srgb, ${s.color} 12%, white)`
  const current = state.subs.find((x) => x.serviceId === s.id && x.state !== 'expired')
  const pending = state.requests.find((r) => r.serviceId === s.id && r.status === 'pending')
  const visible = (offers ?? []).filter((o) => !device || o.devices.includes(device))
  const chosen = visible.find((o) => o.id === selected) ?? null
  const cheapest = s.chooseOffer && offers?.length ? Math.min(...offers.map((o) => o.price)) : s.price
  const availableDevices = DEVICES.map((d) => d.id).filter((d) => offers?.some((o) => o.devices.includes(d)))

  const share = async () => {
    const data = { title: `${s.name} sur Sub.ci`, text: `${s.name} dès ${fcfa(cheapest)} FCFA/mois sur Sub.ci`, url: location.href }
    try {
      if (navigator.share) await navigator.share(data)
      else {
        await navigator.clipboard.writeText(data.url)
        toast({ text: 'Lien copié' })
      }
    } catch {
      /* partage annulé */
    }
  }

  return (
    <div className="min-h-dvh bg-sand">
      <div className="mx-auto max-w-[440px]">
        <div className="pt-safe pb-[26px] md:mt-6 md:rounded-[28px]" style={{ background: tint }}>
          <div className="flex justify-between px-4 pt-1">
            <BackButton />
            <RoundIconButton label="Partager" onClick={share}>
              <IconShare size={18} />
            </RoundIconButton>
          </div>
          <div className="flex flex-col gap-3.5 px-6 pt-3">
            <ServiceLogo service={s} size={72} />
            <h1 className="t-hero leading-[1.05]">{s.name}</h1>
            <p className="text-[15px] leading-normal font-medium text-body">{s.description}</p>
          </div>
        </div>

        <div className="flex flex-col gap-3.5 px-5 pt-5">
          <Card className="flex flex-col gap-2.5 p-[18px]">
            <div className="flex items-baseline gap-1.5">
              <span className="text-[15px] font-bold text-muted">dès</span>
              <span className="font-display text-4xl leading-none font-extrabold tracking-[-0.02em]">{fcfa(cheapest)}</span>
              <span className="text-[15px] font-bold">FCFA / mois</span>
            </div>
            <div className="flex flex-wrap items-center gap-2">
              <span className="text-sm font-semibold text-muted line-through">{fcfa(s.fullPrice)} FCFA</span>
              <span className="rounded-lg bg-ok-soft px-[9px] py-[5px] text-[13px] font-extrabold text-ok-ink">Jusqu’à {fcfa(s.fullPrice - cheapest)} FCFA d’économie</span>
            </div>
          </Card>

          {current ? (
            <Card className="flex flex-col gap-1 p-[18px]">
              <span className="text-[15px] font-bold">Tu es déjà membre{current.hostName ? ` du cercle de ${current.hostName}` : ''}</span>
              <span className="text-[13px] font-semibold text-muted">Renouvelle au prix actuel : {fcfa(current.price)} FCFA / mois.</span>
            </Card>
          ) : pending ? (
            <div className="flex gap-2.5 rounded-card bg-info-soft p-4 text-[14px] leading-[1.45] font-semibold text-info-ink">
              <span className="font-extrabold">i</span>
              <span>
                Ta demande est chez <b>{pending.hostName}</b>. Réponse d’ici {timeLeft(pending.expiresAt)}, sinon tu es remboursé.
              </span>
            </div>
          ) : !s.chooseOffer ? (
            // Musique : chacun garde son compte, toutes les offres se valent — pas de choix.
            <Card className="flex flex-col gap-3 p-[18px]">
              <div className="flex items-center justify-between gap-3">
                <span className="text-[15px] font-bold">{s.free ? `${s.free} place${s.free > 1 ? 's' : ''} disponible${s.free > 1 ? 's' : ''}` : 'Aucune place pour l’instant'}</span>
                <DeviceList devices={['phone', 'tablet', 'computer', 'tv']} className="justify-end" />
              </div>
              <ul className="flex flex-col gap-2 text-[14px] leading-snug font-medium text-body">
                <li>• Ton propre compte {s.name.replace(/ (Famille|Duo)$/, '')} : tes playlists et recommandations restent à toi.</li>
                <li>• On te place dans un groupe famille, l’hôte valide sous 24 h.</li>
                <li>• Si l’hôte refuse ou ne répond pas, tu es remboursé.</li>
              </ul>
            </Card>
          ) : (
            <section className="flex flex-col gap-3" aria-label="Offres disponibles">
              <div className="flex items-baseline justify-between px-1">
                <h2 className="t-section">Choisis ton offre</h2>
                {offers && <span className="text-[13px] font-bold text-muted">{visible.length} offre{visible.length > 1 ? 's' : ''}</span>}
              </div>
              {availableDevices.length > 1 && (
                <div className="no-scrollbar -mx-5 flex gap-2 overflow-x-auto px-5" role="group" aria-label="Filtrer par appareil">
                  <Chip size="sm" active={device === null} onClick={() => setDevice(null)}>
                    Tous
                  </Chip>
                  {availableDevices.map((d) => (
                    <DeviceChip key={d} device={d} active={device === d} onClick={() => setDevice(device === d ? null : d)} />
                  ))}
                </div>
              )}

              {offers === null ? (
                <>
                  <Skeleton className="h-[132px] rounded-card" />
                  <Skeleton className="h-[132px] rounded-card" />
                </>
              ) : visible.length === 0 ? (
                <Card className="flex flex-col items-center gap-2 p-6 text-center">
                  <span className="text-[15px] font-bold">{offers.length ? 'Aucune offre pour cet appareil' : 'Aucune place libre pour l’instant'}</span>
                  <span className="text-[13px] font-medium text-muted">
                    {offers.length ? 'Essaie un autre appareil.' : 'On te prévient dès qu’un hôte publie une offre.'}
                  </span>
                </Card>
              ) : (
                <div role="radiogroup" aria-label="Offres" className="flex flex-col gap-2.5">
                  {visible.map((o) => (
                    <OfferOption key={o.id} offer={o} selected={o.id === selected} onSelect={() => setSelected(o.id)} />
                  ))}
                </div>
              )}
            </section>
          )}

          <Card className="px-[18px] py-1">
            <Row label="Activation" value="Dès que l’hôte accepte" />
            <Row label="Durée" value="1, 3 ou 6 mois" />
            <Row label="Garantie" value="Remboursé si refus (24 h)" />
          </Card>
        </div>

        <StickyAction>
          {current ? (
            <Button onClick={() => navigate(`/checkout/${s.id}`, { viewTransition: true })}>Renouveler · {fcfa(current.price)} FCFA</Button>
          ) : pending ? (
            <Button variant="ink" onClick={() => navigate('/subs')}>
              Voir ma demande
            </Button>
          ) : !s.chooseOffer ? (
            s.free > 0 ? (
              <Button onClick={() => navigate(`/checkout/${s.id}`, { viewTransition: true })}>Rejoindre pour {fcfa(s.price)} FCFA</Button>
            ) : (
              <Button variant="ink" onClick={() => toast({ tone: 'success', text: 'Tu es sur la liste d’attente. On te prévient dès qu’une place se libère.' })}>
                Me prévenir
              </Button>
            )
          ) : chosen ? (
            <Button onClick={() => navigate(`/checkout/${s.id}?offer=${chosen.id}`, { state: { offer: chosen }, viewTransition: true })}>
              Rejoindre {chosen.host.name} · {fcfa(chosen.price)} FCFA
            </Button>
          ) : offers?.length ? (
            <Button disabled>Choisis une offre</Button>
          ) : (
            <Button variant="ink" onClick={() => toast({ tone: 'success', text: 'Tu es sur la liste d’attente. On te prévient dès qu’une place se libère.' })}>
              Me prévenir
            </Button>
          )}
        </StickyAction>
      </div>
    </div>
  )
}

/** Une offre d'hôte : qui, quelle formule, quels appareils, combien, combien de places. */
export function OfferOption({ offer, selected, onSelect }: { offer: PublicOffer; selected?: boolean; onSelect?: () => void }) {
  const taken = offer.seats - offer.free
  const body = (
    <>
      <div className="flex items-start gap-3">
        <span className="grid size-10 shrink-0 place-items-center rounded-full bg-sand font-display text-base font-extrabold">{offer.host.name.charAt(0)}</span>
        <span className="flex min-w-0 flex-1 flex-col gap-0.5">
          <span className="flex items-center gap-1.5 text-[15px] font-bold">
            {offer.host.name}
            {offer.host.trusted && <span className="rounded-full bg-ok-soft px-2 py-0.5 text-[11px] font-extrabold text-ok-ink">Hôte fiable</span>}
          </span>
          <span className="text-[13px] font-semibold text-muted">Hôte depuis {since(Date.parse(offer.host.since))}</span>
        </span>
        <span className="flex flex-col items-end">
          <span className="font-display text-xl leading-none font-extrabold">{fcfa(offer.price)}</span>
          <span className="text-[11px] font-semibold text-muted">FCFA/mois</span>
        </span>
      </div>
      <div className="flex flex-wrap items-center gap-2">
        <span className="text-[14px] font-bold">{offer.plan}</span>
        {offer.quality && <span className="rounded-md bg-sand px-1.5 py-0.5 text-[11px] font-extrabold">{offer.quality}</span>}
        {offer.mode === 'family' && <span className="rounded-md bg-info-soft px-1.5 py-0.5 text-[11px] font-extrabold text-info">Ton propre compte</span>}
      </div>
      <DeviceList devices={offer.devices} />
      <div className="flex items-center justify-between border-t border-line-soft pt-3">
        <span className="text-[13px] font-bold text-ok">
          {offer.free} place{offer.free > 1 ? 's' : ''} sur {offer.seats}
        </span>
        <Avatars
          size={26}
          members={offer.members.slice(0, 4)}
          extra={taken > 4 ? <span className="-ml-1.5 grid size-[26px] place-items-center rounded-full bg-sand text-[11px] font-extrabold">+{taken - 4}</span> : null}
        />
      </div>
    </>
  )
  if (!onSelect) return <div className="flex flex-col gap-3 rounded-card bg-white p-4">{body}</div>
  return (
    <button
      type="button"
      role="radio"
      aria-checked={selected}
      onClick={onSelect}
      className={cx('pressable flex flex-col gap-3 rounded-card bg-white p-4 text-left', selected ? 'outline-2 outline-ink' : 'outline-0')}
    >
      {body}
    </button>
  )
}

export function NotFound() {
  const navigate = useNavigate()
  return (
    <div className="grid min-h-dvh place-items-center bg-sand px-6 text-center">
      <div className="flex flex-col items-center gap-4">
        <h1 className="t-title">Page introuvable</h1>
        <p className="text-base font-medium text-muted">Ce lien n’existe plus ou a changé.</p>
        <Button block={false} className="px-7" onClick={() => navigate('/home', { replace: true })}>
          Retour à l’accueil
        </Button>
      </div>
    </div>
  )
}
