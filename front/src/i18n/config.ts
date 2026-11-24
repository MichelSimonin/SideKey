/** Langues de SideKey (CDC §12.4, décision F10) : français par défaut, anglais. */
export const SUPPORTED_LANGUAGES = ['fr', 'en'] as const
export type Language = (typeof SUPPORTED_LANGUAGES)[number]

export const DEFAULT_LANGUAGE: Language = 'fr'

/** Clé du localStorage où le choix de langue est mémorisé (cookie inutile : préférence purement locale). */
export const LANGUAGE_STORAGE_KEY = 'sidekey.langue'

export function isSupportedLanguage(value: unknown): value is Language {
  return typeof value === 'string' && (SUPPORTED_LANGUAGES as readonly string[]).includes(value)
}

/** Réduit une étiquette BCP 47 (« en-US », « fr-CA ») à une langue gérée, sinon la langue par défaut. */
export function toSupportedLanguage(tag: string | null | undefined): Language {
  const base = (tag ?? '').toLowerCase().split('-')[0]
  return isSupportedLanguage(base) ? base : DEFAULT_LANGUAGE
}
