import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { ConfirmButton } from '../../components/ui/ConfirmButton'
import { TextField } from '../../components/ui/TextField'
import { useRequest } from '../../hooks/useRequest'
import { ErrorSummary } from '../auth/ErrorSummary'
import { createPublisher, deletePublisher, fetchPublishers, updatePublisher } from './adminApi'
import { adminFeedback, NO_FEEDBACK, type FormFeedback } from './adminFeedback'

const NEW_ID = 'publisher-new-name'
const EDIT_ID = 'publisher-edit-name'

/** Éditeurs (US-50) : liste, création, renommage, suppression (refusée par l'API tant que des jeux s'y rattachent). */
export function PublishersPage() {
  const { t } = useTranslation()
  const [publishers, reload] = useRequest((signal) => fetchPublishers({ signal }), [])
  const [name, setName] = useState('')
  const [editing, setEditing] = useState<{ id: string; nom: string } | null>(null)
  const [pending, setPending] = useState(false)
  const [feedback, setFeedback] = useState<FormFeedback>(NO_FEEDBACK)
  const [notice, setNotice] = useState('')

  const labels = { nom: t('admin.publishers.name') }
  const fieldIds = { nom: editing === null ? NEW_ID : EDIT_ID }

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
    if (name.trim() === '') {
      setFeedback({ fields: { nom: t('auth.errors.required') }, form: null })
      return
    }
    void run(async () => {
      const created = await createPublisher(name.trim())
      setName('')
      return t('admin.publishers.created', { name: created.nom })
    })
  }

  function onRename(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (editing === null) return
    if (editing.nom.trim() === '') {
      setFeedback({ fields: { nom: t('auth.errors.required') }, form: null })
      return
    }
    void run(async () => {
      await updatePublisher(editing.id, editing.nom.trim())
      setEditing(null)
      return t('admin.publishers.updated')
    })
  }

  return (
    <section aria-labelledby="publishers-title" aria-busy={pending}>
      <h1 id="publishers-title">{t('admin.publishers.title')}</h1>

      <ErrorSummary feedback={feedback} fieldIds={fieldIds} labels={labels} />
      <p role="status" aria-live="polite" className="notice">
        {notice}
      </p>

      <form onSubmit={onCreate} noValidate className="inline-form">
        <TextField
          id={NEW_ID}
          name="nom"
          label={t('admin.publishers.newName')}
          value={name}
          onChange={(event) => setName(event.target.value)}
          error={editing === null ? feedback.fields.nom : undefined}
          maxLength={120}
          required
        />
        <button type="submit" className="btn" disabled={pending}>
          {t('admin.publishers.add')}
        </button>
      </form>

      {publishers.status === 'loading' ? <p role="status">{t('admin.common.loading')}</p> : null}
      {publishers.status === 'error' ? (
        <div role="alert" className="error-summary">
          <p>{t('admin.errors.load')}</p>
          <button type="button" className="btn" onClick={reload}>
            {t('admin.errors.retry')}
          </button>
        </div>
      ) : null}
      {publishers.status === 'success' && publishers.data.length === 0 ? <p>{t('admin.publishers.none')}</p> : null}
      {publishers.status === 'success' && publishers.data.length > 0 ? (
        <div className="table-wrap">
          <table className="admin-table">
            <caption>{t('admin.publishers.caption')}</caption>
            <thead>
              <tr>
                <th scope="col">{t('admin.publishers.name')}</th>
                <th scope="col">{t('admin.publishers.games')}</th>
                <th scope="col">{t('admin.common.actions')}</th>
              </tr>
            </thead>
            <tbody>
              {publishers.data.map((publisher) => (
                <tr key={publisher.id}>
                  <th scope="row">
                    {editing?.id === publisher.id ? (
                      <form onSubmit={onRename} noValidate className="inline-form">
                        <TextField
                          id={EDIT_ID}
                          name="nom"
                          label={t('admin.publishers.editName', { name: publisher.nom })}
                          value={editing.nom}
                          onChange={(event) => setEditing({ id: publisher.id, nom: event.target.value })}
                          error={feedback.fields.nom}
                          maxLength={120}
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
                      publisher.nom
                    )}
                  </th>
                  <td>{publisher.nombreJeux}</td>
                  <td>
                    {editing?.id === publisher.id ? null : (
                      <div className="row-actions">
                        <button
                          type="button"
                          className="btn btn-secondary"
                          aria-label={t('admin.publishers.editFor', { name: publisher.nom })}
                          onClick={() => {
                            setFeedback(NO_FEEDBACK)
                            setEditing({ id: publisher.id, nom: publisher.nom })
                          }}
                        >
                          {t('admin.common.edit')}
                        </button>
                        {publisher.nombreJeux > 0 ? (
                          <span className="field-hint">{t('admin.publishers.inUse', { count: publisher.nombreJeux })}</span>
                        ) : (
                          <ConfirmButton
                            label={t('admin.publishers.deleteFor', { name: publisher.nom })}
                            visibleLabel={t('admin.common.delete')}
                            prompt={t('admin.publishers.deleteConfirm', { name: publisher.nom })}
                            disabled={pending}
                            onConfirm={() =>
                              run(async () => {
                                await deletePublisher(publisher.id)
                                return t('admin.publishers.deleted', { name: publisher.nom })
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
