import { useEffect, useState } from 'react'
import { useApi } from '../api/useApi'
import { Empty, ErrorBox, Loading } from './ui'

/**
 * Server-side table for Laravel paginated endpoints.
 *
 * columns: [{ key, label, sortable?, render?(row) }]
 * params:  extra query params (filters), e.g. { role: 'teacher' }
 * actions: (row) => JSX shown in the last column
 * reloadKey: change it to refetch (e.g. after saving)
 */
export default function DataTable({ url, columns, params = {}, actions, reloadKey = 0, searchPlaceholder = 'Search…', toolbar }) {
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [debounced, setDebounced] = useState('')
  const [sort, setSort] = useState({ key: null, dir: 'asc' })

  // Wait for typing to pause before searching.
  useEffect(() => {
    const id = setTimeout(() => {
      setDebounced(search)
      setPage(1)
    }, 300)
    return () => clearTimeout(id)
  }, [search])

  const query = {
    ...params,
    page,
    per_page: 15,
    ...(debounced ? { search: debounced } : {}),
    ...(sort.key ? { sort: sort.key, dir: sort.dir } : {}),
    _r: reloadKey,
  }
  const { data, loading, error } = useApi(url, query)

  function toggleSort(key) {
    setSort((s) => (s.key === key ? { key, dir: s.dir === 'asc' ? 'desc' : 'asc' } : { key, dir: 'asc' }))
  }

  const rows = data?.data || []

  return (
    <div className="card p-0">
      <div className="flex flex-wrap items-center gap-3 border-b border-slate-100 p-4">
        <input className="input max-w-xs" placeholder={searchPlaceholder} value={search} onChange={(e) => setSearch(e.target.value)} />
        {toolbar}
        {data && <span className="ml-auto text-xs text-slate-400">{data.total} total</span>}
      </div>
      <ErrorBox message={error} />

      {loading && !data ? (
        <Loading />
      ) : rows.length === 0 ? (
        <div className="p-4"><Empty>Nothing found.</Empty></div>
      ) : (
        <div className="overflow-x-auto">
          <table className="table">
            <thead>
              <tr>
                {columns.map((c) => (
                  <th key={c.key}>
                    {c.sortable ? (
                      <button className="inline-flex items-center gap-1 hover:text-slate-800" onClick={() => toggleSort(c.key)}>
                        {c.label}
                        <span className="text-xs">{sort.key === c.key ? (sort.dir === 'asc' ? '▲' : '▼') : '↕'}</span>
                      </button>
                    ) : (
                      c.label
                    )}
                  </th>
                ))}
                {actions && <th className="text-right">Actions</th>}
              </tr>
            </thead>
            <tbody className={loading ? 'opacity-50' : ''}>
              {rows.map((row) => (
                <tr key={row.id}>
                  {columns.map((c) => <td key={c.key}>{c.render ? c.render(row) : row[c.key] ?? '—'}</td>)}
                  {actions && <td className="whitespace-nowrap text-right">{actions(row)}</td>}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {data && data.last_page > 1 && (
        <div className="flex items-center justify-between border-t border-slate-100 px-4 py-3 text-sm">
          <button className="btn-ghost py-1" disabled={page <= 1} onClick={() => setPage(page - 1)}>← Previous</button>
          <span className="text-slate-500">Page {data.current_page} of {data.last_page}</span>
          <button className="btn-ghost py-1" disabled={page >= data.last_page} onClick={() => setPage(page + 1)}>Next →</button>
        </div>
      )}
    </div>
  )
}
