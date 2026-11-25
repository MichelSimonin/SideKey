import { applySession, getAccessToken } from './session'
import type { ApiErrorBody, SessionPayload } from './types'

/**
 * Client HTTP vers l'API SideKey.
 * En dev, VITE_API_URL vaut "/api" : Vite relaie vers Nginx (même origine).
 */
const DEFAULT_BASE_URL = '/api'

export function apiUrl(path: string, baseUrl: string = import.meta.env.VITE_API_URL ?? DEFAULT_BASE_URL): string {
  const base = baseUrl.replace(/\/+$/, '')
  const cleanPath = path.replace(/^\/+/, '')
  return `${base}/${cleanPath}`
}

/**
 * En-têtes communs : l'API traduit ses messages d'erreur selon Accept-Language (F-02).
 * La langue est celle de la page (attribut lang, tenu à jour par l'i18n).
 */
export function apiHeaders(language: string = document.documentElement.lang || 'fr'): Record<string, string> {
  return {
    Accept: 'application/json',
    'Accept-Language': language,
  }
}

export type HealthStatus = { status: 'ok' }

export async function fetchHealth(fetchFn: typeof fetch = fetch): Promise<HealthStatus> {
  const response = await fetchFn(apiUrl('health'), {
    headers: apiHeaders(),
    credentials: 'same-origin',
  })
  if (!response.ok) {
    throw new Error(`API indisponible (HTTP ${response.status})`)
  }
  return (await response.json()) as HealthStatus
}

// --- Erreurs --------------------------------------------------------------------------------------

/** Réponse d'erreur de l'API : code HTTP, corps traduit (contrat d'erreur, architecture-couches.md §4). */
export class ApiError extends Error {
  readonly status: number
  readonly body: ApiErrorBody
  /** Secondes à attendre (en-tête Retry-After d'un 429), sinon null. */
  readonly retryAfter: number | null

  constructor(status: number, body: ApiErrorBody, retryAfter: number | null = null) {
    super(body.message ?? `HTTP ${status}`)
    this.name = 'ApiError'
    this.status = status
    this.body = body
    this.retryAfter = retryAfter
  }
}

async function toApiError(response: Response): Promise<ApiError> {
  let body: ApiErrorBody = {}
  try {
    const parsed: unknown = await response.json()
    if (parsed !== null && typeof parsed === 'object') {
      body = parsed as ApiErrorBody
    }
  } catch {
    // corps vide ou non JSON : on garde le code HTTP seul
  }
  const header = Number.parseInt(response.headers.get('Retry-After') ?? '', 10)
  return new ApiError(response.status, body, Number.isFinite(header) && header > 0 ? header : null)
}

// --- Cookie CSRF ------------------------------------------------------------------------------------

/** Cookie de double soumission posé par l'API (lisible par le script, il ne donne aucun accès à lui seul). */
export const CSRF_COOKIE = 'sk_csrf'
export const CSRF_HEADER = 'X-CSRF-Token'

export function readCookie(name: string, source: string = document.cookie): string | null {
  for (const part of source.split(';')) {
    const [key, ...rest] = part.trim().split('=')
    if (key === name) {
      return decodeURIComponent(rest.join('='))
    }
  }
  return null
}

/** Efface le cookie CSRF côté navigateur quand la session est terminée (mêmes attributs que la pose). */
export function clearCsrfCookie(): void {
  document.cookie = `${CSRF_COOKIE}=; Max-Age=0; Path=/; Secure; SameSite=Strict`
}

// --- Rafraîchissement silencieux ----------------------------------------------------------------------

let inflightRefresh: Promise<SessionPayload | null> | null = null

/**
 * Plusieurs onglets, ou plusieurs requêtes à la fois : un seul rafraîchissement à la fois. Le jeton de rafraîchissement
 * est à usage unique : deux échanges simultanés avec le même jeton passeraient pour un vol et couperaient la session
 * (ADR-0003). Dans un onglet, les appels partagent une seule promesse ; entre onglets, un verrou Web Locks
 * sérialise les échanges, le second onglet repartant alors du cookie déjà renouvelé.
 */
async function serialize<T>(task: () => Promise<T>): Promise<T> {
  if (typeof navigator !== 'undefined' && navigator.locks) {
    return navigator.locks.request('sidekey-refresh', task)
  }
  return task()
}

/**
 * Échange le cookie de rafraîchissement contre une nouvelle session (POST /api/token/refresh).
 * Renvoie null si aucune session ne peut être rétablie ; la session locale est alors effacée.
 */
export function refreshSession(fetchFn: typeof fetch = fetch): Promise<SessionPayload | null> {
  inflightRefresh ??= serialize(() => doRefresh(fetchFn)).finally(() => {
    inflightRefresh = null
  })
  return inflightRefresh
}

async function doRefresh(fetchFn: typeof fetch): Promise<SessionPayload | null> {
  const csrf = readCookie(CSRF_COOKIE)
  if (csrf === null) {
    // Pas de cookie CSRF : il n'y a jamais eu de session (ou elle est terminée). Inutile d'interroger l'API.
    applySession(null)
    return null
  }
  try {
    const response = await fetchFn(apiUrl('token/refresh'), {
      method: 'POST',
      headers: { ...apiHeaders(), [CSRF_HEADER]: csrf },
      credentials: 'same-origin',
    })
    if (!response.ok) {
      clearCsrfCookie()
      applySession(null)
      return null
    }
    const session = (await response.json()) as SessionPayload
    applySession(session)
    return session
  } catch {
    applySession(null)
    return null
  }
}

// --- Requêtes de l'application -----------------------------------------------------------------------

export type RequestOptions = {
  method?: 'GET' | 'POST' | 'PATCH' | 'PUT' | 'DELETE'
  body?: unknown
  fetchFn?: typeof fetch
  /** Permet d'abandonner une requête devenue inutile (changement de filtres, de page, de langue). */
  signal?: AbortSignal
}

/**
 * Requête JSON vers l'API, avec le jeton d'accès en mémoire dans l'en-tête Authorization.
 * Sur un 401 alors qu'on était connecté, le jeton a probablement expiré (15 min) : un rafraîchissement
 * silencieux est tenté, puis la requête est rejouée UNE fois. Si le rafraîchissement échoue, la session locale
 * est effacée et l'erreur 401 remonte (l'interface renvoie vers la connexion).
 */
export async function apiRequest<T>(path: string, options: RequestOptions = {}): Promise<T> {
  const { method = 'GET', body, fetchFn = fetch, signal } = options

  const send = (): Promise<Response> => {
    const token = getAccessToken()
    const headers: Record<string, string> = apiHeaders()
    if (body !== undefined) {
      headers['Content-Type'] = 'application/json'
    }
    if (token !== null) {
      headers.Authorization = `Bearer ${token}`
    }
    return fetchFn(apiUrl(path), {
      method,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
      credentials: 'same-origin',
      signal,
    })
  }

  const hadSession = getAccessToken() !== null
  let response = await send()
  if (response.status === 401 && hadSession) {
    if ((await refreshSession(fetchFn)) !== null) {
      response = await send()
    }
  }

  if (!response.ok) {
    throw await toApiError(response)
  }
  if (response.status === 204) {
    return undefined as T
  }
  return (await response.json()) as T
}
