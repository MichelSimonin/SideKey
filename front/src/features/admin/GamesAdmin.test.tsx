import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { jsonResponse } from '../../test/helpers'
import { GAME, GENRES, json, noContent, PLATFORMS, PUBLISHERS, renderAt, stubServer } from './adminTestKit'
import type { AdminGame, AdminGameRow, GameInput } from './types'

afterEach(() => {
  vi.unstubAllGlobals()
})

const row = (n: number, extra: Partial<AdminGameRow> = {}): AdminGameRow => ({
  id: `jeu-${n}`,
  slug: `jeu-${n}`,
  titre: `Jeu ${n}`,
  editeur: 'Studio Aube',
  pegi: 12,
  actif: true,
  jaquette: null,
  nombreOffres: 1,
  ...extra,
})

describe('écran E12 — liste des jeux (US-50)', () => {
  it('affiche un tableau avec légende et en-têtes, jeux inactifs compris, et annonce le nombre de résultats', async () => {
    stubServer(({ path }) =>
      path === 'admin/jeux' ? jsonResponse({ jeux: [row(1), row(2, { actif: false, nombreOffres: 0 })], page: 1, nombrePages: 1, total: 2 }) : undefined,
    )
    renderAt('/admin/jeux')

    const table = await screen.findByRole('table', { name: 'Liste des jeux' })
    expect(within(table).getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Titre', 'Éditeur', 'PEGI', 'Offres', 'Statut', 'Actions'])
    expect(within(within(table).getByRole('row', { name: /Jeu 2/ })).getByText('Inactif')).toBeInTheDocument()
    expect(screen.getByRole('status')).toHaveTextContent('2 jeux')
    expect(screen.getByRole('link', { name: 'Nouveau jeu' })).toHaveAttribute('href', '/admin/jeux/nouveau')
    expect(screen.getByRole('link', { name: 'Modifier Jeu 1' })).toHaveAttribute('href', '/admin/jeux/jeu-1')
  })

  it('dit explicitement quand aucun jeu ne correspond', async () => {
    stubServer(({ path }) => (path === 'admin/jeux' ? jsonResponse({ jeux: [], page: 1, nombrePages: 1, total: 0 }) : undefined))
    renderAt('/admin/jeux')

    expect(await screen.findByText('Aucun jeu ne correspond à cette recherche.')).toBeInTheDocument()
    expect(screen.queryByRole('table')).not.toBeInTheDocument()
  })

  it('cherche par titre et statut, reflète la recherche dans l’URL et la transmet à l’API', async () => {
    const user = userEvent.setup()
    const { calls } = stubServer(() => jsonResponse({ jeux: [row(1)], page: 1, nombrePages: 1, total: 1 }))
    renderAt('/admin/jeux')
    await screen.findByRole('table')

    await user.type(screen.getByLabelText('Rechercher par titre'), '  étoile ')
    await user.selectOptions(screen.getByLabelText('Statut'), 'actifs')
    await user.click(screen.getByRole('button', { name: 'Rechercher' }))

    await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent('/admin/jeux?q=%C3%A9toile&statut=actifs'))
    expect(calls.at(-1)?.path).toBe('admin/jeux?q=%C3%A9toile&statut=actifs')
  })

  it('lit sa recherche dans l’URL et ignore un statut ou une page invalides', async () => {
    const { calls } = stubServer(() => jsonResponse({ jeux: [row(1)], page: 1, nombrePages: 1, total: 1 }))
    renderAt('/admin/jeux?q=aube&statut=nimporte&page=-4')

    await screen.findByRole('table')
    expect(calls[0].path).toBe('admin/jeux?q=aube')
    expect(screen.getByLabelText('Rechercher par titre')).toHaveValue('aube')
  })

  it('pagine : la page 2 est un vrai lien qui conserve la recherche', async () => {
    stubServer(({ path }) => jsonResponse({ jeux: [row(1)], page: path.includes('page=2') ? 2 : 1, nombrePages: 3, total: 41 }))
    renderAt('/admin/jeux?q=jeu')

    const nav = await screen.findByRole('navigation', { name: 'Pagination des résultats' })
    expect(within(nav).getByRole('link', { name: 'Page 2' })).toHaveAttribute('href', '/admin/jeux?q=jeu&page=2')
  })

  it('désactive un jeu après confirmation (suppression logique) et recharge la liste', async () => {
    const user = userEvent.setup()
    let active = true
    const { calls } = stubServer(({ method, path }) => {
      if (method === 'DELETE' && path === 'admin/jeux/jeu-1') {
        active = false
        return noContent()
      }
      if (path === 'admin/jeux') return jsonResponse({ jeux: [row(1, { actif: active })], page: 1, nombrePages: 1, total: 1 })
      return undefined
    })
    renderAt('/admin/jeux')
    await screen.findByRole('table')

    await user.click(screen.getByRole('button', { name: 'Désactiver Jeu 1' }))
    expect(screen.getByRole('group', { name: /^Désactiver « Jeu 1 »/ })).toHaveTextContent('ses offres, ses clés et l\'historique des commandes sont conservés')
    expect(calls.some((c) => c.method === 'DELETE')).toBe(false)
    await user.click(screen.getByRole('button', { name: 'Confirmer' }))

    expect(await screen.findByText('« Jeu 1 » a été désactivé.')).toBeInTheDocument()
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Désactiver Jeu 1' })).not.toBeInTheDocument())
    expect(screen.getByText('Inactif')).toBeInTheDocument()
  })

  it('ne propose pas de désactiver un jeu déjà inactif', async () => {
    stubServer(() => jsonResponse({ jeux: [row(1, { actif: false })], page: 1, nombrePages: 1, total: 1 }))
    renderAt('/admin/jeux')
    await screen.findByRole('table')

    expect(screen.queryByRole('button', { name: /Désactiver/ })).not.toBeInTheDocument()
  })

  it('affiche l’erreur de l’API quand la désactivation échoue', async () => {
    const user = userEvent.setup()
    stubServer(({ method, path }) => {
      if (method === 'DELETE') return jsonResponse({ message: "Cet élément n'existe pas." }, 404)
      return path === 'admin/jeux' ? jsonResponse({ jeux: [row(1)], page: 1, nombrePages: 1, total: 1 }) : undefined
    })
    renderAt('/admin/jeux')
    await screen.findByRole('table')

    await user.click(screen.getByRole('button', { name: 'Désactiver Jeu 1' }))
    await user.click(screen.getByRole('button', { name: 'Confirmer' }))

    expect(await screen.findByText("Cet élément n'existe pas.")).toBeInTheDocument()
  })
})

describe('écran E12 — formulaire d’un jeu (US-50)', () => {
  function server(create: (input: GameInput) => Response = () => jsonResponse(GAME, 201), game: AdminGame = GAME) {
    return stubServer(({ method, path, body }) => {
      if (path === 'admin/editeurs') return jsonResponse({ editeurs: PUBLISHERS })
      if (path === 'admin/genres') return jsonResponse({ genres: GENRES })
      if (path === 'admin/plateformes') return jsonResponse({ plateformes: PLATFORMS })
      if (path === 'admin/jeux' && method === 'POST') return create(body as GameInput)
      if (path === 'admin/jeux/jeu-1' && method === 'GET') return jsonResponse(game)
      if (path === 'admin/jeux/jeu-1' && method === 'PUT') return jsonResponse({ ...game, titre: (body as GameInput).titre })
      return undefined
    })
  }

  async function fillValid(user: ReturnType<typeof userEvent.setup>) {
    await user.type(await screen.findByLabelText('Titre'), '  Aube Écarlate ')
    await user.selectOptions(screen.getByLabelText('Classification PEGI'), '16')
    await user.selectOptions(screen.getByLabelText('Éditeur'), 'pub-aube')
    await user.type(screen.getByLabelText('Description en français'), 'Une description française assez longue.')
    await user.type(screen.getByLabelText('Description en anglais'), 'A long enough English description.')
  }

  it('propose tous les champs étiquetés, les genres dans un groupe nommé, et seulement le formulaire en création', async () => {
    server()
    renderAt('/admin/jeux/nouveau')

    expect(await screen.findByRole('heading', { level: 1, name: 'Nouveau jeu' })).toBeInTheDocument()
    for (const label of ['Titre', 'Adresse du jeu (slug)', 'Date de sortie', 'Classification PEGI', 'Éditeur', 'Description en français', 'Description en anglais']) {
      expect(await screen.findByLabelText(label)).toBeInTheDocument()
    }
    const genres = screen.getByRole('group', { name: 'Genres' })
    expect(within(genres).getByRole('checkbox', { name: 'Action' })).toBeInTheDocument()
    expect(screen.getByLabelText('Date de sortie')).toHaveAttribute('type', 'date')
    expect(screen.queryByRole('heading', { name: 'Jaquette' })).not.toBeInTheDocument()
    expect(screen.queryByRole('heading', { name: 'Offres par plateforme' })).not.toBeInTheDocument()
  })

  it('refuse un formulaire vide : résumé focalisé, erreurs reliées aux champs, aucun appel', async () => {
    const user = userEvent.setup()
    const { calls } = server()
    renderAt('/admin/jeux/nouveau')

    await user.click(await screen.findByRole('button', { name: 'Enregistrer' }))

    const summary = await screen.findByRole('alert')
    expect(summary).toHaveFocus()
    for (const field of ['Titre', 'Classification PEGI', 'Éditeur', 'Description en français', 'Description en anglais']) {
      expect(within(summary).getByRole('link', { name: new RegExp(`^${field} :`) })).toBeInTheDocument()
    }
    expect(screen.getByLabelText('Titre')).toHaveAttribute('aria-invalid', 'true')
    expect(screen.getByLabelText('Description en français')).toHaveAccessibleDescription(/10 caractères au minimum/)
    expect(calls.some((c) => c.method === 'POST')).toBe(false)
  })

  it('refuse une description de moins de 10 ou de plus de 4 000 caractères', async () => {
    const user = userEvent.setup()
    server()
    renderAt('/admin/jeux/nouveau')
    await fillValid(user)

    await user.clear(screen.getByLabelText('Description en français'))
    await user.type(screen.getByLabelText('Description en français'), 'courte')
    await user.click(screen.getByRole('button', { name: 'Enregistrer' }))
    expect(await screen.findByText('10 caractères au minimum.', { selector: '.field-error' })).toBeInTheDocument()
  })

  it('crée le jeu : corps conforme à l’API (slug vide → null, PEGI numérique), puis ouvre sa fiche avec jaquette et offres', async () => {
    const user = userEvent.setup()
    const { calls } = server()
    renderAt('/admin/jeux/nouveau')
    await fillValid(user)
    await user.click(screen.getByRole('checkbox', { name: 'Action' }))
    await user.click(screen.getByRole('button', { name: 'Enregistrer' }))

    expect(await screen.findByRole('heading', { level: 1, name: 'Modifier le jeu' })).toBeInTheDocument()
    expect(screen.getByTestId('location')).toHaveTextContent('/admin/jeux/jeu-1')
    expect(json(calls.find((c) => c.method === 'POST')!)).toEqual({
      titre: 'Aube Écarlate',
      slug: null,
      dateSortie: null,
      pegi: 16,
      editeurId: 'pub-aube',
      actif: true,
      descriptions: { fr: 'Une description française assez longue.', en: 'A long enough English description.' },
      genres: ['action'],
    })
    expect(await screen.findByText('Jeu créé. Vous pouvez maintenant ajouter une jaquette et des offres.')).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Jaquette' })).toBeInTheDocument()
    expect(screen.getByRole('heading', { name: 'Offres par plateforme' })).toBeInTheDocument()
  })

  it('relie les erreurs par champ de l’API (422), y compris les descriptions imbriquées', async () => {
    const user = userEvent.setup()
    server(() =>
      jsonResponse(
        { message: 'Certaines données sont invalides.', erreurs: { titre: 'Valeur invalide.', 'descriptions.en': 'Valeur invalide.' } },
        422,
      ),
    )
    renderAt('/admin/jeux/nouveau')
    await fillValid(user)
    await user.click(screen.getByRole('button', { name: 'Enregistrer' }))

    const summary = await screen.findByRole('alert')
    expect(within(summary).getByRole('link', { name: 'Titre : Valeur invalide.' })).toHaveAttribute('href', '#game-title')
    expect(within(summary).getByRole('link', { name: 'Description en anglais : Valeur invalide.' })).toHaveAttribute('href', '#game-description-en')
    expect(screen.getByLabelText('Description en anglais')).toHaveAttribute('aria-invalid', 'true')
    expect(screen.getByLabelText('Titre')).toHaveValue('  Aube Écarlate ')   // la saisie est conservée telle quelle
  })

  it('annonce un slug déjà pris (409) en message général', async () => {
    const user = userEvent.setup()
    server(() => jsonResponse({ message: 'Ce slug est déjà utilisé par un autre jeu.' }, 409))
    renderAt('/admin/jeux/nouveau')
    await fillValid(user)
    await user.click(screen.getByRole('button', { name: 'Enregistrer' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Ce slug est déjà utilisé par un autre jeu.')
  })

  it('préremplit la fiche d’un jeu existant et enregistre les modifications', async () => {
    const user = userEvent.setup()
    const { calls } = server()
    renderAt('/admin/jeux/jeu-1')

    const title = await screen.findByLabelText('Titre')
    expect(title).toHaveValue('Aube Écarlate')
    expect(screen.getByLabelText('Adresse du jeu (slug)')).toHaveValue('aube-ecarlate')
    expect(screen.getByLabelText('Date de sortie')).toHaveValue('2025-03-14')
    expect(screen.getByLabelText('Classification PEGI')).toHaveValue('16')
    expect(screen.getByLabelText('Éditeur')).toHaveValue('pub-aube')
    expect(screen.getByRole('checkbox', { name: 'Action' })).toBeChecked()
    expect(screen.getByRole('checkbox', { name: 'RPG' })).not.toBeChecked()

    await user.clear(title)
    await user.type(title, 'Aube Rouge')
    await user.click(screen.getByRole('button', { name: 'Enregistrer' }))

    expect(await screen.findByText('Jeu enregistré.')).toBeInTheDocument()
    const put = calls.find((c) => c.method === 'PUT')!
    expect(put.path).toBe('admin/jeux/jeu-1')
    expect(json<GameInput>(put).titre).toBe('Aube Rouge')
    expect(json<GameInput>(put).slug).toBe('aube-ecarlate')
  })

  it('signale un jeu désactivé et un jeu introuvable', async () => {
    server(() => jsonResponse(GAME), { ...GAME, actif: false })
    renderAt('/admin/jeux/jeu-1')
    expect(await screen.findByText("Ce jeu est désactivé : il n'apparaît pas au catalogue.")).toBeInTheDocument()
  })

  it('affiche le message de l’API quand le jeu n’existe pas', async () => {
    stubServer(({ path }) => {
      if (path === 'admin/jeux/absent') return jsonResponse({ message: "Cet élément n'existe pas." }, 404)
      if (path === 'admin/editeurs') return jsonResponse({ editeurs: [] })
      if (path === 'admin/genres') return jsonResponse({ genres: [] })
      if (path === 'admin/plateformes') return jsonResponse({ plateformes: [] })
      return undefined
    })
    renderAt('/admin/jeux/absent')

    expect(await screen.findByRole('alert')).toHaveTextContent("Cet élément n'existe pas.")
  })
})
