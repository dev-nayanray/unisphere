import { useState } from 'react'
import { api, errorMessage } from '../api/client'
import { useApi } from '../api/useApi'
import PasswordForm from '../components/PasswordForm'
import { Avatar, ErrorBox, Field, PageHeader, formatDate } from '../components/ui'
import { useAuth } from '../context/AuthContext'

export default function Profile() {
  const { user, setUser } = useAuth()
  const activity = useApi('/auth/activity')
  const [form, setForm] = useState({ name: user.name, phone: user.phone || '' })
  const [error, setError] = useState(null)
  const [saved, setSaved] = useState(false)

  async function save(e) {
    e.preventDefault()
    setError(null)
    try {
      const res = await api.put('/auth/profile', { ...form, phone: form.phone || null })
      setUser(res.data)
      setSaved(true)
    } catch (err) {
      setError(errorMessage(err))
    }
  }

  async function uploadAvatar(e) {
    const file = e.target.files[0]
    if (!file) return
    const body = new FormData()
    body.append('avatar', file)
    try {
      const res = await api.post('/auth/avatar', body)
      setUser(res.data)
    } catch (err) {
      setError(errorMessage(err))
    }
  }

  const s = user.student
  const f = user.faculty

  return (
    <div className="mx-auto max-w-4xl">
      <PageHeader title="My Profile" subtitle={user.role_label} />
      <ErrorBox message={error} />

      <div className="grid gap-6 md:grid-cols-3">
        <div className="card text-center">
          <Avatar user={user} size="lg" />
          <p className="mt-3 font-semibold">{user.name}</p>
          <p className="text-sm text-slate-500">{user.email}</p>
          <label className="btn-ghost mt-4 cursor-pointer">
            Change photo
            <input type="file" accept="image/png,image/jpeg,image/webp" className="hidden" onChange={uploadAvatar} />
          </label>

          <dl className="mt-6 space-y-2 text-left text-sm">
            {s && (
              <>
                <Info label="Student ID" value={s.student_id} />
                <Info label="Program" value={s.program?.name} />
                <Info label="Batch" value={s.batch} />
                <Info label="Advisor" value={s.advisor?.name} />
                <Info label="CGPA" value={Number(s.cgpa).toFixed(2)} />
              </>
            )}
            {f && (
              <>
                <Info label="Employee ID" value={f.employee_id} />
                <Info label="Department" value={f.department?.name} />
                <Info label="Designation" value={f.designation} />
              </>
            )}
          </dl>
        </div>

        <div className="space-y-6 md:col-span-2">
          <form onSubmit={save} className="card">
            <h2 className="mb-3 font-semibold">Personal details</h2>
            <Field label="Full name">
              <input className="input" value={form.name} onChange={(e) => { setForm({ ...form, name: e.target.value }); setSaved(false) }} required />
            </Field>
            <Field label="Mobile number">
              <input className="input" value={form.phone} placeholder="01712345678" onChange={(e) => { setForm({ ...form, phone: e.target.value }); setSaved(false) }} />
            </Field>
            <p className="mb-3 text-xs text-slate-400">To change your email, contact the registrar's office.</p>
            <button className="btn-primary">{saved ? 'Saved ✓' : 'Save changes'}</button>
          </form>

          <div className="card">
            <h2 className="mb-3 font-semibold">Change password</h2>
            <PasswordForm />
          </div>

          <div className="card">
            <h2 className="mb-3 font-semibold">Recent sign-ins</h2>
            <ul className="divide-y divide-slate-100 text-sm">
              {activity.data?.map((a) => (
                <li key={a.id} className="flex justify-between gap-4 py-2">
                  <span className="truncate text-slate-600">{a.user_agent || 'Unknown device'}</span>
                  <span className="whitespace-nowrap text-slate-400">{a.ip_address} · {formatDate(a.created_at, true)}</span>
                </li>
              ))}
            </ul>
          </div>
        </div>
      </div>
    </div>
  )
}

function Info({ label, value }) {
  return (
    <div className="flex justify-between gap-3">
      <dt className="text-slate-500">{label}</dt>
      <dd className="text-right font-medium">{value || '—'}</dd>
    </div>
  )
}
