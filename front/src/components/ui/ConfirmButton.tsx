import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'

/**
 * Action destructive en deux temps, sans boîte de dialogue du navigateur : un premier clic demande confirmation (le focus
 * passe sur « Confirmer »), Échap ou « Annuler » renonce et rend le focus au bouton d'origine. `label` nomme précisément la
 * cible pour les lecteurs d'écran (« Supprimer Studio Aube » et non « Supprimer » répété dans chaque ligne).
 */
export function ConfirmButton({
  label,
  visibleLabel,
  prompt,
  onConfirm,
  disabled = false,
}: {
  /** Nom accessible du bouton d'origine. */
  label: string
  /** Texte affiché du bouton d'origine. */
  visibleLabel: string
  /** Question posée avant d'agir. */
  prompt: string
  onConfirm: () => void | Promise<void>
  disabled?: boolean
}) {
  const { t } = useTranslation()
  const [asking, setAsking] = useState(false)
  const [pending, setPending] = useState(false)
  const trigger = useRef<HTMLButtonElement>(null)
  const confirm = useRef<HTMLButtonElement>(null)
  const wasAsking = useRef(false)

  useEffect(() => {
    if (asking) {
      confirm.current?.focus()
    } else if (wasAsking.current) {
      trigger.current?.focus()
    }
    wasAsking.current = asking
  }, [asking])

  if (!asking) {
    return (
      <button ref={trigger} type="button" className="btn btn-danger" aria-label={label} disabled={disabled} onClick={() => setAsking(true)}>
        {visibleLabel}
      </button>
    )
  }

  async function run() {
    setPending(true)
    try {
      await onConfirm()
    } finally {
      setPending(false)
      setAsking(false)
    }
  }

  return (
    <span
      role="group"
      aria-label={prompt}
      className="confirm"
      onKeyDown={(event) => {
        if (event.key === 'Escape') setAsking(false)
      }}
    >
      <span>{prompt}</span>
      <button ref={confirm} type="button" className="btn btn-danger" disabled={pending} onClick={() => void run()}>
        {t('admin.common.confirm')}
      </button>
      <button type="button" className="btn btn-secondary" disabled={pending} onClick={() => setAsking(false)}>
        {t('admin.common.cancel')}
      </button>
    </span>
  )
}
