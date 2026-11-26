import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { jsonResponse } from '../../test/helpers'
import { GAME, GENRES, json, noContent, OFFER, PLATFORMS, PUBLISHERS, renderAt, stubServer, type Request } from './adminTestKit'
import type { AdminGame, AdminOffer } from './types'

afterEach(() => {
  vi.unstubAllGlobals()
})

/** Faux serveur d'un jeu ouvert dans l'éditeur : référentiels, fiche, et un gestionnaire pour le reste. */
function server(handler: (request: Request) => Response | undefined, game: AdminGame = GAME) {
  return stubServer((request) => {
    const { path, method } = request
    if (path === 'admin/editeurs') return jsonResponse({ editeurs: PUBLISHERS })
    if (path === 'admin/genres') return jsonResponse({ genres: GENRES })
    if (path === 'admin/plateformes') return jsonResponse({ plateformes: PLATFORMS })
    if (path === 'admin/jeux/jeu-1' && method === 'GET') return jsonResponse(game)
    return handler(request)
  })
}

const file = (name: string, type: string, bytes = 1024) => new File([new Uint8Array(bytes)], name, { type })

describe('jaquette — téléversement sécurisé (US-50)', () => {
  it('décrit les contraintes et accepte uniquement le WebP dans le sélecteur', async () => {
    server(() => undefined)
    renderAt('/admin/jeux/jeu-1')

    const input = await screen.findByLabelText('Image de la jaquette')
    expect(input).toHaveAttribute('type', 'file')
    expect(input).toHaveAttribute('accept', 'image/webp')
    expect(input).toHaveAccessibleDescription('WebP uniquement, 512 Kio au plus, de 100 à 4 000 pixels de côté.')
    expect(screen.getByRole('img', { name: 'Pas de jaquette' })).toBeInTheDocument()
  })

  it('refuse sans appeler l’API : aucun fichier, mauvais type, trop lourd', async () => {
    const user = userEvent.setup({ applyAccept: false })   // un fichier forcé hors du filtre du sélecteur
    const { calls } = server(() => undefined)
    renderAt('/admin/jeux/jeu-1')
    const input = await screen.findByLabelText('Image de la jaquette')
    const submit = screen.getByRole('button', { name: 'Téléverser' })

    await user.click(submit)
    expect(await screen.findByRole('alert')).toHaveTextContent('Choisissez une image.')

    await user.upload(input, file('shell.php', 'application/x-php'))
    await user.click(submit)
    expect(await screen.findByText('Seules les images WebP sont acceptées.')).toBeInTheDocument()

    await user.upload(input, file('jaquette.png', 'image/png'))
    await user.click(submit)
    expect(await screen.findByText('Seules les images WebP sont acceptées.')).toBeInTheDocument()

    await user.upload(input, file('lourde.webp', 'image/webp', 513 * 1024))
    await user.click(submit)
    expect(await screen.findByText("L'image dépasse 512 Kio.")).toBeInTheDocument()

    expect(calls.some((c) => c.method === 'POST')).toBe(false)
    expect(input).toHaveAttribute('aria-invalid', 'true')
  })

  it('envoie l’image dans le champ « jaquette » d’un formulaire multipart, sans fixer le Content-Type à la main', async () => {
    const user = userEvent.setup()
    const { calls } = server(({ method, path }) =>
      method === 'POST' && path === 'admin/jeux/jeu-1/jaquette' ? jsonResponse({ ...GAME, jaquette: 'jaquettes/0123456789abcdef0123456789abcdef.webp' }) : undefined,
    )
    renderAt('/admin/jeux/jeu-1')

    await user.upload(await screen.findByLabelText('Image de la jaquette'), file('ma jaquette.webp', 'image/webp'))
    await user.click(screen.getByRole('button', { name: 'Téléverser' }))

    expect(await screen.findByText('Jaquette enregistrée.')).toBeInTheDocument()
    const post = calls.find((c) => c.method === 'POST')!
    expect(post.body).toBeInstanceOf(FormData)
    const sent = (post.body as FormData).get('jaquette')
    expect(sent).toBeInstanceOf(File)
    expect((sent as File).type).toBe('image/webp')
    const headers = post.init.headers as Record<string, string>
    expect(headers['Content-Type']).toBeUndefined()   // le navigateur ajoute la frontière multipart
    expect(headers.Authorization).toBe('Bearer jwt-gestionnaire')
    expect(await screen.findByRole('img', { name: 'Jaquette de Aube Écarlate' })).toHaveAttribute('src', '/media/jaquettes/0123456789abcdef0123456789abcdef.webp')
    expect(await screen.findByRole('button', { name: 'Retirer la jaquette' })).toBeInTheDocument()
  })

  it('affiche le motif donné par l’API quand elle refuse l’image', async () => {
    const user = userEvent.setup()
    server(({ method }) =>
      method === 'POST'
        ? jsonResponse({ message: 'Seules les images WebP valides sont acceptées.' }, 422)
        : undefined,
    )
    renderAt('/admin/jeux/jeu-1')

    await user.upload(await screen.findByLabelText('Image de la jaquette'), file('fausse.webp', 'image/webp'))
    await user.click(screen.getByRole('button', { name: 'Téléverser' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Seules les images WebP valides sont acceptées.')
  })

  it('retire la jaquette après confirmation', async () => {
    const user = userEvent.setup()
    const { calls } = server(
      ({ method, path }) => (method === 'DELETE' && path === 'admin/jeux/jeu-1/jaquette' ? noContent() : undefined),
      { ...GAME, jaquette: 'jaquettes/0123456789abcdef0123456789abcdef.webp' },
    )
    renderAt('/admin/jeux/jeu-1')

    await user.click(await screen.findByRole('button', { name: 'Retirer la jaquette' }))
    expect(calls.some((c) => c.method === 'DELETE')).toBe(false)
    await user.click(screen.getByRole('button', { name: 'Confirmer' }))

    expect(await screen.findByText('Jaquette retirée.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Retirer la jaquette' })).not.toBeInTheDocument()
    expect(screen.getByRole('img', { name: 'Pas de jaquette' })).toBeInTheDocument()
  })
})

describe('écran E12 — offres et verrouillage optimiste (US-51)', () => {
  const offersTable = () => screen.getByRole('table', { name: 'Offres du jeu' })

  it('affiche les offres dans un tableau, prix mis en forme sans calcul, stock fourni par l’API', async () => {
    server(() => undefined)
    renderAt('/admin/jeux/jeu-1')

    const table = await screen.findByRole('table', { name: 'Offres du jeu' })
    expect(within(table).getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Plateforme', 'Prix TTC', "Seuil d'alerte", 'Clés disponibles', 'Statut', 'Actions'])
    const row = within(table).getByRole('row', { name: /Steam/ })
    expect(within(row).getByText(/14,99/)).toBeInTheDocument()
    expect(within(row).getByText('7')).toBeInTheDocument()
    expect(within(row).getByText('Actif')).toBeInTheDocument()
  })

  it('modifie une offre en rendant la version lue, puis affiche la nouvelle', async () => {
    const user = userEvent.setup()
    const { calls } = server(({ method, path, body }) => {
      if (method === 'PUT' && path === 'admin/offres/offre-1') {
        const input = body as { prix: string; seuilAlerte: number; actif: boolean; version: number }
        return jsonResponse({ ...OFFER, prix: input.prix, seuilAlerte: input.seuilAlerte, actif: input.actif, version: input.version + 1 })
      }
      return undefined
    })
    renderAt('/admin/jeux/jeu-1')
    await screen.findByRole('table', { name: 'Offres du jeu' })

    await user.click(screen.getByRole('button', { name: "Modifier l'offre Steam" }))
    const table = offersTable()
    const price = within(table).getByLabelText('Prix TTC en euros')
    expect(price).toHaveValue('14.99')
    await user.clear(price)
    await user.type(price, '12.50')
    await user.click(within(table).getByRole('checkbox', { name: 'Offre en vente' }))
    await user.click(within(table).getByRole('button', { name: 'Enregistrer' }))

    expect(await screen.findByText('Offre Steam enregistrée.')).toBeInTheDocument()
    expect(json(calls.find((c) => c.method === 'PUT')!)).toEqual({ prix: '12.50', seuilAlerte: 5, actif: false, version: 2 })
    expect(within(offersTable()).getByText(/12,50/)).toBeInTheDocument()
    expect(within(offersTable()).getByText('Inactif')).toBeInTheDocument()
  })

  it('refuse un prix nul, négatif ou mal formé sans appeler l’API', async () => {
    const user = userEvent.setup()
    const { calls } = server(() => undefined)
    renderAt('/admin/jeux/jeu-1')
    await screen.findByRole('table', { name: 'Offres du jeu' })

    await user.click(screen.getByRole('button', { name: "Modifier l'offre Steam" }))
    const price = within(offersTable()).getByLabelText('Prix TTC en euros')
    for (const bad of ['0', '0.00', '-5', '14.999', '14,99', 'gratuit', '']) {
      await user.clear(price)
      if (bad !== '') await user.type(price, bad)
      await user.click(within(offersTable()).getByRole('button', { name: 'Enregistrer' }))
      expect(await screen.findByRole('alert', {}, { timeout: 2000 })).toHaveTextContent(/Prix TTC en euros : Exemple : 14\.99/)
    }
    expect(calls.some((c) => c.method === 'PUT')).toBe(false)
  })

  it('CONFLIT : annonce explicitement la modification d’un collègue, ne perd rien, propose de recharger', async () => {
    const user = userEvent.setup()
    let version = 2
    const { calls } = server(({ method, path, body }) => {
      if (method === 'PUT' && path === 'admin/offres/offre-1') {
        const sent = (body as { version: number }).version
        if (sent !== version) {
          return jsonResponse({ message: 'Offre modifiée par un collègue : rechargez la page avant de réessayer.', versionActuelle: version }, 409)
        }
        version += 1
        return jsonResponse({ ...OFFER, prix: (body as { prix: string }).prix, version })
      }
      return undefined
    })
    renderAt('/admin/jeux/jeu-1')
    await screen.findByRole('table', { name: 'Offres du jeu' })

    // Un collègue enregistre pendant que l'écran est ouvert : la version courante passe à 3.
    version = 3
    await user.click(screen.getByRole('button', { name: "Modifier l'offre Steam" }))
    const price = within(offersTable()).getByLabelText('Prix TTC en euros')
    await user.clear(price)
    await user.type(price, '9.99')
    await user.click(within(offersTable()).getByRole('button', { name: 'Enregistrer' }))

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent('Offre modifiée par un collègue : rechargez la page avant de réessayer.')
    expect(alert).toHaveTextContent("Rechargez l'offre pour voir sa dernière version, puis refaites votre modification.")
    expect(within(offersTable()).getByLabelText('Prix TTC en euros')).toHaveValue('9.99')   // la saisie n'est pas perdue
    expect(json(calls.find((c) => c.method === 'PUT')!)).toMatchObject({ version: 2 })

    // « Recharger l'offre » relit le jeu (nouvelle version, nouveau prix du collègue), puis la modification passe.
    const reloaded: AdminGame = { ...GAME, offres: [{ ...OFFER, prix: '11.00', version: 3 }] }
    server(({ method, path, body }) => {
      if (method === 'PUT' && path === 'admin/offres/offre-1') {
        expect((body as { version: number }).version).toBe(3)
        return jsonResponse({ ...OFFER, prix: (body as { prix: string }).prix, version: 4 })
      }
      return undefined
    }, reloaded)
    await user.click(screen.getByRole('button', { name: "Recharger l'offre" }))

    expect(await screen.findByText('Offre rechargée.')).toBeInTheDocument()
    expect(screen.queryByRole('alert')).not.toBeInTheDocument()
    expect(within(offersTable()).getByText(/11,00/)).toBeInTheDocument()

    await user.click(screen.getByRole('button', { name: "Modifier l'offre Steam" }))
    const again = within(offersTable()).getByLabelText('Prix TTC en euros')
    await user.clear(again)
    await user.type(again, '9.99')
    await user.click(within(offersTable()).getByRole('button', { name: 'Enregistrer' }))
    expect(await screen.findByText('Offre Steam enregistrée.')).toBeInTheDocument()
    expect(within(offersTable()).getByText(/9,99/)).toBeInTheDocument()
  })

  it('ne propose pour une nouvelle offre que les plateformes sans offre active, et la crée', async () => {
    const user = userEvent.setup()
    const created: AdminOffer = { id: 'offre-2', plateforme: { id: 'pl-psn', code: 'PSN', libelle: 'PlayStation Store' }, prix: '19.99', seuilAlerte: 3, actif: true, version: 1, stock: 0 }
    const { calls } = server(({ method, path }) => (method === 'POST' && path === 'admin/jeux/jeu-1/offres' ? jsonResponse(created, 201) : undefined))
    renderAt('/admin/jeux/jeu-1')
    await screen.findByRole('table', { name: 'Offres du jeu' })

    const select = screen.getByLabelText('Plateforme')
    expect(within(select).getAllByRole('option').map((o) => o.textContent)).toEqual(['Choisir une plateforme', 'PlayStation Store'])
    await user.selectOptions(select, 'pl-psn')
    await user.type(screen.getAllByLabelText('Prix TTC en euros')[0], '19.99')
    await user.clear(screen.getByLabelText("Seuil d'alerte de stock"))
    await user.type(screen.getByLabelText("Seuil d'alerte de stock"), '3')
    await user.click(screen.getByRole('button', { name: 'Ajouter une offre' }))

    expect(await screen.findByText('Offre ajoutée.')).toBeInTheDocument()
    expect(json(calls.find((c) => c.method === 'POST')!)).toEqual({ plateformeId: 'pl-psn', prix: '19.99', seuilAlerte: 3 })
    expect(within(offersTable()).getByRole('row', { name: /PlayStation Store/ })).toBeInTheDocument()
    await waitFor(() => expect(screen.getByText('Toutes les plateformes ont déjà une offre active.')).toBeInTheDocument())
  })

  it('valide la création côté formulaire et relaie le refus de l’API (offre active déjà présente)', async () => {
    const user = userEvent.setup()
    const { calls } = server(({ method, path }) =>
      method === 'POST' && path === 'admin/jeux/jeu-1/offres' ? jsonResponse({ message: 'Ce jeu a déjà une offre active sur cette plateforme.' }, 409) : undefined,
    )
    renderAt('/admin/jeux/jeu-1')
    await screen.findByRole('table', { name: 'Offres du jeu' })

    await user.click(screen.getByRole('button', { name: 'Ajouter une offre' }))
    const summary = await screen.findByRole('alert')
    expect(within(summary).getByRole('link', { name: /Plateforme : Ce champ est obligatoire/ })).toBeInTheDocument()
    expect(within(summary).getByRole('link', { name: /Prix TTC en euros :/ })).toBeInTheDocument()
    expect(calls.some((c) => c.method === 'POST')).toBe(false)

    await user.selectOptions(screen.getByLabelText('Plateforme'), 'pl-psn')
    await user.type(screen.getAllByLabelText('Prix TTC en euros')[0], '5')
    await user.click(screen.getByRole('button', { name: 'Ajouter une offre' }))
    expect(await screen.findByText('Ce jeu a déjà une offre active sur cette plateforme.')).toBeInTheDocument()
  })

  it('dit qu’un jeu n’a pas encore d’offre', async () => {
    server(() => undefined, { ...GAME, offres: [] })
    renderAt('/admin/jeux/jeu-1')

    expect(await screen.findByText("Ce jeu n'a pas encore d'offre.")).toBeInTheDocument()
    expect(screen.queryByRole('table', { name: 'Offres du jeu' })).not.toBeInTheDocument()
  })
})
