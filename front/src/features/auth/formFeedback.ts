import type { TFunction } from 'i18next'
import { ApiError } from '../../api/client'

/** Retour d'un formulaire : une erreur par champ (réponse 422) et/ou un message général. */
export type FormFeedback = {
  fields: Record<string, string>
  form: string | null
}

export const NO_FEEDBACK: FormFeedback = { fields: {}, form: null }

/**
 * Traduit une erreur d'appel en retour de formulaire. Les messages de l'API arrivent déjà dans la langue de la page
 * (en-tête Accept-Language) ; le front n'y ajoute que ce qui dépend de l'interface (durée d'attente d'un 429).
 * Aucune saisie n'est renvoyée par l'API : on n'affiche que ses messages.
 */
export function feedbackFrom(error: unknown, t: TFunction): FormFeedback {
  if (error instanceof ApiError) {
    if (error.status === 422 && error.body.erreurs) {
      return { fields: error.body.erreurs, form: null }
    }
    if (error.status === 409) {
      // Conflit à l'inscription : un compte existe déjà avec cet e-mail.
      return { fields: { email: error.body.message ?? t('auth.errors.generic') }, form: null }
    }
    if (error.status === 429) {
      const minutes = Math.max(1, Math.ceil((error.retryAfter ?? 60) / 60))
      return { fields: {}, form: t('auth.errors.rateLimited', { minutes }) }
    }
    return { fields: {}, form: error.body.message ?? t('auth.errors.generic') }
  }
  if (error instanceof TypeError) {
    return { fields: {}, form: t('auth.errors.network') }   // fetch lève une TypeError quand le réseau échoue
  }
  return { fields: {}, form: t('auth.errors.generic') }
}

export function hasFeedback(feedback: FormFeedback): boolean {
  return feedback.form !== null || Object.keys(feedback.fields).length > 0
}
