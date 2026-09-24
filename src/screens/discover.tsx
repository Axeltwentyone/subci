import { useEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router'
import { IconClose, IconFilter, IconHistory, IconSearch, IconShare } from '../components/icons'
import { Sheet } from '../components/Sheet'
import { useToast } from '../components/Toast'
import { Avatars, Button, Card, Chip, RoundIconButton, BackButton, Row, ServiceLogo, StickyAction, UnderlineTabs, cx } from '../components/ui'
import { AVATAR_COLORS, CATEGORIES, SERVICES, availLabel, getService, savingPct, type Category, type Service } from '../lib/data'
import { fcfa } from '../lib/format'
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

/* ---------- 07 · Page abonnement ---------- */

/** Teinte de marque du service en en-tête ; sticky CTA avec le prix répété. */
export function ServicePage() {
  const { id = '' } = useParams()
  const navigate = useNavigate()
  const toast = useToast()
  const s = getService(id)
  if (!s) return <NotFound />

  const tint = `color-mix(in srgb, ${s.color} 12%, white)`
  const save = s.fullPrice - s.price
  const taken = s.seats - s.groupFree

  const share = async () => {
    const data = { title: `${s.name} sur Sub.ci`, text: `${s.name} à ${fcfa(s.price)} FCFA/mois sur Sub.ci`, url: location.href }
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
              <span className="font-display text-4xl leading-none font-extrabold tracking-[-0.02em]">{fcfa(s.price)}</span>
              <span className="text-[15px] font-bold">FCFA / mois</span>
            </div>
            <div className="flex flex-wrap items-center gap-2">
              <span className="text-sm font-semibold text-muted line-through">{fcfa(s.fullPrice)} FCFA</span>
              <span className="rounded-lg bg-ok-soft px-[9px] py-[5px] text-[13px] font-extrabold text-ok-ink">Tu économises {fcfa(save)} FCFA</span>
            </div>
          </Card>
          <Card className="flex items-center justify-between p-[18px]">
            <div className="flex flex-col gap-1">
              <span className="text-[15px] font-bold">{s.groupFree ? `${s.groupFree} place${s.groupFree > 1 ? 's' : ''} disponible${s.groupFree > 1 ? 's' : ''}` : 'Groupe complet'}</span>
              <span className="text-[13px] font-semibold text-muted">sur {s.seats} · groupe vérifié</span>
            </div>
            <Avatars
              size={34}
              members={Array.from({ length: Math.min(taken, 3) }, (_, k) => ({ name: ' ', color: AVATAR_COLORS[k] }))}
              extra={
                s.groupFree > 0 ? (
                  <span className="-ml-1.5 grid size-[34px] place-items-center rounded-full border-2 border-dashed border-ok text-sm font-extrabold text-ok">+</span>
                ) : null
              }
            />
          </Card>
          <Card className="px-[18px] py-1">
            <Row label="Activation" value={`Sous ${s.activation} min`} />
            <Row label="Durée" value="1, 3 ou 6 mois" />
            <Row label="Garantie" value="Remboursé si non activé" />
          </Card>
        </div>
        <StickyAction>
          {s.free > 0 ? (
            <Button onClick={() => navigate(`/checkout/${s.id}`, { viewTransition: true })}>Rejoindre pour {fcfa(s.price)} FCFA</Button>
          ) : (
            <Button variant="ink" onClick={() => toast({ tone: 'success', text: 'Tu es sur la liste d’attente. On te prévient dès qu’une place se libère.' })}>
              Rejoindre la liste d’attente
            </Button>
          )}
        </StickyAction>
      </div>
    </div>
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
