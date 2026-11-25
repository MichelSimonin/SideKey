import { createContext } from 'react'
import type { CurrentUser } from '../../api/types'
import type * as authApi from './authApi'

export type AuthStatus = 'loading' | 'anonymous' | 'authenticated'

export type AuthContextValue = {
  status: AuthStatus
  user: CurrentUser | null
  login: (credentials: authApi.Credentials) => Promise<authApi.LoginResult>
  register: (data: Omit<authApi.Registration, 'langue'>) => Promise<authApi.LoginResult>
  logout: () => Promise<void>
}

export const AuthContext = createContext<AuthContextValue | null>(null)

