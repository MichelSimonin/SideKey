import { screen, waitFor } from '@testing-library/react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { jsonResponse, USER } from '../../test/helpers'
import { PLATFORMS, PUBLISHERS, renderAt, stubServer } from './adminTestKit'

afterEach(() => {
  vi.unstubAllGlobals()
})

/**
 * La garde MASQUE l'interface ; elle n'autorise rien (l'API vérifie permission et A2F à chaque appel). Ces tests vérifient
 * surtout qu'un compte sans le droit n'envoie AUCUNE requête d'administration.
 */
describe('accès au back-office', () => {
  it('renvoie un visiteur non connecté vers la page de connexion', async () => {
    const { calls } = stubServer(() => undefined, null)

    renderAt('/admin/jeux')

    expect(await screen.findByRole('heading', { level: 1, name: 'Connexion' })).toBeInTheDocument()
    expect(screen.getByTestId('location')).toHaveTextContent('/connexion')
    expect(calls).toHaveLength(0)
  })

  it('refuse un client connecté sans lui envoyer aucune donnée', async () => {
    const { calls } = stubServer(() => undefined, USER)

    renderAt('/admin/jeux')

    expect(await screen.findByRole('heading', { name: 'Accès refusé' })).toBeInTheDocument()
    expect(screen.getByRole('alert')).toHaveTextContent("Votre compte n'a pas le droit de gérer le catalogue.")
    expect(screen.queryByRole('link', { name: 'Administration' })).not.toBeInTheDocument()
    expect(calls).toHaveLength(0)
  })

  it('refuse aussi la création et la fiche d’un jeu à un client', async () => {
    const { calls } = stubServer(() => undefined, USER)

    renderAt('/admin/jeux/nouveau')
    expect(await screen.findByRole('heading', { name: 'Accès refusé' })).toBeInTheDocument()
    expect(calls).toHaveLength(0)
  })

  it('ouvre le back-office à un gestionnaire et lui montre le lien « Administration »', async () => {
    stubServer(({ path }) => {
      if (path.startsWith('admin/jeux')) return jsonResponse({ jeux: [], page: 1, nombrePages: 1, total: 0 })
      return undefined
    })

    renderAt('/admin')

    expect(await screen.findByRole('heading', { level: 1, name: 'Jeux et offres' })).toBeInTheDocument()
    expect(screen.getByTestId('location')).toHaveTextContent('/admin/jeux')
    expect(screen.getByRole('link', { name: 'Administration' })).toHaveAttribute('href', '/admin')
    const nav = screen.getByRole('navigation', { name: "Sections de l'administration" })
    expect(nav).toHaveTextContent('Éditeurs')
    expect(screen.getByRole('link', { name: 'Jeux et offres' })).toHaveAttribute('aria-current', 'page')
  })

  it('signale la section active de la navigation', async () => {
    stubServer(({ path }) => (path === 'admin/editeurs' ? jsonResponse({ editeurs: PUBLISHERS }) : undefined))

    renderAt('/admin/editeurs')

    await screen.findByRole('heading', { level: 1, name: 'Éditeurs' })
    expect(screen.getByRole('link', { name: 'Éditeurs' })).toHaveAttribute('aria-current', 'page')
    expect(screen.getByRole('link', { name: 'Plateformes' })).not.toHaveAttribute('aria-current')
  })

  it('ne charge que la section demandée une fois la session rétablie', async () => {
    const { calls } = stubServer(({ path }) => (path === 'admin/plateformes' ? jsonResponse({ plateformes: PLATFORMS }) : undefined))

    renderAt('/admin/plateformes')

    await waitFor(() => expect(screen.getByRole('heading', { level: 1, name: 'Plateformes' })).toBeInTheDocument())
    expect(calls.every((call) => call.path === 'admin/plateformes')).toBe(true)
  })
})
