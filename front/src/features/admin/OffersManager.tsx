import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { SelectField } from '../../components/ui/SelectField'
import { TextField } from '../../components/ui/TextField'
import { ErrorSummary } from '../auth/ErrorSummary'
import { formatPrice } from '../catalogue/format'
import { createOffer, updateOffer } from './adminApi'
import { adminFeedback, isOfferConflict, NO_FEEDBACK, type FormFeedback } from './adminFeedback'
import type { AdminOffer, Platform } from './types'

const PRICE = /^\d{1,5}(\.\d{1,2})?$/
const THRESHOLD_MAX = 32_767

type Draft = { id: string; prix: string; seuil: string; actif: boolean }

type Props = {
  gameId: string
  offers: AdminOffer[]
  platforms: Platform[]
  /** Remplace une offre dans le jeu affiché (après création, modification ou rechargement). */
  onOfferSaved: (offer: AdminOffer) => void
  /** Recharge le jeu depuis l'API (après un conflit de version). */
  onReload: () => Promise<void>
}

/**
 * Offres d'un jeu par plateforme (US-51, écran E12) : prix TTC, seuil d'alerte, mise en vente. Chaque offre est lue avec sa
 * `version` ; la modification la rend à l'API. Si un collègue a enregistré entre-temps, l'API répond 409 : l'écran l'indique
 * explicitement, ne perd pas la saisie en cours de lecture et propose de recharger l'offre — jamais d'écrasement silencieux.
 * Le prix est affiché tel que l'API le fournit (aucun calcul de prix côté front).
 */
export function OffersManager({ gameId, offers, platforms, onOfferSaved, onReload }: Props) {
  const { t, i18n } = useTranslation()
  const language = i18n.resolvedLanguage ?? 'fr'
  const [draft, setDraft] = useState<Draft | null>(null)
  const [conflict, setConflict] = useState<{ id: string; message: string } | null>(null)
  const [feedback, setFeedback] = useState<FormFeedback>(NO_FEEDBACK)
  const [notice, setNotice] = useState('')
  const [pending, setPending] = useState(false)
  const [newPlatform, setNewPlatform] = useState('')
  const [newPrice, setNewPrice] = useState('')
  const [newThreshold, setNewThreshold] = useState('5')
  const [addFeedback, setAddFeedback] = useState<FormFeedback>(NO_FEEDBACK)

  const free = platforms.filter((platform) => !offers.some((offer) => offer.actif && offer.plateforme.id === platform.id))
  const editLabels = { prix: t('admin.offers.priceLabel'), seuilAlerte: t('admin.offers.thresholdLabel') }
  const editIds = { prix: 'offer-edit-price', seuilAlerte: 'offer-edit-threshold' }
  const addLabels = { plateformeId: t('admin.offers.platform'), prix: t('admin.offers.priceLabel'), seuilAlerte: t('admin.offers.thresholdLabel') }
  const addIds = { plateformeId: 'offer-new-platform', prix: 'offer-new-price', seuilAlerte: 'offer-new-threshold' }

  function validate(price: string, threshold: string): Record<string, string> {
    const errors: Record<string, string> = {}
    if (!PRICE.test(price) || Number(price) <= 0) errors.prix = t('admin.offers.priceHint')
    const value = Number(threshold)
    if (threshold === '' || !Number.isInteger(value) || value < 0 || value > THRESHOLD_MAX) errors.seuilAlerte = t('auth.errors.required')
    return errors
  }

  async function save(offer: AdminOffer, event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (draft === null) return
    const errors = validate(draft.prix, draft.seuil)
    if (Object.keys(errors).length > 0) {
      setFeedback({ fields: errors, form: null })
      return
    }

    setPending(true)
    setFeedback(NO_FEEDBACK)
    setConflict(null)
    setNotice('')
    try {
      const saved = await updateOffer(offer.id, { prix: draft.prix, seuilAlerte: Number(draft.seuil), actif: draft.actif }, offer.version)
      onOfferSaved(saved)
      setDraft(null)
      setNotice(t('admin.offers.saved', { platform: offer.plateforme.libelle }))
    } catch (error) {
      if (isOfferConflict(error)) {
        // Conflit de version : message explicite de l'API, saisie conservée pour être refaite après rechargement.
        setConflict({ id: offer.id, message: error.body.message ?? t('admin.offers.conflictTitle') })
      } else {
        setFeedback(adminFeedback(error, t))
      }
    } finally {
      setPending(false)
    }
  }

  async function reload() {
    setPending(true)
    try {
      await onReload()
      setConflict(null)
      setDraft(null)
      setNotice(t('admin.offers.reloaded'))
    } catch (error) {
      setFeedback(adminFeedback(error, t))
    } finally {
      setPending(false)
    }
  }

  async function add(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    const errors = validate(newPrice, newThreshold)
    if (newPlatform === '') errors.plateformeId = t('auth.errors.required')
    if (Object.keys(errors).length > 0) {
      setAddFeedback({ fields: errors, form: null })
      return
    }

    setPending(true)
    setAddFeedback(NO_FEEDBACK)
    setNotice('')
    try {
      onOfferSaved(await createOffer(gameId, newPlatform, { prix: newPrice, seuilAlerte: Number(newThreshold) }))
      setNewPlatform('')
      setNewPrice('')
      setNewThreshold('5')
      setNotice(t('admin.offers.added'))
    } catch (error) {
      setAddFeedback(adminFeedback(error, t))
    } finally {
      setPending(false)
    }
  }

  return (
    <section aria-labelledby="offers-title" aria-busy={pending} className="admin-offers">
      <h2 id="offers-title">{t('admin.offers.title')}</h2>
      <p role="status" aria-live="polite" className="notice">
        {notice}
      </p>

      {conflict !== null ? (
        <div role="alert" className="error-summary">
          <p>{conflict.message}</p>
          <p>{t('admin.offers.conflictHelp')}</p>
          <button type="button" className="btn" onClick={() => void reload()} disabled={pending}>
            {t('admin.offers.reload')}
          </button>
        </div>
      ) : null}
      {draft !== null ? <ErrorSummary feedback={feedback} fieldIds={editIds} labels={editLabels} /> : null}

      {offers.length === 0 ? (
        <p>{t('admin.offers.none')}</p>
      ) : (
        <div className="table-wrap">
          <table className="admin-table">
            <caption>{t('admin.offers.caption')}</caption>
            <thead>
              <tr>
                <th scope="col">{t('admin.offers.columns.platform')}</th>
                <th scope="col">{t('admin.offers.columns.price')}</th>
                <th scope="col">{t('admin.offers.columns.threshold')}</th>
                <th scope="col">{t('admin.offers.columns.stock')}</th>
                <th scope="col">{t('admin.common.state')}</th>
                <th scope="col">{t('admin.common.actions')}</th>
              </tr>
            </thead>
            <tbody>
              {offers.map((offer) =>
                draft?.id === offer.id ? (
                  <tr key={offer.id}>
                    <th scope="row">{offer.plateforme.libelle}</th>
                    <td colSpan={5}>
                      <form onSubmit={(event) => void save(offer, event)} noValidate className="inline-form">
                        <TextField
                          id={editIds.prix}
                          name="prix"
                          label={editLabels.prix}
                          hint={t('admin.offers.priceHint')}
                          inputMode="decimal"
                          value={draft.prix}
                          onChange={(event) => setDraft({ ...draft, prix: event.target.value })}
                          error={feedback.fields.prix}
                          autoComplete="off"
                          required
                        />
                        <TextField
                          id={editIds.seuilAlerte}
                          name="seuilAlerte"
                          type="number"
                          min={0}
                          max={THRESHOLD_MAX}
                          label={editLabels.seuilAlerte}
                          value={draft.seuil}
                          onChange={(event) => setDraft({ ...draft, seuil: event.target.value })}
                          error={feedback.fields.seuilAlerte}
                          required
                        />
                        <label className="checkbox">
                          <input type="checkbox" checked={draft.actif} onChange={(event) => setDraft({ ...draft, actif: event.target.checked })} />
                          {t('admin.offers.activeLabel')}
                        </label>
                        <button type="submit" className="btn" disabled={pending}>
                          {pending ? t('admin.common.saving') : t('admin.common.save')}
                        </button>
                        <button
                          type="button"
                          className="btn btn-secondary"
                          onClick={() => {
                            setDraft(null)
                            setFeedback(NO_FEEDBACK)
                          }}
                        >
                          {t('admin.common.cancel')}
                        </button>
                      </form>
                    </td>
                  </tr>
                ) : (
                  <tr key={offer.id}>
                    <th scope="row">{offer.plateforme.libelle}</th>
                    <td>{formatPrice(offer.prix, language)}</td>
                    <td>{offer.seuilAlerte}</td>
                    <td>{offer.stock}</td>
                    <td>{offer.actif ? t('admin.common.active') : t('admin.common.inactive')}</td>
                    <td>
                      <button
                        type="button"
                        className="btn btn-secondary"
                        aria-label={t('admin.offers.edit', { platform: offer.plateforme.libelle })}
                        onClick={() => {
                          setFeedback(NO_FEEDBACK)
                          setConflict(null)
                          setNotice('')
                          setDraft({ id: offer.id, prix: offer.prix, seuil: String(offer.seuilAlerte), actif: offer.actif })
                        }}
                      >
                        {t('admin.common.edit')}
                      </button>
                    </td>
                  </tr>
                ),
              )}
            </tbody>
          </table>
        </div>
      )}

      <h3>{t('admin.offers.add')}</h3>
      {free.length === 0 ? (
        <p>{t('admin.offers.noPlatform')}</p>
      ) : (
        <form onSubmit={(event) => void add(event)} noValidate className="inline-form">
          <div className="full-row">
            <ErrorSummary feedback={addFeedback} fieldIds={addIds} labels={addLabels} />
          </div>
          <SelectField
            id={addIds.plateformeId}
            name="plateformeId"
            label={addLabels.plateformeId}
            placeholder={t('admin.offers.platformPlaceholder')}
            value={newPlatform}
            onChange={(event) => setNewPlatform(event.target.value)}
            error={addFeedback.fields.plateformeId}
            options={free.map((platform) => ({ value: platform.id, label: platform.libelle }))}
            required
          />
          <TextField
            id={addIds.prix}
            name="prix"
            label={addLabels.prix}
            hint={t('admin.offers.priceHint')}
            inputMode="decimal"
            value={newPrice}
            onChange={(event) => setNewPrice(event.target.value)}
            error={addFeedback.fields.prix}
            autoComplete="off"
            required
          />
          <TextField
            id={addIds.seuilAlerte}
            name="seuilAlerte"
            type="number"
            min={0}
            max={THRESHOLD_MAX}
            label={addLabels.seuilAlerte}
            value={newThreshold}
            onChange={(event) => setNewThreshold(event.target.value)}
            error={addFeedback.fields.seuilAlerte}
            required
          />
          <button type="submit" className="btn" disabled={pending}>
            {t('admin.offers.add')}
          </button>
        </form>
      )}
    </section>
  )
}
