import { useState, type FormEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { TextField } from '../../components/ui/TextField'
import { ErrorSummary } from './ErrorSummary'
import { feedbackFrom, NO_FEEDBACK, type FormFeedback } from './formFeedback'
import { SecondFactorStep } from './SecondFactorStep'
import { useAuth } from './useAuth'

const IDS = { email: 'login-email', motDePasse: 'login-password' }

/** Formulaire de connexion (E05, US-21). */
export function LoginForm() {
  const { t } = useTranslation()
  const { login } = useAuth()
  const navigate = useNavigate()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [showPassword, setShowPassword] = useState(false)
  const [pending, setPending] = useState(false)
  const [step, setStep] = useState<{ mode: 'code' | 'enrolment'; token: string } | null>(null)
  const [notice, setNotice] = useState('')
  const [feedback, setFeedback] = useState<FormFeedback>(NO_FEEDBACK)

  const labels = { email: t('auth.fields.email'), motDePasse: t('auth.fields.password') }

  async function onSubmit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setNotice('')

    // Contrôle de confort : l'API reste seule juge (validation côté serveur).
    const missing: Record<string, string> = {}
    if (email.trim() === '') missing.email = t('auth.errors.required')
    if (password === '') missing.motDePasse = t('auth.errors.required')
    if (Object.keys(missing).length > 0) {
      setFeedback({ fields: missing, form: null })
      return
    }

    setPending(true)
    setFeedback(NO_FEEDBACK)
    try {
      const result = await login({ email: email.trim(), motDePasse: password })
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
        id={IDS.email}
        name="email"
        type="email"
        label={labels.email}
        autoComplete="username"
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
        autoComplete="current-password"
        value={password}
        onChange={(event) => setPassword(event.target.value)}
        error={feedback.fields.motDePasse}
        required
        maxLength={128}
      />
      <label className="checkbox">
        <input type="checkbox" checked={showPassword} onChange={(event) => setShowPassword(event.target.checked)} />
        {t('auth.fields.showPassword')}
      </label>

      <button type="submit" className="btn" disabled={pending}>
        {pending ? t('auth.pending') : t('auth.login.submit')}
      </button>
    </form>
  )
}
