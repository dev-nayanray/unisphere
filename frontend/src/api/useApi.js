import { useCallback, useEffect, useState } from 'react'
import { api, errorMessage } from './client'

/**
 * Fetch a GET endpoint and keep its data in state.
 *   const { data, loading, error, reload } = useApi('/classes')
 * Pass null as the url to skip fetching.
 */
export function useApi(url, params) {
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(Boolean(url))
  const [error, setError] = useState(null)
  const key = JSON.stringify(params ?? {})

  const reload = useCallback(async () => {
    if (!url) return
    setLoading(true)
    setError(null)
    try {
      const res = await api.get(url, { params: JSON.parse(key) })
      setData(res.data)
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setLoading(false)
    }
  }, [url, key])

  useEffect(() => {
    reload()
  }, [reload])

  return { data, setData, loading, error, reload }
}
