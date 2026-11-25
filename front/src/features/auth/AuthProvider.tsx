import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { refreshSession } from '../../api/client'
import { onSession } from '../../api/session'
import type { CurrentUser, UserLanguage } from '../../api/types'
import { toSupportedLanguage } from '../../i18n/config'
import * as authApi from './authApi'
import { AuthContext, type AuthContextValue, type AuthStatus } from './authContext'

/**
 * État d'authentification de l'application. Le jeton d'accès reste dans api/session.ts (mémoire) : ce composant
 * ne garde que l'utilisateur affiché. Au démarrage, une session est rétablie en silence si un cookie de rafraîchissement
 * existe (le jeton d'accès est perdu au rechargement de la page).
 */
export function AuthProvider({ children }: { children: ReactNode }) {
  const { i18n } = useTranslation()
  const [status, setStatus] = useState<AuthStatus>('loading')
  const [user, setUser] = useState<CurrentUser | null>(null)

  // Toute évolution de la session (connexion, rafraîchissement silencieux, expiration) met l'interface à jour.
  useEffect(
    () =>
      onSession((session) => {
        setUser(session?.utilisateur ?? null)
        setStatus(session === null ? 'anonymous' : 'authenticated')
      }),
    [],
  )

  // Amorçage silencieux. Le rafraîchissement est partagé (une seule requête même en StrictMode ou à plusieurs onglets).
  useEffect(() => {
    void refreshSession().then((session) => {
      if (session === null) {
        setStatus('anonymous')
      }
    })
  }, [])

  const language = toSupportedLanguage(i18n.resolvedLanguage)

  const login = useCallback(
    async (credentials: authApi.Credentials) => {
      const result = await authApi.login(credentials)
      if (result.kind === 'connected') {
        // La préférence de langue du compte prend le pas sur celle du navigateur à la connexion (CDC §12.4).
        void i18n.changeLanguage(result.user.langue)
      }
      return result
    },
    [i18n],
  )

  const register = useCallback(
    async (data: Omit<authApi.Registration, 'langue'>) => {
      const langue: UserLanguage = language
      const result = await authApi.register({ ...data, langue })
      return result
    },
    [language],
  )

  const value = useMemo<AuthContextValue>(
    () => ({ status, user, login, register, logout: () => authApi.logout() }),
    [status, user, login, register],
  )

  return <AuthContext value={value}>{children}</AuthContext>
}
