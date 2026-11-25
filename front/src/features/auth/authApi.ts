import { apiRequest, clearCsrfCookie, type RequestOptions } from '../../api/client'
import { applySession } from '../../api/session'
import type { CurrentUser, SecondFactorPayload, SessionPayload, UserLanguage } from '../../api/types'

type Fetcher = Pick<RequestOptions, 'fetchFn'>

export type Credentials = { email: string; motDePasse: string }

export type Registration = Credentials & {
  prenom: string
  nom: string
  langue: UserLanguage
  consentement: boolean
}

export type LoginResult = { kind: 'connected'; user: CurrentUser } | { kind: 'second-factor'; expiresIn: number }

/**
 * POST /api/connexion. Une session ouverte est installée en mémoire (jeton d'accès). Un compte protégé par l'A2F
 * reçoit seulement un jeton intermédiaire : il n'est PAS gardé (la saisie du code TOTP arrive avec F-04).
 */
export async function login(credentials: Credentials, options: Fetcher = {}): Promise<LoginResult> {
  const response = await apiRequest<SessionPayload | SecondFactorPayload>('connexion', {
    method: 'POST',
    body: credentials,
    ...options,
  })
  if (response.etape === 'a2f') {
    return { kind: 'second-factor', expiresIn: response.expireDans }
  }
  applySession(response)
  return { kind: 'connected', user: response.utilisateur }
}

/** POST /api/inscription (201), puis connexion avec les mêmes identifiants. */
export async function register(registration: Registration, options: Fetcher = {}): Promise<LoginResult> {
  await apiRequest('inscription', { method: 'POST', body: registration, ...options })
  return login({ email: registration.email, motDePasse: registration.motDePasse }, options)
}

/** POST /api/deconnexion : révoque le refresh et le jeton d'accès côté serveur, puis efface la session locale. */
export async function logout(options: Fetcher = {}): Promise<void> {
  try {
    await apiRequest('deconnexion', { method: 'POST', ...options })
  } catch {
    // Même si l'API ne répond pas (jeton déjà expiré, réseau), la session locale doit disparaître.
  } finally {
    clearCsrfCookie()
    applySession(null)
  }
}
