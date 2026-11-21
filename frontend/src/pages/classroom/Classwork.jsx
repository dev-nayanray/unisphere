import { useState } from 'react'
import { Link } from 'react-router-dom'
import { api, errorMessage } from '../../api/client'
import { useApi } from '../../api/useApi'
import { Badge, Empty, ErrorBox, Field, Loading, Modal, formatDate } from '../../components/ui'

export default function Classwork({ cls }) {
  const assignments = useApi(`/classes/${cls.id}/assignments`)
  const materials = useApi(`/classes/${cls.id}/materials`)
  const quizzes = useApi(`/classes/${cls.id}/quizzes`)
  const [modal, setModal] = useState(null) // 'assignment' | 'material'

  if (assignments.loading || materials.loading) return <Loading />

  return (
    <div className="space-y-6">
      {cls.can_manage && (
        <div className="flex gap-2">
          <button className="btn-primary" onClick={() => setModal('assignment')}>+ Assignment</button>
          <button className="btn-ghost" onClick={() => setModal('material')}>+ Material</button>
        </div>
      )}

      <section>
        <h2 className="mb-2 font-semibold">Assignments & exams</h2>
        {assignments.data?.length ? (
          <ul className="card divide-y divide-slate-100 p-0">
            {assignments.data.map((a) => {
              const mine = a.submissions?.[0]
              return (
                <li key={a.id}>
                  <Link to={`/assignments/${a.id}`} className="flex items-center justify-between px-5 py-3 hover:bg-slate-50">
                    <div>
                      <p className="font-medium">{a.title}</p>
                      <p className="text-xs text-slate-500">
                        <span className="capitalize">{a.category}</span> · {a.max_marks} marks · Due {formatDate(a.due_at, true)}
                      </p>
                    </div>
                    {cls.can_manage ? (
                      <span className="text-xs text-slate-500">{a.submissions_count} turned in · {a.graded_count} graded</span>
                    ) : mine?.marks != null ? (
                      <Badge tone="emerald">{mine.marks}/{a.max_marks}</Badge>
                    ) : mine ? (
                      <Badge tone="indigo">Turned in</Badge>
                    ) : (
                      <Badge tone="amber">Assigned</Badge>
                    )}
                  </Link>
                </li>
              )
            })}
          </ul>
        ) : (
          <Empty>No assignments yet.</Empty>
        )}
      </section>

      {quizzes.data?.length > 0 && (
        <section>
          <h2 className="mb-2 font-semibold">Quizzes</h2>
          <ul className="card divide-y divide-slate-100 p-0">
            {quizzes.data.map((q) => (
              <li key={q.id} className="flex items-center justify-between px-5 py-3">
                <div>
                  <p className="font-medium">{q.title}</p>
                  <p className="text-xs text-slate-500">{q.questions_count} questions · {q.duration_minutes} min · {Number(q.max_marks || 0)} marks</p>
                </div>
                {!cls.can_manage && q.attempts?.[0]?.score != null && <Badge tone="emerald">{q.attempts[0].score}</Badge>}
              </li>
            ))}
          </ul>
        </section>
      )}

      <section>
        <h2 className="mb-2 font-semibold">Materials</h2>
        {materials.data?.length ? (
          <ul className="grid gap-3 sm:grid-cols-2">
            {materials.data.map((m) => (
              <li key={m.id} className="card py-3">
                <a href={m.file_url || m.url} target="_blank" rel="noreferrer" className="font-medium text-indigo-700 hover:underline">
                  {m.title}
                </a>
                <p className="text-xs text-slate-500">{m.topic || 'General'} · {m.type}</p>
              </li>
            ))}
          </ul>
        ) : (
          <Empty>No materials yet.</Empty>
        )}
      </section>

      <AssignmentForm open={modal === 'assignment'} cls={cls} onClose={() => setModal(null)} onSaved={assignments.reload} />
      <MaterialForm open={modal === 'material'} cls={cls} onClose={() => setModal(null)} onSaved={materials.reload} />
    </div>
  )
}

function AssignmentForm({ open, cls, onClose, onSaved }) {
  const [form, setForm] = useState({ title: '', instructions: '', category: 'assignment', max_marks: 20, due_at: '' })
  const [error, setError] = useState(null)
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })

  async function save(e) {
    e.preventDefault()
    try {
      await api.post(`/classes/${cls.id}/assignments`, { ...form, due_at: form.due_at || null })
      onSaved()
      onClose()
    } catch (err) {
      setError(errorMessage(err))
    }
  }

  return (
    <Modal open={open} title="New assignment" onClose={onClose}>
      <form onSubmit={save}>
        <ErrorBox message={error} />
        <Field label="Title"><input className="input" value={form.title} onChange={set('title')} required /></Field>
        <Field label="Instructions"><textarea className="input" value={form.instructions} onChange={set('instructions')} /></Field>
        <div className="grid grid-cols-3 gap-3">
          <Field label="Counts as">
            <select className="input" value={form.category} onChange={set('category')}>
              <option value="assignment">Assignment</option>
              <option value="quiz">Class test</option>
              <option value="mid">Midterm</option>
              <option value="final">Final</option>
            </select>
          </Field>
          <Field label="Marks"><input type="number" className="input" value={form.max_marks} onChange={set('max_marks')} /></Field>
          <Field label="Due"><input type="datetime-local" className="input" value={form.due_at} onChange={set('due_at')} /></Field>
        </div>
        <button className="btn-primary w-full">Assign</button>
      </form>
    </Modal>
  )
}

function MaterialForm({ open, cls, onClose, onSaved }) {
  const [form, setForm] = useState({ title: '', topic: '', type: 'link', url: '' })
  const [file, setFile] = useState(null)
  const [error, setError] = useState(null)
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })

  async function save(e) {
    e.preventDefault()
    const body = new FormData()
    Object.entries(form).forEach(([k, v]) => v && body.append(k, v))
    if (form.type === 'file' && file) body.append('file', file)
    try {
      await api.post(`/classes/${cls.id}/materials`, body)
      onSaved()
      onClose()
    } catch (err) {
      setError(errorMessage(err))
    }
  }

  return (
    <Modal open={open} title="New material" onClose={onClose}>
      <form onSubmit={save}>
        <ErrorBox message={error} />
        <Field label="Title"><input className="input" value={form.title} onChange={set('title')} required /></Field>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Topic / week"><input className="input" value={form.topic} onChange={set('topic')} /></Field>
          <Field label="Type">
            <select className="input" value={form.type} onChange={set('type')}>
              <option value="link">Link</option>
              <option value="video">Video</option>
              <option value="file">File upload</option>
            </select>
          </Field>
        </div>
        {form.type === 'file' ? (
          <Field label="File"><input type="file" className="input" onChange={(e) => setFile(e.target.files[0])} required /></Field>
        ) : (
          <Field label="URL"><input type="url" className="input" value={form.url} onChange={set('url')} required /></Field>
        )}
        <button className="btn-primary w-full">Upload</button>
      </form>
    </Modal>
  )
}
