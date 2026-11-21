import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { api, errorMessage } from '../api/client'
import AuthCard from '../components/AuthCard'
import { ErrorBox, Field } from '../components/ui'

export default function ResetPassword() {
  const [params] = useSearchParams()
  const [form, setForm] = useState({ password: '', password_confirmation: '' })
  const [done, setDone] = useState(null)
  const [error, setError] = useState(null)

  async function submit(e) {
    e.preventDefault()
    setError(null)
    try {
      const res = await api.post('/auth/reset-password', {
        ...form,
        token: params.get('token'),
        email: params.get('email'),
      })
      setDone(res.data.message)
    } catch (err) {
      setError(errorMessage(err))
    }
  }

  return (
    <AuthCard title="Set a new password" subtitle={params.get('email')}>
      <ErrorBox message={error} />
      {done ? (
        <>
          <p className="rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{done}</p>
          <Link to="/login" className="btn-primary mt-4 w-full">Sign in</Link>
        </>
      ) : (
        <form onSubmit={submit}>
          <Field label="New password (8+ characters, letters and numbers)">
            <input type="password" className="input" minLength={8} value={form.password} onChange={(e) => setForm({ ...form, password: e.target.value })} required />
          </Field>
          <Field label="Confirm new password">
            <input type="password" className="input" value={form.password_confirmation} onChange={(e) => setForm({ ...form, password_confirmation: e.target.value })} required />
          </Field>
          <button className="btn-primary w-full">Reset password</button>
        </form>
      )}
    </AuthCard>
  )
}
