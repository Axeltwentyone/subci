import { useEffect, useState, type ReactNode } from 'react'
import { useNavigate, useSearchParams } from 'react-router'
import { PullToRefresh, SwipeToArchive } from '../components/gestures'
import { IconGear } from '../components/icons'
import { InstallSheet, NotifSheet, canAskNotifications } from '../components/pwa'
import { NameForm } from '../components/NameForm'
import { Sheet } from '../components/Sheet'
import { useToast } from '../components/Toast'
import { BackButton, Card, Chip, ListLink, MethodLogo, RoundIconButton, Screen, SectionLabel, Toggle, cx } from '../components/ui'
import { getMethod } from '../lib/data'
import { DAY, clock, fcfa, formatPhone, shortDate } from '../lib/format'
import { isStandalone } from '../lib/hooks'
import { subscribePush } from '../lib/push'
import { errorMessage, useSavings, useStore, type Notif, type Settings } from '../lib/store'
import { ConfirmModal } from './manage'

/* ---------- 13 · Activité (notifications + paiements) ---------- */

const NOTIF_ICON: Record<Notif['kind'], { bg: string; fg: string; glyph: string }> = {
  ok: { bg: '#DDF3E8', fg: '#0B6B49', glyph: '✓' },
  pay: { bg: '#E3EBFF', fg: '#2446A8', glyph: '₣' },
  due: { bg: '#FFF1D6', fg: '#8A5000', glyph: '!' },
  seat: { bg: '#FFE6DA', fg: '#A83A0E', glyph: '+1' },
  host: { bg: '#DDF3E8', fg: '#0B6B49', glyph: '↗' },
}

function groupOf(ts: number) {
  const startOfDay = new Date()
  startOfDay.setHours(0, 0, 0, 0)
  if (ts >= startOfDay.getTime()) return 'Aujourd’hui'
  if (ts >= startOfDay.getTime() - 6 * DAY) return 'Cette semaine'
  return 'Plus ancien'
}

/** Chaque notif porte son action : pas besoin d'ouvrir. Swipe gauche = archiver. */
export function Activity() {
  const { state, actions } = useStore()
  const navigate = useNavigate()
  const toast = useToast()
  const [params, setParams] = useSearchParams()
  const tab = params.get('tab') === 'payments' ? 'payments' : 'notifs'
  const unread = state.notifs.filter((n) => n.unread).length
  const [askNotif, setAskNotif] = useState(false)

  useEffect(() => {
    if (state.notifPromptDone || !canAskNotifications()) return
    const t = setTimeout(() => setAskNotif(true), 800)
    return () => clearTimeout(t)
  }, [state.notifPromptDone])

  const groups: [string, Notif[]][] = []
  for (const n of state.notifs) {
    const g = groupOf(n.at)
    const last = groups[groups.length - 1]
    if (last && last[0] === g) last[1].push(n)
    else groups.push([g, [n]])
  }

  const archive = (n: Notif) => {
    actions
      .archive(n)
      .then((index) =>
        toast({ tone: 'ink', text: 'Notification archivée', action: { label: 'Annuler', onClick: () => actions.restore(n, index).catch(() => {}) } }),
      )
      .catch((e) => toast({ tone: 'error', text: errorMessage(e) }))
  }

  return (
    <PullToRefresh onRefresh={() => actions.sync().catch(() => {})}>
      <div className="mx-auto flex max-w-[720px] flex-col gap-4 px-5 pt-2 md:px-8 md:pt-7 desk:pt-9">
        <div className="flex items-center justify-between">
          <h1 className="t-title">Activité</h1>
          {tab === 'notifs' && unread > 0 && (
            <button type="button" className="text-sm font-bold text-muted" onClick={() => actions.readAll().catch(() => {})}>
              Tout lire
            </button>
          )}
        </div>
        <div className="flex gap-2">
          <Chip active={tab === 'notifs'} onClick={() => setParams({}, { replace: true })} surface>
            Notifications{unread ? ` · ${unread}` : ''}
          </Chip>
          <Chip active={tab === 'payments'} onClick={() => setParams({ tab: 'payments' }, { replace: true })} surface>
            Paiements
          </Chip>
        </div>

        {tab === 'notifs' ? (
          state.notifs.length === 0 ? (
            <div className="flex flex-col items-center gap-3 py-16 text-center">
              <p className="font-display text-xl font-bold">Tout est calme</p>
              <p className="text-[15px] font-medium text-muted">On te prévient avant chaque échéance et dès qu’une place se libère.</p>
            </div>
          ) : (
            groups.map(([g, list]) => (
              <section key={g} className="flex flex-col gap-2.5">
                <SectionLabel>{g}</SectionLabel>
                {list.map((n) => (
                  <SwipeToArchive key={n.id} onArchive={() => archive(n)}>
                    <NotifCard
                      n={n}
                      onOpen={() => {
                        actions.read(n.id)
                        if (n.action) navigate(n.action.to, { viewTransition: true })
                      }}
                    />
                  </SwipeToArchive>
                ))}
              </section>
            ))
          )
        ) : (
          <Card className="px-4 py-1">
            {state.payments.map((p) => {
              const m = getMethod(p.method)
              return (
                <div key={p.id} className="flex items-center gap-3 border-b border-line-soft py-3.5 last:border-b-0">
                  <MethodLogo method={m} size={40} />
                  <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                    <span className="truncate text-[15px] font-bold">{p.label}</span>
                    <span className="text-[13px] font-semibold text-muted">
                      {p.pending ? <b className="text-warn-ink">En cours · </b> : null}
                      {shortDate(p.at)} · {m.short} · {p.ref}
                    </span>
                  </div>
                  <span className={cx('font-display text-base font-extrabold whitespace-nowrap', p.direction === 'in' && 'text-ok-ink')}>
                    {p.direction === 'in' ? '+' : '–'}
                    {fcfa(p.amount)}
                  </span>
                </div>
              )
            })}
          </Card>
        )}
        <div className="h-2" />
      </div>
      <NotifSheet open={askNotif} onClose={() => setAskNotif(false)} />
    </PullToRefresh>
  )
}

function NotifCard({ n, onOpen }: { n: Notif; onOpen: () => void }) {
  const icon = NOTIF_ICON[n.kind]
  const inline = n.kind === 'due' && n.action
  const navigate = useNavigate()
  return (
    <div className="flex flex-col gap-3 p-3.5">
      <button type="button" onClick={onOpen} className="flex gap-3 text-left">
        <span className="grid size-10 shrink-0 place-items-center rounded-tile text-sm font-extrabold" style={{ background: icon.bg, color: icon.fg }}>
          {icon.glyph}
        </span>
        <span className="flex min-w-0 flex-1 flex-col gap-[3px]">
          <span className="text-[15px] font-bold">{n.title}</span>
          <span className="text-sm leading-[1.4] font-medium text-muted">{n.body}</span>
        </span>
        <span className="flex flex-col items-end gap-2">
          <span className="text-xs font-semibold text-muted">{Date.now() - n.at < DAY ? clock(n.at) : shortDate(n.at)}</span>
          {n.unread && <span className="size-2 rounded-full bg-brand" aria-label="Non lue" />}
        </span>
      </button>
      {inline && (
        <button
          type="button"
          onClick={() => navigate(n.action!.to, { viewTransition: true })}
          className="pressable ml-[52px] flex h-10 items-center self-start rounded-tile bg-brand px-4 text-sm font-bold"
        >
          {n.action!.label}
        </button>
      )}
    </div>
  )
}

/* ---------- 14 · Profil ---------- */

/** Le profil montre la valeur (économies) avant les réglages. Parrainage = seule surface promo. */
export function Profile() {
  const { state, actions } = useStore()
  const navigate = useNavigate()
  const toast = useToast()
  const { count, saved } = useSavings()
  const waiting = state.requests.filter((r) => r.status === 'pending').length
  const members = state.offers.reduce((n, o) => n + o.members.length, 0)
  const hostWaiting = state.offers.reduce((n, o) => n + (o.requests?.length ?? 0), 0)
  const [editName, setEditName] = useState(false)
  const user = state.user ?? { name: null, firstName: null, phone: '', referralCode: '' }
  const code = user.referralCode

  const share = async () => {
    const url = `${location.origin}/?ref=${encodeURIComponent(code)}`
    const text = `Rejoins-moi sur Sub.ci : tes abonnements (Netflix, Spotify…) à prix partagé, en mobile money. Avec mon code ${code}, tes frais de service sont offerts.`
    try {
      if (navigator.share) await navigator.share({ title: 'Sub.ci', text, url })
      else {
        await navigator.clipboard.writeText(`${text} ${url}`)
        toast({ tone: 'ink', text: 'Code copié', action: { label: 'OK', onClick: () => {} } })
      }
    } catch {
      /* partage annulé */
    }
  }

  return (
    <PullToRefresh onRefresh={() => actions.sync().catch(() => {})}>
      <div className="mx-auto flex max-w-[720px] flex-col gap-[18px] px-5 pt-2 md:px-8 md:pt-7 desk:pt-9">
        <div className="flex items-center gap-3.5">
          <span className="grid size-16 place-items-center rounded-full bg-[#FFB38F] font-display text-[26px] font-extrabold">{(user.name ?? '?').charAt(0)}</span>
          <div className="flex flex-1 flex-col gap-0.5">
            <button type="button" onClick={() => setEditName(true)} className="text-left">
              <h1 className={cx('font-display text-2xl font-bold tracking-[-0.02em]', !user.name && 'text-muted')}>{user.name ?? 'Ajoute ton prénom'}</h1>
            </button>
            <span className="text-sm font-semibold text-muted">+225 {formatPhone(user.phone)}</span>
          </div>
          <RoundIconButton label="Paramètres" onClick={() => navigate('/settings', { viewTransition: true })}>
            <IconGear size={20} />
          </RoundIconButton>
        </div>
        <div className="grid grid-cols-2 gap-2.5">
          <Stat
            value={String(count)}
            label={`abonnement${count > 1 ? 's' : ''} actif${count > 1 ? 's' : ''}`}
            hint={waiting > 0 ? `+${waiting} en attente de l’hôte` : undefined}
            onClick={() => navigate('/subs')}
          />
          <Stat value={fcfa(saved)} label="FCFA économisés / mois" onClick={() => navigate('/subs')} />
          {state.offers.length > 0 && (
            <>
              <Stat
                value={String(members)}
                label={`membre${members > 1 ? 's' : ''} dans tes cercles`}
                hint={hostWaiting > 0 ? `${hostWaiting} demande${hostWaiting > 1 ? 's' : ''} à traiter` : `${state.offers.length} offre${state.offers.length > 1 ? 's' : ''} partagée${state.offers.length > 1 ? 's' : ''}`}
                onClick={() => navigate('/subs?mode=host')}
              />
              <Stat
                value={fcfa(state.monthGain)}
                label="FCFA gagnés ce mois"
                hint={state.pending > 0 ? `dont ${fcfa(state.pending)} à venir` : undefined}
                onClick={() => navigate('/subs?mode=host')}
              />
            </>
          )}
        </div>
        <ReferralCard code={code} onShare={share} />
        <Card className="px-[18px]">
          <ListLink label="Partager & gagner" hint={state.offers.length ? `${fcfa(state.balance)} FCFA` : undefined} onClick={() => navigate(state.offers.length ? '/subs?mode=host' : '/host', { viewTransition: true })} />
          <ListLink label="Moyens de paiement" hint={getMethod(state.lastMethod).name} onClick={() => navigate('/settings', { viewTransition: true })} />
          <ListLink label="Historique des paiements" onClick={() => navigate('/activity?tab=payments')} />
          <ListLink label="Aide & WhatsApp" onClick={() => window.open('https://wa.me/2250700000000', '_blank', 'noopener')} />
          <ListLink label="Paramètres" onClick={() => navigate('/settings', { viewTransition: true })} />
        </Card>
        <div className="h-2" />
        <Sheet open={editName} onClose={() => setEditName(false)} label="Prénom et nom">
          <h2 className="mb-4 font-display text-2xl font-bold tracking-[-0.02em]">Prénom et nom</h2>
          {editName && <NameForm submitLabel="Enregistrer" onDone={() => { setEditName(false); toast({ text: 'Nom mis à jour' }) }} />}
        </Sheet>
      </div>
    </PullToRefresh>
  )
}

/* ---------- 15 · Paramètres ---------- */

/** Lignes ≥ 56 px, toggles 52×32. */
export function SettingsScreen() {
  const { state, actions } = useStore()
  const navigate = useNavigate()
  const toast = useToast()
  const [install, setInstall] = useState(false)
  const [askNotif, setAskNotif] = useState(false)
  const [logout, setLogout] = useState(false)
  const [others, setOthers] = useState(false)
  const set = <K extends keyof Settings>(key: K, value: Settings[K]) =>
    actions.setSetting(key, value).catch((e) => toast({ tone: 'error', text: errorMessage(e) }))
  const s = state.settings

  const notifToggle = (key: 'notifDue' | 'notifSeats' | 'notifPromo') => (v: boolean) => {
    set(key, v)
    if (v && canAskNotifications()) setAskNotif(true)
    else if (v) subscribePush().catch(() => {})
  }

  const cycleData = () => set('dataSaver', s.dataSaver === 'auto' ? 'on' : s.dataSaver === 'on' ? 'off' : 'auto')
  const dataLabel = { auto: 'Auto', on: 'Activée', off: 'Désactivée' }[s.dataSaver]

  return (
    <Screen>
      <div className="flex items-center gap-3 px-4 pt-1">
        <BackButton />
        <h1 className="font-display text-[22px] font-bold">Paramètres</h1>
      </div>
      <div className="flex flex-col gap-2.5 px-5 pt-4 pb-10">
        <SectionLabel className="px-1">Notifications</SectionLabel>
        <Card className="px-[18px]">
          <SettingRow label="Échéances & paiements" hint="Recommandé">
            <Toggle label="Échéances & paiements" checked={s.notifDue} onChange={notifToggle('notifDue')} />
          </SettingRow>
          <SettingRow label="Places libérées">
            <Toggle label="Places libérées" checked={s.notifSeats} onChange={notifToggle('notifSeats')} />
          </SettingRow>
          <SettingRow label="Bons plans" hint="Max 1 par semaine">
            <Toggle label="Bons plans" checked={s.notifPromo} onChange={notifToggle('notifPromo')} />
          </SettingRow>
        </Card>

        <SectionLabel className="px-1 pt-3.5">Sécurité</SectionLabel>
        <Card className="px-[18px]">
          <SettingRow label="Déverrouillage biométrique">
            <Toggle label="Déverrouillage biométrique" checked={s.biometric} onChange={(v) => set('biometric', v)} />
          </SettingRow>
          <ListLink
            label="Masquer les accès"
            hint={s.hideAccess === 'always' ? 'Toujours' : 'Jamais'}
            onClick={() => set('hideAccess', s.hideAccess === 'always' ? 'never' : 'always')}
          />
          <ListLink label="Déconnecter mes autres appareils" hint="Téléphone perdu ?" onClick={() => setOthers(true)} />
        </Card>

        <SectionLabel className="px-1 pt-3.5">App</SectionLabel>
        <Card className="px-[18px]">
          <ListLink label="Langue" hint="Français" onClick={() => toast({ text: 'English & Nouchi : bientôt' })} />
          <ListLink label="Économie de données" hint={dataLabel} onClick={cycleData} />
          {!isStandalone() && <ListLink label="Installer l’app" hint="< 1 Mo" onClick={() => setInstall(true)} />}
          <div className="flex min-h-14 items-center justify-between text-[15px] font-bold">
            <span>Version</span>
            <span className="font-semibold text-muted">{__APP_VERSION__} · à jour</span>
          </div>
        </Card>

        <button type="button" onClick={() => setLogout(true)} className="mt-1.5 min-h-14 text-[15px] font-bold text-err">
          Se déconnecter
        </button>
      </div>
      <InstallSheet open={install} onClose={() => setInstall(false)} />
      <NotifSheet open={askNotif} onClose={() => setAskNotif(false)} />
      {others && (
        <ConfirmModal
          title="Déconnecter les autres appareils ?"
          text="Tous les autres téléphones et ordinateurs connectés à ton compte devront se reconnecter par SMS. Cet appareil reste connecté."
          confirm="Déconnecter"
          onCancel={() => setOthers(false)}
          onConfirm={async () => {
            try {
              const n = await actions.logoutOthers()
              toast({ tone: 'ink', text: n > 0 ? `${n} appareil${n > 1 ? 's' : ''} déconnecté${n > 1 ? 's' : ''}` : 'Aucun autre appareil connecté' })
            } catch (e) {
              toast({ tone: 'error', text: errorMessage(e) })
            }
            setOthers(false)
          }}
        />
      )}
      {logout && (
        <ConfirmModal
          title="Se déconnecter ?"
          text="Tes accès hors ligne seront effacés de cet appareil."
          confirm="Déconnexion"
          onCancel={() => setLogout(false)}
          onConfirm={async () => {
            await actions.logout()
            navigate('/login', { replace: true })
          }}
        />
      )}
    </Screen>
  )
}

function SettingRow({ label, hint, children }: { label: string; hint?: string; children: ReactNode }) {
  return (
    <div className="flex min-h-[60px] items-center justify-between gap-3 border-b border-line-soft last:border-b-0">
      <div className="flex flex-col gap-0.5">
        <span className="text-[15px] font-bold">{label}</span>
        {hint && <span className="text-xs font-semibold text-muted">{hint}</span>}
      </div>
      {children}
    </div>
  )
}

/** Chiffre clé du profil, cliquable vers le détail. */
function Stat({ value, label, hint, onClick }: { value: string; label: string; hint?: string; onClick?: () => void }) {
  return (
    <button type="button" onClick={onClick} className="pressable text-left">
      <Card className="flex h-full flex-col gap-1 p-4">
        <span className="font-display text-[28px] leading-none font-extrabold">{value}</span>
        <span className="text-[13px] font-semibold text-muted">{label}</span>
        {hint && <span className="text-[12px] font-bold text-brand-ink">{hint}</span>}
      </Card>
    </button>
  )
}

/** Parrainage : l'ami ne paie pas les frais, le parrain gagne du crédit à son 1er « oui » d'un hôte. */
function ReferralCard({ code, onShare }: { code: string; onShare: () => void }) {
  const { state, actions } = useStore()
  const toast = useToast()
  const r = state.referral
  const reward = r?.reward ?? 500
  const [friend, setFriend] = useState('')
  const [open, setOpen] = useState(false)
  const [busy, setBusy] = useState(false)

  return (
    <div className="flex flex-col gap-3 rounded-card bg-brand p-[18px]">
      <div className="flex flex-col gap-1">
        <span className="font-display text-xl leading-[1.15] font-bold">Invite un ami, gagne {fcfa(reward)} FCFA</span>
        <span className="text-[13px] leading-snug font-semibold text-ink/75">
          Ton ami ne paie pas les frais de service. Toi, tu reçois {fcfa(reward)} F de crédit dès qu’un hôte l’accepte, déduits de ton prochain paiement.
        </span>
      </div>
      <div className="flex gap-2">
        <span className="flex h-11 flex-1 items-center rounded-tile bg-white px-3.5 text-[15px] font-extrabold tracking-[0.06em]">{code}</span>
        <button type="button" onClick={onShare} className="pressable flex h-11 items-center rounded-tile bg-ink px-4 text-sm font-bold text-white">
          Partager
        </button>
      </div>
      {r && (r.friends > 0 || r.pending > 0 || r.credit > 0) && (
        <div className="grid grid-cols-2 gap-2 text-ink">
          <span className="flex flex-col rounded-tile bg-white/55 px-3 py-2">
            <span className="font-display text-lg leading-none font-extrabold">{r.friends}</span>
            <span className="text-[12px] font-semibold">
              ami{r.friends > 1 ? 's' : ''} parrainé{r.friends > 1 ? 's' : ''}{r.pending > 0 ? ` · ${r.pending} en route` : ''}
            </span>
          </span>
          <span className="flex flex-col rounded-tile bg-white/55 px-3 py-2">
            <span className="font-display text-lg leading-none font-extrabold">{fcfa(r.credit)} F</span>
            <span className="text-[12px] font-semibold">de crédit à utiliser</span>
          </span>
        </div>
      )}
      {r?.referredBy && <span className="text-[12px] font-semibold text-ink/75">Parrainé·e par {r.referredBy}{r.feeWaived ? ' · tes frais de service sont offerts' : ''}</span>}
      {r?.canApply &&
        (open ? (
          <form
            className="flex gap-2"
            onSubmit={async (e) => {
              e.preventDefault()
              setBusy(true)
              try {
                await actions.applyReferral(friend)
                toast({ tone: 'success', text: 'Code appliqué : tes frais de service sont offerts' })
                setOpen(false)
              } catch (err) {
                toast({ tone: 'error', text: errorMessage(err) })
              } finally {
                setBusy(false)
              }
            }}
          >
            <input
              autoFocus
              value={friend}
              onChange={(e) => setFriend(e.target.value.toUpperCase().slice(0, 16))}
              placeholder="Code de ton ami"
              aria-label="Code de parrainage d’un ami"
              className="h-11 min-w-0 flex-1 rounded-tile bg-white px-3.5 text-[15px] font-bold tracking-[0.06em] outline-none placeholder:font-medium placeholder:tracking-normal"
            />
            <button type="submit" disabled={busy || friend.length < 4} className="pressable h-11 rounded-tile bg-ink px-4 text-sm font-bold text-white disabled:opacity-50">
              OK
            </button>
          </form>
        ) : (
          <button type="button" onClick={() => setOpen(true)} className="self-start text-[13px] font-bold underline">
            Un ami t’a donné son code ?
          </button>
        ))}
    </div>
  )
}
