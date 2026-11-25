import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'

export function NotFoundPage() {
  const { t } = useTranslation()

  return (
    <section>
      <h1>{t('notFound.title')}</h1>
      <p>{t('notFound.text')}</p>
      <Link to="/">{t('notFound.back')}</Link>
    </section>
  )
}
