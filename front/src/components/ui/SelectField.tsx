import type { SelectHTMLAttributes } from 'react'

type SelectFieldProps = Omit<SelectHTMLAttributes<HTMLSelectElement>, 'id'> & {
  id: string
  label: string
  hint?: string
  error?: string
  /** Première option, vide : « Choisir… ». */
  placeholder?: string
  options: Array<{ value: string; label: string }>
}

/** Liste déroulante accessible : mêmes liens étiquette, aide et erreur que TextField (RGAA 11). */
export function SelectField({ id, label, hint, error, placeholder, options, ...selectProps }: SelectFieldProps) {
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
      <select id={id} aria-invalid={error ? true : undefined} aria-describedby={describedBy} {...selectProps}>
        {placeholder !== undefined ? <option value="">{placeholder}</option> : null}
        {options.map((option) => (
          <option key={option.value} value={option.value}>
            {option.label}
          </option>
        ))}
      </select>
      {error ? (
        <p id={errorId} className="field-error">
          {error}
        </p>
      ) : null}
    </div>
  )
}
