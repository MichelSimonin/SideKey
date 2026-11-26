import { useTranslation } from 'react-i18next'
import { NavLink, Outlet } from 'react-router-dom'
import { RequireCatalogueAccess } from './RequireCatalogueAccess'

/** Gabarit du back-office catalogue : titre, navigation entre les trois sections (aria-current sur la section active), contenu. */
export function AdminLayout() {
  const { t } = useTranslation()

  return (
    <RequireCatalogueAccess>
      <div className="admin">
        <p className="admin-title">{t('admin.title')}</p>
        <nav aria-label={t('admin.nav.label')} className="tabs">
          <NavLink to="/admin/jeux">{t('admin.nav.games')}</NavLink>
          <NavLink to="/admin/editeurs">{t('admin.nav.publishers')}</NavLink>
          <NavLink to="/admin/plateformes">{t('admin.nav.platforms')}</NavLink>
        </nav>
        <Outlet />
      </div>
    </RequireCatalogueAccess>
  )
}
