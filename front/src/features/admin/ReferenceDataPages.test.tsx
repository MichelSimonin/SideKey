import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { jsonResponse } from '../../test/helpers'
import { json, noContent, PLATFORMS, PUBLISHERS, renderAt, stubServer } from './adminTestKit'
import type { Platform, Publisher } from './types'

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('éditeurs (US-50)', () => {
  function server(overrides: (path: string, method: string, body: unknown) => Response | undefined = () => undefined) {
    let list: Publisher[] = [...PUBLISHERS]
    return stubServer(({ method, path, body }) => {
      const custom = overrides(path, method, body)
      if (custom !== undefined) return custom
      if (path === 'admin/editeurs' && method === 'GET') return jsonResponse({ editeurs: list })
      if (path === 'admin/editeurs' && method === 'POST') {
        const created = { id: 'pub-new', nom: (body as { nom: string }).nom, nombreJeux: 0 }
        list = [...list, created]
        return jsonResponse(created, 201)
      }
      if (path.startsWith('admin/editeurs/') && method === 'PUT') {
        const id = path.split('/')[2]
        list = list.map((p) => (p.id === id ? { ...p, nom: (body as { nom: string }).nom } : p))
        return jsonResponse(list.find((p) => p.id === id))
      }
      if (path.startsWith('admin/editeurs/') && method === 'DELETE') {
        const id = path.split('/')[2]
        list = list.filter((p) => p.id !== id)
        return noContent()
      }
      return undefined
    })
  }

  it('affiche un tableau avec légende, en-têtes de colonnes et nombre de jeux', async () => {
    server()
    renderAt('/admin/editeurs')

    const table = await screen.findByRole('table', { name: 'Liste des éditeurs' })
    expect(within(table).getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Nom', 'Jeux', 'Actions'])
    const row = within(table).getByRole('row', { name: /Studio Aube/ })
    expect(within(row).getByRole('rowheader')).toHaveTextContent('Studio Aube')
    expect(within(row).getByText('2')).toBeInTheDocument()
  })

  it('crée un éditeur, l’annonce et recharge la liste', async () => {
    const user = userEvent.setup()
    const { calls } = server()
    renderAt('/admin/editeurs')
    await screen.findByRole('table')

    await user.type(screen.getByLabelText('Nom du nouvel éditeur'), '  Studio Neuf  ')
    await user.click(screen.getByRole('button', { name: 'Ajouter' }))

    expect(await screen.findByText('Éditeur « Studio Neuf » créé.')).toBeInTheDocument()
    expect(json(calls.find((c) => c.method === 'POST')!)).toEqual({ nom: 'Studio Neuf' })
    expect(await screen.findByRole('rowheader', { name: 'Studio Neuf' })).toBeInTheDocument()
    expect(screen.getByLabelText('Nom du nouvel éditeur')).toHaveValue('')
  })

  it('refuse un nom vide sans appeler l’API, avec un résumé d’erreurs focalisé et relié au champ', async () => {
    const user = userEvent.setup()
    const { calls } = server()
    renderAt('/admin/editeurs')
    await screen.findByRole('table')

    await user.click(screen.getByRole('button', { name: 'Ajouter' }))

    const summary = await screen.findByRole('alert')
    expect(summary).toHaveFocus()
    expect(within(summary).getByRole('link', { name: /Nom : Ce champ est obligatoire\./ })).toHaveAttribute('href', '#publisher-new-name')
    expect(screen.getByLabelText('Nom du nouvel éditeur')).toHaveAttribute('aria-invalid', 'true')
    expect(calls.some((c) => c.method === 'POST')).toBe(false)
  })

  it('affiche le message de l’API pour un nom déjà pris (409) et les erreurs par champ (422)', async () => {
    const user = userEvent.setup()
    let attempt = 0
    server((path, method) => {
      if (path === 'admin/editeurs' && method === 'POST') {
        attempt += 1
        return attempt === 1
          ? jsonResponse({ message: 'Un éditeur porte déjà ce nom.' }, 409)
          : jsonResponse({ message: 'Certaines données sont invalides.', erreurs: { nom: 'Valeur invalide.' } }, 422)
      }
      return undefined
    })
    renderAt('/admin/editeurs')
    await screen.findByRole('table')

    await user.type(screen.getByLabelText('Nom du nouvel éditeur'), 'Studio Aube')
    await user.click(screen.getByRole('button', { name: 'Ajouter' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('Un éditeur porte déjà ce nom.')

    await user.click(screen.getByRole('button', { name: 'Ajouter' }))
    await waitFor(() => expect(screen.getByLabelText('Nom du nouvel éditeur')).toHaveAttribute('aria-invalid', 'true'))
    expect(screen.getByText('Valeur invalide.', { selector: '.field-error' })).toBeInTheDocument()
  })

  it('renomme un éditeur en place', async () => {
    const user = userEvent.setup()
    const { calls } = server()
    renderAt('/admin/editeurs')
    await screen.findByRole('table')

    await user.click(screen.getByRole('button', { name: 'Renommer Studio Lune' }))
    const field = screen.getByLabelText("Nom de l'éditeur Studio Lune")
    expect(field).toHaveFocus()
    await user.clear(field)
    await user.type(field, 'Studio Lune 2')
    await user.click(screen.getByRole('button', { name: 'Enregistrer' }))

    expect(await screen.findByText('Éditeur renommé.')).toBeInTheDocument()
    expect(calls.find((c) => c.method === 'PUT')?.path).toBe('admin/editeurs/pub-lune')
    expect(json(calls.find((c) => c.method === 'PUT')!)).toEqual({ nom: 'Studio Lune 2' })
    expect(await screen.findByRole('rowheader', { name: 'Studio Lune 2' })).toBeInTheDocument()
  })

  it('supprime en deux temps : confirmation explicite, annulation possible, focus rendu', async () => {
    const user = userEvent.setup()
    const { calls } = server()
    renderAt('/admin/editeurs')
    await screen.findByRole('table')

    await user.click(screen.getByRole('button', { name: 'Supprimer Studio Lune' }))
    const group = screen.getByRole('group', { name: "Supprimer l'éditeur « Studio Lune » ?" })
    expect(within(group).getByRole('button', { name: 'Confirmer' })).toHaveFocus()
    expect(calls.some((c) => c.method === 'DELETE')).toBe(false)

    await user.click(within(group).getByRole('button', { name: 'Annuler' }))
    expect(screen.getByRole('button', { name: 'Supprimer Studio Lune' })).toHaveFocus()
    expect(calls.some((c) => c.method === 'DELETE')).toBe(false)

    await user.click(screen.getByRole('button', { name: 'Supprimer Studio Lune' }))
    await user.click(screen.getByRole('button', { name: 'Confirmer' }))
    expect(await screen.findByText('Éditeur « Studio Lune » supprimé.')).toBeInTheDocument()
    expect(calls.find((c) => c.method === 'DELETE')?.path).toBe('admin/editeurs/pub-lune')
    await waitFor(() => expect(screen.queryByRole('rowheader', { name: 'Studio Lune' })).not.toBeInTheDocument())
  })

  it('Échap annule la confirmation', async () => {
    const user = userEvent.setup()
    const { calls } = server()
    renderAt('/admin/editeurs')
    await screen.findByRole('table')

    await user.click(screen.getByRole('button', { name: 'Supprimer Studio Lune' }))
    await user.keyboard('{Escape}')

    expect(screen.queryByRole('button', { name: 'Confirmer' })).not.toBeInTheDocument()
    expect(calls.some((c) => c.method === 'DELETE')).toBe(false)
  })

  it('n’offre pas de suppression pour un éditeur utilisé et explique pourquoi', async () => {
    server()
    renderAt('/admin/editeurs')
    const table = await screen.findByRole('table')

    const row = within(table).getByRole('row', { name: /Studio Aube/ })
    expect(within(row).queryByRole('button', { name: /Supprimer/ })).not.toBeInTheDocument()
    expect(within(row).getByText('Utilisé par 2 jeux : suppression impossible.')).toBeInTheDocument()
  })

  it('affiche l’erreur de l’API quand la suppression est refusée (409)', async () => {
    const user = userEvent.setup()
    server((_path, method) =>
      method === 'DELETE' ? jsonResponse({ message: 'Suppression impossible : cet élément est encore utilisé. Désactivez-le plutôt.' }, 409) : undefined,
    )
    renderAt('/admin/editeurs')
    await screen.findByRole('table')

    await user.click(screen.getByRole('button', { name: 'Supprimer Studio Lune' }))
    await user.click(screen.getByRole('button', { name: 'Confirmer' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Suppression impossible : cet élément est encore utilisé.')
  })

  it('propose de réessayer quand le chargement échoue', async () => {
    const user = userEvent.setup()
    let fail = true
    stubServer(({ path }) => {
      if (path !== 'admin/editeurs') return undefined
      if (fail) return jsonResponse({}, 500)
      return jsonResponse({ editeurs: PUBLISHERS })
    })
    renderAt('/admin/editeurs')

    expect(await screen.findByRole('alert')).toHaveTextContent('Impossible de charger les données.')
    fail = false
    await user.click(screen.getByRole('button', { name: 'Réessayer' }))

    expect(await screen.findByRole('table')).toBeInTheDocument()
  })
})

describe('plateformes (US-50)', () => {
  function server(overrides: (path: string, method: string) => Response | undefined = () => undefined) {
    let list: Platform[] = [...PLATFORMS]
    return stubServer(({ method, path, body }) => {
      const custom = overrides(path, method)
      if (custom !== undefined) return custom
      if (path === 'admin/plateformes' && method === 'GET') return jsonResponse({ plateformes: list })
      if (path === 'admin/plateformes' && method === 'POST') {
        const input = body as { code: string; libelle: string }
        const created = { id: 'pl-new', code: input.code, libelle: input.libelle, nombreOffres: 0 }
        list = [...list, created]
        return jsonResponse(created, 201)
      }
      if (path.startsWith('admin/plateformes/') && method === 'PUT') {
        const id = path.split('/')[2]
        list = list.map((p) => (p.id === id ? { ...p, libelle: (body as { libelle: string }).libelle } : p))
        return jsonResponse(list.find((p) => p.id === id))
      }
      return undefined
    })
  }

  it('affiche code, libellé et nombre d’offres', async () => {
    server()
    renderAt('/admin/plateformes')

    const table = await screen.findByRole('table', { name: 'Liste des plateformes' })
    expect(within(table).getAllByRole('columnheader').map((h) => h.textContent)).toEqual(['Code', 'Libellé', 'Offres', 'Actions'])
    const row = within(table).getByRole('row', { name: /PSN/ })
    expect(within(row).getByText('PlayStation Store')).toBeInTheDocument()
  })

  it('met le code en majuscules et le crée avec son libellé', async () => {
    const user = userEvent.setup()
    const { calls } = server()
    renderAt('/admin/plateformes')
    await screen.findByRole('table')

    await user.type(screen.getByLabelText('Code'), 'eshop')
    expect(screen.getByLabelText('Code')).toHaveValue('ESHOP')
    await user.type(screen.getByLabelText('Libellé'), 'Nintendo eShop')
    await user.click(screen.getByRole('button', { name: 'Ajouter' }))

    expect(await screen.findByText('Plateforme « Nintendo eShop » créée.')).toBeInTheDocument()
    expect(json(calls.find((c) => c.method === 'POST')!)).toEqual({ code: 'ESHOP', libelle: 'Nintendo eShop' })
  })

  it('refuse un code mal formé et un libellé vide sans appeler l’API', async () => {
    const user = userEvent.setup()
    const { calls } = server()
    renderAt('/admin/plateformes')
    await screen.findByRole('table')

    await user.type(screen.getByLabelText('Code'), 'AB-C')
    await user.click(screen.getByRole('button', { name: 'Ajouter' }))

    const summary = await screen.findByRole('alert')
    expect(within(summary).getByRole('link', { name: /Code :/ })).toHaveAttribute('href', '#platform-new-code')
    expect(within(summary).getByRole('link', { name: /Libellé : Ce champ est obligatoire/ })).toBeInTheDocument()
    expect(calls.some((c) => c.method === 'POST')).toBe(false)
  })

  it('ne permet de modifier que le libellé : le code reste affiché en lecture seule', async () => {
    const user = userEvent.setup()
    const { calls } = server()
    renderAt('/admin/plateformes')
    await screen.findByRole('table')

    await user.click(screen.getByRole('button', { name: 'Renommer Steam' }))
    const table = screen.getByRole('table')
    expect(within(table).queryByLabelText('Code')).not.toBeInTheDocument()
    const field = within(table).getByLabelText('Libellé de la plateforme Steam')
    await user.clear(field)
    await user.type(field, 'Steam PC')
    await user.click(within(table).getByRole('button', { name: 'Enregistrer' }))

    expect(await screen.findByText('Plateforme renommée.')).toBeInTheDocument()
    expect(json(calls.find((c) => c.method === 'PUT')!)).toEqual({ libelle: 'Steam PC' })
  })

  it('interdit la suppression d’une plateforme utilisée par des offres', async () => {
    server()
    renderAt('/admin/plateformes')
    const table = await screen.findByRole('table')

    const steam = within(table).getByRole('row', { name: /STEAM/ })
    expect(within(steam).queryByRole('button', { name: /Supprimer/ })).not.toBeInTheDocument()
    expect(within(steam).getByText('Utilisée par 3 offres : suppression impossible.')).toBeInTheDocument()
    expect(within(within(table).getByRole('row', { name: /PSN/ })).getByRole('button', { name: 'Supprimer PlayStation Store' })).toBeInTheDocument()
  })
})
