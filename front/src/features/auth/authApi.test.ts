import { describe, expect, it } from 'vitest'
import { getAccessToken } from '../../api/session'
import { header, jsonResponse, mockApi, sessionPayload, setCsrfCookie, USER } from '../../test/helpers'
import { confirmEnrolment, login, logout, openSession, register, startEnrolment, verifyCode } from './authApi'

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

    expect(result).toEqual({ kind: 'second-factor', mode: 'code', token: 'jeton-intermediaire', expiresIn: 300 })
    expect(getAccessToken()).toBeNull()
  })

  it('un compte du back-office sans A2F est orienté vers l’enrôlement', async () => {
    const { fetchFn } = mockApi({
      'POST connexion': [jsonResponse({ etape: 'a2f_enrolement', jetonIntermediaire: 'jeton-i', expireDans: 300 })],
    })

    const result = await login({ email: 'gestionnaire@sidekey.test', motDePasse: 'x' }, { fetchFn })

    expect(result).toEqual({ kind: 'second-factor', mode: 'enrolment', token: 'jeton-i', expiresIn: 300 })
    expect(getAccessToken()).toBeNull()
  })

  it('le jeton intermédiaire n’est jamais écrit dans un stockage du navigateur', async () => {
    const { fetchFn } = mockApi({
      'POST connexion': [jsonResponse({ etape: 'a2f', jetonIntermediaire: 'jeton-intermediaire', expireDans: 300 })],
    })

    await login({ email: 'admin@sidekey.test', motDePasse: 'x' }, { fetchFn })

    expect(JSON.stringify({ ...localStorage, ...sessionStorage }) + document.cookie).not.toContain('jeton-intermediaire')
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

describe('seconde étape (A2F)', () => {
  it('verifyCode présente le jeton intermédiaire, jamais le jeton d’accès, puis installe la session', async () => {
    const { fetchFn, calls } = mockApi({ 'POST connexion/a2f': [jsonResponse(sessionPayload('jwt-definitif'))] })

    const user = await verifyCode('jeton-intermediaire', '123456', { fetchFn })

    expect(user).toEqual(USER)
    expect(header(calls[0], 'Authorization')).toBe('Bearer jeton-intermediaire')
    expect(JSON.parse(String(calls[0].init.body))).toEqual({ code: '123456' })
    expect(getAccessToken()).toBe('jwt-definitif')
  })

  it('un 401 de la seconde étape ne déclenche aucun rafraîchissement et n’installe rien', async () => {
    const { fetchFn, mock } = mockApi({
      'POST connexion/a2f': [jsonResponse({ message: 'Code incorrect ou expiré.', raison: 'code_invalide' }, 401)],
    })
    setCsrfCookie()

    await expect(verifyCode('jeton-intermediaire', '000000', { fetchFn })).rejects.toMatchObject({
      status: 401,
      body: { raison: 'code_invalide' },
    })

    expect(mock).toHaveBeenCalledTimes(1)   // pas d'appel à /token/refresh
    expect(getAccessToken()).toBeNull()
  })

  it('startEnrolment renvoie le secret et le QR code', async () => {
    const { fetchFn, calls } = mockApi({
      'POST connexion/a2f/enrolement': [jsonResponse({ secret: 'ABCDEFGHABCDEFGHABCDEFGHABCDEFGH', uri: 'otpauth://totp/x', qrCode: 'data:image/svg+xml;base64,AAAA' })],
    })

    const data = await startEnrolment('jeton-i', { fetchFn })

    expect(data.secret).toHaveLength(32)
    expect(header(calls[0], 'Authorization')).toBe('Bearer jeton-i')
  })

  it('confirmEnrolment n’ouvre PAS la session : les codes de secours doivent d’abord être vus', async () => {
    const codes = Array.from({ length: 10 }, (_, i) => `ABCDE-FGHJ${i}`)
    const { fetchFn } = mockApi({
      'POST connexion/a2f/enrolement/confirmation': [jsonResponse({ ...sessionPayload('jwt-neuf'), codesSecours: codes }, 201)],
    })

    const confirmation = await confirmEnrolment('jeton-i', '123456', { fetchFn })

    expect(confirmation.codesSecours).toEqual(codes)
    expect(getAccessToken()).toBeNull()

    openSession(confirmation)

    expect(getAccessToken()).toBe('jwt-neuf')
  })

  it('les codes de secours ne sont jamais écrits dans un stockage du navigateur', async () => {
    const { fetchFn } = mockApi({
      'POST connexion/a2f/enrolement/confirmation': [jsonResponse({ ...sessionPayload('jwt-neuf'), codesSecours: ['ZZZZZ-YYYYY'] }, 201)],
    })

    openSession(await confirmEnrolment('jeton-i', '123456', { fetchFn }))

    expect(JSON.stringify({ ...localStorage, ...sessionStorage }) + document.cookie).not.toContain('ZZZZZ-YYYYY')
  })
})
