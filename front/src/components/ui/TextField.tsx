import type { InputHTMLAttributes } from 'react'

type TextFieldProps = Omit<InputHTMLAttributes<HTMLInputElement>, 'id'> & {
  id: string
  label: string
  /** Aide à la saisie, reliée au champ (aria-describedby). */
  hint?: string
  /** Message d'erreur, relié au champ (aria-describedby) et annoncé par aria-invalid. */
  error?: string
}

/**
 * Champ de formulaire accessible (RGAA 11) : étiquette visible reliée au champ, aide et erreur reliées par
 * aria-describedby, état invalide annoncé. L'attribut autoComplete se règle à l'usage (WCAG 1.3.5).
 */
export function TextField({ id, label, hint, error, ...inputProps }: TextFieldProps) {
  const hintId = hint ? `${id}-hint` : undefined
  const errorId = error ? `${id}-error` : undefined
  const describedBy = [hintId, errorId].filter(Boolean).join(' ') || undefined

  return (
    <div className="field">
      <label htmlFor={id}>{label}</label>
      {hint ? (
        <p id={hintId} className="field-hint">
          {hint}
        </p>
      ) : null}
      <input id={id} aria-invalid={error ? true : undefined} aria-describedby={describedBy} {...inputProps} />
      {error ? (
        <p id={errorId} className="field-error">
          {error}
        </p>
      ) : null}
    </div>
  )
}
