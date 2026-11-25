import type { TFunction } from 'i18next'
import { describe, expect, it } from 'vitest'
import { ApiError } from '../../api/client'
import { feedbackFrom, hasFeedback, NO_FEEDBACK } from './formFeedback'

// Traducteur de test : renvoie la clé et ses paramètres, pour vérifier ce que l'interface demande d'afficher.
const t = ((key: string, params?: Record<string, unknown>) =>
  params ? `${key}:${JSON.stringify(params)}` : key) as unknown as TFunction

describe('feedbackFrom', () => {
  it('reprend les erreurs par champ d’un 422', () => {
    const error = new ApiError(422, { message: 'invalide', erreurs: { email: 'Adresse invalide.', motDePasse: 'Trop court.' } })

    expect(feedbackFrom(error, t)).toEqual({ fields: { email: 'Adresse invalide.', motDePasse: 'Trop court.' }, form: null })
  })

  it('rattache un 409 (e-mail déjà utilisé) au champ e-mail', () => {
    const error = new ApiError(409, { message: 'Un compte existe déjà avec cet e-mail.' })

    expect(feedbackFrom(error, t).fields).toEqual({ email: 'Un compte existe déjà avec cet e-mail.' })
  })

  it('arrondit le Retry-After d’un 429 à la minute supérieure', () => {
    expect(feedbackFrom(new ApiError(429, {}, 742), t).form).toBe('auth.errors.rateLimited:{"minutes":13}')
    expect(feedbackFrom(new ApiError(429, {}, 30), t).form).toBe('auth.errors.rateLimited:{"minutes":1}')
    expect(feedbackFrom(new ApiError(429, {}), t).form).toBe('auth.errors.rateLimited:{"minutes":1}')
  })

  it('affiche le message de l’API pour un 401 (générique, déjà traduit)', () => {
    expect(feedbackFrom(new ApiError(401, { message: 'E-mail ou mot de passe incorrect.' }), t).form).toBe(
      'E-mail ou mot de passe incorrect.',
    )
  })

  it('retombe sur un message générique sans corps exploitable', () => {
    expect(feedbackFrom(new ApiError(500, {}), t).form).toBe('auth.errors.generic')
    expect(feedbackFrom(new Error('boom'), t).form).toBe('auth.errors.generic')
  })

  it('signale une panne réseau (fetch lève une TypeError)', () => {
    expect(feedbackFrom(new TypeError('Failed to fetch'), t).form).toBe('auth.errors.network')
  })

  it('n’affiche jamais le message d’une exception inattendue (pas de détail technique)', () => {
    const feedback = feedbackFrom(new Error('SQLSTATE[42P01] relation "utilisateur" does not exist'), t)

    expect(JSON.stringify(feedback)).not.toContain('SQLSTATE')
  })
})

describe('hasFeedback', () => {
  it('est faux sans erreur', () => {
    expect(hasFeedback(NO_FEEDBACK)).toBe(false)
  })

  it('est vrai avec un message général ou une erreur de champ', () => {
    expect(hasFeedback({ fields: {}, form: 'x' })).toBe(true)
    expect(hasFeedback({ fields: { email: 'x' }, form: null })).toBe(true)
  })
})
