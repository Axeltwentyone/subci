import { useNavigate } from 'react-router'
import { IconWifiOff } from '../components/icons'
import { useToast } from '../components/Toast'
import { Button, ServiceLogo } from '../components/ui'
import { getService } from '../lib/data'
import { useActiveSubs } from '../lib/store'

/**
 * 17 · Hors ligne. Shell + accès en cache : l'écran plein n'est montré que
 * pour les contenus sans cache (catalogue) ; sinon seule la bannière Ink apparaît.
 */
export function OfflineScreen({ embedded }: { embedded?: boolean }) {
  const navigate = useNavigate()
  const toast = useToast()
  const subs = useActiveSubs().filter((s) => s.state === 'active')

  return (
    <div className="mx-auto flex min-h-[calc(100dvh-160px)] max-w-[560px] flex-col">
      <div className="flex flex-col gap-3.5 px-6 pt-12">
        <span className="grid size-16 place-items-center rounded-card bg-surface">
          <IconWifiOff size={30} />
        </span>
        <h1 className="t-title">
          Pas de réseau.
          <br />
          Tes accès, eux, sont là.
        </h1>
        <p className="text-base leading-normal font-medium text-muted">Les paiements reprendront dès le retour de la connexion.</p>
      </div>
      <div className="flex flex-col gap-2.5 px-5 pt-5">
        {subs.map((sub) => {
          const svc = getService(sub.serviceId)!
          return (
            <button
              key={sub.id}
              type="button"
              onClick={() => navigate(`/subs/${sub.id}`, { viewTransition: true })}
              className="pressable flex items-center gap-3 rounded-card bg-surface p-3.5 text-left"
            >
              <ServiceLogo service={svc} size={40} />
              <span className="flex-1 text-[15px] font-bold">{svc.name}</span>
              <span className="text-sm font-bold">Accès ›</span>
            </button>
          )
        })}
      </div>
      <div className={embedded ? 'mt-auto px-6 pt-8 pb-6' : 'mt-auto px-6 pt-8 pb-[calc(env(safe-area-inset-bottom)+40px)]'}>
        <Button
          variant="ink"
          onClick={() => {
            if (navigator.onLine) window.location.reload()
            else toast({ tone: 'warn', text: 'Toujours hors ligne. On réessaie automatiquement.' })
          }}
        >
          ↻ Réessayer
        </Button>
      </div>
    </div>
  )
}
