import { useState } from 'react'
import { api, errorMessage } from '../api/client'
import { useApi } from '../api/useApi'
import ConfirmButton from '../components/ConfirmButton'
import { useToast } from '../components/Toast'
import { Badge, ErrorBox, Loading, PageHeader, statusTone } from '../components/ui'
import { useAuth } from '../context/AuthContext'

const STATUS_LABEL = { pending: 'Waiting for advisor', approved: 'Approved', rejected: 'Rejected', dropped: 'Dropped', waitlisted: 'Waitlisted' }

export default function Registration() {
  const toast = useToast()
  const offered = useApi('/registration/offered')
  const mine = useApi('/registration/mine')
  const [selected, setSelected] = useState([])
  const [errors, setErrors] = useState({})
  const [general, setGeneral] = useState(null)
  const [busy, setBusy] = useState(false)
  const [showSlip, setShowSlip] = useState(false)

  if (offered.loading || mine.loading) return <Loading />
  if (offered.error) return <ErrorBox message={offered.error} />

  const data = offered.data
  const myRows = mine.data || []
  const active = myRows.filter((r) => ['pending', 'approved', 'waitlisted'].includes(r.status))
  const activeCredits = active.reduce((t, r) => t + Number(r.section.course.credit), 0)
  const selectedCredits = data.sections.filter((s) => selected.includes(s.id)).reduce((t, s) => t + Number(s.course.credit), 0)
  const canPick = (s) => data.open && (!s.my_status || ['dropped', 'rejected'].includes(s.my_status))

  const toggle = (id) => setSelected((s) => (s.includes(id) ? s.filter((x) => x !== id) : [...s, id]))

  function reload() {
    offered.reload()
    mine.reload()
  }

  async function submit() {
    setBusy(true)
    setErrors({})
    setGeneral(null)
    try {
      const res = await api.post('/registration', { section_ids: selected })
      toast.success(res.data.message)
      setSelected([])
      reload()
    } catch (err) {
      const e = err.response?.data?.errors || {}
      setErrors(e)
      setGeneral(e.section_ids?.join(' ') || (Object.keys(e).length ? 'Fix the problems marked below.' : errorMessage(err)))
    } finally {
      setBusy(false)
    }
  }

  async function drop(r) {
    try {
      await api.delete(`/registration/${r.id}`)
      toast.success(`${r.section.course.code} dropped.`)
      reload()
    } catch (err) {
      toast.error(errorMessage(err))
    }
  }

  return (
    <div>
      <PageHeader
        title="Course Registration"
        subtitle={`${data.semester.name} · ${data.open ? `Open${data.semester.registration_deadline ? ` until ${data.semester.registration_deadline}` : ''}` : 'Closed'}`}
        actions={active.length > 0 && <button className="btn-ghost" onClick={() => setShowSlip(true)}>Registration slip</button>}
      />

      {active.length + myRows.length > 0 && (
        <section className="card mb-6 p-0">
          <h2 className="border-b border-slate-100 px-5 py-3 font-semibold">My registrations · {activeCredits} credits</h2>
          <ul className="divide-y divide-slate-100">
            {myRows.map((r) => (
              <li key={r.id} className="flex flex-wrap items-center gap-3 px-5 py-3 text-sm">
                <span className="min-w-56 font-medium">{r.section.course.code} · {r.section.course.title}</span>
                <span className="text-slate-500">Sec. {r.section.section} · {r.section.course.credit} cr</span>
                <Badge tone={statusTone(r.status)}>{STATUS_LABEL[r.status]}{r.waitlist_position ? ` #${r.waitlist_position}` : ''}</Badge>
                {r.is_retake && <Badge tone="amber">Retake</Badge>}
                {r.note && <span className="text-xs text-slate-500">“{r.note}”{r.approver ? ` — ${r.approver.name}` : ''}</span>}
                {data.open && ['pending', 'approved', 'waitlisted'].includes(r.status) && (
                  <ConfirmButton
                    className="ml-auto text-rose-600 hover:underline"
                    title={`Drop ${r.section.course.code}?`}
                    message="Your seat goes to the next student on the waitlist."
                    confirmLabel="Drop course"
                    onConfirm={() => drop(r)}
                  >
                    Drop
                  </ConfirmButton>
                )}
              </li>
            ))}
          </ul>
        </section>
      )}

      <ErrorBox message={general} />

      <div className="card overflow-x-auto p-0">
        <table className="table">
          <thead>
            <tr><th /><th>Course</th><th>Credit</th><th>Section</th><th>Teacher</th><th>Schedule</th><th>Seats</th><th>Status</th></tr>
          </thead>
          <tbody>
            {data.sections.map((s) => {
              const rowErrors = errors[`sections.${s.id}`]
              return (
                <tr key={s.id} className={rowErrors ? 'bg-rose-50/70' : ''}>
                  <td>{canPick(s) && <input type="checkbox" checked={selected.includes(s.id)} onChange={() => toggle(s.id)} />}</td>
                  <td>
                    <p className="font-medium">{s.course.code}</p>
                    <p className="text-xs text-slate-500">{s.course.title}</p>
                    {s.course.prerequisite && <p className="text-xs text-amber-600">Prerequisite: {s.course.prerequisite.code}</p>}
                    {rowErrors && <p className="mt-1 text-xs text-rose-700">{rowErrors.join(' ')}</p>}
                  </td>
                  <td>{s.course.credit}</td>
                  <td>{s.section}</td>
                  <td>{s.teacher?.name || 'TBA'}</td>
                  <td className="text-xs">{(s.schedule || []).map((t) => `${t.day} ${t.start}–${t.end}`).join(', ')}</td>
                  <td>{s.seats_left > 0 ? s.seats_left : <Badge tone="amber">Full · waitlist</Badge>}</td>
                  <td>{s.my_status && <Badge tone={statusTone(s.my_status)}>{STATUS_LABEL[s.my_status]}</Badge>}</td>
                </tr>
              )
            })}
          </tbody>
        </table>
      </div>

      <div className="sticky bottom-4 mt-4 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-lg">
        <p className="text-sm">
          Selected <b>{selectedCredits}</b> + registered <b>{activeCredits}</b> = <b>{selectedCredits + activeCredits}</b> credits
          <span className="text-slate-500"> (allowed {data.min_credits}–{data.max_credits})</span>
        </p>
        <button className="btn-primary" disabled={!selected.length || !data.open || busy} onClick={submit}>
          {busy ? 'Submitting…' : 'Submit for advisor approval'}
        </button>
      </div>

      {showSlip && <Slip semester={data.semester} rows={active} onClose={() => setShowSlip(false)} />}
    </div>
  )
}

/** Printable registration slip (use the browser's Print → Save as PDF). */
function Slip({ semester, rows, onClose }) {
  const { user } = useAuth()
  const credits = rows.reduce((t, r) => t + Number(r.section.course.credit), 0)

  return (
    <div className="fixed inset-0 z-50 overflow-y-auto bg-white p-8 print:p-0">
      <div className="mx-auto max-w-3xl">
        <div className="mb-6 flex justify-between print:hidden">
          <button className="btn-ghost" onClick={onClose}>← Back</button>
          <button className="btn-primary" onClick={() => window.print()}>Print / Save as PDF</button>
        </div>
        <div className="flex items-center gap-3 border-b-2 border-slate-800 pb-3">
          <img src="/icon.svg" alt="" className="h-12 w-12" />
          <div>
            <h1 className="text-xl font-bold">UniSphere University</h1>
            <p className="text-sm">Course Registration Slip · {semester.name}</p>
          </div>
        </div>
        <dl className="my-4 grid grid-cols-2 gap-1 text-sm">
          <dt>Name: <b>{user.name}</b></dt>
          <dt>Student ID: <b>{user.student?.student_id}</b></dt>
          <dt>Program: <b>{user.student?.program?.name}</b></dt>
          <dt>Printed: <b>{new Date().toLocaleDateString('en-GB')}</b></dt>
        </dl>
        <table className="w-full border-collapse text-sm">
          <thead>
            <tr className="border-y border-slate-800 text-left">
              <th className="py-1">Code</th><th>Course</th><th>Section</th><th>Credit</th><th>Status</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r) => (
              <tr key={r.id} className="border-b border-slate-200">
                <td className="py-1">{r.section.course.code}</td>
                <td>{r.section.course.title}</td>
                <td>{r.section.section}</td>
                <td>{r.section.course.credit}</td>
                <td className="capitalize">{r.status}</td>
              </tr>
            ))}
          </tbody>
          <tfoot>
            <tr className="font-semibold"><td colSpan={3} className="py-1 text-right">Total credits</td><td>{credits}</td><td /></tr>
          </tfoot>
        </table>
        <div className="mt-16 flex justify-between text-sm">
          <span className="border-t border-slate-800 px-6 pt-1">Student</span>
          <span className="border-t border-slate-800 px-6 pt-1">Advisor</span>
          <span className="border-t border-slate-800 px-6 pt-1">Registrar</span>
        </div>
      </div>
    </div>
  )
}
