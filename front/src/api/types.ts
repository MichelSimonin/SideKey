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

/**
 * Seconde étape requise (A2F, F-04) : seul un jeton intermédiaire à portée limitée est remis.
 * « a2f » : l'utilisateur saisit un code ; « a2f_enrolement » : l'A2F est obligatoire mais pas encore configurée.
 */
export type SecondFactorPayload = {
  etape: 'a2f' | 'a2f_enrolement'
  jetonIntermediaire: string
  expireDans: number
}

/** Corps d'erreur de l'API : message traduit et, pour un 422, une erreur par champ. */
export type ApiErrorBody = {
  message?: string
  erreurs?: Record<string, string>
  /** Précise un 401 de la seconde étape : « code_invalide » (code faux, à corriger) ; sans elle, le jeton intermédiaire a expiré. */
  raison?: string
}

/** POST /api/connexion/a2f/enrolement : le secret du QR code, montré une seule fois. */
export type EnrolmentPayload = {
  /** Secret en base32, pour la saisie manuelle quand le QR code ne peut pas être lu. */
  secret: string
  uri: string
  /** QR code en URI de données SVG, affiché dans un <img> : jamais de HTML injecté. */
  qrCode: string
}

/** POST /api/connexion/a2f/enrolement/confirmation : la session et les 10 codes de secours, montrés une seule fois. */
export type ConfirmationPayload = SessionPayload & { codesSecours: string[] }
