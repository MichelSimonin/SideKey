import type { ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { LanguageSwitcher } from './LanguageSwitcher'

/** Gabarit commun : lien d'évitement, en-tête, contenu principal, pied de page (RGAA 12). */
export function Layout({ children }: { children: ReactNode }) {
  const { t } = useTranslation()

  return (
    <>
      <a className="skip-link" href="#contenu">
        {t('skipLink')}
      </a>
      <header className="site-header">
        <a href="/" className="logo" aria-label={t('header.home')}>
          SideKey
        </a>
        <LanguageSwitcher />
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
