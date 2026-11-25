import { useTranslation } from 'react-i18next'
import { Navigate, NavLink } from 'react-router-dom'
import { LoginForm } from './LoginForm'
import { RegisterForm } from './RegisterForm'
import { useAuth } from './useAuth'

/**
 * Écran E05 : connexion et inscription (US-20, US-21). Les deux vues ont chacune leur adresse
 * (/connexion, /inscription) ; le « onglet » actif est signalé par aria-current (RGAA 12.6, 7.1).
 */
export function AuthPage({ mode }: { mode: 'login' | 'register' }) {
  const { t } = useTranslation()
  const { status } = useAuth()

  if (status === 'loading') {
    // Session en cours de rétablissement : on n'affiche pas un formulaire que l'on redirigerait aussitôt.
    return <p role="status">{t('auth.loading')}</p>
  }
  if (status === 'authenticated') {
    return <Navigate to="/" replace />
  }

  return (
    <section className="auth">
      <h1>{mode === 'login' ? t('auth.login.title') : t('auth.register.title')}</h1>
      <nav aria-label={t('auth.tabs')} className="tabs">
        <NavLink to="/connexion">{t('auth.login.tab')}</NavLink>
        <NavLink to="/inscription">{t('auth.register.tab')}</NavLink>
      </nav>
      {mode === 'login' ? <LoginForm /> : <RegisterForm />}
    </section>
  )
}
