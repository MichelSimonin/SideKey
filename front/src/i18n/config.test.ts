import { describe, expect, it } from 'vitest'
import { isSupportedLanguage, toSupportedLanguage } from './config'

describe('toSupportedLanguage', () => {
  it('garde la langue de base des étiquettes régionales', () => {
    expect(toSupportedLanguage('en-US')).toBe('en')
    expect(toSupportedLanguage('FR-ca')).toBe('fr')
  })

  it('replie sur le français pour une langue non gérée ou absente', () => {
    expect(toSupportedLanguage('de-DE')).toBe('fr')
    expect(toSupportedLanguage('')).toBe('fr')
    expect(toSupportedLanguage(undefined)).toBe('fr')
  })
})

describe('isSupportedLanguage', () => {
  it("n'accepte que fr et en", () => {
    expect(isSupportedLanguage('fr')).toBe(true)
    expect(isSupportedLanguage('en')).toBe(true)
    expect(isSupportedLanguage('es')).toBe(false)
    expect(isSupportedLanguage(42)).toBe(false)
  })
})
