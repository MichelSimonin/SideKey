/** Contrats du back-office catalogue (F-09). Reflètent les DTO de sortie de l'API (CatalogueAdminResponse). */

export type Publisher = { id: string; nom: string; nombreJeux: number }

export type Platform = { id: string; code: string; libelle: string; nombreOffres: number }

export type GenreOption = { slug: string; libelle: string }

export type AdminOffer = {
  id: string
  plateforme: { id: string; code: string; libelle: string }
  /** Prix TTC en chaîne décimale (« 14.99 »), tel que stocké : jamais de nombre à virgule flottante. */
  prix: string
  seuilAlerte: number
  actif: boolean
  /** Version lue avec l'offre, à rendre à la modification (verrouillage optimiste, US-51). */
  version: number
  /** Clés disponibles : calculé par l'API, jamais saisi. */
  stock: number
}

export type AdminGame = {
  id: string
  slug: string
  titre: string
  dateSortie: string | null
  pegi: number
  actif: boolean
  jaquette: string | null
  editeur: { id: string; nom: string }
  descriptions: { fr: string; en: string }
  genres: string[]
  offres: AdminOffer[]
}

export type AdminGameRow = {
  id: string
  slug: string
  titre: string
  editeur: string
  pegi: number
  actif: boolean
  jaquette: string | null
  nombreOffres: number
}

export type AdminGamePage = { jeux: AdminGameRow[]; page: number; nombrePages: number; total: number }

export type GameStatus = 'tous' | 'actifs' | 'inactifs'

/** Corps de création et de modification d'un jeu (JeuRequest). Le slug vide est omis : l'API le dérive du titre. */
export type GameInput = {
  titre: string
  slug: string | null
  dateSortie: string | null
  pegi: number
  editeurId: string
  actif: boolean
  descriptions: { fr: string; en: string }
  genres: string[]
}

export type OfferInput = { prix: string; seuilAlerte: number; actif: boolean }

/** Permission qui ouvre le back-office catalogue (matrice RBAC, CDC §11.5). */
export const CATALOGUE_PERMISSION = 'gerer_catalogue'

/** Contraintes de l'image de jaquette, reflets de ValidationJaquette côté API (qui reste seule juge). */
export const COVER_MAX_BYTES = 512 * 1024
export const COVER_TYPE = 'image/webp'
