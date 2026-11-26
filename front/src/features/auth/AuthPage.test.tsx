import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import App from '../../App'
import { getAccessToken } from '../../api/session'
import { jsonResponse, sessionPayload, setCsrfCookie, USER } from '../../test/helpers'

/** Écran E05 de bout en bout côté front : formulaires, erreurs reliées aux champs, session en mémoire. */
function renderAt(path: string) {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <App />
    </MemoryRouter>,
  )
}

function stubFetch(handler: (url: string, init: RequestInit) => Response | Promise<Response>) {
  const fn = vi.fn(async (input: RequestInfo | URL, init: RequestInit = {}) => handler(String(input), init))
  vi.stubGlobal('fetch', fn)
  return fn
}

beforeEach(() => {
  document.documentElement.lang = 'fr'
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('E05 — connexion', () => {
  it('affiche les champs étiquetés avec les bons attributs autocomplete', async () => {
    stubFetch(() => jsonResponse({}, 500))
    renderAt('/connexion')

    expect(await screen.findByRole('heading', { level: 1, name: 'Connexion' })).toBeInTheDocument()
    expect(screen.getByLabelText('Adresse e-mail')).toHaveAttribute('autocomplete', 'username')
    expect(screen.getByLabelText('Adresse e-mail')).toHaveAttribute('type', 'email')
    expect(screen.getByLabelText('Mot de passe')).toHaveAttribute('autocomplete', 'current-password')
    expect(screen.getByLabelText('Mot de passe')).toHaveAttribute('type', 'password')
  })

  it('signale la vue active dans la navigation par onglets (aria-current)', async () => {
    renderAt('/connexion')

    const nav = await screen.findByRole('navigation', { name: 'Connexion ou création de compte' })
    expect(within(nav).getByRole('link', { name: 'Connexion' })).toHaveAttribute('aria-current', 'page')
    expect(within(nav).getByRole('link', { name: 'Inscription' })).not.toHaveAttribute('aria-current')
  })

  it('affiche et masque le mot de passe à la demande', async () => {
    const user = userEvent.setup()
    renderAt('/connexion')
    const field = await screen.findByLabelText('Mot de passe')

    await user.click(screen.getByLabelText('Afficher le mot de passe'))
    expect(field).toHaveAttribute('type', 'text')

    await user.click(screen.getByLabelText('Afficher le mot de passe'))
    expect(field).toHaveAttribute('type', 'password')
  })

  it('refuse un formulaire vide : erreurs reliées aux champs, résumé annoncé et focalisé', async () => {
    const user = userEvent.setup()
    const fetchFn = stubFetch(() => jsonResponse({}, 500))
    renderAt('/connexion')

    await user.click(await screen.findByRole('button', { name: 'Se connecter' }))

    const summary = screen.getByRole('alert')
    expect(summary).toHaveFocus()
    expect(summary).toHaveTextContent('Le formulaire contient des erreurs')
    const email = screen.getByLabelText('Adresse e-mail')
    expect(email).toHaveAttribute('aria-invalid', 'true')
    expect(email).toHaveAccessibleDescription('Ce champ est obligatoire.')
    expect(within(summary).getByRole('link', { name: /Adresse e-mail/ })).toHaveAttribute('href', '#login-email')
    expect(fetchFn).not.toHaveBeenCalledWith('/api/connexion', expect.anything())
  })

  it('connecte l’utilisateur : jeton en mémoire seulement, en-tête mis à jour', async () => {
    const user = userEvent.setup()
    const fetchFn = stubFetch((url) =>
      url === '/api/connexion' ? jsonResponse(sessionPayload('jwt-secret-en-memoire')) : jsonResponse({}, 500),
    )
    renderAt('/connexion')

    await user.type(await screen.findByLabelText('Adresse e-mail'), 'lea@example.test')
    await user.type(screen.getByLabelText('Mot de passe'), 'une phrase de passe')
    await user.click(screen.getByRole('button', { name: 'Se connecter' }))

    expect(await screen.findByText('Bonjour Léa')).toBeInTheDocument()
    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Vos clés de jeux')
    expect(getAccessToken()).toBe('jwt-secret-en-memoire')
    // Jamais de jeton dans un stockage lisible par le script (CDC §11.4).
    expect(JSON.stringify({ ...localStorage, ...sessionStorage }) + document.cookie).not.toContain('jwt-secret-en-memoire')
    const appel = fetchFn.mock.calls.find(([url]) => url === '/api/connexion')
    expect(JSON.parse(String(appel?.[1]?.body))).toEqual({ email: 'lea@example.test', motDePasse: 'une phrase de passe' })
  })

  it('affiche le message générique de l’API sur un 401, sans réafficher le mot de passe', async () => {
    const user = userEvent.setup()
    stubFetch(() => jsonResponse({ message: 'E-mail ou mot de passe incorrect.' }, 401))
    renderAt('/connexion')

    await user.type(await screen.findByLabelText('Adresse e-mail'), 'lea@example.test')
    await user.type(screen.getByLabelText('Mot de passe'), 'mauvais mot de passe')
    await user.click(screen.getByRole('button', { name: 'Se connecter' }))

    const alert = await screen.findByRole('alert')
    expect(alert).toHaveTextContent('E-mail ou mot de passe incorrect.')
    expect(alert).not.toHaveTextContent('mauvais mot de passe')
    expect(getAccessToken()).toBeNull()
  })

  it('annonce le délai d’attente d’un 429', async () => {
    const user = userEvent.setup()
    stubFetch(() => jsonResponse({ message: 'Trop de tentatives.' }, 429, { 'Retry-After': '780' }))
    renderAt('/connexion')

    await user.type(await screen.findByLabelText('Adresse e-mail'), 'lea@example.test')
    await user.type(screen.getByLabelText('Mot de passe'), 'x')
    await user.click(screen.getByRole('button', { name: 'Se connecter' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Réessayez dans 13 min.')
  })

  it('passe à la seconde étape pour un compte A2F, sans connecter', async () => {
    const user = userEvent.setup()
    stubFetch(() => jsonResponse({ etape: 'a2f', jetonIntermediaire: 'jeton', expireDans: 300 }))
    renderAt('/connexion')

    await user.type(await screen.findByLabelText('Adresse e-mail'), 'admin@sidekey.test')
    await user.type(screen.getByLabelText('Mot de passe'), 'x')
    await user.click(screen.getByRole('button', { name: 'Se connecter' }))

    expect(await screen.findByRole('heading', { level: 2, name: 'Vérification en deux étapes' })).toBeInTheDocument()
    expect(screen.queryByLabelText('Mot de passe')).not.toBeInTheDocument()
    expect(getAccessToken()).toBeNull()
  })

  it('désactive le bouton pendant l’envoi', async () => {
    const user = userEvent.setup()
    let release: (response: Response) => void = () => undefined
    stubFetch(() => new Promise<Response>((resolve) => (release = resolve)))
    renderAt('/connexion')

    await user.type(await screen.findByLabelText('Adresse e-mail'), 'lea@example.test')
    await user.type(screen.getByLabelText('Mot de passe'), 'x')
    await user.click(screen.getByRole('button', { name: 'Se connecter' }))

    expect(await screen.findByRole('button', { name: 'Veuillez patienter…' })).toBeDisabled()
    release(jsonResponse({ message: 'E-mail ou mot de passe incorrect.' }, 401))
    await waitFor(() => expect(screen.getByRole('button', { name: 'Se connecter' })).toBeEnabled())
  })

  it('applique la langue du compte à la connexion', async () => {
    const user = userEvent.setup()
    stubFetch(() => jsonResponse(sessionPayload('jwt', { ...USER, langue: 'en' })))
    renderAt('/connexion')

    await user.type(await screen.findByLabelText('Adresse e-mail'), 'lea@example.test')
    await user.type(screen.getByLabelText('Mot de passe'), 'x')
    await user.click(screen.getByRole('button', { name: 'Se connecter' }))

    expect(await screen.findByText('Hello Léa')).toBeInTheDocument()
    expect(document.documentElement.lang).toBe('en')
  })
})

describe('E05 — inscription', () => {
  it('affiche les règles du mot de passe et un décompte annoncé poliment', async () => {
    const user = userEvent.setup()
    renderAt('/inscription')

    const password = await screen.findByLabelText('Mot de passe')
    expect(password).toHaveAttribute('autocomplete', 'new-password')
    expect(password).toHaveAccessibleDescription(/12 à 128 caractères/)
    await user.type(password, 'abcdef')
    expect(screen.getByText('6 caractère(s) saisi(s)')).toHaveAttribute('aria-live', 'polite')
  })

  it('porte les bons attributs autocomplete et exige le consentement', async () => {
    const user = userEvent.setup()
    const fetchFn = stubFetch(() => jsonResponse({}, 500))
    renderAt('/inscription')

    expect(await screen.findByLabelText('Prénom')).toHaveAttribute('autocomplete', 'given-name')
    expect(screen.getByLabelText('Nom')).toHaveAttribute('autocomplete', 'family-name')
    expect(screen.getByLabelText('Adresse e-mail')).toHaveAttribute('autocomplete', 'email')

    await user.type(screen.getByLabelText('Prénom'), 'Léa')
    await user.type(screen.getByLabelText('Nom'), 'Roy')
    await user.type(screen.getByLabelText('Adresse e-mail'), 'lea@example.test')
    await user.type(screen.getByLabelText('Mot de passe'), 'une phrase de passe solide')
    await user.click(screen.getByRole('button', { name: 'Créer mon compte' }))

    expect(screen.getByRole('alert')).toHaveTextContent('obligatoire')
    expect(screen.getByRole('checkbox', { name: /politique de confidentialité/ })).toHaveAttribute('aria-invalid', 'true')
    expect(fetchFn).not.toHaveBeenCalledWith('/api/inscription', expect.anything())
  })

  it('crée le compte puis connecte l’utilisateur avec la langue de la page', async () => {
    const user = userEvent.setup()
    const fetchFn = stubFetch((url) => {
      if (url === '/api/inscription') return jsonResponse({ utilisateur: USER }, 201)
      if (url === '/api/connexion') return jsonResponse(sessionPayload('jwt-inscrit'))
      return jsonResponse({}, 500)
    })
    renderAt('/inscription')

    await user.type(await screen.findByLabelText('Prénom'), 'Léa')
    await user.type(screen.getByLabelText('Nom'), 'Roy')
    await user.type(screen.getByLabelText('Adresse e-mail'), 'lea@example.test')
    await user.type(screen.getByLabelText('Mot de passe'), 'une phrase de passe solide')
    await user.click(screen.getByRole('checkbox', { name: /politique de confidentialité/ }))
    await user.click(screen.getByRole('button', { name: 'Créer mon compte' }))

    expect(await screen.findByText('Bonjour Léa')).toBeInTheDocument()
    const appel = fetchFn.mock.calls.find(([url]) => url === '/api/inscription')
    expect(JSON.parse(String(appel?.[1]?.body))).toEqual({
      prenom: 'Léa',
      nom: 'Roy',
      email: 'lea@example.test',
      motDePasse: 'une phrase de passe solide',
      consentement: true,
      langue: 'fr',
    })
    // Le front n'envoie ni rôle ni statut : l'API les impose (mass assignment).
    expect(String(appel?.[1]?.body)).not.toMatch(/role|actif|emailVerifie/i)
  })

  it('rattache les erreurs de validation de l’API aux champs concernés', async () => {
    const user = userEvent.setup()
    stubFetch(() =>
      jsonResponse(
        { message: 'Certaines données sont invalides.', erreurs: { motDePasse: 'Ce mot de passe est trop courant. Choisissez une phrase de passe.' } },
        422,
      ),
    )
    renderAt('/inscription')

    await user.type(await screen.findByLabelText('Prénom'), 'Léa')
    await user.type(screen.getByLabelText('Nom'), 'Roy')
    await user.type(screen.getByLabelText('Adresse e-mail'), 'lea@example.test')
    await user.type(screen.getByLabelText('Mot de passe'), 'password12345')
    await user.click(screen.getByRole('checkbox', { name: /politique de confidentialité/ }))
    await user.click(screen.getByRole('button', { name: 'Créer mon compte' }))

    const password = await screen.findByLabelText('Mot de passe')
    await waitFor(() => expect(password).toHaveAttribute('aria-invalid', 'true'))
    expect(password).toHaveAccessibleDescription(/trop courant/)
  })

  it('signale un e-mail déjà utilisé sur le champ e-mail (409)', async () => {
    const user = userEvent.setup()
    stubFetch(() => jsonResponse({ message: 'Un compte existe déjà avec cet e-mail.' }, 409))
    renderAt('/inscription')

    await user.type(await screen.findByLabelText('Prénom'), 'Léa')
    await user.type(screen.getByLabelText('Nom'), 'Roy')
    await user.type(screen.getByLabelText('Adresse e-mail'), 'lea@example.test')
    await user.type(screen.getByLabelText('Mot de passe'), 'une phrase de passe solide')
    await user.click(screen.getByRole('checkbox', { name: /politique de confidentialité/ }))
    await user.click(screen.getByRole('button', { name: 'Créer mon compte' }))

    await waitFor(() => expect(screen.getByLabelText('Adresse e-mail')).toHaveAccessibleDescription('Un compte existe déjà avec cet e-mail.'))
  })

  it('est entièrement disponible en anglais', async () => {
    const user = userEvent.setup()
    renderAt('/inscription')
    await user.click(await screen.findByRole('button', { name: 'English' }))

    expect(await screen.findByRole('heading', { level: 1, name: 'Create an account' })).toBeInTheDocument()
    expect(screen.getByLabelText('First name')).toBeInTheDocument()
    expect(screen.getByLabelText('E-mail address')).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Create my account' })).toBeInTheDocument()
  })
})

describe('session rétablie en silence', () => {
  it('sans cookie CSRF, aucune requête de rafraîchissement : le visiteur voit « Connexion »', async () => {
    const fetchFn = stubFetch(() => jsonResponse({}, 500))
    renderAt('/')

    expect(await screen.findByRole('link', { name: 'Connexion' })).toHaveAttribute('href', '/connexion')
    expect(fetchFn).not.toHaveBeenCalled()
  })

  it('avec un cookie CSRF, rétablit la session au chargement et affiche l’utilisateur', async () => {
    setCsrfCookie('csrf-1')
    const fetchFn = stubFetch((url) => (url === '/api/token/refresh' ? jsonResponse(sessionPayload('jwt-retabli')) : jsonResponse({}, 500)))
    renderAt('/')

    expect(await screen.findByText('Bonjour Léa')).toBeInTheDocument()
    expect(getAccessToken()).toBe('jwt-retabli')
    expect(fetchFn).toHaveBeenCalledTimes(1)
  })

  it('un refresh refusé laisse le visiteur déconnecté', async () => {
    setCsrfCookie('csrf-1')
    stubFetch(() => jsonResponse({ message: 'Session expirée' }, 401))
    renderAt('/')

    expect(await screen.findByRole('link', { name: 'Connexion' })).toBeInTheDocument()
    expect(getAccessToken()).toBeNull()
  })

  it('redirige un utilisateur connecté qui ouvre /connexion', async () => {
    setCsrfCookie('csrf-1')
    stubFetch((url) => (url === '/api/token/refresh' ? jsonResponse(sessionPayload('jwt')) : jsonResponse({}, 500)))
    renderAt('/connexion')

    await waitFor(() => expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Vos clés de jeux'))
  })

  it('se déconnecte : l’interface revient à « Connexion » et le jeton est effacé', async () => {
    const user = userEvent.setup()
    setCsrfCookie('csrf-1')
    stubFetch((url) => {
      if (url === '/api/token/refresh') return jsonResponse(sessionPayload('jwt'))
      if (url === '/api/deconnexion') return new Response(null, { status: 204 })
      return jsonResponse({}, 500)
    })
    renderAt('/')

    await user.click(await screen.findByRole('button', { name: 'Déconnexion' }))

    expect(await screen.findByRole('link', { name: 'Connexion' })).toBeInTheDocument()
    expect(getAccessToken()).toBeNull()
  })
})
