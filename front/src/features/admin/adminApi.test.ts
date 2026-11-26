import { describe, expect, it } from 'vitest'
import { header, jsonResponse, mockApi } from '../../test/helpers'
import {
  createGame,
  createOffer,
  createPlatform,
  createPublisher,
  deactivateGame,
  deletePlatform,
  deletePublisher,
  fetchAdminGame,
  fetchAdminGames,
  fetchGenres,
  fetchPlatforms,
  fetchPublishers,
  removeCover,
  updateGame,
  updateOffer,
  updatePlatform,
  updatePublisher,
  uploadCover,
} from './adminApi'
import type { GameInput } from './types'

const INPUT: GameInput = {
  titre: 'Aube',
  slug: null,
  dateSortie: null,
  pegi: 16,
  editeurId: 'pub-1',
  actif: true,
  descriptions: { fr: 'Une description française.', en: 'An English description.' },
  genres: ['action'],
}

describe('adminApi', () => {
  it('lit les listes de référence', async () => {
    const { fetchFn } = mockApi({
      'GET admin/editeurs': [jsonResponse({ editeurs: [{ id: 'p', nom: 'Aube', nombreJeux: 0 }] })],
      'GET admin/plateformes': [jsonResponse({ plateformes: [{ id: 'pl', code: 'STEAM', libelle: 'Steam', nombreOffres: 1 }] })],
      'GET admin/genres': [jsonResponse({ genres: [{ slug: 'action', libelle: 'Action' }] })],
    })

    expect((await fetchPublishers({ fetchFn }))[0].nom).toBe('Aube')
    expect((await fetchPlatforms({ fetchFn }))[0].code).toBe('STEAM')
    expect((await fetchGenres({ fetchFn }))[0].slug).toBe('action')
  })

  it('envoie les écritures d’éditeurs et de plateformes avec la bonne méthode et un corps JSON limité aux champs utiles', async () => {
    const { fetchFn, calls } = mockApi({
      'POST admin/editeurs': [jsonResponse({}, 201)],
      'PUT admin/editeurs/p1': [jsonResponse({})],
      'DELETE admin/editeurs/p1': [new Response(null, { status: 204 })],
      'POST admin/plateformes': [jsonResponse({}, 201)],
      'PUT admin/plateformes/pl1': [jsonResponse({})],
      'DELETE admin/plateformes/pl1': [new Response(null, { status: 204 })],
    })

    await createPublisher('Aube', { fetchFn })
    await updatePublisher('p1', 'Aube 2', { fetchFn })
    await expect(deletePublisher('p1', { fetchFn })).resolves.toBeUndefined()
    await createPlatform('STEAM', 'Steam', { fetchFn })
    await updatePlatform('pl1', 'Steam PC', { fetchFn })
    await deletePlatform('pl1', { fetchFn })

    expect(calls.map((c) => c.init.body)).toEqual([
      '{"nom":"Aube"}',
      '{"nom":"Aube 2"}',
      undefined,
      '{"code":"STEAM","libelle":"Steam"}',
      '{"libelle":"Steam PC"}',
      undefined,
    ])
    expect(header(calls[0], 'Content-Type')).toBe('application/json')
  })

  it('construit la requête de liste des jeux : paramètres omis quand ils valent leur défaut', async () => {
    const { fetchFn, calls } = mockApi({
      'GET admin/jeux': [jsonResponse({})],
      'GET admin/jeux?q=%C3%A9toile+noire&statut=inactifs&page=3': [jsonResponse({})],
    })

    await fetchAdminGames({ q: '  ', statut: 'tous', page: 1 }, { fetchFn })
    await fetchAdminGames({ q: ' étoile noire ', statut: 'inactifs', page: 3 }, { fetchFn })

    expect(calls.map((c) => c.url)).toEqual(['/api/admin/jeux', '/api/admin/jeux?q=%C3%A9toile+noire&statut=inactifs&page=3'])
  })

  it('encode les identifiants placés dans un chemin (aucune traversée possible depuis une valeur d’URL)', async () => {
    const { fetchFn, calls } = mockApi({
      'GET admin/jeux/..%2F..%2Fadmin%2Fx%3Fa%3D1': [jsonResponse({})],
      'DELETE admin/jeux/a%20b': [new Response(null, { status: 204 })],
    })

    await fetchAdminGame('../../admin/x?a=1', { fetchFn })
    await deactivateGame('a b', { fetchFn })

    expect(calls[0].url).toBe('/api/admin/jeux/..%2F..%2Fadmin%2Fx%3Fa%3D1')
  })

  it('crée et modifie un jeu avec le corps du formulaire', async () => {
    const { fetchFn, calls } = mockApi({ 'POST admin/jeux': [jsonResponse({}, 201)], 'PUT admin/jeux/j1': [jsonResponse({})] })

    await createGame(INPUT, { fetchFn })
    await updateGame('j1', INPUT, { fetchFn })

    expect(JSON.parse(String(calls[0].init.body))).toEqual(INPUT)
    expect(calls[1].init.method).toBe('PUT')
  })

  it('téléverse la jaquette en multipart dans le champ « jaquette » et la retire', async () => {
    const { fetchFn, calls } = mockApi({
      'POST admin/jeux/j1/jaquette': [jsonResponse({})],
      'DELETE admin/jeux/j1/jaquette': [new Response(null, { status: 204 })],
    })
    const image = new File([new Uint8Array(10)], 'x.webp', { type: 'image/webp' })

    await uploadCover('j1', image, { fetchFn })
    await removeCover('j1', { fetchFn })

    const body = calls[0].init.body
    expect(body).toBeInstanceOf(FormData)
    expect((body as FormData).get('jaquette')).toBeInstanceOf(File)
    expect(header(calls[0], 'Content-Type')).toBeUndefined()
  })

  it('crée une offre, et modifie une offre en rendant la version lue', async () => {
    const { fetchFn, calls } = mockApi({ 'POST admin/jeux/j1/offres': [jsonResponse({}, 201)], 'PUT admin/offres/o1': [jsonResponse({})] })

    await createOffer('j1', 'pl1', { prix: '14.99', seuilAlerte: 3 }, { fetchFn })
    await updateOffer('o1', { prix: '12.00', seuilAlerte: 3, actif: false }, 7, { fetchFn })

    expect(JSON.parse(String(calls[0].init.body))).toEqual({ plateformeId: 'pl1', prix: '14.99', seuilAlerte: 3 })
    expect(JSON.parse(String(calls[1].init.body))).toEqual({ prix: '12.00', seuilAlerte: 3, actif: false, version: 7 })
  })
})
