import { describe, expect, it } from 'vitest'
import en from './locales/en/common.json'
import fr from './locales/fr/common.json'

type Tree = { [key: string]: string | Tree }

/** Aplati { a: { b: 'x' } } en { 'a.b': 'x' }. */
function flatten(tree: Tree, prefix = ''): Record<string, string> {
  return Object.entries(tree).reduce<Record<string, string>>((acc, [key, value]) => {
    const path = prefix ? `${prefix}.${key}` : key
    return typeof value === 'string' ? { ...acc, [path]: value } : { ...acc, ...flatten(value, path) }
  }, {})
}

// CDC §12.4 : la CI échoue si une clé manque dans l'une des deux langues.
describe('fichiers de traduction', () => {
  const frKeys = flatten(fr)
  const enKeys = flatten(en)

  it('ont exactement les mêmes clés en FR et en EN', () => {
    expect(Object.keys(enKeys).sort()).toEqual(Object.keys(frKeys).sort())
  })

  it("n'ont aucune valeur vide", () => {
    const empty = Object.entries({ ...prefixed('fr', frKeys), ...prefixed('en', enKeys) }).filter(
      ([, value]) => value.trim() === '',
    )
    expect(empty).toEqual([])
  })
})

function prefixed(language: string, keys: Record<string, string>): Record<string, string> {
  return Object.fromEntries(Object.entries(keys).map(([key, value]) => [`${language}:${key}`, value]))
}
