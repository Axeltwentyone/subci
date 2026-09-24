const nf = new Intl.NumberFormat('fr-FR')

/** 7125 → "7 125" (espace simple, comme dans la maquette). */
export function fcfa(n: number): string {
  return nf.format(Math.round(n)).replace(/[  ]/g, ' ')
}

const dayMonth = new Intl.DateTimeFormat('fr-FR', { day: 'numeric', month: 'short' })
const weekday = new Intl.DateTimeFormat('fr-FR', { weekday: 'long' })
const longDate = new Intl.DateTimeFormat('fr-FR', { weekday: 'long', day: 'numeric', month: 'short' })
const hm = new Intl.DateTimeFormat('fr-FR', { hour: '2-digit', minute: '2-digit' })

export const DAY = 24 * 60 * 60 * 1000

/** "27 sept." */
export function shortDate(ts: number): string {
  return dayMonth.format(ts)
}

/** "samedi" */
export function dayName(ts: number): string {
  return weekday.format(ts)
}

/** "Mercredi 24 sept." */
export function todayLabel(ts = Date.now()): string {
  const s = longDate.format(ts)
  return s.charAt(0).toUpperCase() + s.slice(1)
}

export function clock(ts: number): string {
  return hm.format(ts)
}

export function daysLeft(ts: number, now = Date.now()): number {
  return Math.ceil((ts - now) / DAY)
}

export function greeting(now = new Date()): string {
  const h = now.getHours()
  return h >= 18 || h < 4 ? 'Bonsoir' : 'Bonjour'
}

/** "07 58 42 11 21" → "07 58 •• •• 21" */
export function maskPhone(phone: string): string {
  const d = phone.replace(/\D/g, '')
  if (d.length < 10) return phone
  return `${d.slice(0, 2)} ${d.slice(2, 4)} •• •• ${d.slice(8, 10)}`
}

/** Regroupe les chiffres par paires : "0758421121" → "07 58 42 11 21" */
export function formatPhone(raw: string): string {
  return raw.replace(/\D/g, '').slice(0, 10).replace(/(\d{2})(?=\d)/g, '$1 ')
}

export function addMonths(ts: number, months: number): number {
  const d = new Date(ts)
  d.setMonth(d.getMonth() + months)
  return d.getTime()
}

export function haptic(ms = 20) {
  try {
    navigator.vibrate?.(ms)
  } catch {
    /* non supporté */
  }
}
