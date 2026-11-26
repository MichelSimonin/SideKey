import type { TFunction } from 'i18next'
import { ApiError } from '../../api/client'
import { feedbackFrom, NO_FEEDBACK, type FormFeedback } from '../auth/formFeedback'

export { NO_FEEDBACK, type FormFeedback }

/**
 * Traduit une erreur d'appel du back-office en retour de formulaire : le 422 de l'API désigne les champs fautifs, un 409
 * (doublon, élément utilisé, conflit d'offre) devient un message général — ce n'est pas une saisie à corriger champ par champ.
 * Les messages arrivent de l'API déjà traduits dans la langue de la page.
 */
export function adminFeedback(error: unknown, t: TFunction): FormFeedback {
  if (error instanceof ApiError) {
    // 422 sans champ désigné (image de jaquette refusée…) : le message de l'API est le motif, à afficher tel quel.
    if (error.status === 422 && Object.keys(error.body.erreurs ?? {}).length === 0) {
      return { fields: {}, form: error.body.message ?? t('auth.errors.generic') }
    }
    if (error.status === 409) {
      return { fields: {}, form: error.body.message ?? t('admin.errors.conflict') }
    }
    if (error.status === 403) {
      return { fields: {}, form: t('admin.errors.forbidden') }
    }
    if (error.status === 404) {
      return { fields: {}, form: error.body.message ?? t('admin.errors.notFound') }
    }
  }
  return feedbackFrom(error, t)
}

/** Verrouillage optimiste d'une offre : 409 portant la version actuelle (US-51, écran E12). */
export function isOfferConflict(error: unknown): error is ApiError {
  return error instanceof ApiError && error.status === 409 && typeof error.body.versionActuelle === 'number'
}
