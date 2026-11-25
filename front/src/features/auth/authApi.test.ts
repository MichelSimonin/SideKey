import { describe, expect, it } from 'vitest'
import { getAccessToken } from '../../api/session'
import { header, jsonResponse, mockApi, sessionPayload, setCsrfCookie, USER } from '../../test/helpers'
import { login, logout, register } from './authApi'

describe('login', () => {
  it('installe la session en mémoire et renvoie l’utilisateur', async () => {
    const { fetchFn, calls } = mockApi({ 'POST connexion': [jsonResponse(sessionPayload('jwt-1'))] })

    const result = await login({ email: 'lea@example.test', motDePasse: 'une phrase de passe' }, { fetchFn })

    expect(result).toEqual({ kind: 'connected', user: USER })
    expect(getAccessToken()).toBe('jwt-1')
    expect(JSON.parse(String(calls[0].init.body))).toEqual({ email: 'lea@example.test', motDePasse: 'une phrase de passe' })
  })

  it('un compte avec A2F ne reçoit aucune session : seulement l’étape suivante', async () => {
    const { fetchFn } = mockApi({
      'POST connexion': [jsonResponse({ etape: 'a2f', jetonIntermediaire: 'jeton-intermediaire', expireDans: 300 })],
    })

    const result = await login({ email: 'admin@sidekey.test', motDePasse: 'x' }, { fetchFn })

    expect(result).toEqual({ kind: 'second-factor', expiresIn: 300 })
    expect(getAccessToken()).toBeNull()
  })

  it('le jeton intermédiaire n’est pas gardé (la saisie du code arrive avec F-04)', async () => {
    const { fetchFn } = mockApi({
      'POST connexion': [jsonResponse({ etape: 'a2f', jetonIntermediaire: 'jeton-intermediaire', expireDans: 300 })],
    })

    await login({ email: 'admin@sidekey.test', motDePasse: 'x' }, { fetchFn })

    expect(JSON.stringify({ ...localStorage, ...sessionStorage })).not.toContain('jeton-intermediaire')
  })

  it('laisse remonter l’erreur de l’API sans installer de session', async () => {
    const { fetchFn } = mockApi({ 'POST connexion': [jsonResponse({ message: 'E-mail ou mot de passe incorrect.' }, 401)] })

    await expect(login({ email: 'a@b.test', motDePasse: 'x' }, { fetchFn })).rejects.toMatchObject({ status: 401 })

    expect(getAccessToken()).toBeNull()
  })
})

describe('register', () => {
  it('crée le compte puis ouvre la session avec les mêmes identifiants', async () => {
    const { fetchFn, calls } = mockApi({
      'POST inscription': [jsonResponse({ utilisateur: USER }, 201)],
      'POST connexion': [jsonResponse(sessionPayload('jwt-inscrit'))],
    })

    const result = await register(
      { email: 'lea@example.test', motDePasse: 'une phrase de passe', prenom: 'Léa', nom: 'Roy', langue: 'fr', consentement: true },
      { fetchFn },
    )

    expect(result.kind).toBe('connected')
    expect(getAccessToken()).toBe('jwt-inscrit')
    expect(calls.map((c) => c.url)).toEqual(['/api/inscription', '/api/connexion'])
    expect(JSON.parse(String(calls[1].init.body))).toEqual({ email: 'lea@example.test', motDePasse: 'une phrase de passe' })
  })

  it('n’ouvre pas de session quand l’inscription est refusée', async () => {
    const { fetchFn, mock } = mockApi({
      'POST inscription': [jsonResponse({ message: 'Un compte existe déjà avec cet e-mail.' }, 409)],
    })

    await expect(
      register({ email: 'a@b.test', motDePasse: 'x', prenom: 'A', nom: 'B', langue: 'fr', consentement: true }, { fetchFn }),
    ).rejects.toMatchObject({ status: 409 })

    expect(mock).toHaveBeenCalledTimes(1)
    expect(getAccessToken()).toBeNull()
  })
})

describe('logout', () => {
  it('révoque côté serveur, puis efface la session et le cookie CSRF', async () => {
    const { fetchFn, calls } = mockApi({
      'POST connexion': [jsonResponse(sessionPayload('jwt-1'))],
      'POST deconnexion': [new Response(null, { status: 204 })],
    })
    await login({ email: 'a@b.test', motDePasse: 'x' }, { fetchFn })
    setCsrfCookie()

    await logout({ fetchFn })

    expect(header(calls[1], 'Authorization')).toBe('Bearer jwt-1')
    expect(getAccessToken()).toBeNull()
    expect(document.cookie).not.toContain('sk_csrf')
  })

  it('efface la session locale même si l’API ne répond pas', async () => {
    const { fetchFn } = mockApi({
      'POST connexion': [jsonResponse(sessionPayload('jwt-1'))],
      'POST deconnexion': [jsonResponse({}, 500)],
    })
    await login({ email: 'a@b.test', motDePasse: 'x' }, { fetchFn })

    await expect(logout({ fetchFn })).resolves.toBeUndefined()

    expect(getAccessToken()).toBeNull()
  })
})
