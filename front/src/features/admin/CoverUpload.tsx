import { useRef, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { ConfirmButton } from '../../components/ui/ConfirmButton'
import { Cover } from '../catalogue/Cover'
import { removeCover, uploadCover } from './adminApi'
import { adminFeedback } from './adminFeedback'
import { COVER_MAX_BYTES, COVER_TYPE, type AdminGame } from './types'

/**
 * Jaquette d'un jeu (US-50) : téléversement sécurisé. Le navigateur contrôle le type et la taille pour répondre tout de
 * suite ; ce n'est qu'un confort, l'API relit le CONTENU du fichier (signature WebP, taille, dimensions), régénère le nom
 * et reste seule juge. Une image refusée n'est jamais écrite sur le serveur.
 */
export function CoverUpload({ game, onChange }: { game: AdminGame; onChange: (game: AdminGame) => void }) {
  const { t } = useTranslation()
  const input = useRef<HTMLInputElement>(null)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [pending, setPending] = useState(false)

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setError('')
    setNotice('')
    const file = input.current?.files?.[0]
    if (file === undefined) {
      setError(t('admin.cover.choose'))
      return
    }
    if (file.type !== COVER_TYPE) {
      setError(t('admin.cover.type'))
      return
    }
    if (file.size > COVER_MAX_BYTES) {
      setError(t('admin.cover.size'))
      return
    }

    setPending(true)
    try {
      onChange(await uploadCover(game.id, file))
      if (input.current) input.current.value = ''
      setNotice(t('admin.cover.uploaded'))
    } catch (failure) {
      const feedback = adminFeedback(failure, t)
      setError(feedback.fields.jaquette ?? feedback.form ?? t('auth.errors.generic'))
    } finally {
      setPending(false)
    }
  }

  async function remove() {
    setError('')
    setNotice('')
    try {
      await removeCover(game.id)
      onChange({ ...game, jaquette: null })
      setNotice(t('admin.cover.removed'))
    } catch (failure) {
      setError(adminFeedback(failure, t).form ?? t('auth.errors.generic'))
    }
  }

  return (
    <section aria-labelledby="cover-title" className="admin-cover">
      <h2 id="cover-title">{t('admin.cover.title')}</h2>
      <Cover path={game.jaquette} title={game.titre} width={150} height={200} />

      <form onSubmit={(event) => void submit(event)} noValidate aria-busy={pending}>
        <div className="field">
          <label htmlFor="cover-file">{t('admin.cover.file')}</label>
          <p id="cover-hint" className="field-hint">
            {t('admin.cover.hint')}
          </p>
          <input ref={input} id="cover-file" name="jaquette" type="file" accept={COVER_TYPE} aria-describedby={error ? 'cover-hint cover-error' : 'cover-hint'} aria-invalid={error ? true : undefined} />
          {error !== '' ? (
            <p id="cover-error" role="alert" className="field-error">
              {error}
            </p>
          ) : null}
        </div>
        <div className="row-actions">
          <button type="submit" className="btn" disabled={pending}>
            {pending ? t('admin.cover.uploading') : t('admin.cover.upload')}
          </button>
          {game.jaquette !== null ? (
            <ConfirmButton label={t('admin.cover.remove')} visibleLabel={t('admin.cover.remove')} prompt={t('admin.cover.removeConfirm')} disabled={pending} onConfirm={remove} />
          ) : null}
        </div>
      </form>
      <p role="status" aria-live="polite">
        {notice}
      </p>
    </section>
  )
}
