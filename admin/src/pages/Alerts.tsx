import { useEffect, useState } from 'react'
import { useToast } from '../../../src/components/Toast'
import { api, errorText } from '../api'
import { Button, ErrorBox, Loading, PageHeader, Panel, cx, useAsync } from '../kit'
import { currentSubscription, disablePush, enablePush, needsInstall, pushSupported, type EnableResult } from '../push'

const HINTS: Record<string, string> = {
  payments: 'À chaque paiement confirmé par GeniusPay : montant, membre, service.',
  offers: 'Quand un hôte envoie une offre avec sa preuve d’abonnement.',
  payouts: 'Retrait demandé par un hôte, ou remboursement à envoyer.',
  disputes: 'Un membre signale qu’il n’a plus accès (gains de l’hôte gelés).',
  signups: 'Chaque nouveau compte créé. Peut être fréquent.',
}

const FAIL: Record<Exclude<EnableResult, 'ok'>, string> = {
  denied: 'Notifications refusées. Autorise-les dans les réglages du navigateur pour ce site.',
  'no-sw': 'Le service worker n’est pas actif. Utilise la version installée (npm run admin:build puis admin:preview, ou la prod).',
  'no-key': 'Clés VAPID manquantes côté serveur (php artisan push:vapid).',
  unsupported: 'Ce navigateur ne gère pas les notifications push.',
}

export function Alerts() {
  const toast = useToast()
  const { data, error, loading, reload, setData } = useAsync(() => api.push(), [])
  const [here, setHere] = useState<boolean | null>(null)
  const [busy, setBusy] = useState<string | null>(null)

  useEffect(() => {
    currentSubscription().then((s) => setHere(!!s)).catch(() => setHere(false))
  }, [])

  const run = async (id: string, fn: () => Promise<void>) => {
    setBusy(id)
    try {
      await fn()
    } catch (e) {
      toast({ tone: 'error', text: errorText(e) })
    } finally {
      setBusy(null)
    }
  }

  if (error) return <ErrorBox message={error} onRetry={reload} />
  if (loading && !data) return <Loading />
  if (!data) return null
  const install = needsInstall()

  return (
    <>
      <PageHeader title="Notifications" subtitle="Reçois une alerte sur ton téléphone quand quelque chose se passe sur Sub.ci." />

      <Panel title="Cet appareil">
        <div className="flex flex-col gap-4">
          {install ? (
            <InstallHint />
          ) : !pushSupported() ? (
            <p className="text-sm font-semibold text-muted">{FAIL.unsupported}</p>
          ) : (
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div className="flex items-center gap-3">
                <span className={cx('size-2.5 rounded-full', here ? 'bg-ok' : 'bg-line')} aria-hidden />
                <span className="flex flex-col">
                  <span className="text-[15px] font-bold">{here ? 'Notifications activées' : 'Notifications désactivées'}</span>
                  <span className="text-[13px] font-semibold text-muted">
                    {data.devices} appareil{data.devices > 1 ? 's' : ''} abonné{data.devices > 1 ? 's' : ''} sur ton compte
                  </span>
                </span>
              </div>
              <div className="flex gap-2">
                {here && (
                  <Button
                    variant="outline"
                    size="sm"
                    block={false}
                    loading={busy === 'test'}
                    onClick={() =>
                      run('test', async () => {
                        await api.testPush()
                        toast({ tone: 'success', text: 'Notification d’essai envoyée' })
                      })
                    }
                  >
                    Envoyer un essai
                  </Button>
                )}
                <Button
                  variant={here ? 'outline' : 'ink'}
                  size="sm"
                  block={false}
                  loading={busy === 'toggle'}
                  onClick={() =>
                    run('toggle', async () => {
                      if (here) {
                        await disablePush()
                        setHere(false)
                        setData(await api.push())
                        return
                      }
                      const res = await enablePush(data.publicKey)
                      if (res !== 'ok') return toast({ tone: 'error', text: FAIL[res], duration: 6000 })
                      setHere(true)
                      setData(await api.push())
                      toast({ tone: 'success', text: 'Cet appareil recevra tes alertes' })
                    })
                  }
                >
                  {here ? 'Désactiver' : 'Activer sur cet appareil'}
                </Button>
              </div>
            </div>
          )}
        </div>
      </Panel>

      <Panel title="M’alerter pour" pad={false}>
        <ul className="flex flex-col">
          {data.alerts.map((a) => (
            <li key={a.kind} className="flex items-center gap-4 border-t border-line-soft px-5 py-4 first:border-t-0">
              <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                <span className="text-[15px] font-bold">{a.label}</span>
                <span className="text-[13px] font-medium text-muted">{HINTS[a.kind]}</span>
              </span>
              <Switch
                on={a.on}
                label={a.label}
                onChange={(on) =>
                  run(a.kind, async () => {
                    setData({ ...data, alerts: data.alerts.map((x) => (x.kind === a.kind ? { ...x, on } : x)) })
                    setData(await api.setAlerts({ [a.kind]: on }))
                  })
                }
              />
            </li>
          ))}
        </ul>
      </Panel>
      <p className="text-[13px] font-medium text-muted">Ces choix valent pour tous tes appareils. Les alertes partent dès que la file d’attente Laravel tourne (queue:work).</p>
    </>
  )
}

function InstallHint() {
  return (
    <div className="flex flex-col gap-2 rounded-tile bg-sand p-4 text-sm font-semibold">
      <span className="font-bold">Sur iPhone, installe d’abord l’app</span>
      <ol className="flex list-decimal flex-col gap-1 pl-5 text-muted">
        <li>Touche le bouton Partager de Safari</li>
        <li>« Sur l’écran d’accueil », puis Ajouter</li>
        <li>Ouvre Sub.ci Admin depuis l’icône orange et reviens ici</li>
      </ol>
    </div>
  )
}

export function Switch({ on, onChange, label }: { on: boolean; onChange: (v: boolean) => void; label: string }) {
  return (
    <button type="button" role="switch" aria-checked={on} aria-label={label} onClick={() => onChange(!on)} className={cx('relative h-7 w-12 shrink-0 rounded-full transition-colors', on ? 'bg-ok' : 'bg-line')}>
      <span className={cx('absolute top-0.5 size-6 rounded-full bg-white shadow transition-[left]', on ? 'left-[22px]' : 'left-0.5')} />
    </button>
  )
}

