/**
 * Affichage seulement : le prix arrive du serveur en chaîne décimale (« 14.99 »), il est mis en forme sans
 * recalcul (RG-11 : aucun calcul de prix côté front). Intl met la devise et la virgule selon la langue.
 */
export function formatPrice(decimal: string, language: string): string {
  const amount = Number(decimal)
  if (!Number.isFinite(amount)) return decimal
  return new Intl.NumberFormat(language, { style: 'currency', currency: 'EUR' }).format(amount)
}

export function formatDate(isoDate: string, language: string): string {
  const date = new Date(`${isoDate}T00:00:00Z`)
  if (Number.isNaN(date.getTime())) return isoDate
  return new Intl.DateTimeFormat(language, { dateStyle: 'long', timeZone: 'UTC' }).format(date)
}

export function formatRating(rating: number, language: string): string {
  return new Intl.NumberFormat(language, { minimumFractionDigits: 1, maximumFractionDigits: 1 }).format(rating)
}

/** URL d'une jaquette. Les fichiers sont servis hors du bundle (téléversés par le back-office, F-09). */
export function coverUrl(path: string, baseUrl: string = import.meta.env.VITE_MEDIA_URL ?? '/media'): string {
  return `${baseUrl.replace(/\/+$/, '')}/${path.replace(/^\/+/, '')}`
}
