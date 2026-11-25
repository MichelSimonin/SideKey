import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import App from '../../App'
import { jsonResponse } from '../../test/helpers'
import type { GameDetail } from './types'

const DETAIL: GameDetail = {
  slug: 'jeu-essai-01',
  titre: "Aube d'Écarlate",
  description: 'Une aventure au bord du monde.\n\nDeuxième paragraphe.',
  pegi: 7,
  jaquette: 'jaquettes/jeu-01.webp',
  dateSortie: '2020-02-21',
  editeur: 'Éditions du Phare',
  genres: [
    { slug: 'action', libelle: 'Action' },
    { slug: 'rpg', libelle: 'RPG' },
  ],
  offres: [
    { offreId: '11111111-1111-4111-8111-111111111111', plateforme: 'PSN', plateformeLibelle: 'PlayStation Store', prix: '14.99', stock: 12 },
    { offreId: '22222222-2222-4222-8222-222222222222', plateforme: 'STEAM', plateformeLibelle: 'Steam', prix: '19.99', stock: 1 },
    { offreId: '33333333-3333-4333-8333-333333333333', plateforme: 'XBOX', plateformeLibelle: 'Xbox Store', prix: '24.99', stock: 0 },
  ],
  noteMoyenne: 3,
  nombreAvis: 2,
}

function renderAt(path: string) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <App />
    </MemoryRouter>,
  )
}

function stubApi(handler: (url: string, init: RequestInit) => Response | Promise<Response>) {
  const fn = vi.fn(async (input: RequestInfo | URL, init: RequestInit = {}) => handler(String(input), init))
  vi.stubGlobal('fetch', fn)
  return fn
}

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('E03 — fiche jeu', () => {
  it('affiche titre, éditeur, date, genres, PEGI en texte, note et description', async () => {
    stubApi(() => jsonResponse(DETAIL))
    renderAt('/jeux/jeu-essai-01')

    expect(await screen.findByRole('heading', { level: 1, name: "Aube d'Écarlate" })).toBeInTheDocument()
    expect(screen.getByText('PEGI 7 : à partir de 7 ans')).toBeInTheDocument()
    expect(screen.getByText('Éditions du Phare')).toBeInTheDocument()
    expect(screen.getByText('21 février 2020')).toBeInTheDocument()
    expect(screen.getByText('Action, RPG')).toBeInTheDocument()
    expect(screen.getByText('Note moyenne : 3,0 / 5 (2 avis)')).toBeInTheDocument()
    expect(screen.getByText(/Une aventure au bord du monde/)).toBeInTheDocument()
  })

  it('décrit la jaquette en texte alternatif, chargée en différé avec ses dimensions', async () => {
    stubApi(() => jsonResponse(DETAIL))
    renderAt('/jeux/jeu-essai-01')

    const image = await screen.findByRole('img', { name: "Jaquette de Aube d'Écarlate" })
    expect(image).toHaveAttribute('loading', 'lazy')
    expect(image).toHaveAttribute('width', '300')
    expect(image).toHaveAttribute('height', '400')
  })

  it('liste les offres par plateforme avec prix et stock en toutes lettres', async () => {
    stubApi(() => jsonResponse(DETAIL))
    renderAt('/jeux/jeu-essai-01')

    await screen.findByRole('heading', { level: 1, name: "Aube d'Écarlate" })
    const offers = screen.getAllByRole('heading', { level: 3 })
    expect(offers.map((h) => h.textContent)).toEqual(['PlayStation Store', 'Steam', 'Xbox Store'])
    expect(screen.getByText('12 clés disponibles')).toBeInTheDocument()
    expect(screen.getByText('1 clé disponible')).toBeInTheDocument()
    expect(screen.getByText('Rupture de stock')).toBeInTheDocument()
    expect(screen.getByText(/14,99/)).toBeInTheDocument()
  })

  it('désactive l’ajout au panier d’une offre en rupture', async () => {
    stubApi(() => jsonResponse(DETAIL))
    renderAt('/jeux/jeu-essai-01')

    expect(await screen.findByRole('button', { name: "Ajouter au panier : Aube d'Écarlate sur Xbox Store" })).toBeDisabled()
    expect(screen.getByRole('button', { name: "Ajouter au panier : Aube d'Écarlate sur Steam" })).toBeEnabled()
  })

  it('ajoute une clé de l’offre au panier et annonce le résultat', async () => {
    const user = userEvent.setup()
    const fn = stubApi((url, init) => {
      if (url === '/api/panier/lignes' && init.method === 'POST') return jsonResponse({ nombreArticles: 1 })
      return jsonResponse(DETAIL)
    })
    renderAt('/jeux/jeu-essai-01')

    await user.click(await screen.findByRole('button', { name: "Ajouter au panier : Aube d'Écarlate sur Steam" }))

    expect(await screen.findByText('Ajouté au panier.')).toBeInTheDocument()
    const appel = fn.mock.calls.find(([url]) => url === '/api/panier/lignes')
    expect(JSON.parse(String(appel?.[1]?.body))).toEqual({ offreId: '22222222-2222-4222-8222-222222222222', quantite: 1 })
    expect(String(appel?.[1]?.body)).not.toMatch(/prix|montant|total/i)   // le prix n'est jamais envoyé : l'API le relit en base
  })

  it('affiche le message de l’API quand l’ajout est refusé (stock insuffisant)', async () => {
    const user = userEvent.setup()
    stubApi((url, init) =>
      url === '/api/panier/lignes' && init.method === 'POST'
        ? jsonResponse({ message: 'Stock insuffisant : 1 clé(s) disponible(s) pour cette offre.' }, 409)
        : jsonResponse(DETAIL),
    )
    renderAt('/jeux/jeu-essai-01')

    await user.click(await screen.findByRole('button', { name: "Ajouter au panier : Aube d'Écarlate sur Steam" }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Stock insuffisant')
  })

  it('affiche « jeu introuvable » sur un 404', async () => {
    stubApi(() => jsonResponse({ message: 'Ce jeu n’existe pas.' }, 404))
    renderAt('/jeux/inconnu')

    expect(await screen.findByRole('heading', { level: 1, name: 'Jeu introuvable' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Retour au catalogue' })).toHaveAttribute('href', '/catalogue')
  })

  it('propose de réessayer quand l’API est indisponible', async () => {
    const user = userEvent.setup()
    let appels = 0
    stubApi(() => (++appels === 1 ? jsonResponse({}, 503) : jsonResponse(DETAIL)))
    renderAt('/jeux/jeu-essai-01')

    expect(await screen.findByRole('alert')).toHaveTextContent('momentanément indisponible')
    await user.click(screen.getByRole('button', { name: 'Réessayer' }))

    expect(await screen.findByRole('heading', { level: 1, name: "Aube d'Écarlate" })).toBeInTheDocument()
  })

  it('encode le slug de l’URL avant l’appel à l’API (aucun chemin forgé)', async () => {
    const fn = stubApi(() => jsonResponse({}, 404))
    renderAt('/jeux/a%2F..%2Fb')

    await screen.findByRole('heading', { level: 1, name: 'Jeu introuvable' })
    expect(String(fn.mock.calls[0][0])).toBe('/api/jeux/a%2F..%2Fb')
  })

  it('n’interprète jamais le HTML de la description ni du titre (pas de dangerouslySetInnerHTML)', async () => {
    stubApi(() =>
      jsonResponse({ ...DETAIL, titre: '<b>Gras</b>', description: '<script>alert(1)</script><img src=x onerror=alert(1)>' }),
    )
    renderAt('/jeux/jeu-essai-01')

    await screen.findByRole('heading', { level: 1, name: '<b>Gras</b>' })
    expect(screen.getByText(/<script>alert\(1\)<\/script>/)).toBeInTheDocument()
    expect(document.querySelector('article script')).toBeNull()
    expect(document.querySelector('article b')).toBeNull()
    expect(document.querySelector('article img[onerror]')).toBeNull()
  })

  it('se traduit en anglais (description, genres et libellés de l’interface)', async () => {
    const user = userEvent.setup()
    stubApi((url, init) => {
      void init
      return jsonResponse(url.startsWith('/api/jeux/') ? { ...DETAIL, genres: [{ slug: 'action', libelle: 'Action' }] } : {})
    })
    renderAt('/jeux/jeu-essai-01')
    await screen.findByRole('heading', { level: 1, name: "Aube d'Écarlate" })

    await user.click(screen.getByRole('button', { name: 'English' }))

    await waitFor(() => expect(screen.getByText('PEGI 7: ages 7 and over')).toBeInTheDocument())
    expect(screen.getByText('February 21, 2020')).toBeInTheDocument()
    expect(screen.getByText('12 keys available')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: "Add to cart: Aube d'Écarlate on Steam" })).toBeInTheDocument()
  })
})
