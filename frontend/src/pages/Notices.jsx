import { useState } from 'react'
import { api, errorMessage } from '../api/client'
import { useApi } from '../api/useApi'
import { Badge, Empty, ErrorBox, Field, Loading, Modal, PageHeader, formatDate } from '../components/ui'
import { useAuth } from '../context/AuthContext'

export default function Notices() {
  const { hasRole } = useAuth()
  const { data, loading, error, reload } = useApi('/notices')
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState({ title: '', body: '', audience: 'all', is_urgent: false })
  const [formError, setFormError] = useState(null)

  async function save(e) {
    e.preventDefault()
    try {
      await api.post('/notices', form)
      setOpen(false)
      setForm({ title: '', body: '', audience: 'all', is_urgent: false })
      reload()
    } catch (err) {
      setFormError(errorMessage(err))
    }
  }

  return (
    <div className="mx-auto max-w-4xl">
      <PageHeader
        title="Notice Board"
        subtitle="Official announcements from the university"
        actions={hasRole('registrar', 'department_head', 'teacher') && <button className="btn-primary" onClick={() => setOpen(true)}>+ New notice</button>}
      />
      <ErrorBox message={error} />
      {loading ? (
        <Loading />
      ) : data?.data?.length ? (
        <div className="space-y-3">
          {data.data.map((n) => (
            <article key={n.id} className={`card ${n.is_urgent ? 'border-l-4 border-l-rose-500' : ''}`}>
              <div className="flex flex-wrap items-center gap-2">
                {n.is_urgent && <Badge tone="rose">Urgent</Badge>}
                <Badge>{n.audience}</Badge>
                <span className="text-xs text-slate-400">{formatDate(n.published_at, true)} · {n.author?.name}</span>
              </div>
              <h2 className="mt-2 text-lg font-semibold">{n.title}</h2>
              <p className="mt-1 whitespace-pre-line text-sm text-slate-600">{n.body}</p>
            </article>
          ))}
        </div>
      ) : (
        <Empty>No notices.</Empty>
      )}

      <Modal open={open} title="Publish a notice" onClose={() => setOpen(false)}>
        <form onSubmit={save}>
          <ErrorBox message={formError} />
          <Field label="Title"><input className="input" value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} required /></Field>
          <Field label="Message"><textarea className="input min-h-28" value={form.body} onChange={(e) => setForm({ ...form, body: e.target.value })} required /></Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Audience">
              <select className="input" value={form.audience} onChange={(e) => setForm({ ...form, audience: e.target.value })}>
                <option value="all">Everyone</option>
                <option value="students">Students</option>
                <option value="faculty">Faculty & staff</option>
              </select>
            </Field>
            <label className="mt-7 flex items-center gap-2 text-sm">
              <input type="checkbox" checked={form.is_urgent} onChange={(e) => setForm({ ...form, is_urgent: e.target.checked })} /> Mark as urgent
            </label>
          </div>
          <button className="btn-primary w-full">Publish & notify</button>
        </form>
      </Modal>
    </div>
  )
}
