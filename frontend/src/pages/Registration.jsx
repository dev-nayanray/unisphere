import { useState } from 'react'
import { api, errorMessage } from '../api/client'
import { useApi } from '../api/useApi'
import { Badge, ErrorBox, Loading, PageHeader, statusTone } from '../components/ui'

export default function Registration() {
  const { data, loading, error, reload } = useApi('/registration/offered')
  const [selected, setSelected] = useState([])
  const [message, setMessage] = useState(null)
  const [failed, setFailed] = useState(null)

  if (loading) return <Loading />
  if (error) return <ErrorBox message={error} />

  const toggle = (id) => setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]))
  const credits = data.sections.filter((s) => selected.includes(s.id)).reduce((t, s) => t + Number(s.course.credit), 0)
  const registered = data.sections
    .filter((s) => ['pending', 'approved'].includes(s.my_status))
    .reduce((t, s) => t + Number(s.course.credit), 0)

  async function submit() {
    setFailed(null)
    try {
      const res = await api.post('/registration', { section_ids: selected })
      setMessage(res.data.message)
      setSelected([])
      reload()
    } catch (err) {
      setFailed(errorMessage(err))
    }
  }

  return (
    <div>
      <PageHeader
        title="Course Registration"
        subtitle={`${data.semester.name} · ${data.semester.registration_open ? 'Registration is open' : 'Registration is closed'}`}
      />
      <ErrorBox message={failed} />
      {message && <div className="mb-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{message}</div>}

      <div className="card overflow-x-auto p-0">
        <table className="table">
          <thead>
            <tr><th /><th>Course</th><th>Credit</th><th>Section</th><th>Teacher</th><th>Schedule</th><th>Seats</th><th>Status</th></tr>
          </thead>
          <tbody>
            {data.sections.map((s) => (
              <tr key={s.id}>
                <td>
                  {!s.my_status || s.my_status === 'dropped' ? (
                    <input type="checkbox" checked={selected.includes(s.id)} onChange={() => toggle(s.id)} disabled={!s.seats_left} />
                  ) : null}
                </td>
                <td>
                  <p className="font-medium">{s.course.code}</p>
                  <p className="text-xs text-slate-500">{s.course.title}</p>
                  {s.course.prerequisite && <p className="text-xs text-amber-600">Prerequisite: {s.course.prerequisite.code}</p>}
                </td>
                <td>{s.course.credit}</td>
                <td>{s.section}</td>
                <td>{s.teacher?.name || 'TBA'}</td>
                <td className="text-xs">{(s.schedule || []).map((t) => `${t.day} ${t.start}`).join(', ')}</td>
                <td>{s.seats_left}</td>
                <td>{s.my_status && <Badge tone={statusTone(s.my_status)}>{s.my_status}</Badge>}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="sticky bottom-4 mt-4 flex items-center justify-between rounded-xl border border-slate-200 bg-white p-4 shadow-lg">
        <p className="text-sm">
          Selected <b>{credits}</b> credits · Registered <b>{registered}</b> · Limit <b>{data.max_credits}</b>
        </p>
        <button className="btn-primary" disabled={!selected.length || !data.semester.registration_open} onClick={submit}>
          Submit for advisor approval
        </button>
      </div>
    </div>
  )
}
