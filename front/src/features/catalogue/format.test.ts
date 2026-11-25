import { describe, expect, it } from 'vitest'
import { coverUrl, formatDate, formatPrice, formatRating } from './format'

describe('formatPrice', () => {
  it('met la devise et la virgule selon la langue, sans recalcul', () => {
    expect(formatPrice('14.99', 'fr').replace(/\s/g, ' ')).toBe('14,99 €')
    expect(formatPrice('14.99', 'en')).toBe('€14.99')
  })

  it('affiche telle quelle une valeur qui n’est pas un nombre', () => {
    expect(formatPrice('n/a', 'fr')).toBe('n/a')
  })
})

describe('formatDate et formatRating', () => {
  it('formate une date ISO sans décalage de fuseau', () => {
    expect(formatDate('2020-02-21', 'fr')).toBe('21 février 2020')
    expect(formatDate('2020-02-21', 'en')).toBe('February 21, 2020')
  })

  it('formate la note avec une décimale', () => {
    expect(formatRating(3, 'fr')).toBe('3,0')
    expect(formatRating(4.5, 'en')).toBe('4.5')
  })
})

describe('coverUrl', () => {
  it('construit l’adresse de la jaquette sans double slash', () => {
    expect(coverUrl('jaquettes/jeu-01.webp', '/media/')).toBe('/media/jaquettes/jeu-01.webp')
    expect(coverUrl('/jaquettes/jeu-01.webp', '/media')).toBe('/media/jaquettes/jeu-01.webp')
  })
})
