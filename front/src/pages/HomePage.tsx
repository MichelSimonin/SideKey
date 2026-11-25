import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'

export function HomePage() {
  const { t } = useTranslation()

  return (
    <section className="hero">
      <h1>{t('home.title')}</h1>
      <p>{t('home.subtitle')}</p>
      <p>
        <Link to="/catalogue" className="btn btn-link">
          {t('home.cta')}
        </Link>
      </p>
    </section>
  )
}
