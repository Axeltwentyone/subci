export type Category = 'streaming' | 'music' | 'ia' | 'sport'

export type Service = {
  id: string
  mono: string
  name: string
  /** Couleur de marque (logo + en-tête produit uniquement) */
  color: string
  fg: string
  category: Category
  meta: string
  description: string
  /** Prix d'une place / mois */
  price: number
  /** Prix de l'abonnement complet / mois */
  fullPrice: number
  seats: number
  /** Places libres, toutes offres en ligne confondues */
  free: number
  /** Places libres du groupe qui sera attribué (le moins cher) */
  groupFree: number
  /** Minutes avant activation */
  activation: number
  popular: boolean
}

export const CATEGORIES: { id: Category | 'all'; label: string; chip: string }[] = [
  { id: 'all', label: 'Tout', chip: 'Tout' },
  { id: 'streaming', label: 'Streaming', chip: 'Streaming' },
  { id: 'music', label: 'Musique', chip: 'Musique' },
  { id: 'ia', label: 'IA', chip: 'IA & outils' },
  { id: 'sport', label: 'Sport', chip: 'Sport' },
]

/**
 * Catalogue de repli (1er lancement hors ligne). Remplacé par celui de l'API
 * à chaque synchro — `SERVICES` est un binding vivant pour tous les imports.
 */
const FALLBACK: Service[] = [
  {
    id: 'netflix', mono: 'N', name: 'Netflix Premium', color: '#E50914', fg: '#fff', category: 'streaming',
    meta: '4K · 4 écrans', description: 'Ton propre profil, 4K Ultra HD, sans pub. Sur tous tes écrans.',
    price: 2500, fullPrice: 8000, seats: 4, free: 1, groupFree: 1, activation: 15, popular: false,
  },
  {
    id: 'spotify', mono: 'S', name: 'Spotify Famille', color: '#1DB954', fg: '#0B0B0B', category: 'music',
    meta: 'Sans pub · hors ligne', description: 'Ton compte Spotify Premium perso dans un groupe famille. Sans pub, écoute hors ligne.',
    price: 1500, fullPrice: 5500, seats: 6, free: 2, groupFree: 2, activation: 30, popular: true,
  },
  {
    id: 'spotify-duo', mono: 'S', name: 'Spotify Duo', color: '#1DB954', fg: '#0B0B0B', category: 'music',
    meta: '2 comptes Premium', description: 'Deux comptes Premium sous un même toit. Mix Duo inclus.',
    price: 2200, fullPrice: 4400, seats: 2, free: 0, groupFree: 0, activation: 30, popular: false,
  },
  {
    id: 'youtube', mono: 'Y', name: 'YouTube Premium', color: '#FF0033', fg: '#fff', category: 'streaming',
    meta: 'Sans pub · Music inclus', description: 'YouTube sans pub, lecture en arrière-plan et YouTube Music inclus.',
    price: 1800, fullPrice: 5200, seats: 6, free: 0, groupFree: 0, activation: 30, popular: false,
  },
  {
    id: 'canal', mono: 'C+', name: 'Canal+ Évasion', color: '#16130F', fg: '#fff', category: 'streaming',
    meta: 'Chaînes + replay', description: 'Les chaînes Canal+ Évasion en direct et en replay sur myCANAL.',
    price: 3000, fullPrice: 7500, seats: 3, free: 1, groupFree: 1, activation: 20, popular: false,
  },
  {
    id: 'canal-sport', mono: 'C+', name: 'Canal+ Sport', color: '#16130F', fg: '#fff', category: 'sport',
    meta: 'Foot · Ligue 1 · CAN', description: 'Tout le sport Canal+ : championnats européens, CAN et Ligue 1.',
    price: 4000, fullPrice: 12000, seats: 3, free: 1, groupFree: 1, activation: 20, popular: false,
  },
  {
    id: 'prime', mono: 'P', name: 'Prime Video', color: '#1A98FF', fg: '#0B0B0B', category: 'streaming',
    meta: 'Films · séries', description: 'Films, séries et originaux Amazon. Ton profil perso.',
    price: 1200, fullPrice: 2700, seats: 5, free: 3, groupFree: 3, activation: 15, popular: true,
  },
  {
    id: 'chatgpt', mono: 'AI', name: 'ChatGPT Plus', color: '#10A37F', fg: '#fff', category: 'ia',
    meta: 'Modèles avancés · images', description: 'Accès prioritaire aux derniers modèles, génération d’images et analyse de fichiers.',
    price: 5000, fullPrice: 13000, seats: 2, free: 1, groupFree: 1, activation: 10, popular: true,
  },
  {
    id: 'disney', mono: 'D+', name: 'Disney+', color: '#0E2A6B', fg: '#fff', category: 'streaming',
    meta: 'Marvel · Pixar · Star Wars', description: 'Disney, Pixar, Marvel, Star Wars et National Geographic en 4K.',
    price: 2000, fullPrice: 6000, seats: 4, free: 3, groupFree: 3, activation: 15, popular: true,
  },
]

const ORDER = ['netflix', 'spotify', 'youtube', 'canal', 'prime', 'chatgpt', 'disney', 'spotify-duo', 'canal-sport']

export let SERVICES: Service[] = [...FALLBACK].sort((a, b) => ORDER.indexOf(a.id) - ORDER.indexOf(b.id))

export function setCatalog(list: Service[]) {
  if (list.length) SERVICES = list
}

export function popularServices(): Service[] {
  return SERVICES.filter((s) => s.popular)
}

export function getService(id: string): Service | undefined {
  return SERVICES.find((s) => s.id === id)
}

export function savingPct(s: Service): number {
  return Math.round((1 - s.price / s.fullPrice) * 100)
}

export function availLabel(s: Service, long = true): string {
  if (s.free === 0) return 'Liste d’attente'
  if (!long) return `${s.free} place${s.free > 1 ? 's' : ''}`
  // Le groupe que Sub.ci attribuera : « 1 place sur 3 ».
  return `${s.groupFree} place${s.groupFree > 1 ? 's' : ''} sur ${s.seats}`
}

/** Durées proposées au checkout */
export const DURATIONS = [
  { months: 1, discount: 0 },
  { months: 3, discount: 0.05 },
  { months: 6, discount: 0.1 },
]

export function durationPrice(price: number, months: number): number {
  const d = DURATIONS.find((x) => x.months === months)?.discount ?? 0
  return Math.round(price * months * (1 - d))
}

export type PayMethodId = 'om' | 'wave' | 'mtn' | 'moov' | 'card'

export const PAY_METHODS: { id: PayMethodId; name: string; short: string; mono: string; color: string; fg: string; ussd?: string }[] = [
  { id: 'om', name: 'Orange Money', short: 'Orange Money', mono: 'OM', color: '#FF7900', fg: '#fff', ussd: '#144#' },
  { id: 'wave', name: 'Wave', short: 'Wave', mono: 'W', color: '#1DC8F2', fg: '#0B0B0B' },
  { id: 'mtn', name: 'MTN MoMo', short: 'MTN MoMo', mono: 'MTN', color: '#FFCC00', fg: '#0B0B0B', ussd: '*133#' },
  { id: 'moov', name: 'Moov Money', short: 'Moov Money', mono: 'Moov', color: '#0055A5', fg: '#fff', ussd: '*155#' },
  { id: 'card', name: 'Carte bancaire', short: 'Carte', mono: 'CB', color: '#16130F', fg: '#fff' },
]

export function getMethod(id: PayMethodId) {
  return PAY_METHODS.find((m) => m.id === id) ?? PAY_METHODS[0]
}

export const HOST_FEE = 0.1

/** Formules partageables côté hôte */
export const HOST_PLANS: Record<string, { label: string; maxShare: number; own: number; reco: [number, number]; mode: 'credentials' | 'family' }> = {
  netflix: { label: 'Premium · 4 écrans', maxShare: 3, own: 8000, reco: [2000, 2700], mode: 'credentials' },
  spotify: { label: 'Famille · 6 comptes', maxShare: 5, own: 5500, reco: [1200, 1600], mode: 'family' },
  youtube: { label: 'Famille · 6 comptes', maxShare: 5, own: 5200, reco: [1400, 1900], mode: 'family' },
  other: { label: 'À préciser', maxShare: 4, own: 6000, reco: [1500, 2500], mode: 'credentials' },
}

export const AVATAR_COLORS = ['#FFB38F', '#9FD7BE', '#C9B8F2', '#F7D774', '#9CC7F2']
