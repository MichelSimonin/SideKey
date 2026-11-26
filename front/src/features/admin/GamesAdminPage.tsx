import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router-dom'
import { ConfirmButton } from '../../components/ui/ConfirmButton'
import { SelectField } from '../../components/ui/SelectField'
import { TextField } from '../../components/ui/TextField'
import { useRequest } from '../../hooks/useRequest'
import { Pagination } from '../catalogue/Pagination'
import { deactivateGame, fetchAdminGames } from './adminApi'
import { adminFeedback } from './adminFeedback'
import type { GameStatus } from './types'

const STATUSES: GameStatus[] = ['tous', 'actifs', 'inactifs']

function parsePage(value: string | null): number {
  const page = Number.parseInt(value ?? '', 10)
  return Number.isInteger(page) && page >= 1 && page <= 10_000 ? page : 1
}

/**
 * Écran E12 « Jeux et offres » : liste paginée (20 par page) de tous les jeux, inactifs compris, avec recherche par titre
 * et filtre par statut reflétés dans l'URL. « Désactiver » est la suppression logique : le jeu sort du catalogue, ses
 * offres et son historique restent.
 */
export function GamesAdminPage() {
  const { t } = useTranslation()
  const [params, setParams] = useSearchParams()
  const q = params.get('q') ?? ''
  const rawStatus = params.get('statut')
  const status: GameStatus = STATUSES.includes(rawStatus as GameStatus) ? (rawStatus as GameStatus) : 'tous'
  const page = parsePage(params.get('page'))

  const [games, reload] = useRequest((signal) => fetchAdminGames({ q, statut: status, page }, { signal }), [q, status, page])
  const [search, setSearch] = useState(q)
  const [statusChoice, setStatusChoice] = useState<GameStatus>(status)
  const [notice, setNotice] = useState('')
  const [error, setError] = useState('')

  function onSearch(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const next = new URLSearchParams()
    if (search.trim() !== '') next.set('q', search.trim())
    if (statusChoice !== 'tous') next.set('statut', statusChoice)
    setParams(next)
  }

  const hrefFor = (target: number) => {
    const next = new URLSearchParams(params)
    if (target > 1) next.set('page', String(target))
    else next.delete('page')
    const query = next.toString()
    return `/admin/jeux${query === '' ? '' : `?${query}`}`
  }

  async function deactivate(id: string, title: string) {
    setNotice('')
    setError('')
    try {
      await deactivateGame(id)
      setNotice(t('admin.games.deactivated', { title }))
      reload()
    } catch (failure) {
      setError(adminFeedback(failure, t).form ?? t('auth.errors.generic'))
    }
  }

  let count = ''
  if (games.status === 'success') {
    count = games.data.total === 0 ? t('admin.games.none') : t('admin.games.results', { count: games.data.total })
  }

  return (
    <section aria-labelledby="games-title" aria-busy={games.status === 'loading'}>
      <div className="admin-head">
        <h1 id="games-title">{t('admin.games.title')}</h1>
        <Link to="/admin/jeux/nouveau" className="btn btn-link">
          {t('admin.games.new')}
        </Link>
      </div>

      <form onSubmit={onSearch} role="search" className="inline-form" aria-label={t('admin.games.searchForm')}>
        <TextField
          id="admin-games-q"
          name="q"
          type="search"
          label={t('admin.games.search')}
          value={search}
          onChange={(event) => setSearch(event.target.value)}
          maxLength={100}
          autoComplete="off"
        />
        <SelectField
          id="admin-games-status"
          name="statut"
          label={t('admin.games.status')}
          value={statusChoice}
          onChange={(event) => setStatusChoice(event.target.value as GameStatus)}
          options={STATUSES.map((value) => ({ value, label: t(`admin.games.statusOptions.${value}`) }))}
        />
        <button type="submit" className="btn">
          {t('admin.games.apply')}
        </button>
      </form>

      <p role="status" aria-live="polite" className="result-count">
        {notice !== '' ? notice : count}
      </p>
      {error !== '' ? (
        <p role="alert" className="error-summary">
          {error}
        </p>
      ) : null}

      {games.status === 'loading' ? <p>{t('admin.common.loading')}</p> : null}
      {games.status === 'error' ? (
        <div role="alert" className="error-summary">
          <p>{t('admin.errors.load')}</p>
          <button type="button" className="btn" onClick={reload}>
            {t('admin.errors.retry')}
          </button>
        </div>
      ) : null}

      {games.status === 'success' && games.data.jeux.length > 0 ? (
        <>
          <div className="table-wrap">
            <table className="admin-table">
              <caption>{t('admin.games.caption')}</caption>
              <thead>
                <tr>
                  <th scope="col">{t('admin.games.columns.title')}</th>
                  <th scope="col">{t('admin.games.columns.publisher')}</th>
                  <th scope="col">{t('admin.games.columns.pegi')}</th>
                  <th scope="col">{t('admin.games.columns.offers')}</th>
                  <th scope="col">{t('admin.common.state')}</th>
                  <th scope="col">{t('admin.common.actions')}</th>
                </tr>
              </thead>
              <tbody>
                {games.data.jeux.map((game) => (
                  <tr key={game.id}>
                    <th scope="row">{game.titre}</th>
                    <td>{game.editeur}</td>
                    <td>{game.pegi}</td>
                    <td>{game.nombreOffres}</td>
                    <td>{game.actif ? t('admin.common.active') : t('admin.common.inactive')}</td>
                    <td>
                      <div className="row-actions">
                        <Link to={`/admin/jeux/${encodeURIComponent(game.id)}`} className="btn btn-secondary btn-link" aria-label={t('admin.games.edit', { title: game.titre })}>
                          {t('admin.common.edit')}
                        </Link>
                        {game.actif ? (
                          <ConfirmButton
                            label={t('admin.games.deactivateFor', { title: game.titre })}
                            visibleLabel={t('admin.games.deactivate')}
                            prompt={t('admin.games.deactivateConfirm', { title: game.titre })}
                            onConfirm={() => deactivate(game.id, game.titre)}
                          />
                        ) : null}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <Pagination page={games.data.page} pageCount={games.data.nombrePages} hrefFor={hrefFor} />
        </>
      ) : null}
    </section>
  )
}
