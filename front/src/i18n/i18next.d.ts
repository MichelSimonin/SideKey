import 'i18next'
import type fr from './locales/fr/common.json'

// Clés typées : une clé inexistante est une erreur de compilation.
declare module 'i18next' {
  interface CustomTypeOptions {
    defaultNS: 'common'
    resources: { common: typeof fr }
  }
}
