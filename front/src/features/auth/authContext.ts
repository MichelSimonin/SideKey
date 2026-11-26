import { createContext } from 'react'
import type { ConfirmationPayload, CurrentUser, EnrolmentPayload } from '../../api/types'
import type * as authApi from './authApi'

export type AuthStatus = 'loading' | 'anonymous' | 'authenticated'

export type AuthContextValue = {
  status: AuthStatus
  user: CurrentUser | null
  login: (credentials: authApi.Credentials) => Promise<authApi.LoginResult>
  register: (data: Omit<authApi.Registration, 'langue'>) => Promise<authApi.LoginResult>
  logout: () => Promise<void>
  /** Seconde étape : échange le jeton intermédiaire et un code contre la session. */
  verifyCode: (token: string, code: string) => Promise<void>
  startEnrolment: (token: string) => Promise<EnrolmentPayload>
  /** Confirme l'enrôlement ; la session ne s'ouvre qu'avec openSession, une fois les codes de secours vus. */
  confirmEnrolment: (token: string, code: string) => Promise<ConfirmationPayload>
  openSession: (confirmation: ConfirmationPayload) => void
}

export const AuthContext = createContext<AuthContextValue | null>(null)
