import { useEffect, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { hasFeedback, type FormFeedback } from './formFeedback'

/**
 * Résumé des erreurs d'un formulaire (RGAA 11.10) : annoncé à l'apparition (role="alert"), reçoit le focus pour
 * que l'utilisateur de lecteur d'écran ou de clavier le trouve, et relie chaque erreur à son champ.
 */
export function ErrorSummary({
  feedback,
  fieldIds,
  labels,
}: {
  feedback: FormFeedback
  /** Nom du champ côté API → identifiant HTML du champ. */
  fieldIds: Record<string, string>
  /** Nom du champ côté API → libellé affiché. */
  labels: Record<string, string>
}) {
  const { t } = useTranslation()
  const ref = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (hasFeedback(feedback)) {
      ref.current?.focus()
    }
  }, [feedback])

  if (!hasFeedback(feedback)) {
    return null
  }

  const entries = Object.entries(feedback.fields).filter(([name]) => fieldIds[name] !== undefined)

  return (
    <div ref={ref} role="alert" tabIndex={-1} className="error-summary">
      {feedback.form ? <p>{feedback.form}</p> : null}
      {entries.length > 0 ? (
        <>
          <p>{t('auth.errors.summary')}</p>
          <ul>
            {entries.map(([name, message]) => (
              <li key={name}>
                <a href={`#${fieldIds[name]}`}>
                  {labels[name]} : {message}
                </a>
              </li>
            ))}
          </ul>
        </>
      ) : null}
    </div>
  )
}
