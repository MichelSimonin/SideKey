import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, useLocation } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import App from '../../App'
import { jsonResponse } from '../../test/helpers'
import type { FilterValues, GamePage, GameSummary } from './types'

const VALUES: FilterValues = {
  plateformes: [
    { code: 'PSN', libelle: 'PlayStation Store' },
    { code: 'STEAM', libelle: 'Steam' },
  ],
  genres: [
    { slug: 'action', libelle: 'Action' },
    { slug: 'rpg', libelle: 'RPG' },
  ],
  prixMin: '9.99',
  prixMax: '54.99',
  pegi: [3, 7, 12, 16, 18],
  tris: ['titre', 'nouveaute', 'prix_asc', 'prix_desc', 'note'],
}

const game = (n: number, extra: Partial<GameSummary> = {}): GameSummary => ({
  slug: `jeu-${n}`,
  titre: `Jeu ${n}`,
  pegi: 12,
  jaquette: `jaquettes/jeu-${n}.webp`,
  plateformes: ['STEAM'],
  prixMin: '14.99',
  disponible: true,
  noteMoyenne: null,
  nombreAvis: 0,
  ...extra,
})

const page = (jeux: GameSummary[], total = jeux.length, pageNumber = 1): GamePage => ({
  jeux,
  total,
  page: pageNumber,
  parPage: 20,
  nombrePages: Math.max(1, Math.ceil(total / 20)),
})

function Location() {
  const location = useLocation()
  return <p data-testid="location">{location.pathname + location.search}</p>
}

function renderAt(path: string) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <App />
      <Location />
    </MemoryRouter>,
  )
}

/** Faux serveur : répond à /api/catalogue/filtres et /api/jeux ; mémorise les URL appelées. */
function stubApi(handler: (query: URLSearchParams) => Response) {
  const urls: string[] = []
  const fn = vi.fn(async (input: RequestInfo | URL) => {
    const url = String(input)
    urls.push(url)
    if (url.startsWith('/api/catalogue/filtres')) return jsonResponse(VALUES)
    if (url.startsWith('/api/jeux')) return handler(new URL(url, 'https://localhost').searchParams)
    return jsonResponse({}, 404)
  })
  vi.stubGlobal('fetch', fn)
  return { urls, fn }
}

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('E02 — catalogue', () => {
  it('affiche la grille, le nombre de résultats annoncé et les cartes en texte', async () => {
    stubApi(() => jsonResponse(page([game(1, { disponible: false, noteMoyenne: 3.5, nombreAvis: 2 }), game(2)], 39)))
    renderAt('/catalogue')

    expect(await screen.findByRole('heading', { level: 1, name: 'Catalogue' })).toBeInTheDocument()
    const status = await screen.findByText('39 jeux trouvés')
    expect(status).toHaveAttribute('aria-live', 'polite')
    expect(status).toHaveAttribute('role', 'status')

    const items = screen.getAllByRole('listitem').filter((li) => li.classList.contains('game-card'))
    expect(items).toHaveLength(2)
    const first = within(items[0])
    expect(first.getByRole('link', { name: 'Jeu 1' })).toHaveAttribute('href', '/jeux/jeu-1')
    expect(first.getByText('PEGI 12')).toBeInTheDocument()
    expect(first.getByText('Plateformes : STEAM')).toBeInTheDocument()
    expect(first.getByText(/À partir de 14,99/)).toBeInTheDocument()
    expect(first.getByText('Rupture de stock')).toBeInTheDocument()
    expect(first.getByText('Note 3,5 sur 5 (2 avis)')).toBeInTheDocument()
    expect(within(items[1]).getByText('Disponible')).toBeInTheDocument()
    expect(within(items[1]).getByText('Pas encore noté')).toBeInTheDocument()
  })

  it('annonce « un jeu » au singulier', async () => {
    stubApi(() => jsonResponse(page([game(1)], 1)))
    renderAt('/catalogue')

    expect(await screen.findByText('1 jeu trouvé')).toBeInTheDocument()
  })

  it('affiche un message explicite quand aucun jeu ne correspond', async () => {
    stubApi(() => jsonResponse(page([], 0)))
    renderAt('/catalogue?q=introuvable')

    const status = await screen.findByText(/Aucun jeu ne correspond à votre recherche/)
    expect(status).toHaveAttribute('role', 'status')
    expect(document.querySelector('.game-grid')).toBeNull()
  })

  it('regroupe les filtres en fieldset avec legend et étiquette tous les champs', async () => {
    stubApi(() => jsonResponse(page([game(1)])))
    renderAt('/catalogue')

    const form = await screen.findByRole('search', { name: 'Affiner la recherche' })
    expect(await within(form).findByRole('group', { name: 'Plateformes' })).toBeInTheDocument()
    expect(within(form).getByRole('group', { name: 'Genres' })).toBeInTheDocument()
    expect(within(form).getByRole('group', { name: 'Prix (en euros)' })).toBeInTheDocument()
    expect(within(form).getByRole('checkbox', { name: 'Steam' })).toBeInTheDocument()
    expect(within(form).getByRole('checkbox', { name: 'RPG' })).toBeInTheDocument()
    expect(within(form).getByLabelText('Rechercher un titre')).toBeInTheDocument()
    expect(within(form).getByLabelText('Minimum')).toHaveAttribute('inputmode', 'decimal')
    expect(within(form).getByLabelText('Classification PEGI maximale')).toBeInTheDocument()
    expect(within(form).getByLabelText('Trier par')).toBeInTheDocument()
  })

  it('reflète les critères appliqués dans l’URL et interroge l’API avec les mêmes critères', async () => {
    const user = userEvent.setup()
    const { urls } = stubApi(() => jsonResponse(page([game(1)])))
    renderAt('/catalogue')

    await user.type(await screen.findByLabelText('Rechercher un titre'), 'aube')
    await user.click(await screen.findByRole('checkbox', { name: 'Steam' }))
    await user.click(screen.getByRole('checkbox', { name: 'RPG' }))
    await user.type(screen.getByLabelText('Maximum'), '30,5')
    await user.selectOptions(screen.getByLabelText('Classification PEGI maximale'), '16')
    await user.selectOptions(screen.getByLabelText('Trier par'), 'prix_asc')
    await user.click(screen.getByRole('button', { name: 'Appliquer les filtres' }))

    await waitFor(() =>
      expect(screen.getByTestId('location')).toHaveTextContent(
        '/catalogue?q=aube&plateforme=STEAM&genre=rpg&prix_max=30.5&pegi=16&tri=prix_asc',
      ),
    )
    await waitFor(() => {
      const appel = urls.filter((u) => u.startsWith('/api/jeux')).at(-1) ?? ''
      expect(decodeURIComponent(appel)).toBe(
        '/api/jeux?q=aube&plateforme[]=STEAM&genre[]=rpg&prix_max=30.5&pegi=16&tri=prix_asc&page=1',
      )
    })
  })

  it('relit les critères depuis l’URL (lien partagé) et coche les cases correspondantes', async () => {
    const { urls } = stubApi(() => jsonResponse(page([game(1)])))
    renderAt('/catalogue?q=aube&plateforme=PSN&genre=action&pegi=7&tri=note&prix_min=5')

    expect(await screen.findByLabelText('Rechercher un titre')).toHaveValue('aube')
    expect(await screen.findByRole('checkbox', { name: 'PlayStation Store' })).toBeChecked()
    expect(screen.getByRole('checkbox', { name: 'Action' })).toBeChecked()
    expect(screen.getByRole('checkbox', { name: 'Steam' })).not.toBeChecked()
    expect(screen.getByLabelText('Classification PEGI maximale')).toHaveValue('7')
    expect(screen.getByLabelText('Trier par')).toHaveValue('note')
    expect(screen.getByLabelText('Minimum')).toHaveValue('5')
    expect(decodeURIComponent(urls.find((u) => u.startsWith('/api/jeux?')) ?? '')).toBe(
      '/api/jeux?q=aube&plateforme[]=PSN&genre[]=action&prix_min=5&pegi=7&tri=note&page=1',
    )
  })

  it('assainit une URL hostile avant d’appeler l’API', async () => {
    const { urls } = stubApi(() => jsonResponse(page([game(1)])))
    renderAt('/catalogue?tri=mot_de_passe_hash&pegi=99&page=-3&plateforme=x%27%20OR%201%3D1')

    await screen.findByText('1 jeu trouvé')
    const appel = decodeURIComponent(urls.find((u) => u.startsWith('/api/jeux?')) ?? '')
    expect(appel).toBe('/api/jeux?tri=titre&page=1')
  })

  it('propose de réinitialiser les filtres seulement quand il y en a', async () => {
    const user = userEvent.setup()
    stubApi(() => jsonResponse(page([game(1)])))
    renderAt('/catalogue?genre=action')

    await user.click(await screen.findByRole('button', { name: 'Réinitialiser les filtres' }))

    await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent(/^\/catalogue$/))
    expect(screen.queryByRole('button', { name: 'Réinitialiser les filtres' })).not.toBeInTheDocument()
  })

  it('pagine avec de vrais liens, conserve les critères et signale la page courante', async () => {
    stubApi(() => jsonResponse(page([game(1)], 45, 2)))
    renderAt('/catalogue?genre=action&page=2')

    const nav = await screen.findByRole('navigation', { name: 'Pagination des résultats' })
    expect(within(nav).getByRole('link', { name: 'Page 2' })).toHaveAttribute('aria-current', 'page')
    expect(within(nav).getByRole('link', { name: 'Page précédente' })).toHaveAttribute('href', '/catalogue?genre=action')
    expect(within(nav).getByRole('link', { name: 'Page suivante' })).toHaveAttribute('href', '/catalogue?genre=action&page=3')
    expect(within(nav).getByText('Page 2 sur 3')).toBeInTheDocument()
  })

  it('n’affiche pas de pagination pour une seule page', async () => {
    stubApi(() => jsonResponse(page([game(1)], 5)))
    renderAt('/catalogue')

    await screen.findByText('5 jeux trouvés')
    expect(screen.queryByRole('navigation', { name: 'Pagination des résultats' })).not.toBeInTheDocument()
  })

  it('indique qu’une page hors bornes n’existe pas et propose de revenir à la première', async () => {
    stubApi(() => jsonResponse({ message: 'Cette page de résultats n’existe pas.' }, 404))
    renderAt('/catalogue?page=9')

    expect(await screen.findByRole('alert')).toHaveTextContent('Cette page de résultats n\'existe pas.')
    expect(screen.getByRole('link', { name: 'Revenir à la première page' })).toHaveAttribute('href', '/catalogue')
  })

  it('affiche une erreur avec « Réessayer » quand l’API est indisponible', async () => {
    const user = userEvent.setup()
    let calls = 0
    stubApi(() => (++calls === 1 ? jsonResponse({}, 503) : jsonResponse(page([game(1)]))))
    renderAt('/catalogue')

    expect(await screen.findByRole('alert')).toHaveTextContent('momentanément indisponible')
    await user.click(screen.getByRole('button', { name: 'Réessayer' }))

    expect(await screen.findByText('1 jeu trouvé')).toBeInTheDocument()
  })

  it('charge les jaquettes en différé avec des dimensions déclarées (éco-conception)', async () => {
    stubApi(() => jsonResponse(page([game(1), game(2)])))
    renderAt('/catalogue')

    await screen.findByText('2 jeux trouvés')
    const images = document.querySelectorAll('.game-card img')
    expect(images).toHaveLength(2)
    images.forEach((img) => {
      expect(img).toHaveAttribute('loading', 'lazy')
      expect(img).toHaveAttribute('decoding', 'async')
      expect(img).toHaveAttribute('width', '300')
      expect(img).toHaveAttribute('height', '400')
      expect(img).toHaveAttribute('alt', '')
      expect(img.getAttribute('src')).toMatch(/\/media\/jaquettes\/jeu-\d\.webp$/)
    })
  })

  it('remplace une jaquette absente ou en erreur par un bloc de texte léger', async () => {
    stubApi(() => jsonResponse(page([game(1, { jaquette: null })])))
    renderAt('/catalogue')

    await screen.findByText('1 jeu trouvé')
    expect(document.querySelector('.game-card img')).toBeNull()
    expect(document.querySelector('.game-card .cover-missing')).not.toBeNull()
  })

  it('n’injecte jamais de HTML venu de l’API : un titre piégé s’affiche comme du texte', async () => {
    stubApi(() => jsonResponse(page([game(1, { titre: '<img src=x onerror=alert(1)>Piège' })])))
    renderAt('/catalogue')

    expect(await screen.findByRole('link', { name: '<img src=x onerror=alert(1)>Piège' })).toBeInTheDocument()
    expect(document.querySelector('.game-card h2 img')).toBeNull()
  })

  it('annonce les résultats en anglais et traduit les tris', async () => {
    const user = userEvent.setup()
    stubApi(() => jsonResponse(page([game(1)], 3)))
    renderAt('/catalogue')
    await screen.findByText('3 jeux trouvés')

    await user.click(screen.getByRole('button', { name: 'English' }))

    expect(await screen.findByText('3 games found')).toBeInTheDocument()
    expect(screen.getByRole('option', { name: 'Price: low to high' })).toBeInTheDocument()
    expect(screen.getByText(/From €14\.99/)).toBeInTheDocument()
  })
})
