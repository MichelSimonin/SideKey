import { Route, Routes } from 'react-router-dom'
import { Layout } from './components/layout/Layout'
import { AuthPage } from './features/auth/AuthPage'
import { AuthProvider } from './features/auth/AuthProvider'
import { CataloguePage } from './features/catalogue/CataloguePage'
import { GamePage } from './features/catalogue/GamePage'
import { HomePage } from './pages/HomePage'
import { NotFoundPage } from './pages/NotFoundPage'

/** Application : fournisseur d'authentification, gabarit commun et routes. Le routeur est monté par main.tsx. */
function App() {
  return (
    <AuthProvider>
      <Layout>
        <Routes>
          <Route path="/" element={<HomePage />} />
          <Route path="/catalogue" element={<CataloguePage />} />
          <Route path="/jeux/:slug" element={<GamePage />} />
          <Route path="/connexion" element={<AuthPage mode="login" />} />
          <Route path="/inscription" element={<AuthPage mode="register" />} />
          <Route path="*" element={<NotFoundPage />} />
        </Routes>
      </Layout>
    </AuthProvider>
  )
}

export default App
