/** Contrats de l'API d'authentification (F-03). Reflètent les DTO de sortie de l'API Symfony. */

export type UserLanguage = 'fr' | 'en'

export type CurrentUser = {
  id: string
  email: string
  prenom: string
  nom: string
  langue: UserLanguage
  roles: string[]
  /** Permissions déduites de la matrice RBAC (CDC §11.5). Sert à masquer des actions, jamais à autoriser : l'API refuse (403). */
  permissions: string[]
  a2fActive: boolean
  emailVerifie: boolean
}

/** Réponse de POST /api/connexion et /api/token/refresh quand la session est ouverte. */
export type SessionPayload = {
  etape: 'connecte'
  jetonAcces: string
  typeJeton: 'Bearer'
  expireDans: number
  utilisateur: CurrentUser
}

/** Seconde étape requise (A2F, F-04) : seul un jeton intermédiaire à portée limitée est remis. */
export type SecondFactorPayload = {
  etape: 'a2f'
  jetonIntermediaire: string
  expireDans: number
}

/** Corps d'erreur de l'API : message traduit et, pour un 422, une erreur par champ. */
export type ApiErrorBody = {
  message?: string
  erreurs?: Record<string, string>
}
