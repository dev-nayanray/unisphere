import { useState } from 'react'
import { api, errorMessage } from '../../api/client'
import { useApi } from '../../api/useApi'
import { Badge, ErrorBox, Loading, formatDate } from '../../components/ui'

/** Teacher view: take attendance by list or open a QR check-in. */
export default function AttendanceTab({ cls }) {
  const people = useApi(`/classes/${cls.id}/people`)
  const sessions = useApi(`/classes/${cls.id}/attendance`)
  const report = useApi(`/classes/${cls.id}/attendance/report`)
  const [marks, setMarks] = useState({})
  const [qr, setQr] = useState(null)
  const [error, setError] = useState(null)
  const today = new Date().toISOString().slice(0, 10)

  const statusOf = (id) => marks[id] || 'present'

  async function save(withQr = false) {
    setError(null)
    try {
      const records = withQr ? [] : people.data.students.map((s) => ({ student_id: s.id, status: statusOf(s.id) }))
      const res = await api.post(`/classes/${cls.id}/attendance`, { date: today, with_qr: withQr, records })
      if (withQr) setQr(res.data.qr_token)
      sessions.reload()
      report.reload()
    } catch (err) {
      setError(errorMessage(err))
    }
  }

  if (people.loading) return <Loading />

  return (
    <div className="grid gap-6 lg:grid-cols-3">
      <div className="card lg:col-span-2">
        <div className="mb-3 flex items-center justify-between">
          <h2 className="font-semibold">Take attendance · {formatDate(today)}</h2>
          <button className="btn-ghost" onClick={() => save(true)}>Open QR check-in</button>
        </div>
        <ErrorBox message={error} />
        {qr && (
          <div className="mb-4 rounded-lg bg-indigo-50 p-4 text-sm">
            Show this check-in code to the class (valid 10 minutes). Students enter it on their Attendance page,
            or render it as a QR code:
            <p className="mt-2 break-all font-mono text-xs">{qr}</p>
          </div>
        )}
        <ul className="divide-y divide-slate-100">
          {people.data.students.map((s) => (
            <li key={s.id} className="flex items-center justify-between py-2 text-sm">
              <span>{s.name}</span>
              <div className="flex gap-1">
                {['present', 'late', 'absent'].map((st) => (
                  <button
                    key={st}
                    onClick={() => setMarks({ ...marks, [s.id]: st })}
                    className={`rounded px-2 py-1 text-xs capitalize ${statusOf(s.id) === st ? 'bg-indigo-600 text-white' : 'bg-slate-100'}`}
                  >
                    {st}
                  </button>
                ))}
              </div>
            </li>
          ))}
        </ul>
        <button className="btn-primary mt-4" onClick={() => save(false)}>Save attendance</button>
      </div>

      <div className="space-y-6">
        <div className="card">
          <h2 className="mb-2 font-semibold">Below {report.data?.minimum_percent}%</h2>
          {report.data?.students?.filter((s) => s.warning).map((s) => (
            <p key={s.id} className="flex justify-between text-sm">
              {s.name} <Badge tone="rose">{s.percent}%</Badge>
            </p>
          ))}
        </div>
        <div className="card">
          <h2 className="mb-2 font-semibold">Past sessions</h2>
          {sessions.data?.map((s) => (
            <p key={s.id} className="flex justify-between text-sm">
              {formatDate(s.date)} <span className="text-slate-500">{s.present_count}/{s.records_count}</span>
            </p>
          ))}
        </div>
      </div>
    </div>
  )
}
