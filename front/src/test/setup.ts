import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterEach, beforeEach } from 'vitest'
import i18n from '../i18n'

// Chaque test démarre en français, sans choix mémorisé.
beforeEach(async () => {
  await i18n.changeLanguage('fr')
  localStorage.clear()
})

afterEach(() => {
  cleanup()
})
