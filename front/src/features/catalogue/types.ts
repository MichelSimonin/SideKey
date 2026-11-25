/** Contrats de l'API du catalogue (F-01). Reflètent les DTO de sortie de l'API Symfony. */

export type GameSummary = {
  slug: string
  titre: string
  pegi: number
  /** Chemin relatif de la jaquette (ex. « jaquettes/jeu-01.webp »), null si le jeu n'en a pas. */
  jaquette: string | null
  plateformes: string[]
  /** Prix minimum : chaîne décimale calculée par le serveur (« 14.99 »). Le front l'affiche, il ne calcule pas. */
  prixMin: string
  disponible: boolean
  noteMoyenne: number | null
  nombreAvis: number
}

export type GamePage = {
  jeux: GameSummary[]
  total: number
  page: number
  parPage: number
  nombrePages: number
}

export type GameOffer = {
  offreId: string
  plateforme: string
  plateformeLibelle: string
  prix: string
  stock: number
}

export type GameDetail = {
  slug: string
  titre: string
  description: string
  pegi: number
  jaquette: string | null
  dateSortie: string | null
  editeur: string
  genres: Array<{ slug: string; libelle: string }>
  offres: GameOffer[]
  noteMoyenne: number | null
  nombreAvis: number
}

export type FilterValues = {
  plateformes: Array<{ code: string; libelle: string }>
  genres: Array<{ slug: string; libelle: string }>
  prixMin: string
  prixMax: string
  pegi: number[]
  tris: string[]
}

export const SORTS = ['titre', 'nouveaute', 'prix_asc', 'prix_desc', 'note'] as const
export type Sort = (typeof SORTS)[number]

export const PEGI_VALUES = [3, 7, 12, 16, 18] as const

/** Critères de recherche, tels que reflétés dans l'URL (US-02). */
export type CatalogueFilters = {
  q: string
  plateformes: string[]
  genres: string[]
  prixMin: string
  prixMax: string
  pegi: number | null
  tri: Sort
  page: number
}
