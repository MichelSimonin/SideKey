import type { SessionPayload } from './types'

/**
 * Jeton d'accès de la session (CDC §11.4) : conservé UNIQUEMENT dans cette variable de module.
 * Jamais dans localStorage, sessionStorage ni un cookie : une faille XSS ne pourrait pas le lire après coup,
 * et il disparaît à la fermeture de l'onglet. Au rechargement, la session est rétablie en silence par le
 * jeton de rafraîchissement (cookie HttpOnly, invisible du JavaScript).
 */
let accessToken: string | null = null

type SessionListener = (session: SessionPayload | null) => void
const listeners = new Set<SessionListener>()

export function getAccessToken(): string | null {
  return accessToken
}

/** Installe (ou efface, avec null) la session courante et prévient l'interface. */
export function applySession(session: SessionPayload | null): void {
  accessToken = session?.jetonAcces ?? null
  listeners.forEach((listener) => listener(session))
}

/** S'abonne aux changements de session (connexion, rafraîchissement silencieux, expiration). */
export function onSession(listener: SessionListener): () => void {
  listeners.add(listener)
  return () => {
    listeners.delete(listener)
  }
}
