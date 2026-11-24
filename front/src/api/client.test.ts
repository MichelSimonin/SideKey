import { describe, expect, it, vi } from 'vitest'
import { apiHeaders, apiUrl, fetchHealth } from './client'

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
