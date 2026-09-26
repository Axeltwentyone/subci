import { getService } from './data'
import { fcfa } from './format'
import type { HostOffer } from './store'

/**
 * Lien vers une offre d'hôte, avec son code parrain : l'hôte gagne son crédit quand l'ami
 * (s'il est nouveau) paie une 2e fois.
 */
export function offerShareLink(offer: HostOffer, referralCode?: string): { url: string; text: string } {
  const svc = getService(offer.serviceId)
  const params = new URLSearchParams({ offer: offer.id })
  if (referralCode) params.set('ref', referralCode)
  const url = `${window.location.origin}/service/${offer.serviceId}?${params}`
  const free = offer.seats - offer.members.length
  const text =
    `Rejoins mon ${svc?.name ?? 'abonnement'} (${offer.planLabel}) sur Sub.ci : ${fcfa(offer.price)} FCFA/mois au lieu de payer tout seul. ` +
    `${free > 1 ? `${free} places libres` : 'Plus qu’une place'}, paiement Wave ou Orange Money.`
  return { url, text }
}

/** Partage natif (WhatsApp, SMS…) ; sinon copie du message. Renvoie « shared » | « copied » | null. */
export async function shareOffer(offer: HostOffer, referralCode?: string): Promise<'shared' | 'copied' | null> {
  const { url, text } = offerShareLink(offer, referralCode)
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
