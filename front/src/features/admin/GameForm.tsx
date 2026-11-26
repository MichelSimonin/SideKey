import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { SelectField } from '../../components/ui/SelectField'
import { TextAreaField } from '../../components/ui/TextAreaField'
import { TextField } from '../../components/ui/TextField'
import { PEGI_VALUES } from '../catalogue/types'
import { ErrorSummary } from '../auth/ErrorSummary'
import { adminFeedback, NO_FEEDBACK, type FormFeedback } from './adminFeedback'
import type { AdminGame, GameInput, GenreOption, Publisher } from './types'

const IDS = {
  titre: 'game-title',
  slug: 'game-slug',
  dateSortie: 'game-release',
  pegi: 'game-pegi',
  editeurId: 'game-publisher',
  'descriptions.fr': 'game-description-fr',
  'descriptions.en': 'game-description-en',
  genres: 'game-genres',
}
const DESCRIPTION_MIN = 10
const DESCRIPTION_MAX = 4000

type Props = {
  game: AdminGame | null
  publishers: Publisher[]
  genres: GenreOption[]
  /** Envoie le formulaire (création ou modification) ; lève l'erreur de l'API, mappée ici sur les champs. */
  onSubmit: (input: GameInput) => Promise<void>
}

/**
 * Formulaire d'un jeu (US-50) : titre, slug, date de sortie, PEGI, éditeur, descriptions FR et EN, genres. Les contrôles du
 * formulaire sont un confort (retour immédiat) ; l'API revalide tout et reste seule juge : ses erreurs par champ (422) sont
 * reliées aux champs et résumées en tête de formulaire (RGAA 11).
 */
export function GameForm({ game, publishers, genres, onSubmit }: Props) {
  const { t } = useTranslation()
  const [title, setTitle] = useState(game?.titre ?? '')
  const [slug, setSlug] = useState(game?.slug ?? '')
  const [release, setRelease] = useState(game?.dateSortie ?? '')
  const [pegi, setPegi] = useState(game === null ? '' : String(game.pegi))
  const [publisherId, setPublisherId] = useState(game?.editeur.id ?? '')
  const [active, setActive] = useState(game?.actif ?? true)
  const [descriptionFr, setDescriptionFr] = useState(game?.descriptions.fr ?? '')
  const [descriptionEn, setDescriptionEn] = useState(game?.descriptions.en ?? '')
  const [selectedGenres, setSelectedGenres] = useState<string[]>(game?.genres ?? [])
  const [pending, setPending] = useState(false)
  const [feedback, setFeedback] = useState<FormFeedback>(NO_FEEDBACK)

  const labels = {
    titre: t('admin.form.title'),
    slug: t('admin.form.slug'),
    dateSortie: t('admin.form.releaseDate'),
    pegi: t('admin.form.pegi'),
    editeurId: t('admin.form.publisher'),
    'descriptions.fr': t('admin.form.descriptionFr'),
    'descriptions.en': t('admin.form.descriptionEn'),
    genres: t('admin.form.genres'),
  }

  function describe(text: string): string | undefined {
    const length = text.trim().length
    if (length < DESCRIPTION_MIN) return t('admin.form.tooShort')
    if (length > DESCRIPTION_MAX) return t('admin.form.tooLong')
    return undefined
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()

    const missing: Record<string, string> = {}
    if (title.trim() === '') missing.titre = t('auth.errors.required')
    if (pegi === '') missing.pegi = t('auth.errors.required')
    if (publisherId === '') missing.editeurId = t('auth.errors.required')
    const frError = describe(descriptionFr)
    if (frError) missing['descriptions.fr'] = frError
    const enError = describe(descriptionEn)
    if (enError) missing['descriptions.en'] = enError
    if (Object.keys(missing).length > 0) {
      setFeedback({ fields: missing, form: null })
      return
    }

    setPending(true)
    setFeedback(NO_FEEDBACK)
    try {
      await onSubmit({
        titre: title.trim(),
        slug: slug.trim() === '' ? null : slug.trim(),
        dateSortie: release === '' ? null : release,
        pegi: Number(pegi),
        editeurId: publisherId,
        actif: active,
        descriptions: { fr: descriptionFr.trim(), en: descriptionEn.trim() },
        genres: selectedGenres,
      })
    } catch (error) {
      setFeedback(adminFeedback(error, t))
    } finally {
      setPending(false)
    }
  }

  function toggleGenre(slugValue: string, checked: boolean) {
    setSelectedGenres((current) => (checked ? [...current, slugValue] : current.filter((value) => value !== slugValue)))
  }

  return (
    <form onSubmit={(event) => void submit(event)} noValidate aria-busy={pending} className="admin-form">
      <ErrorSummary feedback={feedback} fieldIds={IDS} labels={labels} />

      <TextField
        id={IDS.titre}
        name="titre"
        label={labels.titre}
        value={title}
        onChange={(event) => setTitle(event.target.value)}
        error={feedback.fields.titre}
        maxLength={200}
        required
      />
      <TextField
        id={IDS.slug}
        name="slug"
        label={labels.slug}
        hint={t('admin.form.slugHint')}
        value={slug}
        onChange={(event) => setSlug(event.target.value)}
        error={feedback.fields.slug}
        maxLength={200}
        autoCapitalize="none"
        spellCheck={false}
      />
      <TextField
        id={IDS.dateSortie}
        name="dateSortie"
        type="date"
        label={labels.dateSortie}
        value={release}
        onChange={(event) => setRelease(event.target.value)}
        error={feedback.fields.dateSortie}
      />
      <SelectField
        id={IDS.pegi}
        name="pegi"
        label={labels.pegi}
        placeholder={t('admin.form.pegiPlaceholder')}
        value={pegi}
        onChange={(event) => setPegi(event.target.value)}
        error={feedback.fields.pegi}
        options={PEGI_VALUES.map((age) => ({ value: String(age), label: t('admin.form.pegiOption', { age }) }))}
        required
      />
      <SelectField
        id={IDS.editeurId}
        name="editeurId"
        label={labels.editeurId}
        placeholder={t('admin.form.publisherPlaceholder')}
        value={publisherId}
        onChange={(event) => setPublisherId(event.target.value)}
        error={feedback.fields.editeurId}
        options={publishers.map((publisher) => ({ value: publisher.id, label: publisher.nom }))}
        required
      />
      <TextAreaField
        id={IDS['descriptions.fr']}
        name="descriptionFr"
        label={labels['descriptions.fr']}
        hint={t('admin.form.descriptionHint')}
        value={descriptionFr}
        onChange={(event) => setDescriptionFr(event.target.value)}
        error={feedback.fields['descriptions.fr']}
        maxLength={DESCRIPTION_MAX + 200}
        lang="fr"
        required
      />
      <TextAreaField
        id={IDS['descriptions.en']}
        name="descriptionEn"
        label={labels['descriptions.en']}
        hint={t('admin.form.descriptionHint')}
        value={descriptionEn}
        onChange={(event) => setDescriptionEn(event.target.value)}
        error={feedback.fields['descriptions.en']}
        maxLength={DESCRIPTION_MAX + 200}
        lang="en"
        required
      />

      <fieldset id={IDS.genres} aria-describedby={feedback.fields.genres ? 'game-genres-error' : undefined}>
        <legend>{labels.genres}</legend>
        <p className="field-hint">{t('admin.form.genresHint')}</p>
        <div className="checkbox-grid">
          {genres.map((genre) => (
            <label key={genre.slug} className="checkbox">
              <input type="checkbox" checked={selectedGenres.includes(genre.slug)} onChange={(event) => toggleGenre(genre.slug, event.target.checked)} />
              {genre.libelle}
            </label>
          ))}
        </div>
        {feedback.fields.genres ? (
          <p id="game-genres-error" className="field-error">
            {feedback.fields.genres}
          </p>
        ) : null}
      </fieldset>

      <label className="checkbox">
        <input type="checkbox" checked={active} onChange={(event) => setActive(event.target.checked)} />
        {t('admin.form.active')}
      </label>

      <button type="submit" className="btn" disabled={pending}>
        {pending ? t('admin.common.saving') : t('admin.common.save')}
      </button>
    </form>
  )
}
