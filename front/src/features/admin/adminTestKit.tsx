import { render } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { vi } from 'vitest'
import App from '../../App'
import type { CurrentUser } from '../../api/types'
import { jsonResponse, sessionPayload, setCsrfCookie, USER } from '../../test/helpers'
import { LocationProbe } from '../../test/LocationProbe'
import type { AdminGame, AdminOffer, GenreOption, Platform, Publisher } from './types'

/** Outillage des tests du back-office (F-09) : faux serveur HTTP, session de gestionnaire, jeux de données. */

export const MANAGER: CurrentUser = {
  ...USER,
  roles: ['GESTIONNAIRE'],
  permissions: ['gerer_catalogue', 'consulter_commandes'],
  a2fActive: true,
}

export const PUBLISHERS: Publisher[] = [
  { id: 'pub-aube', nom: 'Studio Aube', nombreJeux: 2 },
  { id: 'pub-lune', nom: 'Studio Lune', nombreJeux: 0 },
]

export const PLATFORMS: Platform[] = [
  { id: 'pl-steam', code: 'STEAM', libelle: 'Steam', nombreOffres: 3 },
  { id: 'pl-psn', code: 'PSN', libelle: 'PlayStation Store', nombreOffres: 0 },
]

export const GENRES: GenreOption[] = [
  { slug: 'action', libelle: 'Action' },
  { slug: 'rpg', libelle: 'RPG' },
]

export const OFFER: AdminOffer = {
  id: 'offre-1',
  plateforme: { id: 'pl-steam', code: 'STEAM', libelle: 'Steam' },
  prix: '14.99',
  seuilAlerte: 5,
  actif: true,
  version: 2,
  stock: 7,
}

export const GAME: AdminGame = {
  id: 'jeu-1',
  slug: 'aube-ecarlate',
  titre: 'Aube Écarlate',
  dateSortie: '2025-03-14',
  pegi: 16,
  actif: true,
  jaquette: null,
  editeur: { id: 'pub-aube', nom: 'Studio Aube' },
  descriptions: { fr: 'Une description française assez longue.', en: 'A long enough English description.' },
  genres: ['action'],
  offres: [OFFER],
}

export type Request = { method: string; path: string; body: unknown; init: RequestInit }
type Handler = (request: Request) => Response | undefined

export const noContent = () => new Response(null, { status: 204 })

/**
 * Installe un faux fetch. La session est celle d'un gestionnaire (rétablie par le rafraîchissement silencieux) ; toute autre
 * requête passe par `handler`, qui renvoie `undefined` pour une requête non prévue : le test échoue alors (pas de repli).
 */
export function stubServer(handler: Handler, user: CurrentUser | null = MANAGER) {
  if (user !== null) setCsrfCookie('csrf-test')
  const calls: Request[] = []
  const fetchFn = vi.fn(async (input: RequestInfo | URL, init: RequestInit = {}) => {
    const path = String(input).replace(/^\/api\//, '')
    const method = init.method ?? 'GET'
    if (path === 'token/refresh') return user === null ? jsonResponse({}, 401) : jsonResponse(sessionPayload('jwt-gestionnaire', user))
    const body = typeof init.body === 'string' ? (JSON.parse(init.body) as unknown) : init.body
    const request = { method, path, body, init }
    calls.push(request)
    const response = handler(request)
    if (response === undefined) throw new Error(`Requête inattendue : ${method} ${path}`)
    return response
  })
  vi.stubGlobal('fetch', fetchFn)
  return { calls, fetchFn }
}

export function renderAt(path: string) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <App />
      <LocationProbe />
    </MemoryRouter>,
  )
}

export const json = <T,>(request: Request) => request.body as T
