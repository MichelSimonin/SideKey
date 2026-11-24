import i18n from 'i18next'
import LanguageDetector from 'i18next-browser-languagedetector'
import { initReactI18next } from 'react-i18next'
import en from './locales/en/common.json'
import fr from './locales/fr/common.json'
import { DEFAULT_LANGUAGE, LANGUAGE_STORAGE_KEY, SUPPORTED_LANGUAGES, toSupportedLanguage } from './config'

export const resources = {
  fr: { common: fr },
  en: { common: en },
} as const

/** RGAA 8.3 / 8.4 : l'attribut lang de la page suit la langue affichée. */
export function syncDocumentLanguage(language: string): void {
  document.documentElement.lang = toSupportedLanguage(language)
}

i18n.on('languageChanged', syncDocumentLanguage)

void i18n
  .use(LanguageDetector)
  .use(initReactI18next)
  .init({
    resources,
    defaultNS: 'common',
    ns: ['common'],
    supportedLngs: [...SUPPORTED_LANGUAGES],
    nonExplicitSupportedLngs: true,
    load: 'languageOnly',
    fallbackLng: DEFAULT_LANGUAGE,
    // Choix mémorisé, sinon langue du navigateur, sinon français (CDC §12.4).
    // La préférence du compte prendra le pas une fois l'authentification livrée (F-03).
    detection: {
      order: ['localStorage', 'navigator'],
      lookupLocalStorage: LANGUAGE_STORAGE_KEY,
      caches: ['localStorage'],
    },
    interpolation: { escapeValue: false }, // React échappe déjà le rendu
    returnNull: false,
    initAsync: false,
  })

export default i18n
