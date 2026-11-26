import { apiRequest, clearCsrfCookie, type RequestOptions } from '../../api/client'
import { applySession } from '../../api/session'
import type {
  ConfirmationPayload,
  CurrentUser,
  EnrolmentPayload,
  SecondFactorPayload,
  SessionPayload,
  UserLanguage,
} from '../../api/types'

type Fetcher = Pick<RequestOptions, 'fetchFn'>

export type Credentials = { email: string; motDePasse: string }

export type Registration = Credentials & {
  prenom: string
  nom: string
  langue: UserLanguage
  consentement: boolean
}

/**
 * Issue d'une connexion par mot de passe : session ouverte, ou seconde étape (A2F, CDC §11.4).
 * Dans le second cas, `token` est le jeton intermédiaire (5 min, valable sur les seules routes /api/connexion/a2f) :
 * il n'est ni stocké ni partagé, seul le composant de la seconde étape le détient, en mémoire.
 */
export type LoginResult =
  | { kind: 'connected'; user: CurrentUser }
  | { kind: 'second-factor'; mode: 'code' | 'enrolment'; token: string; expiresIn: number }

/** POST /api/connexion. Une session ouverte est installée en mémoire (jeton d'accès). */
export async function login(credentials: Credentials, options: Fetcher = {}): Promise<LoginResult> {
  const response = await apiRequest<SessionPayload | SecondFactorPayload>('connexion', {
    method: 'POST',
    body: credentials,
    ...options,
  })
  if (response.etape === 'connecte') {
    applySession(response)
    return { kind: 'connected', user: response.utilisateur }
  }
  return {
    kind: 'second-factor',
    mode: response.etape === 'a2f_enrolement' ? 'enrolment' : 'code',
    token: response.jetonIntermediaire,
    expiresIn: response.expireDans,
  }
}

/** POST /api/inscription (201), puis connexion avec les mêmes identifiants. */
export async function register(registration: Registration, options: Fetcher = {}): Promise<LoginResult> {
  await apiRequest('inscription', { method: 'POST', body: registration, ...options })
  return login({ email: registration.email, motDePasse: registration.motDePasse }, options)
}

/** POST /api/connexion/a2f : échange le jeton intermédiaire et un code (TOTP ou secours) contre la session. */
export async function verifyCode(token: string, code: string, options: Fetcher = {}): Promise<CurrentUser> {
  const session = await apiRequest<SessionPayload>('connexion/a2f', { method: 'POST', body: { code }, bearer: token, ...options })
  applySession(session)
  return session.utilisateur
}

/** POST /api/connexion/a2f/enrolement : nouveau secret et QR code. Chaque appel remplace le secret en attente. */
export function startEnrolment(token: string, options: Fetcher = {}): Promise<EnrolmentPayload> {
  return apiRequest<EnrolmentPayload>('connexion/a2f/enrolement', { method: 'POST', bearer: token, ...options })
}

/**
 * POST /api/connexion/a2f/enrolement/confirmation : premier code, A2F activée.
 * La session n'est PAS installée ici : les codes de secours ne sont montrés qu'une fois, l'utilisateur doit les avoir vus
 * avant que l'application ne change d'écran (voir openSession).
 */
export function confirmEnrolment(token: string, code: string, options: Fetcher = {}): Promise<ConfirmationPayload> {
  return apiRequest<ConfirmationPayload>('connexion/a2f/enrolement/confirmation', { method: 'POST', body: { code }, bearer: token, ...options })
}

/** Installe la session en mémoire une fois les codes de secours vus. */
export function openSession(session: SessionPayload): void {
  applySession(session)
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
