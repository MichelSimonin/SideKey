import { useTranslation } from 'react-i18next'
import { SUPPORTED_LANGUAGES, toSupportedLanguage } from '../../i18n/config'

/** Sélecteur FR / EN présent dans l'en-tête de chaque page (BX-05, BX-06). */
export function LanguageSwitcher() {
  const { t, i18n } = useTranslation()
  const current = toSupportedLanguage(i18n.resolvedLanguage)

  return (
    <div role="group" aria-label={t('header.language.label')} className="language-switcher">
      {SUPPORTED_LANGUAGES.map((language) => (
        <button
          key={language}
          type="button"
          lang={language}
          aria-pressed={current === language}
          aria-label={t(`header.language.${language}`)}
          onClick={() => void i18n.changeLanguage(language)}
        >
          {language.toUpperCase()}
        </button>
      ))}
    </div>
  )
}
