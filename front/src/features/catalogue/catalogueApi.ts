import { apiRequest } from '../../api/client'
import { toApiQuery } from './filtersUrl'
import type { CatalogueFilters, FilterValues, GameDetail, GamePage } from './types'

type Options = { signal?: AbortSignal; fetchFn?: typeof fetch }

/** GET /api/jeux : la liste filtrée, triée et paginée. */
export function fetchGames(filters: CatalogueFilters, options: Options = {}): Promise<GamePage> {
  return apiRequest<GamePage>(`jeux?${toApiQuery(filters)}`, options)
}

/** GET /api/jeux/{slug} : la fiche. Le slug est encodé : il vient de l'URL. */
export function fetchGame(slug: string, options: Options = {}): Promise<GameDetail> {
  return apiRequest<GameDetail>(`jeux/${encodeURIComponent(slug)}`, options)
}

/** GET /api/catalogue/filtres : plateformes, genres, fourchette de prix proposés par les filtres. */
export function fetchFilterValues(options: Options = {}): Promise<FilterValues> {
  return apiRequest<FilterValues>('catalogue/filtres', options)
}

/** POST /api/panier/lignes (F-05) : ajoute une clé de l'offre au panier. Le prix et le stock sont revérifiés par l'API. */
export function addToCart(offerId: string, options: Options = {}): Promise<unknown> {
  return apiRequest('panier/lignes', { method: 'POST', body: { offreId: offerId, quantite: 1 }, ...options })
}
