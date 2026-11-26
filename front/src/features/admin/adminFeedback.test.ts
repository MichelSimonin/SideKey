import type { TFunction } from 'i18next'
import { describe, expect, it } from 'vitest'
import { ApiError } from '../../api/client'
import { adminFeedback, isOfferConflict } from './adminFeedback'

const t = ((key: string) => key) as unknown as TFunction

describe('adminFeedback', () => {
  it('désigne les champs d’un 422, y compris les descriptions imbriquées', () => {
    const error = new ApiError(422, { message: 'Invalide', erreurs: { titre: 'Valeur invalide.', 'descriptions.fr': 'Valeur invalide.' } })

    expect(adminFeedback(error, t)).toEqual({ fields: { titre: 'Valeur invalide.', 'descriptions.fr': 'Valeur invalide.' }, form: null })
  })

  it('affiche le message d’un 422 sans champ désigné (image refusée)', () => {
    expect(adminFeedback(new ApiError(422, { message: 'Seules les images WebP valides sont acceptées.' }), t)).toEqual({
      fields: {},
      form: 'Seules les images WebP valides sont acceptées.',
    })
    expect(adminFeedback(new ApiError(422, { message: 'x', erreurs: {} }), t).form).toBe('x')
  })

  it('traite un 409 comme un message général (doublon, élément utilisé), jamais comme une erreur de champ', () => {
    expect(adminFeedback(new ApiError(409, { message: 'Un éditeur porte déjà ce nom.' }), t)).toEqual({ fields: {}, form: 'Un éditeur porte déjà ce nom.' })
    expect(adminFeedback(new ApiError(409, {}), t).form).toBe('admin.errors.conflict')
  })

  it('traduit 403 et 404 sans exposer de détail', () => {
    expect(adminFeedback(new ApiError(403, { message: 'Accès refusé.' }), t).form).toBe('admin.errors.forbidden')
    expect(adminFeedback(new ApiError(404, {}), t).form).toBe('admin.errors.notFound')
    expect(adminFeedback(new ApiError(404, { message: "Cet élément n'existe pas." }), t).form).toBe("Cet élément n'existe pas.")
  })

  it('retombe sur les messages communs pour le réseau, le 429 et l’inattendu', () => {
    expect(adminFeedback(new TypeError('fetch failed'), t).form).toBe('auth.errors.network')
    expect(adminFeedback(new ApiError(429, {}, 120), t).form).toBe('auth.errors.rateLimited')
    expect(adminFeedback(new Error('boom'), t).form).toBe('auth.errors.generic')
    expect(adminFeedback(new ApiError(500, {}), t).form).toBe('auth.errors.generic')
  })
})

describe('isOfferConflict', () => {
  it('reconnaît le 409 du verrouillage optimiste par la version actuelle qu’il porte', () => {
    expect(isOfferConflict(new ApiError(409, { message: 'Offre modifiée', versionActuelle: 4 }))).toBe(true)
  })

  it('ne confond pas avec un autre 409 ni une autre erreur', () => {
    expect(isOfferConflict(new ApiError(409, { message: 'Doublon' }))).toBe(false)
    expect(isOfferConflict(new ApiError(422, { versionActuelle: 4 }))).toBe(false)
    expect(isOfferConflict(new Error('x'))).toBe(false)
  })
})
