import { useCallback, useEffect, useState, type DependencyList } from 'react'

export type RequestState<T> =
  | { status: 'loading' }
  | { status: 'success'; data: T }
  | { status: 'error'; error: unknown }

/**
 * Charge une ressource au montage et à chaque changement de `deps`. La requête précédente est abandonnée
 * (AbortController) quand les critères changent : une réponse tardive ne remplace jamais une plus récente.
 * `retry` relance la requête courante (bouton « Réessayer »).
 */
export function useRequest<T>(
  load: (signal: AbortSignal) => Promise<T>,
  deps: DependencyList,
): [RequestState<T>, () => void] {
  const [state, setState] = useState<RequestState<T>>({ status: 'loading' })
  const [attempt, setAttempt] = useState(0)

  useEffect(() => {
    const controller = new AbortController()
    setState({ status: 'loading' })
    load(controller.signal).then(
      (data) => {
        if (!controller.signal.aborted) setState({ status: 'success', data })
      },
      (error: unknown) => {
        if (!controller.signal.aborted) setState({ status: 'error', error })
      },
    )
    return () => controller.abort()
    // eslint-disable-next-line react-hooks/exhaustive-deps -- `load` est recréée à chaque rendu : seules `deps` déclenchent
  }, [...deps, attempt])

  const retry = useCallback(() => setAttempt((n) => n + 1), [])
  return [state, retry]
}
