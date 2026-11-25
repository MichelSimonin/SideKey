import { useContext } from 'react'
import { AuthContext, type AuthContextValue } from './authContext'

/** Accès à l'état d'authentification ; à utiliser sous <AuthProvider>. */
export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext)
  if (context === null) {
    throw new Error('useAuth doit être utilisé dans un <AuthProvider>.')
  }
  return context
}
