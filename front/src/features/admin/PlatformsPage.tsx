import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { ConfirmButton } from '../../components/ui/ConfirmButton'
import { TextField } from '../../components/ui/TextField'
import { useRequest } from '../../hooks/useRequest'
import { ErrorSummary } from '../auth/ErrorSummary'
import { createPlatform, deletePlatform, fetchPlatforms, updatePlatform } from './adminApi'
import { adminFeedback, NO_FEEDBACK, type FormFeedback } from './adminFeedback'

const IDS = { code: 'platform-new-code', libelle: 'platform-new-label' }
const EDIT_ID = 'platform-edit-label'
const CODE = /^[A-Z0-9_]{1,12}$/

/**
 * Plateformes (US-50) : liste, création, renommage du libellé, suppression (refusée par l'API tant que des offres s'y
 * rattachent). Le code est choisi à la création et ne change plus : il n'est donc jamais modifiable ici.
 */
export function PlatformsPage() {
  const { t } = useTranslation()
  const [platforms, reload] = useRequest((signal) => fetchPlatforms({ signal }), [])
  const [code, setCode] = useState('')
  const [label, setLabel] = useState('')
  const [editing, setEditing] = useState<{ id: string; libelle: string } | null>(null)
  const [pending, setPending] = useState(false)
  const [feedback, setFeedback] = useState<FormFeedback>(NO_FEEDBACK)
  const [notice, setNotice] = useState('')

  const labels = { code: t('admin.platforms.code'), libelle: t('admin.platforms.label') }
  const fieldIds = editing === null ? IDS : { libelle: EDIT_ID }

  async function run(action: () => Promise<string>) {
    setPending(true)
    setFeedback(NO_FEEDBACK)
    setNotice('')
    try {
      setNotice(await action())
      reload()
    } catch (error) {
      setFeedback(adminFeedback(error, t))
    } finally {
      setPending(false)
    }
  }

  function onCreate(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    // Contrôle de confort : l'API reste seule juge.
    const missing: Record<string, string> = {}
    if (!CODE.test(code)) missing.code = t('admin.platforms.codeHint')
    if (label.trim() === '') missing.libelle = t('auth.errors.required')
    if (Object.keys(missing).length > 0) {
      setFeedback({ fields: missing, form: null })
      return
    }
    void run(async () => {
      const created = await createPlatform(code, label.trim())
      setCode('')
      setLabel('')
      return t('admin.platforms.created', { name: created.libelle })
    })
  }

  function onRename(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (editing === null) return
    if (editing.libelle.trim() === '') {
      setFeedback({ fields: { libelle: t('auth.errors.required') }, form: null })
      return
    }
    void run(async () => {
      await updatePlatform(editing.id, editing.libelle.trim())
      setEditing(null)
      return t('admin.platforms.updated')
    })
  }

  return (
    <section aria-labelledby="platforms-title" aria-busy={pending}>
      <h1 id="platforms-title">{t('admin.platforms.title')}</h1>

      <ErrorSummary feedback={feedback} fieldIds={fieldIds} labels={labels} />
      <p role="status" aria-live="polite" className="notice">
        {notice}
      </p>

      <form onSubmit={onCreate} noValidate className="inline-form">
        <TextField
          id={IDS.code}
          name="code"
          label={t('admin.platforms.code')}
          hint={t('admin.platforms.codeHint')}
          value={code}
          onChange={(event) => setCode(event.target.value.toUpperCase())}
          error={editing === null ? feedback.fields.code : undefined}
          maxLength={12}
          autoCapitalize="characters"
          autoComplete="off"
          required
        />
        <TextField
          id={IDS.libelle}
          name="libelle"
          label={t('admin.platforms.label')}
          value={label}
          onChange={(event) => setLabel(event.target.value)}
          error={editing === null ? feedback.fields.libelle : undefined}
          maxLength={60}
          required
        />
        <button type="submit" className="btn" disabled={pending}>
          {t('admin.platforms.add')}
        </button>
      </form>

      {platforms.status === 'loading' ? <p role="status">{t('admin.common.loading')}</p> : null}
      {platforms.status === 'error' ? (
        <div role="alert" className="error-summary">
          <p>{t('admin.errors.load')}</p>
          <button type="button" className="btn" onClick={reload}>
            {t('admin.errors.retry')}
          </button>
        </div>
      ) : null}
      {platforms.status === 'success' && platforms.data.length === 0 ? <p>{t('admin.platforms.none')}</p> : null}
      {platforms.status === 'success' && platforms.data.length > 0 ? (
        <div className="table-wrap">
          <table className="admin-table">
            <caption>{t('admin.platforms.caption')}</caption>
            <thead>
              <tr>
                <th scope="col">{t('admin.platforms.code')}</th>
                <th scope="col">{t('admin.platforms.label')}</th>
                <th scope="col">{t('admin.platforms.offers')}</th>
                <th scope="col">{t('admin.common.actions')}</th>
              </tr>
            </thead>
            <tbody>
              {platforms.data.map((platform) => (
                <tr key={platform.id}>
                  <th scope="row">
                    <code>{platform.code}</code>
                  </th>
                  <td>
                    {editing?.id === platform.id ? (
                      <form onSubmit={onRename} noValidate className="inline-form">
                        <TextField
                          id={EDIT_ID}
                          name="libelle"
                          label={t('admin.platforms.editLabel', { name: platform.libelle })}
                          value={editing.libelle}
                          onChange={(event) => setEditing({ id: platform.id, libelle: event.target.value })}
                          error={feedback.fields.libelle}
                          maxLength={60}
                          required
                          autoFocus
                        />
                        <button type="submit" className="btn" disabled={pending}>
                          {t('admin.common.save')}
                        </button>
                        <button type="button" className="btn btn-secondary" onClick={() => setEditing(null)}>
                          {t('admin.common.cancel')}
                        </button>
                      </form>
                    ) : (
                      platform.libelle
                    )}
                  </td>
                  <td>{platform.nombreOffres}</td>
                  <td>
                    {editing?.id === platform.id ? null : (
                      <div className="row-actions">
                        <button
                          type="button"
                          className="btn btn-secondary"
                          aria-label={t('admin.platforms.editFor', { name: platform.libelle })}
                          onClick={() => {
                            setFeedback(NO_FEEDBACK)
                            setEditing({ id: platform.id, libelle: platform.libelle })
                          }}
                        >
                          {t('admin.common.edit')}
                        </button>
                        {platform.nombreOffres > 0 ? (
                          <span className="field-hint">{t('admin.platforms.inUse', { count: platform.nombreOffres })}</span>
                        ) : (
                          <ConfirmButton
                            label={t('admin.platforms.deleteFor', { name: platform.libelle })}
                            visibleLabel={t('admin.common.delete')}
                            prompt={t('admin.platforms.deleteConfirm', { name: platform.libelle })}
                            disabled={pending}
                            onConfirm={() =>
                              run(async () => {
                                await deletePlatform(platform.id)
                                return t('admin.platforms.deleted', { name: platform.libelle })
                              })
                            }
                          />
                        )}
                      </div>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}
    </section>
  )
}
