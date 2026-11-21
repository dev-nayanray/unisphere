import { useState } from 'react'
import { api, errorMessage } from '../api/client'
import { useApi } from '../api/useApi'
import { useOptions } from '../api/useOptions'
import { useToast } from '../components/Toast'
import { Badge, Empty, ErrorBox, Field, Loading, Modal, PageHeader } from '../components/ui'
import { useAuth } from '../context/AuthContext'

export default function Approvals() {
  const toast = useToast()
  const { hasRole } = useAuth()
  const { data, loading, error, reload } = useApi('/registration/pending')
  const [selected, setSelected] = useState([])
  const [note, setNote] = useState('')
  const [forceOpen, setForceOpen] = useState(false)

  const rows = data?.data || []
  const allSelected = rows.length > 0 && selected.length === rows.length

  async function decide(ids, status) {
    try {
      const res = await api.patch('/registration/decide-many', { ids, status, note: note || null })
      toast.success(res.data.message)
      setSelected([])
      setNote('')
      reload()
    } catch (err) {
      toast.error(errorMessage(err))
    }
  }

  // Group requests by student so an advisor sees each student's whole plan.
  const byStudent = rows.reduce((groups, r) => {
    ;(groups[r.student_id] ||= { student: r.student, rows: [] }).rows.push(r)
    return groups
  }, {})

  return (
    <div>
      <PageHeader
        title="Registration Approvals"
        subtitle="Course requests from your advisees and your department"
        actions={hasRole('registrar') && <button className="btn-ghost" onClick={() => setForceOpen(true)}>Force-add student</button>}
      />
      <ErrorBox message={error} />

      {loading ? (
        <Loading />
      ) : rows.length === 0 ? (
        <Empty>No pending requests. 🎉</Empty>
      ) : (
        <>
          <div className="card mb-4 flex flex-wrap items-center gap-3">
            <label className="flex items-center gap-2 text-sm">
              <input type="checkbox" checked={allSelected} onChange={() => setSelected(allSelected ? [] : rows.map((r) => r.id))} />
              Select all ({rows.length})
            </label>
            <input className="input max-w-md flex-1" placeholder="Note to students (optional)" value={note} onChange={(e) => setNote(e.target.value)} />
            <button className="btn-primary" disabled={!selected.length} onClick={() => decide(selected, 'approved')}>Approve {selected.length || ''}</button>
            <button className="btn-ghost text-rose-600" disabled={!selected.length} onClick={() => decide(selected, 'rejected')}>Reject</button>
          </div>

          <div className="space-y-4">
            {Object.values(byStudent).map(({ student, rows: list }) => {
              const credits = list.reduce((t, r) => t + Number(r.section.course.credit), 0)
              const ids = list.map((r) => r.id)
              return (
                <div key={student.id} className="card p-0">
                  <div className="flex flex-wrap items-center gap-3 border-b border-slate-100 px-5 py-3">
                    <p className="font-medium">{student.name}</p>
                    <span className="text-sm text-slate-500">{student.student?.student_id} · CGPA {Number(student.student?.cgpa || 0).toFixed(2)}</span>
                    <Badge>{credits} credits requested</Badge>
                    <span className="ml-auto space-x-3 text-sm">
                      <button className="text-emerald-700 hover:underline" onClick={() => decide(ids, 'approved')}>Approve all</button>
                      <button className="text-rose-600 hover:underline" onClick={() => decide(ids, 'rejected')}>Reject all</button>
                    </span>
                  </div>
                  <ul className="divide-y divide-slate-50">
                    {list.map((r) => (
                      <li key={r.id} className="flex items-center gap-3 px-5 py-2 text-sm">
                        <input
                          type="checkbox"
                          checked={selected.includes(r.id)}
                          onChange={() => setSelected((s) => (s.includes(r.id) ? s.filter((x) => x !== r.id) : [...s, r.id]))}
                        />
                        <span className="font-medium">{r.section.course.code}</span>
                        <span className="text-slate-600">{r.section.course.title}</span>
                        <span className="text-slate-400">{r.section.course.credit} cr</span>
                        {r.is_retake && <Badge tone="amber">Retake</Badge>}
                      </li>
                    ))}
                  </ul>
                </div>
              )
            })}
          </div>
        </>
      )}

      {forceOpen && <ForceAdd onClose={() => setForceOpen(false)} />}
    </div>
  )
}

/** Registrar override: enrol a student into a section even if it's full. */
function ForceAdd({ onClose }) {
  const toast = useToast()
  const semesters = useApi('/semesters')
  const current = semesters.data?.find((s) => s.is_current)
  const sections = useOptions(current ? '/admin/sections' : null, (s) => `${s.course.code} ${s.section} · ${s.students_count}/${s.capacity}`, { semester_id: current?.id })
  const [form, setForm] = useState({ student: '', section_id: '' })
  const [error, setError] = useState(null)

  async function save(e) {
    e.preventDefault()
    try {
      const res = await api.post('/registration/force', form)
      toast.success(res.data.message)
      onClose()
    } catch (err) {
      setError(errorMessage(err))
    }
  }

  return (
    <Modal open title="Force-add a student" onClose={onClose}>
      <form onSubmit={save}>
        <ErrorBox message={error} />
        <Field label="Student ID or email">
          <input className="input" value={form.student} onChange={(e) => setForm({ ...form, student: e.target.value })} required />
        </Field>
        <Field label={`Section (${current?.name || 'current semester'})`}>
          <select className="input" value={form.section_id} onChange={(e) => setForm({ ...form, section_id: e.target.value })} required>
            <option value="">Select…</option>
            {sections.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
          </select>
        </Field>
        <p className="mb-3 text-xs text-slate-500">The student is approved immediately, even over capacity or outside the registration window.</p>
        <button className="btn-primary w-full">Add student</button>
      </form>
    </Modal>
  )
}
