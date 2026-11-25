import { vi } from 'vitest'
import type { CurrentUser, SessionPayload } from '../api/types'

export const USER: CurrentUser = {
  id: '11111111-2222-4333-8444-555555555555',
  email: 'lea@example.test',
  prenom: 'Léa',
  nom: 'Roy',
  langue: 'fr',
  roles: ['CLIENT'],
  permissions: ['commander', 'deposer_avis'],
  a2fActive: false,
  emailVerifie: false,
}

export function sessionPayload(token = 'jwt-acces-1', user: CurrentUser = USER): SessionPayload {
  return { etape: 'connecte', jetonAcces: token, typeJeton: 'Bearer', expireDans: 900, utilisateur: user }
}

export function jsonResponse(body: unknown, status = 200, headers: Record<string, string> = {}): Response {
  return new Response(body === undefined ? null : JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json', ...headers },
  })
}

export type Call = { url: string; init: RequestInit }

/**
 * Faux fetch pilotable : chaque route (« METHODE chemin ») a une file de réponses consommées dans l'ordre.
 * Une requête sans réponse prévue échoue le test (pas de repli silencieux).
 */
export function mockApi(routes: Record<string, Array<Response | (() => Response)>>) {
  const queues = new Map(Object.entries(routes).map(([key, responses]) => [key, [...responses]]))
  const calls: Call[] = []
  const fetchFn = vi.fn(async (input: RequestInfo | URL, init: RequestInit = {}) => {
    const url = String(input)
    const key = `${init.method ?? 'GET'} ${url.replace(/^\/api\//, '')}`
    calls.push({ url, init })
    const next = queues.get(key)?.shift()
    if (next === undefined) {
      throw new Error(`Requête inattendue : ${key}`)
    }
    return typeof next === 'function' ? next() : next
  })
  return { fetchFn: fetchFn as unknown as typeof fetch, calls, mock: fetchFn }
}

export function header(call: Call, name: string): string | undefined {
  return (call.init.headers as Record<string, string> | undefined)?.[name]
}

/** Pose le cookie de double soumission comme le fait l'API après une connexion. */
export function setCsrfCookie(value = 'csrf-token-de-test'): void {
  document.cookie = `sk_csrf=${value}; Path=/; Secure; SameSite=Strict`
}
