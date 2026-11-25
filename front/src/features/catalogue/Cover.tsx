import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { coverUrl } from './format'

/**
 * Jaquette d'un jeu. Éco-conception (RGESN, CDC §12.3) :
 *  - chargement différé (`loading="lazy"`) : une image hors écran n'est pas téléchargée ;
 *  - dimensions déclarées (`width` / `height`) : pas de décalage de mise en page, pas de recalcul ;
 *  - décodage hors du fil principal (`decoding="async"`), format léger (WebP) imposé par le téléversement (F-09) ;
 *  - aucune image décorative lourde : sans jaquette, ou si elle ne charge pas, un simple bloc de texte la remplace.
 * `alt` vide dans une carte (le titre voisin suffit, RGAA 1.2) ; descriptif sur la fiche.
 */
export function Cover({
  path,
  title,
  decorative = false,
  width = 300,
  height = 400,
}: {
  path: string | null
  title: string
  decorative?: boolean
  width?: number
  height?: number
}) {
  const { t } = useTranslation()
  const [failed, setFailed] = useState(false)

  if (path === null || failed) {
    return (
      <div className="cover cover-missing" style={{ aspectRatio: `${width} / ${height}` }} role={decorative ? undefined : 'img'} aria-label={decorative ? undefined : t('game.noCover')} aria-hidden={decorative ? true : undefined}>
        <span aria-hidden="true">{title.slice(0, 1)}</span>
      </div>
    )
  }

  return (
    <img
      className="cover"
      src={coverUrl(path)}
      alt={decorative ? '' : t('game.coverAlt', { title })}
      width={width}
      height={height}
      loading="lazy"
      decoding="async"
      onError={() => setFailed(true)}
    />
  )
}
