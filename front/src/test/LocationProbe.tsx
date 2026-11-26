import { useLocation } from 'react-router-dom'

/** Affiche le chemin courant du routeur pour que les tests vérifient une navigation (data-testid="location"). */
export function LocationProbe() {
  const location = useLocation()
  return <p data-testid="location">{location.pathname + location.search}</p>
}
