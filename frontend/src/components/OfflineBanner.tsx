import { useEffect, useRef } from 'react'
import { useOnline } from '../lib/hooks'
import { useStore } from '../lib/store'
import { clock } from '../lib/format'
import { useToast } from './Toast'

/** En perte de réseau en cours de navigation, seule la bannière Ink apparaît. */
export function OfflineBanner() {
  const online = useOnline()
  const toast = useToast()
  const { state } = useStore()
  const was = useRef(online)

  useEffect(() => {
    if (was.current && !online) toast({ tone: 'warn', text: 'Connexion instable. On réessaie…' })
    if (!was.current && online) toast({ tone: 'success', text: 'De retour en ligne. Tout est synchronisé.' })
    was.current = online
  }, [online, toast])

  if (online) return null
  return (
    <div className="sticky top-0 z-30 px-4 pt-[calc(env(safe-area-inset-top)+8px)] pb-2">
      <div role="status" className="mx-auto flex h-11 max-w-[720px] items-center gap-2.5 rounded-tile bg-ink px-3.5 text-sm font-bold text-sand">
        <span className="size-2 rounded-full bg-warn" />
        Hors ligne{state.lastSync ? ` · synchro à ${clock(state.lastSync)}` : ''}
      </div>
    </div>
  )
}
