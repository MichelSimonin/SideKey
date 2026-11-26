import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { TextField } from '../../components/ui/TextField'
import { ErrorSummary } from './ErrorSummary'
import { feedbackFrom, NO_FEEDBACK, type FormFeedback } from './formFeedback'
import { SecondFactorStep } from './SecondFactorStep'
import { useAuth } from './useAuth'

const IDS = {
  prenom: 'register-first-name',
  nom: 'register-last-name',
  email: 'register-email',
  motDePasse: 'register-password',
  consentement: 'register-consent',
}

/** Formulaire d'inscription (E05, US-20) : règles du mot de passe affichées, consentement exprès. */
export function RegisterForm() {
  const { t } = useTranslation()
  const { register } = useAuth()
  const navigate = useNavigate()
  const [prenom, setPrenom] = useState('')
  const [nom, setNom] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [consent, setConsent] = useState(false)
  const [pending, setPending] = useState(false)
  const [step, setStep] = useState<{ mode: 'code' | 'enrolment'; token: string } | null>(null)
  const [notice, setNotice] = useState('')
  const [feedback, setFeedback] = useState<FormFeedback>(NO_FEEDBACK)

  const labels = {
    prenom: t('auth.fields.firstName'),
    nom: t('auth.fields.lastName'),
    email: t('auth.fields.email'),
    motDePasse: t('auth.fields.password'),
    consentement: t('auth.fields.consent'),
  }

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setNotice('')

    // Contrôle de confort : le serveur revalide tout (politique de mot de passe, formats, consentement).
    const missing: Record<string, string> = {}
    if (prenom.trim() === '') missing.prenom = t('auth.errors.required')
    if (nom.trim() === '') missing.nom = t('auth.errors.required')
    if (email.trim() === '') missing.email = t('auth.errors.required')
    if (password === '') missing.motDePasse = t('auth.errors.required')
    if (!consent) missing.consentement = t('auth.errors.required')
    if (Object.keys(missing).length > 0) {
      setFeedback({ fields: missing, form: null })
      return
    }

    setPending(true)
    setFeedback(NO_FEEDBACK)
    try {
      const result = await register({
        prenom: prenom.trim(),
        nom: nom.trim(),
        email: email.trim(),
        motDePasse: password,
        consentement: consent,
      })
      if (result.kind === 'second-factor') {
        setPassword('')   // le mot de passe ne reste pas en mémoire pendant la seconde étape
        setStep({ mode: result.mode, token: result.token })
      } else {
        void navigate('/')
      }
    } catch (error) {
      setFeedback(feedbackFrom(error, t))
    } finally {
      setPending(false)
    }
  }

  if (step !== null) {
    return (
      <SecondFactorStep
        mode={step.mode}
        token={step.token}
        onRestart={(message) => {
          setStep(null)
          setNotice(message ?? '')
        }}
      />
    )
  }

  return (
    <form onSubmit={(event) => void onSubmit(event)} noValidate aria-busy={pending}>
      <ErrorSummary feedback={feedback} fieldIds={IDS} labels={labels} />
      {notice !== '' ? <p role="status">{notice}</p> : null}

      <TextField
        id={IDS.prenom}
        name="prenom"
        label={labels.prenom}
        autoComplete="given-name"
        value={prenom}
        onChange={(event) => setPrenom(event.target.value)}
        error={feedback.fields.prenom}
        required
        maxLength={80}
      />
      <TextField
        id={IDS.nom}
        name="nom"
        label={labels.nom}
        autoComplete="family-name"
        value={nom}
        onChange={(event) => setNom(event.target.value)}
        error={feedback.fields.nom}
        required
        maxLength={80}
      />
      <TextField
        id={IDS.email}
        name="email"
        type="email"
        label={labels.email}
        autoComplete="email"
        value={email}
        onChange={(event) => setEmail(event.target.value)}
        error={feedback.fields.email}
        required
        maxLength={254}
      />
      <TextField
        id={IDS.motDePasse}
        name="motDePasse"
        type={showPassword ? 'text' : 'password'}
        label={labels.motDePasse}
        hint={t('auth.hints.passwordRules')}
        autoComplete="new-password"
        value={password}
        onChange={(event) => setPassword(event.target.value)}
        error={feedback.fields.motDePasse}
        required
      />
      {/* Décompte annoncé poliment : aide à atteindre les 12 caractères sans bloquer la saisie. */}
      <p className="field-hint" aria-live="polite">
        {t('auth.hints.passwordCount', { count: [...password].length })}
      </p>
      <label className="checkbox">
        <input type="checkbox" checked={showPassword} onChange={(event) => setShowPassword(event.target.checked)} />
        {t('auth.fields.showPassword')}
      </label>

      <div className="field">
        <label className="checkbox">
          <input
            id={IDS.consentement}
            type="checkbox"
            name="consentement"
            checked={consent}
            onChange={(event) => setConsent(event.target.checked)}
            aria-invalid={feedback.fields.consentement ? true : undefined}
            aria-describedby={feedback.fields.consentement ? `${IDS.consentement}-error` : undefined}
            required
          />
          {labels.consentement}
        </label>
        {feedback.fields.consentement ? (
          <p id={`${IDS.consentement}-error`} className="field-error">
            {feedback.fields.consentement}
          </p>
        ) : null}
      </div>

      <button type="submit" className="btn" disabled={pending}>
        {pending ? t('auth.pending') : t('auth.register.submit')}
      </button>
    </form>
  )
}
