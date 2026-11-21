import { useState } from 'react'
import { api, errorMessage } from '../../api/client'
import { useApi } from '../../api/useApi'
import { ErrorBox, Field, Loading, PageHeader } from '../../components/ui'

/** Course offering: open sections (= virtual classrooms) for a semester and assign teachers. */
export default function Offerings() {
  const semesters = useApi('/semesters')
  const current = semesters.data?.find((s) => s.is_current)
  const [semesterId, setSemesterId] = useState('')
  const activeSemester = semesterId || current?.id || ''

  const sections = useApi(activeSemester ? '/admin/sections' : null, { semester_id: activeSemester })
  const courses = useApi('/admin/courses')
  const teachers = useApi('/admin/users', { role: 'teacher', per_page: 200 })

  const [form, setForm] = useState({ course_id: '', teacher_id: '', section: 'A', room: '', capacity: 40 })
  const [error, setError] = useState(null)
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })

  async function add(e) {
    e.preventDefault()
    try {
      await api.post('/admin/sections', { ...form, semester_id: activeSemester })
      setError(null)
      sections.reload()
    } catch (err) {
      setError(errorMessage(err))
    }
  }

  async function toggleRegistration() {
    const sem = semesters.data.find((s) => s.id === Number(activeSemester))
    await api.put(`/admin/semesters/${sem.id}`, { registration_open: !sem.registration_open })
    semesters.reload()
  }

  const sem = semesters.data?.find((s) => s.id === Number(activeSemester))

  return (
    <div>
      <PageHeader
        title="Course Offering"
        subtitle="Each section you open becomes a virtual classroom"
        actions={
          <>
            <select className="input" value={activeSemester} onChange={(e) => setSemesterId(e.target.value)}>
              {semesters.data?.map((s) => <option key={s.id} value={s.id}>{s.name}{s.is_current ? ' (current)' : ''}</option>)}
            </select>
            {sem && (
              <button className="btn-ghost whitespace-nowrap" onClick={toggleRegistration}>
                {sem.registration_open ? 'Close registration' : 'Open registration'}
              </button>
            )}
          </>
        }
      />

      <div className="grid gap-6 lg:grid-cols-3">
        <div className="card overflow-x-auto p-0 lg:col-span-2">
          {sections.loading ? (
            <Loading />
          ) : (
            <table className="table">
              <thead><tr><th>Course</th><th>Section</th><th>Teacher</th><th>Room</th><th>Enrolled</th><th>Class code</th></tr></thead>
              <tbody>
                {sections.data?.map((s) => (
                  <tr key={s.id}>
                    <td><b>{s.course.code}</b> {s.course.title}</td>
                    <td>{s.section}</td>
                    <td>{s.teacher?.name || '—'}</td>
                    <td>{s.room}</td>
                    <td>{s.students_count}/{s.capacity}</td>
                    <td className="font-mono text-xs">{s.join_code}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>

        <form onSubmit={add} className="card">
          <h2 className="mb-3 font-semibold">Open a section</h2>
          <ErrorBox message={error} />
          <Field label="Course">
            <select className="input" value={form.course_id} onChange={set('course_id')} required>
              <option value="">Select…</option>
              {courses.data?.map((c) => <option key={c.id} value={c.id}>{c.code} · {c.title}</option>)}
            </select>
          </Field>
          <Field label="Teacher">
            <select className="input" value={form.teacher_id} onChange={set('teacher_id')}>
              <option value="">Assign later</option>
              {teachers.data?.data?.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
            </select>
          </Field>
          <div className="grid grid-cols-3 gap-2">
            <Field label="Section"><input className="input" value={form.section} onChange={set('section')} required /></Field>
            <Field label="Room"><input className="input" value={form.room} onChange={set('room')} /></Field>
            <Field label="Seats"><input type="number" className="input" value={form.capacity} onChange={set('capacity')} /></Field>
          </div>
          <button className="btn-primary w-full" disabled={!activeSemester}>Create section</button>
        </form>
      </div>
    </div>
  )
}
