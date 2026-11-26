import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Navigate } from 'react-router-dom'
import { useAuth } from '../auth/useAuth'
import { CATALOGUE_PERMISSION } from './types'

/**
 * Garde d'accès au back-office. Elle MASQUE l'interface à qui n'y a pas droit ; elle n'autorise rien : chaque route de l'API
 * vérifie elle-même la permission et le second facteur (403 sinon). Contourner ce composant n'ouvre donc aucune donnée.
 */
export function RequireCatalogueAccess({ children }: { children: ReactNode }) {
  const { t } = useTranslation()
  const { status, user } = useAuth()

  if (status === 'loading') {
    return <p role="status">{t('admin.access.checking')}</p>
  }
  if (status === 'anonymous') {
    return <Navigate to="/connexion" replace />
  }
  if (user === null || !user.permissions.includes(CATALOGUE_PERMISSION)) {
    return (
      <div role="alert" className="error-summary">
        <h1>{t('admin.access.deniedTitle')}</h1>
        <p>{t('admin.access.denied')}</p>
      </div>
    )
  }
  return <>{children}</>
}
