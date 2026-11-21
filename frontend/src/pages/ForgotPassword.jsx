import { useState } from 'react'
import { Link } from 'react-router-dom'
import { api, errorMessage } from '../api/client'
import AuthCard from '../components/AuthCard'
import { ErrorBox, Field } from '../components/ui'

export default function ForgotPassword() {
  const [email, setEmail] = useState('')
  const [message, setMessage] = useState(null)
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)

  async function submit(e) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      const res = await api.post('/auth/forgot-password', { email })
      setMessage(res.data.message)
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <AuthCard title="Forgot your password?" subtitle="We'll email you a link to reset it.">
      <ErrorBox message={error} />
      {message ? (
        <p className="rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-700">{message}</p>
      ) : (
        <form onSubmit={submit}>
          <Field label="University email">
            <input type="email" className="input" value={email} onChange={(e) => setEmail(e.target.value)} required />
          </Field>
          <button className="btn-primary w-full" disabled={busy}>{busy ? 'Sending…' : 'Send reset link'}</button>
        </form>
      )}
      <Link to="/login" className="mt-4 block text-center text-sm text-indigo-600">Back to sign in</Link>
    </AuthCard>
  )
}
