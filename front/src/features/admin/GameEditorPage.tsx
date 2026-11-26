import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useLocation, useNavigate, useParams } from 'react-router-dom'
import { ApiError } from '../../api/client'
import { useRequest } from '../../hooks/useRequest'
import { createGame, fetchAdminGame, fetchGenres, fetchPlatforms, fetchPublishers, updateGame } from './adminApi'
import { CoverUpload } from './CoverUpload'
import { GameForm } from './GameForm'
import { OffersManager } from './OffersManager'
import type { AdminGame, AdminOffer, GameInput, GenreOption, Platform, Publisher } from './types'

type Loaded = { game: AdminGame | null; publishers: Publisher[]; genres: GenreOption[]; platforms: Platform[] }

/**
 * Création et modification d'un jeu (US-50, US-51, écran E12) : formulaire, jaquette et offres sur une même page. Les listes
 * de référence (éditeurs, genres, plateformes) et le jeu sont chargés ensemble ; en création, seul le formulaire est proposé,
 * la jaquette et les offres viennent une fois le jeu enregistré.
 */
export function GameEditorPage() {
  const { t } = useTranslation()
  const { id } = useParams()
  const [data, retry] = useRequest<Loaded>(
    async (signal) => {
      const [publishers, genres, platforms, game] = await Promise.all([
        fetchPublishers({ signal }),
        fetchGenres({ signal }),
        fetchPlatforms({ signal }),
        id === undefined ? Promise.resolve(null) : fetchAdminGame(id, { signal }),
      ])
      return { game, publishers, genres, platforms }
    },
    [id],
  )

  return (
    <section aria-labelledby="editor-title">
      <p>
        <Link to="/admin/jeux">{t('admin.editor.back')}</Link>
      </p>
      <h1 id="editor-title">{id === undefined ? t('admin.editor.newTitle') : t('admin.editor.editTitle')}</h1>

      {data.status === 'loading' ? <p role="status">{t('admin.common.loading')}</p> : null}
      {data.status === 'error' ? (
        <div role="alert" className="error-summary">
          <p>
            {data.error instanceof ApiError && data.error.status === 404
              ? (data.error.body.message ?? t('admin.errors.notFound'))
              : t('admin.errors.load')}
          </p>
          <button type="button" className="btn" onClick={retry}>
            {t('admin.errors.retry')}
          </button>
        </div>
      ) : null}
      {data.status === 'success' ? <GameEditor key={data.data.game?.id ?? 'new'} loaded={data.data} /> : null}
    </section>
  )
}

function GameEditor({ loaded }: { loaded: Loaded }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const location = useLocation()
  const [game, setGame] = useState<AdminGame | null>(loaded.game)
  const justCreated = (location.state as { created?: boolean } | null)?.created === true
  const [notice, setNotice] = useState(justCreated ? t('admin.editor.created') : '')

  async function submit(input: GameInput) {
    setNotice('')
    if (game === null) {
      const created = await createGame(input)
      void navigate(`/admin/jeux/${encodeURIComponent(created.id)}`, { replace: true, state: { created: true } })
      return
    }
    setGame(await updateGame(game.id, input))
    setNotice(t('admin.editor.saved'))
  }

  function saveOffer(offer: AdminOffer) {
    setGame((current) => {
      if (current === null) return current
      const others = current.offres.filter((existing) => existing.id !== offer.id)
      return { ...current, offres: [...others, offer].sort((a, b) => a.plateforme.code.localeCompare(b.plateforme.code)) }
    })
  }

  async function reloadGame() {
    if (game === null) return
    setGame(await fetchAdminGame(game.id))
  }

  return (
    <>
      <p role="status" aria-live="polite" className="notice">
        {notice}
      </p>
      {game !== null && !game.actif ? <p className="badge">{t('admin.editor.inactiveNotice')}</p> : null}

      <GameForm game={game} publishers={loaded.publishers} genres={loaded.genres} onSubmit={submit} />

      {game !== null ? (
        <>
          <CoverUpload game={game} onChange={setGame} />
          <OffersManager gameId={game.id} offers={game.offres} platforms={loaded.platforms} onOfferSaved={saveOffer} onReload={reloadGame} />
        </>
      ) : null}
    </>
  )
}
