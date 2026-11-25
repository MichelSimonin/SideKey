import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router-dom'
import { ApiError } from '../../api/client'
import { useRequest } from '../../hooks/useRequest'
import { toSupportedLanguage } from '../../i18n/config'
import { addToCart, fetchGame } from './catalogueApi'
import { Cover } from './Cover'
import { formatDate, formatPrice, formatRating } from './format'
import type { GameOffer } from './types'

/**
 * Écran E03 : fiche d'un jeu (US-04). Jaquette, description dans la langue de la page, éditeur, PEGI écrit en
 * texte, offres par plateforme avec prix et stock en toutes lettres, note moyenne. Description et titre sortent
 * échappés par React : aucun `dangerouslySetInnerHTML`, un contenu HTML éventuel s'afficherait comme du texte.
 */
export function GamePage() {
  const { t, i18n } = useTranslation()
  const { slug = '' } = useParams()
  const language = toSupportedLanguage(i18n.resolvedLanguage)
  const [game, retry] = useRequest((signal) => fetchGame(slug, { signal }), [slug, language])

  if (game.status === 'loading') {
    return <p role="status">{t('game.loading')}</p>
  }
  if (game.status === 'error') {
    const missing = game.error instanceof ApiError && game.error.status === 404
    return (
      <section>
        <h1>{missing ? t('game.notFoundTitle') : t('catalogue.title')}</h1>
        <p role={missing ? undefined : 'alert'}>{missing ? t('game.notFoundText') : t('catalogue.error')}</p>
        {missing ? null : (
          <button type="button" className="btn" onClick={retry}>
            {t('catalogue.retry')}
          </button>
        )}
        <p>
          <Link to="/catalogue">{t('game.back')}</Link>
        </p>
      </section>
    )
  }

  const data = game.data
  return (
    <article className="game">
      <p>
        <Link to="/catalogue">{t('game.back')}</Link>
      </p>
      <div className="game-layout">
        <Cover path={data.jaquette} title={data.titre} />
        <div>
          <h1>{data.titre}</h1>
          <p className="badge">{t('game.pegi', { age: data.pegi })}</p>
          <dl className="game-facts">
            <dt>{t('game.publisher')}</dt>
            <dd>{data.editeur}</dd>
            {data.dateSortie ? (
              <>
                <dt>{t('game.releaseDate')}</dt>
                <dd>{formatDate(data.dateSortie, language)}</dd>
              </>
            ) : null}
            {data.genres.length > 0 ? (
              <>
                <dt>{t('game.genres')}</dt>
                <dd>{data.genres.map((genre) => genre.libelle).join(', ')}</dd>
              </>
            ) : null}
          </dl>
          <p>
            {data.noteMoyenne === null
              ? t('game.noReviews')
              : t('game.rating', { rating: formatRating(data.noteMoyenne, language), count: data.nombreAvis })}
          </p>
          <p className="game-description">{data.description}</p>
        </div>
      </div>

      <section aria-labelledby="offers-title">
        <h2 id="offers-title">{t('game.offers')}</h2>
        <ul className="offers">
          {data.offres.map((offer) => (
            <Offer key={offer.offreId} offer={offer} title={data.titre} language={language} />
          ))}
        </ul>
      </section>
    </article>
  )
}

function Offer({ offer, title, language }: { offer: GameOffer; title: string; language: string }) {
  const { t } = useTranslation()
  const [state, setState] = useState<'idle' | 'pending' | 'added' | 'error'>('idle')
  const [message, setMessage] = useState('')
  const available = offer.stock > 0

  async function add() {
    setState('pending')
    try {
      await addToCart(offer.offreId)
      setState('added')
      setMessage(t('game.added'))
    } catch (error) {
      setState('error')
      // Le message de l'API (stock insuffisant, offre retirée…) est déjà traduit.
      setMessage(error instanceof ApiError && error.body.message ? error.body.message : t('game.addError'))
    }
  }

  return (
    <li className="offer">
      <h3>{offer.plateformeLibelle}</h3>
      <p className="game-price">{formatPrice(offer.prix, language)}</p>
      <p className={available ? 'stock stock-ok' : 'stock stock-out'}>
        {available ? t('game.stock', { count: offer.stock }) : t('game.outOfStock')}
      </p>
      <button
        type="button"
        className="btn"
        disabled={!available || state === 'pending'}
        aria-label={t('game.addToCartFor', { title, platform: offer.plateformeLibelle })}
        onClick={() => void add()}
      >
        {state === 'pending' ? t('game.adding') : t('game.addToCart')}
      </button>
      <p role={state === 'error' ? 'alert' : 'status'} className={state === 'error' ? 'field-error' : undefined}>
        {state === 'added' || state === 'error' ? message : ''}
      </p>
    </li>
  )
}
