import type { TextareaHTMLAttributes } from 'react'

type TextAreaFieldProps = Omit<TextareaHTMLAttributes<HTMLTextAreaElement>, 'id'> & {
  id: string
  label: string
  hint?: string
  error?: string
}

/** Zone de texte accessible : mêmes liens étiquette, aide et erreur que TextField (RGAA 11). */
export function TextAreaField({ id, label, hint, error, ...areaProps }: TextAreaFieldProps) {
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
      <textarea id={id} rows={5} aria-invalid={error ? true : undefined} aria-describedby={describedBy} {...areaProps} />
      {error ? (
        <p id={errorId} className="field-error">
          {error}
        </p>
      ) : null}
    </div>
  )
}
