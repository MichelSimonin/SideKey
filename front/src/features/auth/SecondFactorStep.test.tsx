import { render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter } from 'react-router-dom'
import { afterEach, describe, expect, it, vi } from 'vitest'
import App from '../../App'
import { getAccessToken } from '../../api/session'
import { jsonResponse, sessionPayload, USER } from '../../test/helpers'

/** Seconde étape de la connexion (E05, US-21, US-22) : code, enrôlement avec QR code, codes de secours, erreurs, accessibilité. */

const SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'
const QR = 'data:image/svg+xml;base64,PHN2Zz48L3N2Zz4='
const CODES = Array.from({ length: 10 }, (_, i) => `ABCDE-FGHJ${i}`.replace(/0/g, '2').replace(/1/g, '3'))
const BO = { ...USER, roles: ['GESTIONNAIRE'], permissions: ['gerer_catalogue'], a2fActive: true }

type Handler = (url: string, init: RequestInit) => Response | Promise<Response>

function renderLogin() {
  return render(
    <MemoryRouter initialEntries={['/connexion']}>
      <App />
    </MemoryRouter>,
  )
}

function stubFetch(handler: Handler) {
  const fn = vi.fn(async (input: RequestInfo | URL, init: RequestInit = {}) => handler(String(input), init))
  vi.stubGlobal('fetch', fn)
  return fn
}

function auth(init: RequestInit): string | undefined {
  return (init.headers as Record<string, string> | undefined)?.Authorization
}

async function passwordStep(etape: 'a2f' | 'a2f_enrolement') {
  const user = userEvent.setup()
  await user.type(await screen.findByLabelText('Adresse e-mail'), 'karim@example.test')
  await user.type(screen.getByLabelText('Mot de passe'), 'une phrase de passe solide')
  await user.click(screen.getByRole('button', { name: 'Se connecter' }))
  expect(await screen.findByRole('heading', { level: 2 })).toBeInTheDocument()
  void etape
  return user
}

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('étape « code »', () => {
  const connexion = (url: string) =>
    url === '/api/connexion' ? jsonResponse({ etape: 'a2f', jetonIntermediaire: 'jeton-intermediaire', expireDans: 300 }) : null

  it('remplace le formulaire de mot de passe par la saisie du code, le titre reçoit le focus', async () => {
    stubFetch((url) => connexion(url) ?? jsonResponse({}, 500))
    renderLogin()

    await passwordStep('a2f')

    const titre = screen.getByRole('heading', { level: 2, name: 'Vérification en deux étapes' })
    expect(titre).toHaveFocus()
    expect(screen.queryByLabelText('Mot de passe')).not.toBeInTheDocument()
    const champ = screen.getByLabelText("Code d'authentification")
    expect(champ).toHaveAttribute('autocomplete', 'one-time-code')
    expect(champ).toHaveAccessibleDescription(/6 chiffres/)
  })

  it('un code valide ouvre la session avec le jeton intermédiaire en en-tête, puis redirige', async () => {
    const fn = stubFetch((url) => connexion(url) ?? (url === '/api/connexion/a2f' ? jsonResponse(sessionPayload('jwt-definitif', BO)) : jsonResponse({}, 500)))
    renderLogin()
    const user = await passwordStep('a2f')

    await user.type(screen.getByLabelText("Code d'authentification"), '123456')
    await user.click(screen.getByRole('button', { name: 'Vérifier le code' }))

    expect(await screen.findByText('Bonjour Léa')).toBeInTheDocument()
    expect(getAccessToken()).toBe('jwt-definitif')
    const appel = fn.mock.calls.find(([url]) => url === '/api/connexion/a2f')
    expect(auth(appel?.[1] ?? {})).toBe('Bearer jeton-intermediaire')
    expect(JSON.parse(String(appel?.[1]?.body))).toEqual({ code: '123456' })
    expect(JSON.stringify({ ...localStorage, ...sessionStorage }) + document.cookie).not.toMatch(/jeton-intermediaire|jwt-definitif/)
  })

  it('accepte un code de secours', async () => {
    const fn = stubFetch((url) => connexion(url) ?? (url === '/api/connexion/a2f' ? jsonResponse(sessionPayload('jwt', BO)) : jsonResponse({}, 500)))
    renderLogin()
    const user = await passwordStep('a2f')

    await user.type(screen.getByLabelText("Code d'authentification"), 'abcde-fghj2')
    await user.click(screen.getByRole('button', { name: 'Vérifier le code' }))

    await screen.findByText('Bonjour Léa')
    const appel = fn.mock.calls.find(([url]) => url === '/api/connexion/a2f')
    expect(JSON.parse(String(appel?.[1]?.body))).toEqual({ code: 'abcde-fghj2' })
  })

  it('un code faux est signalé sur le champ, reste modifiable, et ne ferme pas l’étape', async () => {
    stubFetch((url) => connexion(url) ?? jsonResponse({ message: 'Code incorrect ou expiré.', raison: 'code_invalide' }, 401))
    renderLogin()
    const user = await passwordStep('a2f')

    await user.type(screen.getByLabelText("Code d'authentification"), '000000')
    await user.click(screen.getByRole('button', { name: 'Vérifier le code' }))

    const champ = await screen.findByLabelText("Code d'authentification")
    await waitFor(() => expect(champ).toHaveAttribute('aria-invalid', 'true'))
    expect(champ).toHaveAccessibleDescription(/Code incorrect ou expiré/)
    expect(screen.getByRole('alert')).toHaveFocus()
    expect(getAccessToken()).toBeNull()
  })

  it('un jeton intermédiaire expiré ramène à la saisie du mot de passe avec un message', async () => {
    stubFetch((url) => connexion(url) ?? jsonResponse({ message: 'Authentification requise.' }, 401))
    renderLogin()
    const user = await passwordStep('a2f')

    await user.type(screen.getByLabelText("Code d'authentification"), '123456')
    await user.click(screen.getByRole('button', { name: 'Vérifier le code' }))

    expect(await screen.findByLabelText('Mot de passe')).toBeInTheDocument()
    expect(screen.getByRole('status')).toHaveTextContent('Votre connexion a expiré')
    expect(screen.getByLabelText('Mot de passe')).toHaveValue('')
  })

  it('annonce le délai d’attente d’un 429 (tentatives limitées)', async () => {
    stubFetch((url) => connexion(url) ?? jsonResponse({ message: 'Trop de tentatives.' }, 429, { 'Retry-After': '840' }))
    renderLogin()
    const user = await passwordStep('a2f')

    await user.type(screen.getByLabelText("Code d'authentification"), '123456')
    await user.click(screen.getByRole('button', { name: 'Vérifier le code' }))

    expect(await screen.findByRole('alert')).toHaveTextContent('Réessayez dans 14 min.')
  })

  it('refuse un champ vide sans appeler l’API', async () => {
    const fn = stubFetch((url) => connexion(url) ?? jsonResponse({}, 500))
    renderLogin()
    const user = await passwordStep('a2f')

    await user.click(screen.getByRole('button', { name: 'Vérifier le code' }))

    expect(await screen.findByLabelText("Code d'authentification")).toHaveAccessibleDescription(/obligatoire/)
    expect(fn.mock.calls.filter(([url]) => url === '/api/connexion/a2f')).toHaveLength(0)
  })

  it('permet de revenir à la connexion', async () => {
    stubFetch((url) => connexion(url) ?? jsonResponse({}, 500))
    renderLogin()
    const user = await passwordStep('a2f')

    await user.click(screen.getByRole('button', { name: 'Revenir à la connexion' }))

    expect(await screen.findByLabelText('Mot de passe')).toBeInTheDocument()
  })
})

describe('étape « enrôlement »', () => {
  const routes = (url: string, init: RequestInit): Response => {
    if (url === '/api/connexion') return jsonResponse({ etape: 'a2f_enrolement', jetonIntermediaire: 'jeton-enrol', expireDans: 300 })
    if (url === '/api/connexion/a2f/enrolement') return jsonResponse({ secret: SECRET, uri: 'otpauth://totp/SideKey%3Ak', qrCode: QR })
    if (url === '/api/connexion/a2f/enrolement/confirmation' && init.method === 'POST') {
      return jsonResponse({ ...sessionPayload('jwt-bo', BO), codesSecours: CODES }, 201)
    }
    return jsonResponse({}, 500)
  }

  it('affiche les instructions, le QR code décrit en texte et la clé pour la saisie manuelle', async () => {
    const fn = stubFetch(routes)
    renderLogin()

    await passwordStep('a2f_enrolement')

    expect(await screen.findByRole('heading', { level: 2, name: 'Activer la double authentification' })).toBeInTheDocument()
    expect(screen.getAllByRole('listitem').filter((li) => li.closest('ol.a2f-steps'))).toHaveLength(3)
    const qr = await screen.findByRole('img', { name: /QR code à scanner/ })
    expect(qr).toHaveAttribute('src', QR)
    expect(qr).toHaveAttribute('width', '240')
    expect(screen.getByLabelText('Clé de configuration')).toHaveTextContent('JBSW Y3DP EHPK 3PXP JBSW Y3DP EHPK 3PXP')
    const appel = fn.mock.calls.find(([url]) => url === '/api/connexion/a2f/enrolement')
    expect(auth(appel?.[1] ?? {})).toBe('Bearer jeton-enrol')
  })

  it('le secret n’est jamais écrit dans un stockage du navigateur ni dans l’URL', async () => {
    stubFetch(routes)
    renderLogin()
    await passwordStep('a2f_enrolement')
    await screen.findByRole('img', { name: /QR code à scanner/ })

    expect(JSON.stringify({ ...localStorage, ...sessionStorage }) + document.cookie + window.location.href).not.toContain(SECRET)
  })

  it('le bouton d’activation attend que le QR code soit chargé', async () => {
    let release: (r: Response) => void = () => undefined
    stubFetch((url, init) =>
      url === '/api/connexion/a2f/enrolement' ? new Promise<Response>((resolve) => (release = resolve)) : routes(url, init),
    )
    renderLogin()
    await passwordStep('a2f_enrolement')

    expect(screen.getByRole('button', { name: 'Activer la double authentification' })).toBeDisabled()
    release(jsonResponse({ secret: SECRET, uri: 'x', qrCode: QR }))

    await waitFor(() => expect(screen.getByRole('button', { name: 'Activer la double authentification' })).toBeEnabled())
  })

  it('confirme avec un code, montre les dix codes de secours une seule fois, et n’ouvre la session qu’après confirmation', async () => {
    const fn = stubFetch(routes)
    renderLogin()
    const user = await passwordStep('a2f_enrolement')
    await screen.findByRole('img', { name: /QR code à scanner/ })

    await user.type(screen.getByLabelText("Code d'authentification"), '654321')
    await user.click(screen.getByRole('button', { name: 'Activer la double authentification' }))

    const titre = await screen.findByRole('heading', { level: 2, name: 'Vos codes de secours' })
    expect(titre).toHaveFocus()
    expect(screen.getByRole('alert')).toHaveTextContent('ne seront plus jamais affichés')
    const liste = document.querySelector('ol.backup-codes') as HTMLElement
    expect(within(liste).getAllByRole('listitem')).toHaveLength(10)
    expect(within(liste).getAllByRole('listitem')[0]).toHaveTextContent(CODES[0])
    // La session n'est PAS encore ouverte : l'utilisateur voit d'abord les codes.
    expect(getAccessToken()).toBeNull()
    expect(screen.queryByText('Bonjour Léa')).not.toBeInTheDocument()
    const appel = fn.mock.calls.find(([url]) => url === '/api/connexion/a2f/enrolement/confirmation')
    expect(auth(appel?.[1] ?? {})).toBe('Bearer jeton-enrol')
    expect(JSON.parse(String(appel?.[1]?.body))).toEqual({ code: '654321' })

    await user.click(screen.getByRole('button', { name: "J'ai conservé mes codes, continuer" }))

    expect(await screen.findByText('Bonjour Léa')).toBeInTheDocument()
    expect(getAccessToken()).toBe('jwt-bo')
    expect(screen.queryByText(CODES[0])).not.toBeInTheDocument()   // les codes disparaissent de l'écran
  })

  it('les codes de secours ne sont jamais stockés', async () => {
    stubFetch(routes)
    renderLogin()
    const user = await passwordStep('a2f_enrolement')
    await screen.findByRole('img', { name: /QR code à scanner/ })
    await user.type(screen.getByLabelText("Code d'authentification"), '654321')
    await user.click(screen.getByRole('button', { name: 'Activer la double authentification' }))
    await screen.findByRole('heading', { level: 2, name: 'Vos codes de secours' })

    expect(JSON.stringify({ ...localStorage, ...sessionStorage }) + document.cookie + window.location.href).not.toContain(CODES[0])
  })

  it('un code de confirmation faux garde le QR code et permet un nouvel essai', async () => {
    let essais = 0
    stubFetch((url, init) =>
      url === '/api/connexion/a2f/enrolement/confirmation' && ++essais === 1
        ? jsonResponse({ message: 'Code incorrect ou expiré.', raison: 'code_invalide' }, 401)
        : routes(url, init),
    )
    renderLogin()
    const user = await passwordStep('a2f_enrolement')
    await screen.findByRole('img', { name: /QR code à scanner/ })

    await user.type(screen.getByLabelText("Code d'authentification"), '000000')
    await user.click(screen.getByRole('button', { name: 'Activer la double authentification' }))
    await waitFor(() => expect(screen.getByLabelText("Code d'authentification")).toHaveAttribute('aria-invalid', 'true'))
    expect(screen.getByRole('img', { name: /QR code à scanner/ })).toBeInTheDocument()

    await user.clear(screen.getByLabelText("Code d'authentification"))
    await user.type(screen.getByLabelText("Code d'authentification"), '654321')
    await user.click(screen.getByRole('button', { name: 'Activer la double authentification' }))

    expect(await screen.findByRole('heading', { level: 2, name: 'Vos codes de secours' })).toBeInTheDocument()
  })

  it('un jeton expiré pendant l’enrôlement ramène à la connexion', async () => {
    stubFetch((url, init) => (url === '/api/connexion/a2f/enrolement' ? jsonResponse({ message: 'Authentification requise.' }, 401) : routes(url, init)))
    renderLogin()
    const user = userEvent.setup()
    await user.type(await screen.findByLabelText('Adresse e-mail'), 'karim@example.test')
    await user.type(screen.getByLabelText('Mot de passe'), 'une phrase de passe solide')
    await user.click(screen.getByRole('button', { name: 'Se connecter' }))

    expect(await screen.findByText('Votre connexion a expiré. Saisissez de nouveau votre mot de passe.')).toBeInTheDocument()
    expect(screen.getByLabelText('Mot de passe')).toHaveValue('')
  })

  it('le texte d’aide n’est pas une image : le secret sort dans un <code>, jamais de HTML injecté', async () => {
    stubFetch((url, init) =>
      url === '/api/connexion/a2f/enrolement'
        ? jsonResponse({ secret: '<img src=x onerror=alert(1)>', uri: 'x', qrCode: QR })
        : routes(url, init),
    )
    renderLogin()
    await passwordStep('a2f_enrolement')

    await screen.findByRole('img', { name: /QR code à scanner/ })
    expect(document.querySelector('.a2f-secret img')).toBeNull()
  })

  it('est entièrement disponible en anglais', async () => {
    stubFetch(routes)
    renderLogin()
    const user = userEvent.setup()
    await user.click(await screen.findByRole('button', { name: 'English' }))
    await user.type(await screen.findByLabelText('E-mail address'), 'karim@example.test')
    await user.type(screen.getByLabelText('Password'), 'a strong passphrase here')
    await user.click(screen.getByRole('button', { name: 'Sign in' }))

    expect(await screen.findByRole('heading', { level: 2, name: 'Enable two-factor authentication' })).toBeInTheDocument()
    expect(await screen.findByRole('img', { name: /QR code to scan/ })).toBeInTheDocument()
    expect(screen.getByLabelText('Authentication code')).toBeInTheDocument()
  })
})
