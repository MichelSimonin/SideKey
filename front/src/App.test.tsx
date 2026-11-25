import { render, screen } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { describe, expect, it } from 'vitest'
import App from './App'

function renderApp(path = '/') {
  return render(
    <MemoryRouter initialEntries={[path]}>
      <App />
    </MemoryRouter>,
  )
}

describe('App', () => {
  it('affiche le titre principal', () => {
    renderApp()
    expect(screen.getByRole('heading', { level: 1 })).toBeInTheDocument()
  })

  it('expose les repères de page et le lien d’évitement (RGAA 12)', () => {
    renderApp()

    expect(screen.getByRole('banner')).toBeInTheDocument()
    expect(screen.getByRole('main')).toHaveAttribute('id', 'contenu')
    expect(screen.getByRole('contentinfo')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Aller au contenu' })).toHaveAttribute('href', '#contenu')
  })

  it('affiche une page « introuvable » pour une adresse inconnue', () => {
    renderApp('/page-qui-n-existe-pas')

    expect(screen.getByRole('heading', { level: 1, name: 'Page introuvable' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: "Retour à l'accueil" })).toHaveAttribute('href', '/')
  })

  it('n’affiche jamais le contenu d’une adresse dans la page (pas d’injection par l’URL)', () => {
    renderApp('/<script>alert(1)</script>')

    expect(document.body.innerHTML).not.toContain('<script>alert(1)')
  })
})
