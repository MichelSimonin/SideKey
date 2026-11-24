import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it } from 'vitest'
import App from '../../App'
import { LANGUAGE_STORAGE_KEY } from '../../i18n/config'

describe('LanguageSwitcher', () => {
  it('affiche le français par défaut, bouton FR actif', () => {
    render(<App />)

    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Vos clés de jeux, livrées tout de suite')
    expect(screen.getByRole('button', { name: 'Français' })).toHaveAttribute('aria-pressed', 'true')
    expect(screen.getByRole('button', { name: 'English' })).toHaveAttribute('aria-pressed', 'false')
    expect(document.documentElement.lang).toBe('fr')
  })

  it("passe l'interface en anglais, met à jour lang et mémorise le choix", async () => {
    render(<App />)

    await userEvent.click(screen.getByRole('button', { name: 'English' }))

    expect(screen.getByRole('heading', { level: 1 })).toHaveTextContent('Your game keys, delivered instantly')
    expect(screen.getByRole('group', { name: 'Language' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'English' })).toHaveAttribute('aria-pressed', 'true')
    expect(document.documentElement.lang).toBe('en')
    expect(localStorage.getItem(LANGUAGE_STORAGE_KEY)).toBe('en')
  })

  it('est utilisable au clavier', async () => {
    render(<App />)

    await userEvent.tab() // lien d'évitement
    await userEvent.tab() // logo
    await userEvent.tab() // FR
    await userEvent.tab() // EN
    expect(screen.getByRole('button', { name: 'English' })).toHaveFocus()

    await userEvent.keyboard('{Enter}')
    expect(document.documentElement.lang).toBe('en')
  })
})
