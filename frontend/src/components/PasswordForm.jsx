import { useState } from 'react'
import { api, errorMessage } from '../api/client'
import { useAuth } from '../context/AuthContext'
import { ErrorBox, Field } from './ui'

/** Change-password form, used on the profile page and for forced first-login changes. */
export default function PasswordForm({ onDone }) {
  const { setUser } = useAuth()
  const [form, setForm] = useState({ current_password: '', password: '', password_confirmation: '' })
  const [error, setError] = useState(null)
  const [message, setMessage] = useState(null)
  const [busy, setBusy] = useState(false)
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })

  async function submit(e) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      const res = await api.put('/auth/password', form)
      setUser(res.data.user)
      setMessage(res.data.message)
      setForm({ current_password: '', password: '', password_confirmation: '' })
      onDone?.()
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <form onSubmit={submit}>
      <ErrorBox message={error} />
      {message && <p className="mb-3 text-sm text-emerald-600">{message}</p>}
      <Field label="Current password">
        <input type="password" className="input" value={form.current_password} onChange={set('current_password')} required />
      </Field>
      <Field label="New password (8+ characters, letters and numbers)">
        <input type="password" className="input" value={form.password} onChange={set('password')} minLength={8} required />
      </Field>
      <Field label="Confirm new password">
        <input type="password" className="input" value={form.password_confirmation} onChange={set('password_confirmation')} required />
      </Field>
      <button className="btn-primary w-full" disabled={busy}>{busy ? 'Saving…' : 'Change password'}</button>
    </form>
  )
}
