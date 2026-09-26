import { useEffect, useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { PullToRefresh } from '../components/gestures'
import { IconBell, IconSearch } from '../components/icons'
import { InstallBanner, InstallSheet, NotifSheet, canAskNotifications, shouldOfferInstall } from '../components/pwa'
import { Badge, Card, Progress, ServiceLogo, Skeleton, StatusBadge, cx } from '../components/ui'
import { CATEGORIES, availLabel, getService, popularServices } from '../lib/data'
import { daysLeft, dayName, fcfa, greeting, shortDate, todayLabel } from '../lib/format'
import { isStandalone, useInstall } from '../lib/hooks'
import { subStatus, useActiveSubs, useStore, useUnread, type UserSub } from '../lib/store'

let promptsShownThisSession = false

export function Home() {
  const { state, actions } = useStore()
  const navigate = useNavigate()
  const subs = useActiveSubs()
  const unread = useUnread()
  const install = useInstall()
  // Skeleton seulement au tout premier chargement (rien en cache), après 300 ms.
  const firstLoad = state.lastSync === 0
  const [late, setLate] = useState(false)
  useEffect(() => {
    if (!firstLoad) return
    const t = setTimeout(() => setLate(true), 300)
    return () => clearTimeout(t)
  }, [firstLoad])
  const [sheet, setSheet] = useState<'install' | 'notif' | null>(null)
  const firstName = state.user?.name?.split(' ')[0] ?? 'toi'

  // Installation proposée après le 1er achat (moment de valeur), puis les rappels.
  useEffect(() => {
    if (promptsShownThisSession || state.purchases === 0) return
    const t = setTimeout(() => {
      if (shouldOfferInstall(state.purchases, state.installDismissedAt, isStandalone()) && (install.canPrompt || install.ios)) {
        promptsShownThisSession = true
        setSheet('install')
      } else if (!state.notifPromptDone && canAskNotifications()) {
        promptsShownThisSession = true
        setSheet('notif')
      }
    }, 900)
    return () => clearTimeout(t)
  }, [state.purchases, state.installDismissedAt, state.notifPromptDone, install.canPrompt, install.ios])

  if (firstLoad && state.syncing) return late ? <HomeSkeleton /> : null

  const focus = subs[0]

  return (
    <PullToRefresh onRefresh={() => actions.sync().catch(() => {})}>
      <div className="desk:grid desk:min-h-dvh desk:grid-cols-[minmax(0,1fr)_360px]">
        <div className="mx-auto flex w-full max-w-[960px] flex-col gap-[22px] px-5 pt-2 md:gap-6 md:px-8 md:pt-7 desk:px-10 desk:pt-9">
          <header className="flex items-center justify-between gap-4">
            <div className="flex flex-col gap-0.5">
              <span className="text-sm font-semibold text-muted md:hidden">{todayLabel()}</span>
              <h1 className="font-display text-[28px] leading-[1.1] font-bold tracking-[-0.025em] md:text-[32px] desk:text-4xl desk:tracking-[-0.03em]">
                {greeting()}, {firstName}
              </h1>
            </div>
            <Link
              to="/explore/search"
              viewTransition
              className="hidden h-12 w-[340px] items-center gap-2.5 rounded-[14px] bg-surface px-4 text-[15px] font-semibold text-subtle desk:flex"
            >
              <IconSearch size={18} strokeWidth={2} />
              Rechercher un service
            </Link>
            <Link to="/activity" aria-label={`Activité, ${unread} non lues`} className="pressable relative grid size-12 place-items-center rounded-full bg-surface md:hidden">
              <IconBell />
              {unread > 0 && <span className="absolute top-[11px] right-3 size-[9px] rounded-full border-2 border-surface bg-brand" />}
            </Link>
          </header>
          <InstallBanner onOpen={() => setSheet('install')} />

          <div className="grid gap-[22px] md:grid-cols-[1.3fr_1fr] md:gap-4 desk:grid-cols-1">
            <FocusCard sub={focus} onGo={navigate} />
          </div>

          {state.offers.length === 0 && (
            <Card onClick={() => navigate('/host', { viewTransition: true })} className="flex items-center gap-3.5 p-4">
              <span className="grid size-11 place-items-center rounded-tile bg-brand-soft font-display text-lg font-extrabold text-brand-ink">+</span>
              <span className="flex flex-1 flex-col gap-0.5">
                <span className="text-[15px] font-bold">Tu as un abonnement perso ?</span>
                <span className="text-[13px] font-semibold text-muted">Partage tes places libres et gagne chaque mois.</span>
              </span>
              <span className="text-subtle">›</span>
            </Card>
          )}

          <div className="no-scrollbar -mx-5 flex gap-2 overflow-x-auto px-5 md:hidden">
            {CATEGORIES.slice(1).map((c) => (
              <Link key={c.id} to={`/explore?cat=${c.id}`} className="pressable flex h-10 shrink-0 items-center rounded-full bg-surface px-4 text-sm font-bold">
                {c.chip}
              </Link>
            ))}
          </div>

          <section className="flex flex-col gap-3">
            <div className="flex items-baseline justify-between">
              <h2 className="t-section desk:text-[22px]">Populaires</h2>
              <Link to="/explore" className="text-sm font-bold text-muted">
                Tout voir
              </Link>
            </div>
            <div className="no-scrollbar -mx-5 flex gap-3 overflow-x-auto px-5 md:mx-0 md:grid md:grid-cols-3 md:overflow-visible md:px-0 desk:grid-cols-4 desk:gap-3.5">
              {popularServices().slice(0, 4).map((s, k) => {
                return (
                  <Link
                    key={s.id}
                    to={`/service/${s.id}`}
                    viewTransition
                    className={cx('pressable flex w-[148px] shrink-0 flex-col gap-3 rounded-card bg-surface p-3.5 md:w-auto desk:p-[18px]', k === 3 && 'md:hidden desk:flex')}
                  >
                    <ServiceLogo service={s} size={40} />
                    <span className="flex flex-col gap-0.5">
                      <span className="text-sm font-bold desk:text-[15px]">{s.name}</span>
                      <span className={cx('text-xs font-semibold', s.free ? 'text-ok' : 'text-wait')}>{availLabel(s, false)}</span>
                    </span>
                    <span className="font-display text-[17px] font-extrabold desk:text-[19px]">
                      {fcfa(s.price)} <span className="font-sans text-[11px] font-semibold text-muted">FCFA/mois</span>
                    </span>
                  </Link>
                )
              })}
            </div>
          </section>

          <div className="h-2" />
        </div>

        <aside className="hidden flex-col gap-3.5 border-l border-line bg-surface px-6 py-9 desk:flex">
          <h2 className="t-section">Prochaines échéances</h2>
          {subs.length === 0 && <p className="text-sm font-medium text-muted">Aucune échéance à venir.</p>}
          {subs.map((s) => (
            <DueRow key={s.id} sub={s} />
          ))}
          <div className="mt-auto flex flex-col gap-2.5 rounded-card bg-sand p-[18px]">
            <span className="text-base font-bold">Sub.ci sur ton téléphone</span>
            <span className="text-[13px] leading-normal font-medium text-muted">Scanne pour installer l’app et recevoir tes rappels.</span>
            <InstallQr />
          </div>
        </aside>
      </div>

      <InstallSheet
        open={sheet === 'install'}
        onClose={() => setSheet(!state.notifPromptDone && canAskNotifications() ? 'notif' : null)}
      />
      <NotifSheet open={sheet === 'notif'} onClose={() => setSheet(null)} />
    </PullToRefresh>
  )
}

/** Une seule carte Ink par écran = l'action du moment (échéance la plus proche). */
function FocusCard({ sub, onGo }: { sub?: UserSub; onGo: (to: string, o?: { viewTransition: boolean }) => void }) {
  if (!sub) {
    return (
      <div className="flex flex-col gap-4 scheme-card rounded-[24px] bg-ink p-5 text-sand desk:p-7">
        <span className="t-over text-brand">Bienvenue</span>
        <span className="font-display text-2xl leading-[1.1] font-bold tracking-[-0.02em]">Trouve ton 1er abonnement</span>
        <span className="text-sm font-medium text-ink-muted">Netflix dès 2 500 FCFA, Spotify dès 1 500 FCFA.</span>
        <button type="button" onClick={() => onGo('/explore')} className="pressable flex h-11 items-center self-start rounded-[14px] bg-brand px-5 text-[15px] font-bold text-on-accent">
          Explorer
        </button>
      </div>
    )
  }
  const svc = getService(sub.serviceId)!
  const status = subStatus(sub)
  const days = daysLeft(sub.endAt)
  const total = Math.max(1, sub.endAt - sub.startAt)
  const elapsed = Math.min(1, Math.max(0, (Date.now() - sub.startAt) / total))
  const due = status === 'due'

  return (
    <div className="flex flex-col gap-[18px] scheme-card rounded-[24px] bg-ink p-5 text-sand desk:flex-row desk:items-center desk:gap-6 desk:rounded-[28px] desk:p-7">
      <div className="flex items-center gap-3 desk:contents">
        <span className="desk:hidden">
          <ServiceLogo service={svc} size={44} />
        </span>
        <span className="hidden desk:block">
          <ServiceLogo service={svc} size={64} />
        </span>
        <div className="flex flex-1 flex-col gap-0.5 desk:hidden">
          <span className="text-base font-bold">{svc.name}</span>
          <span className="text-[13px] font-semibold text-ink-muted">{sub.profile.split(' · ')[0]} · {svc.meta.split(' · ')[0]}</span>
        </div>
        <span className="desk:hidden">
          {due ? <Badge tone="brand" className="px-2.5 py-1.5">J-{Math.max(0, days)}</Badge> : <StatusBadge status={status} days={days} />}
        </span>
      </div>
      <div className="flex flex-col gap-2.5 desk:flex-1 desk:gap-1.5">
        <span className="font-display text-2xl leading-[1.1] font-bold tracking-[-0.02em] desk:text-[26px]">
          <span className="hidden desk:inline">{svc.name} — </span>
          {due ? `Renouvelle avant ${dayName(sub.endAt)}` : status === 'pending' ? 'Activation en cours' : `Actif jusqu’au ${shortDate(sub.endAt)}`}
        </span>
        <span className="hidden text-sm font-semibold text-ink-muted desk:block">
          {sub.profile.split(' · ')[0]} · expire le {shortDate(sub.endAt)}
        </span>
        <div className="desk:hidden">
          <Progress value={elapsed} color="#FF5A1F" track="bg-ink-3" />
        </div>
      </div>
      <div className="flex items-center justify-between desk:justify-end">
        <span className="font-display text-xl font-extrabold desk:hidden">
          {fcfa(sub.price)} <span className="font-sans text-[13px] font-semibold text-ink-muted">FCFA/mois</span>
        </span>
        {due ? (
          <button
            type="button"
            onClick={() => onGo(`/checkout/${svc.id}`, { viewTransition: true })}
            className="pressable flex h-11 items-center rounded-[14px] bg-brand px-5 text-[15px] font-bold text-on-accent desk:h-[52px] desk:rounded-btn desk:px-6 desk:text-base"
          >
            Renouveler<span className="hidden desk:inline">&nbsp;· {fcfa(sub.price)} FCFA/mois</span>
          </button>
        ) : (
          <button
            type="button"
            onClick={() => onGo(`/subs/${sub.id}`, { viewTransition: true })}
            className="pressable flex h-11 items-center rounded-[14px] bg-ink-3 px-5 text-[15px] font-bold text-sand"
          >
            Mes accès
          </button>
        )}
      </div>
    </div>
  )
}


function DueRow({ sub }: { sub: UserSub }) {
  const svc = getService(sub.serviceId)!
  const status = subStatus(sub)
  return (
    <Link to={`/subs/${sub.id}`} viewTransition className="flex items-center gap-3 border-b border-line-soft py-3 last:border-b-0">
      <ServiceLogo service={svc} size={40} />
      <span className="flex-1 text-[15px] font-bold">{svc.name.split(' ')[0]}</span>
      {status === 'active' ? <span className="text-[13px] font-semibold text-muted">{shortDate(sub.endAt)}</span> : <StatusBadge status={status} days={daysLeft(sub.endAt)} />}
    </Link>
  )
}

/** 19 · Skeleton : même géométrie que l'écran final ; nav toujours interactive. */
function HomeSkeleton() {
  return (
    <div className="mx-auto flex max-w-[960px] flex-col gap-[22px] px-5 pt-2 md:px-8 md:pt-7" aria-busy="true" aria-label="Chargement">
      <div className="flex items-center justify-between">
        <div className="flex flex-col gap-2">
          <Skeleton className="h-3 w-[110px] rounded-md" />
          <Skeleton className="h-[26px] w-[190px] rounded-lg" />
        </div>
        <Skeleton shimmer={false} className="size-12 rounded-full" />
      </div>
      <Skeleton className="h-[210px] rounded-[24px]" />
      <div className="flex gap-2">
        <Skeleton shimmer={false} className="h-10 w-24 rounded-full" />
        <Skeleton shimmer={false} className="h-10 w-20 rounded-full" />
        <Skeleton shimmer={false} className="h-10 w-[100px] rounded-full" />
      </div>
      <div className="-mr-5 flex gap-3 overflow-hidden">
        <span className="h-[150px] w-[148px] shrink-0 rounded-card bg-surface" />
        <span className="h-[150px] w-[148px] shrink-0 rounded-card bg-surface" />
        <span className="h-[150px] w-[148px] shrink-0 rounded-card bg-surface" />
      </div>
    </div>
  )
}

/** QR code vers l'app (écran d'ordinateur → téléphone). */
function InstallQr() {
  const [src, setSrc] = useState<string | null>(null)
  useEffect(() => {
    import('qrcode')
      .then((QR) => QR.toDataURL(window.location.origin, { margin: 1, width: 176, color: { dark: '#16130F', light: '#FFFFFF' } }))
      .then(setSrc)
      .catch(() => setSrc(null))
  }, [])
  return src ? <img src={src} alt="QR code pour ouvrir Sub.ci sur ton téléphone" width={88} height={88} className="rounded-tile" /> : null
}
