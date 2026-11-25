import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'

/**
 * Pagination (20 jeux par page, US-01) : de vrais liens, qui changent l'URL et se partagent. La page courante est
 * annoncée (`aria-current="page"`) ; précédent et suivant sont masqués plutôt que grisés aux extrémités.
 * `hrefFor` fabrique l'adresse d'une page en conservant les autres critères.
 */
export function Pagination({ page, pageCount, hrefFor }: { page: number; pageCount: number; hrefFor: (page: number) => string }) {
  const { t } = useTranslation()
  if (pageCount <= 1) return null

  const pages = Array.from({ length: pageCount }, (_, index) => index + 1).filter(
    (n) => n === 1 || n === pageCount || Math.abs(n - page) <= 1,
  )

  return (
    <nav aria-label={t('catalogue.pagination.label')} className="pagination">
      <p className="visually-hidden">{t('catalogue.pagination.current', { page, total: pageCount })}</p>
      <ul>
        {page > 1 ? (
          <li>
            <Link to={hrefFor(page - 1)} rel="prev">
              {t('catalogue.pagination.previous')}
            </Link>
          </li>
        ) : null}
        {pages.map((n, index) => (
          <li key={n}>
            {index > 0 && n - pages[index - 1] > 1 ? <span aria-hidden="true">… </span> : null}
            <Link
              to={hrefFor(n)}
              aria-current={n === page ? 'page' : undefined}
              aria-label={t('catalogue.pagination.page', { page: n })}
            >
              {n}
            </Link>
          </li>
        ))}
        {page < pageCount ? (
          <li>
            <Link to={hrefFor(page + 1)} rel="next">
              {t('catalogue.pagination.next')}
            </Link>
          </li>
        ) : null}
      </ul>
    </nav>
  )
}
