import { useState } from 'react'
import { api, errorMessage } from '../../api/client'
import { useApi } from '../../api/useApi'
import { Badge, Empty, ErrorBox, Loading } from '../../components/ui'
import { useAuth } from '../../context/AuthContext'

/** Gradebook: weighted marks per category, computed live from classwork. */
export default function Grades({ cls }) {
  const { hasRole } = useAuth()
  const { data, loading, error } = useApi(`/classes/${cls.id}/gradebook`)
  const [message, setMessage] = useState(null)

  async function sync() {
    try {
      const res = await api.post(`/classes/${cls.id}/gradebook/sync`)
      setMessage(res.data.message)
    } catch (err) {
      setMessage(errorMessage(err))
    }
  }

  async function publish() {
    try {
      const res = await api.post(`/classes/${cls.id}/results/publish`)
      setMessage(res.data.message)
    } catch (err) {
      setMessage(errorMessage(err))
    }
  }

  if (loading) return <Loading />
  if (error) return <ErrorBox message={error} />

  const categories = Object.keys(data.scheme)

  return (
    <div>
      {(cls.can_manage || hasRole('department_head', 'registrar')) && (
        <div className="mb-4 flex flex-wrap items-center gap-3">
          {cls.can_manage && <button className="btn-primary" onClick={sync}>Send to official results</button>}
          {hasRole('department_head', 'registrar') && <button className="btn-ghost" onClick={publish}>Publish results</button>}
          {message && <span className="text-sm text-slate-600">{message}</span>}
        </div>
      )}

      {data.rows.length ? (
        <div className="card overflow-x-auto p-0">
          <table className="table">
            <thead>
              <tr>
                <th>Student</th>
                {categories.map((c) => (
                  <th key={c} className="capitalize">{c} <span className="text-xs font-normal">({data.scheme[c]})</span></th>
                ))}
                <th>Total</th>
                <th>Grade</th>
              </tr>
            </thead>
            <tbody>
              {data.rows.map((r) => (
                <tr key={r.registration_id}>
                  <td className="font-medium">{r.student?.name}</td>
                  {categories.map((c) => <td key={c}>{r.breakdown[c]}</td>)}
                  <td className="font-semibold">{r.total}</td>
                  <td><Badge tone={r.grade_point >= 3 ? 'emerald' : r.grade_point > 0 ? 'amber' : 'rose'}>{r.letter_grade} ({r.grade_point.toFixed(2)})</Badge></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <Empty>No students in the gradebook yet.</Empty>
      )}
      <p className="mt-3 text-xs text-slate-400">Grades follow the UGC uniform grading system. Marks update automatically as work is graded.</p>
    </div>
  )
}
