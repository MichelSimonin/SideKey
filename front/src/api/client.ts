/**
 * Client HTTP minimal vers l'API SideKey.
 * En dev, VITE_API_URL vaut "/api" : Vite relaie vers Nginx (même origine).
 */
const DEFAULT_BASE_URL = '/api'

export function apiUrl(path: string, baseUrl: string = import.meta.env.VITE_API_URL ?? DEFAULT_BASE_URL): string {
  const base = baseUrl.replace(/\/+$/, '')
  const cleanPath = path.replace(/^\/+/, '')
  return `${base}/${cleanPath}`
}

export type HealthStatus = { status: 'ok' }

export async function fetchHealth(fetchFn: typeof fetch = fetch): Promise<HealthStatus> {
  const response = await fetchFn(apiUrl('health'), {
    headers: { Accept: 'application/json' },
    credentials: 'same-origin',
  })
  if (!response.ok) {
    throw new Error(`API indisponible (HTTP ${response.status})`)
  }
  return (await response.json()) as HealthStatus
}
