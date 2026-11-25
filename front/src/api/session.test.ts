import { describe, expect, it, vi } from 'vitest'
import { sessionPayload } from '../test/helpers'
import { applySession, getAccessToken, onSession } from './session'

describe('session en mémoire', () => {
  it('garde le jeton d’accès dans une variable de module', () => {
    expect(getAccessToken()).toBeNull()

    applySession(sessionPayload('jwt-secret'))

    expect(getAccessToken()).toBe('jwt-secret')
  })

  it('n’écrit le jeton ni dans localStorage, ni dans sessionStorage, ni dans un cookie (CDC §11.4)', () => {
    applySession(sessionPayload('jwt-tres-secret'))

    const stockages = [JSON.stringify({ ...localStorage }), JSON.stringify({ ...sessionStorage }), document.cookie]
    for (const contenu of stockages) {
      expect(contenu).not.toContain('jwt-tres-secret')
    }
    expect(localStorage.length).toBe(0)
    expect(sessionStorage.length).toBe(0)
  })

  it('efface le jeton à la déconnexion', () => {
    applySession(sessionPayload('jwt-1'))

    applySession(null)

    expect(getAccessToken()).toBeNull()
  })

  it('prévient les abonnés à chaque changement et permet de se désabonner', () => {
    const listener = vi.fn()
    const unsubscribe = onSession(listener)

    applySession(sessionPayload('jwt-1'))
    applySession(null)
    unsubscribe()
    applySession(sessionPayload('jwt-2'))

    expect(listener).toHaveBeenCalledTimes(2)
    expect(listener.mock.calls[0][0]).toMatchObject({ jetonAcces: 'jwt-1' })
    expect(listener.mock.calls[1][0]).toBeNull()
  })
})
