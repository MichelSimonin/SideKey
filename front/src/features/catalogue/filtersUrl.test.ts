import { describe, expect, it } from 'vitest'
import { DEFAULT_FILTERS, activeFilterCount, normalizePrice, parseFilters, toApiQuery, toSearchParams } from './filtersUrl'
import type { CatalogueFilters } from './types'

const params = (query: string) => new URLSearchParams(query)

describe('parseFilters', () => {
  it('renvoie les valeurs par défaut pour une URL sans paramètre', () => {
    expect(parseFilters(params(''))).toEqual(DEFAULT_FILTERS)
  })

  it('lit tous les critères de l’URL', () => {
    const filters = parseFilters(
      params('q=aube&plateforme=STEAM&plateforme=PSN&genre=action&genre=rpg&prix_min=5&prix_max=30.5&pegi=16&tri=prix_asc&page=3'),
    )

    expect(filters).toEqual({
      q: 'aube',
      plateformes: ['PSN', 'STEAM'],
      genres: ['action', 'rpg'],
      prixMin: '5',
      prixMax: '30.5',
      pegi: 16,
      tri: 'prix_asc',
      page: 3,
    })
  })

  it('accepte la virgule française dans les prix', () => {
    expect(parseFilters(params('prix_max=12,5')).prixMax).toBe('12.5')
  })

  it('ignore un tri hors liste blanche (jamais une colonne venue de l’URL)', () => {
    for (const tri of ['mot_de_passe_hash', 'titre;DROP', 'TITRE', 'j.titre', '']) {
      expect(parseFilters(params(`tri=${encodeURIComponent(tri)}`)).tri).toBe('titre')
    }
  })

  it('ignore les PEGI inconnus et non numériques', () => {
    for (const pegi of ['13', 'abc', '-1', '1e3', '']) {
      expect(parseFilters(params(`pegi=${pegi}`)).pegi).toBeNull()
    }
    expect(parseFilters(params('pegi=18')).pegi).toBe(18)
  })

  it('borne la page à un entier entre 1 et 10 000', () => {
    expect(parseFilters(params('page=0')).page).toBe(1)
    expect(parseFilters(params('page=-4')).page).toBe(1)
    expect(parseFilters(params('page=abc')).page).toBe(1)
    expect(parseFilters(params('page=10001')).page).toBe(1)
    expect(parseFilters(params('page=2.7')).page).toBe(2)
    expect(parseFilters(params('page=10000')).page).toBe(10000)
  })

  it('écarte les plateformes et genres mal formés et les doublons', () => {
    const filters = parseFilters(
      params("plateforme=steam&plateforme=STEAM&plateforme=STEAM&plateforme=P'%20OR%201=1&genre=Action&genre=rpg&genre=rpg&genre=a%20b"),
    )

    expect(filters.plateformes).toEqual(['STEAM'])
    expect(filters.genres).toEqual(['rpg'])
  })

  it('limite à dix cases cochées', () => {
    const query = Array.from({ length: 30 }, (_, i) => `plateforme=P${i}`).join('&')

    expect(parseFilters(params(query)).plateformes).toHaveLength(10)
  })

  it('tronque la recherche à 100 caractères et retire les espaces autour', () => {
    expect(parseFilters(params(`q=${'a'.repeat(500)}`)).q).toHaveLength(100)
    expect(parseFilters(params('q=%20%20aube%20')).q).toBe('aube')
  })

  it('invalide un prix qui n’est pas décimal', () => {
    for (const prix of ['abc', '-5', '1.999', '1e5', '1 000', '9999999']) {
      expect(parseFilters(params(`prix_min=${encodeURIComponent(prix)}`)).prixMin).toBe('')
    }
  })

  it('ne transmet jamais une charge d’injection telle quelle', () => {
    const filters = parseFilters(params('q=%3Cscript%3Ealert(1)%3C%2Fscript%3E&tri=x&pegi=x&page=x&prix_max=%27%20OR%201%3D1'))

    expect(filters.tri).toBe('titre')
    expect(filters.pegi).toBeNull()
    expect(filters.page).toBe(1)
    expect(filters.prixMax).toBe('')
  })
})

describe('toSearchParams', () => {
  it('n’écrit que ce qui diffère des valeurs par défaut', () => {
    expect(toSearchParams(DEFAULT_FILTERS).toString()).toBe('')
    expect(toSearchParams({ ...DEFAULT_FILTERS, page: 1, tri: 'titre' }).toString()).toBe('')
  })

  it('écrit les critères avec les noms de paramètres de l’API', () => {
    const filters: CatalogueFilters = {
      q: 'aube',
      plateformes: ['PSN', 'STEAM'],
      genres: ['action'],
      prixMin: '5',
      prixMax: '30',
      pegi: 16,
      tri: 'note',
      page: 2,
    }

    expect(toSearchParams(filters).toString()).toBe(
      'q=aube&plateforme=PSN&plateforme=STEAM&genre=action&prix_min=5&prix_max=30&pegi=16&tri=note&page=2',
    )
  })

  it('est l’inverse de parseFilters (aller-retour sans perte)', () => {
    const filters: CatalogueFilters = {
      q: "L'Odyssée d'Aëlys & co",
      plateformes: ['PSN'],
      genres: ['rpg', 'action'],
      prixMin: '9.99',
      prixMax: '',
      pegi: 12,
      tri: 'prix_desc',
      page: 4,
    }

    const relu = parseFilters(toSearchParams(filters))

    expect(relu).toEqual({ ...filters, genres: ['action', 'rpg'] })
  })

  it('encode les caractères spéciaux de la recherche', () => {
    const query = toSearchParams({ ...DEFAULT_FILTERS, q: 'a&b=c#d' }).toString()

    expect(query).toBe('q=a%26b%3Dc%23d')
    expect(parseFilters(params(query)).q).toBe('a&b=c#d')
  })
})

describe('toApiQuery', () => {
  it('utilise la notation en crochets des listes attendue par Symfony, toujours avec tri et page', () => {
    const query = toApiQuery({ ...DEFAULT_FILTERS, plateformes: ['PSN', 'STEAM'], genres: ['action'], pegi: 16, page: 2 })

    expect(decodeURIComponent(query)).toBe('plateforme[]=PSN&plateforme[]=STEAM&genre[]=action&pegi=16&tri=titre&page=2')
  })

  it('encode une recherche contenant des caractères réservés (pas de paramètre injecté)', () => {
    const query = toApiQuery({ ...DEFAULT_FILTERS, q: 'x&tri=mot_de_passe_hash' })

    expect(new URLSearchParams(query).get('tri')).toBe('titre')
    expect(new URLSearchParams(query).get('q')).toBe('x&tri=mot_de_passe_hash')
  })
})

describe('activeFilterCount et normalizePrice', () => {
  it('compte les critères actifs sans le tri ni la page', () => {
    expect(activeFilterCount(DEFAULT_FILTERS)).toBe(0)
    expect(activeFilterCount({ ...DEFAULT_FILTERS, q: 'a', plateformes: ['PSN', 'STEAM'], pegi: 7, tri: 'note', page: 3 })).toBe(4)
  })

  it('normalise les prix saisis', () => {
    expect(normalizePrice(' 12,50 ')).toBe('12.50')
    expect(normalizePrice('abc')).toBe('')
    expect(normalizePrice('')).toBe('')
  })
})
