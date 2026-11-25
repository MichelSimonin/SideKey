import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { toSupportedLanguage } from '../../i18n/config'
import { Cover } from './Cover'
import { formatPrice, formatRating } from './format'
import type { GameSummary } from './types'

/**
 * Carte d'un jeu dans la grille (E02, US-01) : jaquette, titre, PEGI, plateformes, prix minimum, disponibilité.
 * Le PEGI, la disponibilité et la note sont écrits en texte : aucune information ne passe par la couleur ou un
 * pictogramme seul (RGAA 3.1). Tout le contenu vient de l'API et sort échappé par React : jamais de HTML injecté.
 */
export function GameCard({ game }: { game: GameSummary }) {
  const { t, i18n } = useTranslation()
  const language = toSupportedLanguage(i18n.resolvedLanguage)

  return (
    <li className="game-card">
      <Cover path={game.jaquette} title={game.titre} decorative />
      <h2>
        <Link to={`/jeux/${encodeURIComponent(game.slug)}`}>{game.titre}</Link>
      </h2>
      <p className="game-meta">
        <span className="badge">{t('catalogue.card.pegi', { age: game.pegi })}</span>{' '}
        <span>{t('catalogue.card.platforms', { list: game.plateformes.join(', ') })}</span>
      </p>
      <p className="game-price">{t('catalogue.card.from', { price: formatPrice(game.prixMin, language) })}</p>
      <p className={game.disponible ? 'stock stock-ok' : 'stock stock-out'}>
        {game.disponible ? t('catalogue.card.available') : t('catalogue.card.soldOut')}
      </p>
      <p className="game-rating">
        {game.noteMoyenne === null
          ? t('catalogue.card.noRating')
          : t('catalogue.card.rating', { rating: formatRating(game.noteMoyenne, language), count: game.nombreAvis })}
      </p>
    </li>
  )
}
