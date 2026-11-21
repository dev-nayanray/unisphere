import { useState } from 'react'
import { api, errorMessage } from '../api/client'
import { useApi } from '../api/useApi'
import { Badge, Empty, ErrorBox, Field, Loading, Modal, PageHeader, formatDate, statusTone } from '../components/ui'
import { useAuth } from '../context/AuthContext'

const CATEGORIES = ['academic', 'transport', 'accounts', 'it', 'facilities', 'other']

export default function Complaints() {
  const { user } = useAuth()
  const { data, loading, error, reload } = useApi('/complaints')
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState({ category: 'academic', subject: '', description: '' })
  const [formError, setFormError] = useState(null)
  const isAdmin = ['super_admin', 'registrar'].includes(user.role)

  async function save(e) {
    e.preventDefault()
    try {
      await api.post('/complaints', form)
      setOpen(false)
      setForm({ category: 'academic', subject: '', description: '' })
      reload()
    } catch (err) {
      setFormError(errorMessage(err))
    }
  }

  async function setStatus(c, status) {
    await api.patch(`/complaints/${c.id}`, { status })
    reload()
  }

  return (
    <div className="mx-auto max-w-5xl">
      <PageHeader
        title="Help Desk"
        subtitle="Raise a ticket and track it until it is resolved"
        actions={<button className="btn-primary" onClick={() => setOpen(true)}>+ New ticket</button>}
      />
      <ErrorBox message={error} />
      {loading ? (
        <Loading />
      ) : data?.data?.length ? (
        <div className="card overflow-x-auto p-0">
          <table className="table">
            <thead><tr><th>#</th><th>Subject</th><th>Category</th>{isAdmin && <th>From</th>}<th>Opened</th><th>Status</th></tr></thead>
            <tbody>
              {data.data.map((c) => (
                <tr key={c.id}>
                  <td className="text-slate-400">{c.id}</td>
                  <td>
                    <p className="font-medium">{c.subject}</p>
                    <p className="max-w-md truncate text-xs text-slate-500">{c.description}</p>
                  </td>
                  <td className="capitalize">{c.category}</td>
                  {isAdmin && <td>{c.user?.name}</td>}
                  <td>{formatDate(c.created_at)}</td>
                  <td>
                    {isAdmin || c.assigned_to === user.id ? (
                      <select className="input py-1" value={c.status} onChange={(e) => setStatus(c, e.target.value)}>
                        <option value="pending">Pending</option>
                        <option value="in_progress">In progress</option>
                        <option value="resolved">Resolved</option>
                      </select>
                    ) : (
                      <Badge tone={statusTone(c.status)}>{c.status.replace('_', ' ')}</Badge>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <Empty>No tickets yet.</Empty>
      )}

      <Modal open={open} title="New help desk ticket" onClose={() => setOpen(false)}>
        <form onSubmit={save}>
          <ErrorBox message={formError} />
          <Field label="Category">
            <select className="input capitalize" value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })}>
              {CATEGORIES.map((c) => <option key={c} value={c}>{c}</option>)}
            </select>
          </Field>
          <Field label="Subject"><input className="input" value={form.subject} onChange={(e) => setForm({ ...form, subject: e.target.value })} required /></Field>
          <Field label="Details"><textarea className="input min-h-28" value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} required /></Field>
          <button className="btn-primary w-full">Submit ticket</button>
        </form>
      </Modal>
    </div>
  )
}
