import { useApi } from './useApi'

/**
 * Load a list for a <select>: useOptions('/programs', (p) => `${p.code} · ${p.name}`)
 * Handles both plain arrays and paginated responses.
 */
export function useOptions(url, label, params) {
  const { data } = useApi(url, params)
  const rows = Array.isArray(data) ? data : data?.data || []
  return rows.map((r) => ({ value: r.id, label: label(r) }))
}
