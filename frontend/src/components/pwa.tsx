import { useEffect, type ReactNode } from 'react'
import { useRegisterSW } from 'virtual:pwa-register/react'
import { useInstall } from '../lib/hooks'
import { enablePush } from '../lib/push'
import { useStore } from '../lib/store'
import { IconBell, IconClose, IconDownload, IconPhone, IconShare } from './icons'
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

/** Téléphone ou tablette (pas un ordinateur) : là où l'app installée a du sens. */
export function isMobileDevice() {
  return window.matchMedia('(pointer: coarse)').matches && Math.min(window.screen.width, window.screen.height) < 820
}

/** Navigateur intégré (Instagram, Facebook, TikTok…) : impossible d'installer depuis là. */
function inAppBrowser() {
  return /FBAN|FBAV|Instagram|Snapchat|TikTok|Line\//i.test(navigator.userAgent)
}

/**
 * Premier lancement sur mobile, dans le navigateur : écran plein « Installe Sub.ci ».
 * Une fois installée, l'app s'ouvre en plein écran, sans barre ni geste de retour du navigateur.
 */
export function InstallGate() {
  const { state, actions } = useStore()
  const install = useInstall()
  const toast = useToast()
  if (install.installed || state.installDismissedAt > 0 || !isMobileDevice()) return null

  const inApp = inAppBrowser()
  const later = () => actions.installDismissed()

  return (
    <div className="fixed inset-0 z-[60] overflow-y-auto bg-ink text-sand" role="dialog" aria-modal="true" aria-label="Installer Sub.ci">
      <div className="pt-safe mx-auto flex min-h-dvh w-full max-w-[440px] flex-col px-6 pb-[calc(env(safe-area-inset-bottom)+28px)]">
        <div className="flex flex-1 flex-col justify-center gap-7 py-10">
          <div className="flex flex-col items-center gap-4 text-center">
            <LogoMark size={88} tone="brand" />
            <h1 className="font-display text-[34px] leading-[1.05] font-bold tracking-[-0.03em] text-balance">Installe Sub.ci sur ton téléphone</h1>
            <p className="text-[15px] font-semibold text-ink-muted">Gratuit, moins de 1 Mo, sans passer par un store.</p>
          </div>
          <ul className="flex flex-col gap-3.5">
            {BENEFITS.map(({ Icon, text }) => (
              <li key={text} className="flex items-center gap-3.5">
                <span className="grid size-10 shrink-0 place-items-center rounded-tile bg-ink-3 text-brand">
                  <Icon size={20} />
                </span>
                <span className="text-[15px] leading-[1.4] font-semibold">{text}</span>
              </li>
            ))}
          </ul>

          {inApp ? (
            <Steps
              steps={['Touche ⋯ en haut à droite', 'Choisis « Ouvrir dans le navigateur » (Safari ou Chrome)', 'Installe Sub.ci depuis là']}
            />
          ) : install.ios ? (
            <Steps
              steps={[
                <>Touche <IconShare size={16} className="inline -mt-1" /> <b>Partager</b> en bas de l’écran</>,
                <>Choisis <b>Sur l’écran d’accueil</b> (fais défiler si besoin)</>,
                <>Touche <b>Ajouter</b>, puis ouvre Sub.ci depuis l’icône</>,
              ]}
            />
          ) : !install.canPrompt ? (
            <Steps steps={[<>Ouvre le menu <b>⋮</b> du navigateur</>, <>Choisis <b>Installer l’application</b> (ou « Ajouter à l’écran d’accueil »)</>, 'Ouvre Sub.ci depuis l’icône']} />
          ) : null}
        </div>

        <div className="flex flex-col gap-2">
          {!install.ios && install.canPrompt && !inApp && (
            <Button
              onClick={async () => {
                if (await install.prompt()) toast({ tone: 'success', text: 'Sub.ci est installée : ouvre-la depuis ton écran d’accueil' })
              }}
            >
              Installer l’app
            </Button>
          )}
          <Button variant="ghost-dark" size="link" onClick={later}>
            Continuer dans le navigateur
          </Button>
        </div>
      </div>
    </div>
  )
}

function Steps({ steps }: { steps: ReactNode[] }) {
  return (
    <ol className="flex flex-col gap-3 rounded-[20px] bg-ink-2 p-4">
      {steps.map((s, i) => (
        <li key={i} className="flex items-start gap-3 text-[15px] leading-[1.4] font-semibold">
          <span className="grid size-6 shrink-0 place-items-center rounded-full bg-brand text-[12px] font-extrabold text-ink">{i + 1}</span>
          <span>{s}</span>
        </li>
      ))}
    </ol>
  )
}

/** Rappel discret sur l'accueil tant que l'app n'est pas installée (3 jours après « plus tard »). */
export function InstallBanner({ onOpen }: { onOpen: () => void }) {
  const { state, actions } = useStore()
  const install = useInstall()
  if (install.installed || !isMobileDevice() || Date.now() - state.installDismissedAt < 3 * 24 * 3600e3) return null
  return (
    <div className="flex items-center gap-3 rounded-card bg-ink p-3.5 pr-2 text-sand">
      <LogoMark size={40} tone="brand" />
      <button type="button" onClick={onOpen} className="flex min-w-0 flex-1 flex-col text-left">
        <span className="text-[15px] font-bold">Installe l’app Sub.ci</span>
        <span className="text-[13px] font-semibold text-ink-muted">Plus rapide, et tes accès même hors ligne</span>
      </button>
      <button type="button" onClick={onOpen} className="pressable h-10 rounded-[12px] bg-brand px-4 text-sm font-bold text-ink">
        Installer
      </button>
      <button type="button" aria-label="Plus tard" onClick={() => actions.installDismissed()} className="grid size-9 place-items-center rounded-full text-ink-muted">
        <IconClose size={16} />
      </button>
    </div>
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
                result = await enablePush()
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
