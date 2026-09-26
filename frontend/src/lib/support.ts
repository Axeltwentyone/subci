/**
 * Contact du support : numéro WhatsApp défini au déploiement (VITE_SUPPORT_WHATSAPP, ex. 2250701020304).
 * Sans numéro, les liens « WhatsApp » sont masqués plutôt que de pointer vers un faux numéro.
 */
const number = ((import.meta.env.VITE_SUPPORT_WHATSAPP as string | undefined) ?? '').replace(/\D/g, '')

export function supportWhatsApp(text?: string): string | null {
  if (number.length < 8) return null
  return `https://wa.me/${number}${text ? `?text=${encodeURIComponent(text)}` : ''}`
}
