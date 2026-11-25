import { describe, expect, it, vi } from 'vitest'
import { header, jsonResponse, mockApi, sessionPayload, setCsrfCookie } from '../test/helpers'
import { ApiError, apiHeaders, apiRequest, apiUrl, fetchHealth, readCookie, refreshSession } from './client'
import { applySession, getAccessToken } from './session'

describe('apiUrl', () => {
  it('joint la base et le chemin sans double slash', () => {
    expect(apiUrl('/health', '/api/')).toBe('/api/health')
    expect(apiUrl('health', '/api')).toBe('/api/health')
  })

  it('accepte une base absolue', () => {
    expect(apiUrl('jeux', 'https://api.example.test')).toBe('https://api.example.test/jeux')
  })
})

describe('fetchHealth', () => {
  it("renvoie le statut quand l'API répond 200", async () => {
    const fetchFn = vi.fn().mockResolvedValue(new Response(JSON.stringify({ status: 'ok' }), { status: 200 }))

    await expect(fetchHealth(fetchFn)).resolves.toEqual({ status: 'ok' })
    expect(fetchFn).toHaveBeenCalledWith('/api/health', expect.objectContaining({ credentials: 'same-origin' }))
  })

  it("lève une erreur quand l'API répond en erreur", async () => {
    const fetchFn = vi.fn().mockResolvedValue(new Response('', { status: 503 }))

    await expect(fetchHealth(fetchFn)).rejects.toThrow('HTTP 503')
  })
})

describe('apiHeaders', () => {
  it('transmet la langue de la page dans Accept-Language', () => {
    document.documentElement.lang = 'en'

    expect(apiHeaders()).toEqual({ Accept: 'application/json', 'Accept-Language': 'en' })
  })

  it('accepte une langue explicite', () => {
    expect(apiHeaders('fr')['Accept-Language']).toBe('fr')
  })
})

describe('readCookie', () => {
  it('lit un cookie par son nom, parmi plusieurs', () => {
    expect(readCookie('b', 'a=1; b=deux; c=3')).toBe('deux')
  })

  it('décode la valeur et garde les signes « = » (base64)', () => {
    expect(readCookie('sk_csrf', 'sk_csrf=ab%2Bc=')).toBe('ab+c=')
  })

  it('renvoie null quand le cookie est absent', () => {
    expect(readCookie('x', 'a=1')).toBeNull()
    expect(readCookie('x', '')).toBeNull()
  })

  it('ne confond pas deux noms qui se ressemblent', () => {
    expect(readCookie('csrf', 'sk_csrf=1')).toBeNull()
  })
})

describe('apiRequest', () => {
  it('envoie le jeton d’accès en mémoire dans l’en-tête Authorization', async () => {
    applySession(sessionPayload('jwt-en-memoire'))
    const { fetchFn, calls } = mockApi({ 'GET moi': [jsonResponse({ ok: true })] })

    await apiRequest('moi', { fetchFn })

    expect(header(calls[0], 'Authorization')).toBe('Bearer jwt-en-memoire')
    expect(calls[0].init.credentials).toBe('same-origin')
  })

  it('n’envoie pas d’Authorization quand personne n’est connecté', async () => {
    const { fetchFn, calls } = mockApi({ 'GET panier': [jsonResponse({})] })

    await apiRequest('panier', { fetchFn })

    expect(header(calls[0], 'Authorization')).toBeUndefined()
  })

  it('envoie un corps JSON avec le bon type de contenu et la langue de la page', async () => {
    document.documentElement.lang = 'en'
    const { fetchFn, calls } = mockApi({ 'POST connexion': [jsonResponse({})] })

    await apiRequest('connexion', { method: 'POST', body: { email: 'a@b.test' }, fetchFn })

    expect(header(calls[0], 'Content-Type')).toBe('application/json')
    expect(header(calls[0], 'Accept-Language')).toBe('en')
    expect(calls[0].init.body).toBe('{"email":"a@b.test"}')
  })

  it('rafraîchit en silence sur un 401, puis rejoue la requête une seule fois avec le nouveau jeton', async () => {
    applySession(sessionPayload('jwt-expire'))
    setCsrfCookie('csrf-1')
    const { fetchFn, calls } = mockApi({
      'GET moi': [jsonResponse({ message: 'Authentification requise.' }, 401), jsonResponse({ utilisateur: 'ok' })],
      'POST token/refresh': [jsonResponse(sessionPayload('jwt-neuf'))],
    })

    const result = await apiRequest<{ utilisateur: string }>('moi', { fetchFn })

    expect(result).toEqual({ utilisateur: 'ok' })
    expect(calls.map((c) => `${c.init.method ?? 'GET'} ${c.url}`)).toEqual([
      'GET /api/moi',
      'POST /api/token/refresh',
      'GET /api/moi',
    ])
    expect(header(calls[1], 'X-CSRF-Token')).toBe('csrf-1')
    expect(header(calls[2], 'Authorization')).toBe('Bearer jwt-neuf')
    expect(getAccessToken()).toBe('jwt-neuf')
  })

  it('ne boucle pas : un second 401 après rafraîchissement remonte en erreur', async () => {
    applySession(sessionPayload('jwt-expire'))
    setCsrfCookie()
    const { fetchFn, mock } = mockApi({
      'GET moi': [jsonResponse({}, 401), jsonResponse({}, 401)],
      'POST token/refresh': [jsonResponse(sessionPayload('jwt-neuf'))],
    })

    await expect(apiRequest('moi', { fetchFn })).rejects.toMatchObject({ status: 401 })
    expect(mock).toHaveBeenCalledTimes(3)
  })

  it('efface la session locale quand le rafraîchissement échoue', async () => {
    applySession(sessionPayload('jwt-expire'))
    setCsrfCookie()
    const { fetchFn } = mockApi({
      'GET moi': [jsonResponse({}, 401)],
      'POST token/refresh': [jsonResponse({ message: 'Session expirée' }, 401)],
    })

    await expect(apiRequest('moi', { fetchFn })).rejects.toBeInstanceOf(ApiError)

    expect(getAccessToken()).toBeNull()
    expect(readCookie('sk_csrf')).toBeNull()
  })

  it('ne tente pas de rafraîchissement quand on n’était pas connecté (visiteur)', async () => {
    const { fetchFn, mock } = mockApi({ 'GET moi': [jsonResponse({}, 401)] })

    await expect(apiRequest('moi', { fetchFn })).rejects.toMatchObject({ status: 401 })

    expect(mock).toHaveBeenCalledTimes(1)
  })

  it('un seul rafraîchissement pour plusieurs requêtes simultanées en 401 (jeton à usage unique)', async () => {
    applySession(sessionPayload('jwt-expire'))
    setCsrfCookie()
    const { fetchFn, calls } = mockApi({
      'GET a': [jsonResponse({}, 401), jsonResponse({ a: 1 })],
      'GET b': [jsonResponse({}, 401), jsonResponse({ b: 2 })],
      'GET c': [jsonResponse({}, 401), jsonResponse({ c: 3 })],
      'POST token/refresh': [jsonResponse(sessionPayload('jwt-neuf'))],
    })

    const results = await Promise.all([
      apiRequest('a', { fetchFn }),
      apiRequest('b', { fetchFn }),
      apiRequest('c', { fetchFn }),
    ])

    expect(results).toEqual([{ a: 1 }, { b: 2 }, { c: 3 }])
    expect(calls.filter((c) => c.url.endsWith('token/refresh'))).toHaveLength(1)
  })

  it('transforme une erreur de validation en ApiError avec les messages par champ', async () => {
    const { fetchFn } = mockApi({
      'POST inscription': [
        jsonResponse({ message: 'Certaines données sont invalides.', erreurs: { email: 'Adresse invalide.' } }, 422),
      ],
    })

    const error = await apiRequest('inscription', { method: 'POST', body: {}, fetchFn }).catch((e: unknown) => e)

    expect(error).toBeInstanceOf(ApiError)
    expect(error).toMatchObject({ status: 422, body: { erreurs: { email: 'Adresse invalide.' } } })
  })

  it('expose le délai Retry-After d’un 429', async () => {
    const { fetchFn } = mockApi({
      'POST connexion': [jsonResponse({ message: 'Trop de tentatives.' }, 429, { 'Retry-After': '742' })],
    })

    await expect(apiRequest('connexion', { method: 'POST', body: {}, fetchFn })).rejects.toMatchObject({
      status: 429,
      retryAfter: 742,
    })
  })

  it('accepte un corps d’erreur vide ou non JSON', async () => {
    const { fetchFn } = mockApi({ 'GET x': [new Response('<html>bad gateway</html>', { status: 502 })] })

    await expect(apiRequest('x', { fetchFn })).rejects.toMatchObject({ status: 502, body: {} })
  })

  it('renvoie undefined pour une réponse 204', async () => {
    const { fetchFn } = mockApi({ 'POST deconnexion': [new Response(null, { status: 204 })] })

    await expect(apiRequest('deconnexion', { method: 'POST', fetchFn })).resolves.toBeUndefined()
  })
})

describe('refreshSession', () => {
  it('n’interroge pas l’API sans cookie CSRF : aucune session à rétablir', async () => {
    const { fetchFn, mock } = mockApi({})

    await expect(refreshSession(fetchFn)).resolves.toBeNull()

    expect(mock).not.toHaveBeenCalled()
  })

  it('rétablit la session depuis le cookie de rafraîchissement', async () => {
    setCsrfCookie('csrf-xyz')
    const { fetchFn, calls } = mockApi({ 'POST token/refresh': [jsonResponse(sessionPayload('jwt-retabli'))] })

    const session = await refreshSession(fetchFn)

    expect(session?.jetonAcces).toBe('jwt-retabli')
    expect(getAccessToken()).toBe('jwt-retabli')
    expect(header(calls[0], 'X-CSRF-Token')).toBe('csrf-xyz')
    expect(calls[0].init.credentials).toBe('same-origin')
    // Le refresh opaque n'est jamais lu ni envoyé par le script : il voyage dans un cookie HttpOnly.
    expect(JSON.stringify(calls[0].init.headers)).not.toContain('sk_refresh')
  })

  it('considère la session terminée sur un refus de l’API', async () => {
    setCsrfCookie()
    const { fetchFn } = mockApi({ 'POST token/refresh': [jsonResponse({ message: 'Session expirée' }, 401)] })

    await expect(refreshSession(fetchFn)).resolves.toBeNull()

    expect(getAccessToken()).toBeNull()
  })

  it('considère la session terminée quand le réseau échoue', async () => {
    setCsrfCookie()
    const fetchFn = vi.fn().mockRejectedValue(new TypeError('Failed to fetch')) as unknown as typeof fetch

    await expect(refreshSession(fetchFn)).resolves.toBeNull()
  })
})
