import { PEGI_VALUES, SORTS, type CatalogueFilters, type Sort } from './types'

/**
 * Les critères du catalogue vivent dans l'URL (US-02 : « reflétés dans l'URL ») : un lien partagé, un rechargement ou
 * le bouton « précédent » retrouvent la même liste. Les noms de paramètres sont ceux de l'API (q, plateforme, genre,
 * prix_min, prix_max, pegi, tri, page) ; les valeurs par défaut (tri par titre, page 1) ne figurent pas dans l'URL.
 *
 * L'URL est une entrée non fiable : toute valeur est assainie (liste blanche du tri, PEGI connus, entiers bornés,
 * prix décimaux) avant de servir. Une valeur invalide est ignorée, jamais transmise ni affichée telle quelle.
 */
export const DEFAULT_FILTERS: CatalogueFilters = {
  q: '',
  plateformes: [],
  genres: [],
  prixMin: '',
  prixMax: '',
  pegi: null,
  tri: 'titre',
  page: 1,
}

const MAX_SEARCH = 100
const MAX_CHECKED = 10
const MAX_PAGE = 10_000
const PRICE = /^\d{1,6}(\.\d{1,2})?$/
const PLATFORM = /^[A-Z0-9_]{1,12}$/
const GENRE = /^[a-z0-9]+(?:-[a-z0-9]+)*$/

function unique(values: string[], format: RegExp): string[] {
  return [...new Set(values.filter((value) => format.test(value)))].sort().slice(0, MAX_CHECKED)
}

/** Accepte « 12,5 » (saisie française) et le normalise en « 12.5 » ; une valeur invalide devient vide. */
export function normalizePrice(input: string): string {
  const value = input.trim().replace(',', '.')
  return PRICE.test(value) ? value : ''
}

export function parseFilters(params: URLSearchParams): CatalogueFilters {
  const tri = params.get('tri')
  const pegi = Number.parseInt(params.get('pegi') ?? '', 10)
  const page = Number.parseInt(params.get('page') ?? '', 10)
  return {
    q: (params.get('q') ?? '').trim().slice(0, MAX_SEARCH),
    plateformes: unique(params.getAll('plateforme'), PLATFORM),
    genres: unique(params.getAll('genre'), GENRE),
    prixMin: normalizePrice(params.get('prix_min') ?? ''),
    prixMax: normalizePrice(params.get('prix_max') ?? ''),
    pegi: (PEGI_VALUES as readonly number[]).includes(pegi) ? pegi : null,
    tri: (SORTS as readonly string[]).includes(tri ?? '') ? (tri as Sort) : DEFAULT_FILTERS.tri,
    page: Number.isInteger(page) && page >= 1 && page <= MAX_PAGE ? page : 1,
  }
}

/** Paramètres d'URL de la page : seulement ce qui diffère des valeurs par défaut. */
export function toSearchParams(filters: CatalogueFilters): URLSearchParams {
  const params = new URLSearchParams()
  if (filters.q.trim() !== '') params.set('q', filters.q.trim())
  filters.plateformes.forEach((code) => params.append('plateforme', code))
  filters.genres.forEach((slug) => params.append('genre', slug))
  if (filters.prixMin !== '') params.set('prix_min', filters.prixMin)
  if (filters.prixMax !== '') params.set('prix_max', filters.prixMax)
  if (filters.pegi !== null) params.set('pegi', String(filters.pegi))
  if (filters.tri !== DEFAULT_FILTERS.tri) params.set('tri', filters.tri)
  if (filters.page > 1) params.set('page', String(filters.page))
  return params
}

/** Chaîne de requête de l'API (listes en `plateforme[]`, comme l'attend Symfony). Les valeurs sont encodées. */
export function toApiQuery(filters: CatalogueFilters): string {
  const params = new URLSearchParams()
  if (filters.q.trim() !== '') params.set('q', filters.q.trim())
  filters.plateformes.forEach((code) => params.append('plateforme[]', code))
  filters.genres.forEach((slug) => params.append('genre[]', slug))
  if (filters.prixMin !== '') params.set('prix_min', filters.prixMin)
  if (filters.prixMax !== '') params.set('prix_max', filters.prixMax)
  if (filters.pegi !== null) params.set('pegi', String(filters.pegi))
  params.set('tri', filters.tri)
  params.set('page', String(filters.page))
  return params.toString()
}

/** Nombre de critères actifs (hors tri et page) : sert à proposer « Réinitialiser ». */
export function activeFilterCount(filters: CatalogueFilters): number {
  return (
    (filters.q.trim() !== '' ? 1 : 0) +
    filters.plateformes.length +
    filters.genres.length +
    (filters.prixMin !== '' ? 1 : 0) +
    (filters.prixMax !== '' ? 1 : 0) +
    (filters.pegi !== null ? 1 : 0)
  )
}
