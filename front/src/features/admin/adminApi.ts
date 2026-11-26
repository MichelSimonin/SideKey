import { apiRequest } from '../../api/client'
import type {
  AdminGame,
  AdminGamePage,
  AdminOffer,
  GameInput,
  GameStatus,
  GenreOption,
  OfferInput,
  Platform,
  Publisher,
} from './types'

type Options = { signal?: AbortSignal; fetchFn?: typeof fetch }

/** Les identifiants viennent de l'URL ou de la liste : encodés avant d'entrer dans un chemin. */
const id = (value: string) => encodeURIComponent(value)

// --- Éditeurs ---------------------------------------------------------------------------------------

export async function fetchPublishers(options: Options = {}): Promise<Publisher[]> {
  return (await apiRequest<{ editeurs: Publisher[] }>('admin/editeurs', options)).editeurs
}

export function createPublisher(nom: string, options: Options = {}): Promise<Publisher> {
  return apiRequest<Publisher>('admin/editeurs', { method: 'POST', body: { nom }, ...options })
}

export function updatePublisher(publisherId: string, nom: string, options: Options = {}): Promise<Publisher> {
  return apiRequest<Publisher>(`admin/editeurs/${id(publisherId)}`, { method: 'PUT', body: { nom }, ...options })
}

export function deletePublisher(publisherId: string, options: Options = {}): Promise<void> {
  return apiRequest<void>(`admin/editeurs/${id(publisherId)}`, { method: 'DELETE', ...options })
}

// --- Plateformes ------------------------------------------------------------------------------------

export async function fetchPlatforms(options: Options = {}): Promise<Platform[]> {
  return (await apiRequest<{ plateformes: Platform[] }>('admin/plateformes', options)).plateformes
}

export function createPlatform(code: string, libelle: string, options: Options = {}): Promise<Platform> {
  return apiRequest<Platform>('admin/plateformes', { method: 'POST', body: { code, libelle }, ...options })
}

/** Seul le libellé se modifie : le code d'une plateforme est immuable. */
export function updatePlatform(platformId: string, libelle: string, options: Options = {}): Promise<Platform> {
  return apiRequest<Platform>(`admin/plateformes/${id(platformId)}`, { method: 'PUT', body: { libelle }, ...options })
}

export function deletePlatform(platformId: string, options: Options = {}): Promise<void> {
  return apiRequest<void>(`admin/plateformes/${id(platformId)}`, { method: 'DELETE', ...options })
}

// --- Jeux -------------------------------------------------------------------------------------------

export async function fetchGenres(options: Options = {}): Promise<GenreOption[]> {
  return (await apiRequest<{ genres: GenreOption[] }>('admin/genres', options)).genres
}

export function fetchAdminGames(
  criteria: { q: string; statut: GameStatus; page: number },
  options: Options = {},
): Promise<AdminGamePage> {
  const params = new URLSearchParams()
  if (criteria.q.trim() !== '') params.set('q', criteria.q.trim())
  if (criteria.statut !== 'tous') params.set('statut', criteria.statut)
  if (criteria.page > 1) params.set('page', String(criteria.page))
  const query = params.toString()
  return apiRequest<AdminGamePage>(`admin/jeux${query === '' ? '' : `?${query}`}`, options)
}

export function fetchAdminGame(gameId: string, options: Options = {}): Promise<AdminGame> {
  return apiRequest<AdminGame>(`admin/jeux/${id(gameId)}`, options)
}

export function createGame(input: GameInput, options: Options = {}): Promise<AdminGame> {
  return apiRequest<AdminGame>('admin/jeux', { method: 'POST', body: input, ...options })
}

export function updateGame(gameId: string, input: GameInput, options: Options = {}): Promise<AdminGame> {
  return apiRequest<AdminGame>(`admin/jeux/${id(gameId)}`, { method: 'PUT', body: input, ...options })
}

/** Suppression logique : le jeu est désactivé, ses offres et son historique restent. */
export function deactivateGame(gameId: string, options: Options = {}): Promise<void> {
  return apiRequest<void>(`admin/jeux/${id(gameId)}`, { method: 'DELETE', ...options })
}

// --- Jaquette ---------------------------------------------------------------------------------------

/** Téléverse l'image dans le champ « jaquette » d'un formulaire multipart. */
export function uploadCover(gameId: string, file: File, options: Options = {}): Promise<AdminGame> {
  const form = new FormData()
  form.append('jaquette', file)
  return apiRequest<AdminGame>(`admin/jeux/${id(gameId)}/jaquette`, { method: 'POST', body: form, ...options })
}

export function removeCover(gameId: string, options: Options = {}): Promise<void> {
  return apiRequest<void>(`admin/jeux/${id(gameId)}/jaquette`, { method: 'DELETE', ...options })
}

// --- Offres -----------------------------------------------------------------------------------------

export function createOffer(
  gameId: string,
  platformId: string,
  input: Pick<OfferInput, 'prix' | 'seuilAlerte'>,
  options: Options = {},
): Promise<AdminOffer> {
  return apiRequest<AdminOffer>(`admin/jeux/${id(gameId)}/offres`, {
    method: 'POST',
    body: { plateformeId: platformId, ...input },
    ...options,
  })
}

/** La version lue avec l'offre est rendue : si un collègue est passé avant, l'API répond 409. */
export function updateOffer(offerId: string, input: OfferInput, version: number, options: Options = {}): Promise<AdminOffer> {
  return apiRequest<AdminOffer>(`admin/offres/${id(offerId)}`, { method: 'PUT', body: { ...input, version }, ...options })
}
