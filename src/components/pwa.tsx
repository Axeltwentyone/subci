import { useEffect } from 'react'
import { useRegisterSW } from 'virtual:pwa-register/react'
import { useInstall } from '../lib/hooks'
import { useStore } from '../lib/store'
import { IconBell, IconDownload, IconPhone, IconShare } from './icons'
import { Sheet } from './Sheet'
import { useToast } from './Toast'
import { Button, LogoMark } from './ui'

/** « Nouvelle version disponible » — toast Ink persistant avec action. */
export function UpdateToast() {
  const toast = useToast()
  const {
    needRefresh: [needRefresh],
    offlineReady: [offlineReady],
    updateServiceWorker,
  } = useRegisterSW()

  useEffect(() => {
    if (needRefresh)
      toast({ tone: 'update', text: 'Nouvelle version disponible', duration: 0, action: { label: 'Mettre à jour', onClick: () => updateServiceWorker(true) } })
  }, [needRefresh, toast, updateServiceWorker])

  useEffect(() => {
    if (offlineReady) toast({ tone: 'success', text: 'Tes accès sont disponibles hors ligne' })
  }, [offlineReady, toast])

  return null
}

const BENEFITS = [
  { Icon: IconPhone, text: 'Ouvre tes accès en un geste, depuis l’écran d’accueil' },
  { Icon: IconBell, text: 'Un rappel avant chaque échéance — jamais coupé' },
  { Icon: IconDownload, text: 'Tes identifiants disponibles même hors ligne' },
]

/**
 * 18 · Installation PWA. Déclenchée après le 1er achat, jamais au 1er lancement.
 * « Plus tard » = pas de relance avant 14 jours.
 */
export function InstallSheet({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { actions } = useStore()
  const install = useInstall()
  const toast = useToast()

  const later = () => {
    actions.installDismissed()
    onClose()
  }

  return (
    <Sheet open={open} onClose={later} label="Installer Sub.ci">
      <div className="flex flex-col gap-5">
        <div className="flex items-center gap-3.5">
          <LogoMark size={64} />
          <div className="flex flex-col gap-1">
            <h2 className="font-display text-2xl leading-[1.1] font-bold tracking-[-0.02em]">Installe Sub.ci</h2>
            <p className="text-sm font-semibold text-muted">Moins de 1 Mo · sans store</p>
          </div>
        </div>
        <ul className="flex flex-col gap-3.5">
          {BENEFITS.map(({ Icon, text }) => (
            <li key={text} className="flex items-center gap-3.5">
              <span className="grid size-10 shrink-0 place-items-center rounded-tile bg-brand-soft">
                <Icon size={20} />
              </span>
              <span className="text-[15px] leading-[1.4] font-semibold">{text}</span>
            </li>
          ))}
        </ul>
        {install.ios ? (
          <div className="flex items-center gap-3 rounded-btn bg-sand p-3.5 text-[15px] leading-[1.4] font-semibold">
            <span className="grid size-10 shrink-0 place-items-center rounded-tile bg-white">
              <IconShare size={18} />
            </span>
            <span>
              Touche <b>Partager</b> puis <b>Sur l’écran d’accueil</b>.
            </span>
          </div>
        ) : null}
        <div className="flex flex-col gap-1.5">
          {!install.ios && (
            <Button
              onClick={async () => {
                if (install.canPrompt) {
                  const ok = await install.prompt()
                  onClose()
                  if (ok) toast({ tone: 'success', text: 'Sub.ci est installée sur ton écran d’accueil' })
                } else {
                  toast({ tone: 'ink', text: 'Menu du navigateur → « Installer l’application »' })
                  onClose()
                }
              }}
            >
              Installer l’app
            </Button>
          )}
          <Button variant="text" size="link" onClick={later}>
            {install.ios ? 'OK, compris' : 'Plus tard'}
          </Button>
        </div>
      </div>
    </Sheet>
  )
}

export function shouldOfferInstall(purchases: number, dismissedAt: number, installed: boolean) {
  return purchases > 0 && !installed && Date.now() - dismissedAt > 14 * 24 * 3600e3
}

/**
 * 20 · Pré-prompt maison avant la demande navigateur
 * (qu'on ne peut demander qu'une fois). Aperçu réel de la notif.
 */
export function NotifSheet({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { actions } = useStore()
  const toast = useToast()

  const done = () => {
    actions.notifPromptDone()
    onClose()
  }

  return (
    <Sheet open={open} onClose={done} label="Activer les rappels">
      <div className="flex flex-col gap-[18px]">
        <div className="flex items-center gap-2.5 rounded-btn bg-sand p-3">
          <LogoMark size={36} />
          <div className="flex flex-col gap-0.5">
            <span className="text-[13px] font-bold">Sub.ci · maintenant</span>
            <span className="text-[13px] font-medium text-muted">Netflix expire dans 3 jours. Renouvelle en 1 tap.</span>
          </div>
        </div>
        <h2 className="font-display text-2xl leading-[1.15] font-bold tracking-[-0.02em]">On te prévient avant que ça coupe ?</h2>
        <p className="text-[15px] leading-normal font-medium text-muted">Échéances, activations, places libérées. Pas de spam.</p>
        <div className="flex flex-col gap-1.5">
          <Button
            onClick={async () => {
              let result: NotificationPermission = 'denied'
              try {
                result = await Notification.requestPermission()
              } catch {
                /* navigateur sans API */
              }
              done()
              if (result === 'granted') toast({ tone: 'success', text: 'Rappels activés. On te prévient à J-3.' })
              else toast({ tone: 'ink', text: 'Tu pourras les activer dans Paramètres.' })
            }}
          >
            Activer les rappels
          </Button>
          <Button variant="text" size="link" onClick={done}>
            Pas maintenant
          </Button>
        </div>
      </div>
    </Sheet>
  )
}

export function canAskNotifications() {
  return typeof Notification !== 'undefined' && Notification.permission === 'default'
}
