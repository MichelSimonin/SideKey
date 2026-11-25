import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterEach, beforeEach } from 'vitest'
import { clearCsrfCookie } from '../api/client'
import { applySession } from '../api/session'
import i18n from '../i18n'

// Chaque test démarre en français, sans choix mémorisé.
beforeEach(async () => {
  await i18n.changeLanguage('fr')
  localStorage.clear()
})

afterEach(() => {
  cleanup()
  // L'état de session est un module : on le remet à zéro entre deux tests.
  applySession(null)
  clearCsrfCookie()
})
