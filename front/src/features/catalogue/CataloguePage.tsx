import { useMemo } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router-dom'
import { ApiError } from '../../api/client'
import { useRequest } from '../../hooks/useRequest'
import { fetchFilterValues, fetchGames } from './catalogueApi'
import { FiltersForm } from './FiltersForm'
import { parseFilters, toSearchParams } from './filtersUrl'
import { GameCard } from './GameCard'
import { Pagination } from './Pagination'
import type { CatalogueFilters } from './types'

/**
 * Écran E02 : catalogue (US-01 à US-05). Grille paginée par 20, filtres combinables reflétés dans l'URL, nombre de
 * résultats annoncé (`aria-live`), message explicite si aucun résultat. La liste vient de l'API (cache Redis côté
 * serveur) ; ce composant n'a aucune règle de gestion : il affiche ce que l'API renvoie.
 */
export function CataloguePage() {
  const { t, i18n } = useTranslation()
  const [params, setParams] = useSearchParams()
  const filters = useMemo(() => parseFilters(params), [params])
  const query = params.toString()
  const language = i18n.resolvedLanguage

  const [values] = useRequest((signal) => fetchFilterValues({ signal }), [language])
  const [games, retry] = useRequest((signal) => fetchGames(filters, { signal }), [query, language])

  function apply(next: CatalogueFilters) {
    setParams(toSearchParams(next))
  }
  const hrefFor = (page: number) => `/catalogue?${toSearchParams({ ...filters, page }).toString()}`

  let status: string
  if (games.status === 'loading') status = t('catalogue.loading')
  else if (games.status === 'error') status = ''
  else status = games.data.total === 0 ? t('catalogue.none') : t('catalogue.results', { count: games.data.total })

  return (
    <div className="catalogue">
      <h1>{t('catalogue.title')}</h1>
      <div className="catalogue-layout">
        <aside>
          {/* La clé recrée le formulaire quand l'URL change (précédent, lien partagé, réinitialisation). */}
          <FiltersForm
            key={query}
            filters={filters}
            values={values.status === 'success' ? values.data : null}
            onApply={apply}
            onReset={() => setParams(new URLSearchParams())}
          />
        </aside>
        <section aria-label={t('catalogue.title')} aria-busy={games.status === 'loading'}>
          {/* Région vivante permanente : le nombre de résultats est annoncé à chaque changement de critères. */}
          <p role="status" aria-live="polite" className="result-count">
            {status}
          </p>

          {games.status === 'error' ? (
            <div role="alert" className="error-summary">
              {games.error instanceof ApiError && games.error.status === 404 ? (
                <>
                  <p>{t('catalogue.pageOutOfRange')}</p>
                  <Link to={hrefFor(1)}>{t('catalogue.firstPage')}</Link>
                </>
              ) : (
                <>
                  <p>{t('catalogue.error')}</p>
                  <button type="button" className="btn" onClick={retry}>
                    {t('catalogue.retry')}
                  </button>
                </>
              )}
            </div>
          ) : null}

          {games.status === 'success' && games.data.jeux.length > 0 ? (
            <>
              <ul className="game-grid">
                {games.data.jeux.map((game) => (
                  <GameCard key={game.slug} game={game} />
                ))}
              </ul>
              <Pagination page={games.data.page} pageCount={games.data.nombrePages} hrefFor={hrefFor} />
            </>
          ) : null}
        </section>
      </div>
    </div>
  )
}
