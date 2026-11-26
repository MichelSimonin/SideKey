import { Navigate, Route, Routes } from 'react-router-dom'
import { Layout } from './components/layout/Layout'
import { AdminLayout } from './features/admin/AdminLayout'
import { GameEditorPage } from './features/admin/GameEditorPage'
import { GamesAdminPage } from './features/admin/GamesAdminPage'
import { PlatformsPage } from './features/admin/PlatformsPage'
import { PublishersPage } from './features/admin/PublishersPage'
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
          {/* Back-office catalogue (F-09) : la garde masque l'interface, l'API vérifie permission et A2F à chaque appel. */}
          <Route path="/admin" element={<AdminLayout />}>
            <Route index element={<Navigate to="jeux" replace />} />
            <Route path="jeux" element={<GamesAdminPage />} />
            <Route path="jeux/nouveau" element={<GameEditorPage />} />
            <Route path="jeux/:id" element={<GameEditorPage />} />
            <Route path="editeurs" element={<PublishersPage />} />
            <Route path="plateformes" element={<PlatformsPage />} />
          </Route>
          <Route path="*" element={<NotFoundPage />} />
        </Routes>
      </Layout>
    </AuthProvider>
  )
}

export default App
