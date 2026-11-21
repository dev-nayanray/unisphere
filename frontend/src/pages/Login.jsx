import { useState } from 'react'
import { Navigate, useNavigate } from 'react-router-dom'
import { errorMessage } from '../api/client'
import { ErrorBox } from '../components/ui'
import { useAuth } from '../context/AuthContext'

const DEMO = [
  ['Student', 'student@unisphere.test'],
  ['Teacher', 'teacher@unisphere.test'],
  ['Dept. Head', 'head.cse@unisphere.test'],
  ['Registrar', 'registrar@unisphere.test'],
  ['Accounts', 'accounts@unisphere.test'],
  ['Super Admin', 'admin@unisphere.test'],
]

export default function Login() {
  const { user, login } = useAuth()
  const navigate = useNavigate()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)

  if (user) return <Navigate to="/" replace />

  async function submit(e) {
    e.preventDefault()
    setBusy(true)
    setError(null)
    try {
      await login(email, password)
      navigate('/')
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="flex min-h-screen items-center justify-center bg-gradient-to-br from-indigo-600 to-violet-700 p-4">
      <div className="w-full max-w-md rounded-2xl bg-white p-8 shadow-2xl">
        <div className="mb-6 flex items-center gap-3">
          <img src="/icon.svg" alt="" className="h-10 w-10" />
          <div>
            <h1 className="text-xl font-semibold">UniSphere</h1>
            <p className="text-sm text-slate-500">Smart University & Virtual Classroom</p>
          </div>
        </div>

        <ErrorBox message={error} />

        <form onSubmit={submit}>
          <label className="mb-3 block">
            <span className="label">Email</span>
            <input className="input" type="email" value={email} onChange={(e) => setEmail(e.target.value)} required />
          </label>
          <label className="mb-5 block">
            <span className="label">Password</span>
            <input className="input" type="password" value={password} onChange={(e) => setPassword(e.target.value)} required />
          </label>
          <button className="btn-primary w-full" disabled={busy}>
            {busy ? 'Signing in…' : 'Sign in'}
          </button>
        </form>

        {import.meta.env.DEV && (
          <div className="mt-6 border-t border-slate-100 pt-4">
            <p className="mb-2 text-xs text-slate-400">Demo accounts (password: password)</p>
            <div className="flex flex-wrap gap-2">
              {DEMO.map(([label, mail]) => (
                <button
                  key={mail}
                  type="button"
                  className="rounded-full bg-slate-100 px-3 py-1 text-xs hover:bg-indigo-50"
                  onClick={() => {
                    setEmail(mail)
                    setPassword('password')
                  }}
                >
                  {label}
                </button>
              ))}
            </div>
          </div>
        )}
      </div>
    </div>
  )
}
