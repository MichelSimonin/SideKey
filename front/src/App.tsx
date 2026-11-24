import { useTranslation } from 'react-i18next'
import { Layout } from './components/layout/Layout'

function App() {
  const { t } = useTranslation()

  return (
    <Layout>
      <section className="hero">
        <h1>{t('home.title')}</h1>
        <p>{t('home.subtitle')}</p>
      </section>
    </Layout>
  )
}

export default App
