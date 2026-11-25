import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { useAuth } from '../../features/auth/useAuth'
import { LanguageSwitcher } from './LanguageSwitcher'

/** Gabarit commun : lien d'évitement, en-tête, contenu principal, pied de page (RGAA 12). */
export function Layout({ children }: { children: ReactNode }) {
  const { t } = useTranslation()
  const { status, user, logout } = useAuth()

  return (
    <>
      <a className="skip-link" href="#contenu">
        {t('skipLink')}
      </a>
      <header className="site-header">
        <Link to="/" className="logo" aria-label={t('header.home')}>
          SideKey
        </Link>
        <nav aria-label={t('header.mainNav')} className="main-nav">
          <Link to="/catalogue">{t('header.catalogue')}</Link>
        </nav>
        <div className="header-actions">
          <nav aria-label={t('header.nav')} className="account-nav">
            {status === 'authenticated' && user ? (
              <>
                <span>{t('header.greeting', { name: user.prenom })}</span>
                <button type="button" className="link-button" onClick={() => void logout()}>
                  {t('header.logout')}
                </button>
              </>
            ) : null}
            {status === 'anonymous' ? <Link to="/connexion">{t('header.login')}</Link> : null}
          </nav>
          <LanguageSwitcher />
        </div>
      </header>
      <main id="contenu" tabIndex={-1}>
        {children}
      </main>
      <footer className="site-footer">
        <ul>
          <li>{t('footer.securePayment')}</li>
          <li>{t('footer.euHosting')}</li>
          <li>{t('footer.legal')}</li>
          <li>{t('footer.privacy')}</li>
          <li>{t('footer.a11y')}</li>
        </ul>
        <p>{t('footer.copyright')}</p>
      </footer>
    </>
  )
}
