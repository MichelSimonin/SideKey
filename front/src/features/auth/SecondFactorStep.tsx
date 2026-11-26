import { useEffect, useRef, useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { ApiError } from '../../api/client'
import type { ConfirmationPayload, EnrolmentPayload } from '../../api/types'
import { TextField } from '../../components/ui/TextField'
import { ErrorSummary } from './ErrorSummary'
import { feedbackFrom, NO_FEEDBACK, type FormFeedback } from './formFeedback'
import { useAuth } from './useAuth'

/**
 * Seconde étape de la connexion (E05, US-21, US-22, CDC §11.4), affichée à la place du formulaire de mot de passe.
 *  - mode « code »     : l'A2F est configurée, l'utilisateur saisit le code de son application ou un code de secours ;
 *  - mode « enrolment » : l'A2F est obligatoire (back-office) mais pas configurée : QR code, premier code, puis les 10
 *    codes de secours, montrés UNE SEULE FOIS ; la session ne s'ouvre qu'après que l'utilisateur les a vus.
 *
 * Le jeton intermédiaire (`token`) n'existe qu'ici, en mémoire : jamais dans le stockage du navigateur, jamais dans l'URL.
 * Le secret et les codes de secours ne sont ni journalisés ni stockés : ils vivent le temps de l'écran.
 */
export function SecondFactorStep({
  mode,
  token,
  onRestart,
}: {
  mode: 'code' | 'enrolment'
  token: string
  /** Retour à la saisie du mot de passe (jeton expiré ou abandon). */
  onRestart: (message?: string) => void
}) {
  const { t } = useTranslation()
  const { verifyCode, startEnrolment, confirmEnrolment, openSession } = useAuth()
  const [code, setCode] = useState('')
  const [pending, setPending] = useState(false)
  const [feedback, setFeedback] = useState<FormFeedback>(NO_FEEDBACK)
  const [enrolment, setEnrolment] = useState<EnrolmentPayload | null>(null)
  const [confirmation, setConfirmation] = useState<ConfirmationPayload | null>(null)
  const titleRef = useRef<HTMLHeadingElement>(null)

  // Le titre de l'étape reçoit le focus : l'utilisateur de clavier ou de lecteur d'écran sait que l'écran a changé (RGAA 12.8).
  useEffect(() => {
    titleRef.current?.focus()
  }, [mode, confirmation])

  useEffect(() => {
    if (mode !== 'enrolment') return
    let cancelled = false
    startEnrolment(token).then(
      (data) => {
        if (!cancelled) setEnrolment(data)
      },
      (error: unknown) => {
        if (!cancelled) handleError(error)
      },
    )
    return () => {
      cancelled = true
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps -- un seul enrôlement par affichage de l'étape
  }, [mode, token])

  function handleError(error: unknown) {
    if (error instanceof ApiError && error.status === 401 && error.body.raison !== 'code_invalide') {
      // Jeton intermédiaire expiré (5 min) ou refusé : on repart du mot de passe.
      onRestart(t('auth.a2f.expired'))
      return
    }
    if (error instanceof ApiError && error.status === 401) {
      setFeedback({ fields: { code: error.body.message ?? t('auth.a2f.invalidCode') }, form: null })
      return
    }
    setFeedback(feedbackFrom(error, t))
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (code.trim() === '') {
      setFeedback({ fields: { code: t('auth.errors.required') }, form: null })
      return
    }
    setPending(true)
    setFeedback(NO_FEEDBACK)
    try {
      if (mode === 'code') {
        await verifyCode(token, code.trim())   // la session s'ouvre : AuthPage redirige
      } else {
        setConfirmation(await confirmEnrolment(token, code.trim()))   // codes de secours à montrer avant d'ouvrir la session
      }
    } catch (error) {
      handleError(error)
    } finally {
      setPending(false)
    }
  }

  if (confirmation !== null) {
    return <BackupCodes codes={confirmation.codesSecours} titleRef={titleRef} onDone={() => openSession(confirmation)} />
  }

  const ids = { code: 'a2f-code' }

  return (
    <section aria-labelledby="a2f-title" className="a2f">
      <h2 id="a2f-title" ref={titleRef} tabIndex={-1}>
        {mode === 'code' ? t('auth.a2f.codeTitle') : t('auth.a2f.enrolmentTitle')}
      </h2>

      {mode === 'enrolment' ? (
        <>
          <p>{t('auth.a2f.enrolmentIntro')}</p>
          <ol className="a2f-steps">
            <li>{t('auth.a2f.step1')}</li>
            <li>{t('auth.a2f.step2')}</li>
            <li>{t('auth.a2f.step3')}</li>
          </ol>
          {enrolment === null ? (
            feedback.form === null ? <p role="status">{t('auth.loading')}</p> : null
          ) : (
            <div className="a2f-qr">
              <img src={enrolment.qrCode} alt={t('auth.a2f.qrAlt')} width={240} height={240} />
              <p>{t('auth.a2f.manual')}</p>
              <p>
                <code className="a2f-secret" aria-label={t('auth.a2f.secretLabel')}>
                  {enrolment.secret.replace(/(.{4})/g, '$1 ').trim()}
                </code>
              </p>
            </div>
          )}
        </>
      ) : (
        <p>{t('auth.a2f.codeIntro')}</p>
      )}

      <form onSubmit={(event) => void submit(event)} noValidate aria-busy={pending}>
        <ErrorSummary feedback={feedback} fieldIds={ids} labels={{ code: t('auth.a2f.codeLabel') }} />
        <TextField
          id={ids.code}
          name="code"
          label={t('auth.a2f.codeLabel')}
          hint={mode === 'code' ? t('auth.a2f.codeHint') : t('auth.a2f.confirmHint')}
          autoComplete="one-time-code"
          inputMode="text"
          autoCapitalize="characters"
          spellCheck={false}
          value={code}
          onChange={(event) => setCode(event.target.value)}
          error={feedback.fields.code}
          required
          maxLength={20}
        />
        <div className="filter-actions">
          <button type="submit" className="btn" disabled={pending || (mode === 'enrolment' && enrolment === null)}>
            {pending ? t('auth.pending') : mode === 'code' ? t('auth.a2f.verify') : t('auth.a2f.activate')}
          </button>
          <button type="button" className="link-button" onClick={() => onRestart()}>
            {t('auth.a2f.restart')}
          </button>
        </div>
      </form>
    </section>
  )
}

/** Les 10 codes de secours (usage unique) : montrés une seule fois, l'utilisateur confirme les avoir conservés. */
function BackupCodes({
  codes,
  titleRef,
  onDone,
}: {
  codes: string[]
  titleRef: React.RefObject<HTMLHeadingElement | null>
  onDone: () => void
}) {
  const { t } = useTranslation()

  return (
    <section aria-labelledby="a2f-codes-title" className="a2f">
      <h2 id="a2f-codes-title" ref={titleRef} tabIndex={-1}>
        {t('auth.a2f.codesTitle')}
      </h2>
      <p role="alert">{t('auth.a2f.codesWarning')}</p>
      <ol className="backup-codes">
        {codes.map((backup) => (
          <li key={backup}>
            <code>{backup}</code>
          </li>
        ))}
      </ol>
      <button type="button" className="btn" onClick={onDone}>
        {t('auth.a2f.codesDone')}
      </button>
    </section>
  )
}
