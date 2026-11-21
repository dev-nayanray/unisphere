import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, errorMessage } from '../api/client'
import { Field } from '../components/ui'

const EMPTY = {
  program_id: '', name: '', email: '', phone: '', date_of_birth: '', gender: '', address: '',
  ssc_gpa: '', ssc_year: '', hsc_gpa: '', hsc_year: '',
}

/** Public online admission form. */
export default function Apply() {
  const [programs, setPrograms] = useState([])
  const [form, setForm] = useState(EMPTY)
  const [files, setFiles] = useState([])
  const [errors, setErrors] = useState({})
  const [message, setMessage] = useState(null)
  const [done, setDone] = useState(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    api.get('/public/programs').then((res) => setPrograms(res.data))
  }, [])

  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })
  const err = (k) => (errors[k] || Object.entries(errors).filter(([key]) => key.startsWith(`${k}.`)).flatMap(([, v]) => v)).join?.(' ')

  async function submit(e) {
    e.preventDefault()
    const body = new FormData()
    Object.entries(form).forEach(([k, v]) => body.append(k, v))
    files.forEach((f) => body.append('documents[]', f))
    setBusy(true)
    setErrors({})
    setMessage(null)
    try {
      const res = await api.post('/applications', body)
      setDone(res.data)
    } catch (error) {
      if (error.response?.data?.errors) setErrors(error.response.data.errors)
      setMessage(errorMessage(error))
    } finally {
      setBusy(false)
    }
  }

  if (done) {
    return (
      <Shell>
        <div className="text-center">
          <p className="text-4xl">🎉</p>
          <h2 className="mt-2 text-xl font-semibold">Application submitted</h2>
          <p className="mt-2 text-sm text-slate-600">Your tracking code is</p>
          <p className="mt-2 inline-block rounded-lg bg-indigo-50 px-4 py-2 font-mono text-2xl tracking-widest text-indigo-700">{done.tracking_code}</p>
          <p className="mt-3 text-sm text-slate-500">Save it. You'll need it with your email to check the status.</p>
          <Link to="/apply/status" className="btn-primary mt-5">Check application status</Link>
        </div>
      </Shell>
    )
  }

  return (
    <Shell>
      <form onSubmit={submit}>
        {message && <div className="mb-4 rounded-lg bg-rose-50 px-4 py-3 text-sm text-rose-700">{message}</div>}
        <h2 className="mb-3 font-semibold">Program</h2>
        <Field label="Program you are applying for">
          <select className="input" value={form.program_id} onChange={set('program_id')} required>
            <option value="">Select a program…</option>
            {programs.map((p) => <option key={p.id} value={p.id}>{p.name} ({p.department?.code})</option>)}
          </select>
        </Field>

        <h2 className="mt-4 mb-3 font-semibold">Personal details</h2>
        <div className="grid gap-x-4 sm:grid-cols-2">
          <Input label="Full name (as in certificates)" error={err('name')}><input className="input" value={form.name} onChange={set('name')} required /></Input>
          <Input label="Email" error={err('email')}><input type="email" className="input" value={form.email} onChange={set('email')} required /></Input>
          <Input label="Mobile number" error={err('phone')}><input className="input" placeholder="01712345678" value={form.phone} onChange={set('phone')} required /></Input>
          <Input label="Date of birth" error={err('date_of_birth')}><input type="date" className="input" value={form.date_of_birth} onChange={set('date_of_birth')} required /></Input>
          <Input label="Gender" error={err('gender')}>
            <select className="input" value={form.gender} onChange={set('gender')} required>
              <option value="">Select…</option><option value="male">Male</option><option value="female">Female</option><option value="other">Other</option>
            </select>
          </Input>
          <Input label="Address" error={err('address')}><input className="input" value={form.address} onChange={set('address')} required /></Input>
        </div>

        <h2 className="mt-4 mb-3 font-semibold">Academic results</h2>
        <div className="grid grid-cols-2 gap-x-4 sm:grid-cols-4">
          <Input label="SSC GPA" error={err('ssc_gpa')}><input type="number" step="0.01" min="1" max="5" className="input" value={form.ssc_gpa} onChange={set('ssc_gpa')} required /></Input>
          <Input label="SSC year" error={err('ssc_year')}><input type="number" className="input" value={form.ssc_year} onChange={set('ssc_year')} required /></Input>
          <Input label="HSC GPA" error={err('hsc_gpa')}><input type="number" step="0.01" min="1" max="5" className="input" value={form.hsc_gpa} onChange={set('hsc_gpa')} required /></Input>
          <Input label="HSC year" error={err('hsc_year')}><input type="number" className="input" value={form.hsc_year} onChange={set('hsc_year')} required /></Input>
        </div>

        <Input label="Documents: SSC and HSC certificates/transcripts (PDF or JPG, up to 5 files, 5 MB each)" error={err('documents')}>
          <input type="file" multiple accept=".pdf,.jpg,.jpeg,.png" className="text-sm" onChange={(e) => setFiles([...e.target.files].slice(0, 5))} required />
        </Input>

        <button className="btn-primary mt-2 w-full" disabled={busy}>{busy ? 'Submitting…' : 'Submit application'}</button>
        <p className="mt-4 text-center text-sm text-slate-500">
          Already applied? <Link to="/apply/status" className="text-indigo-600">Check your status</Link>
        </p>
      </form>
    </Shell>
  )
}

export function ApplicationStatus() {
  const [form, setForm] = useState({ tracking_code: '', email: '' })
  const [result, setResult] = useState(null)
  const [error, setError] = useState(null)

  async function check(e) {
    e.preventDefault()
    setError(null)
    try {
      const res = await api.post('/applications/status', form)
      setResult(res.data)
    } catch (err) {
      setResult(null)
      setError(err.response?.status === 404 ? 'No application matches that code and email.' : errorMessage(err))
    }
  }

  const labels = { submitted: 'Submitted, waiting for review', under_review: 'Under review', accepted: 'Accepted 🎉 Check your email for login details.', rejected: 'Not accepted' }

  return (
    <Shell narrow>
      <form onSubmit={check}>
        <Field label="Tracking code"><input className="input font-mono uppercase" value={form.tracking_code} onChange={(e) => setForm({ ...form, tracking_code: e.target.value })} required /></Field>
        <Field label="Email used to apply"><input type="email" className="input" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} required /></Field>
        <button className="btn-primary w-full">Check status</button>
      </form>
      {error && <p className="mt-4 text-sm text-rose-600">{error}</p>}
      {result && (
        <div className="mt-5 rounded-lg bg-slate-50 p-4 text-sm">
          <p className="font-medium">{result.name} · {result.program?.name}</p>
          <p className="mt-1">{labels[result.status]}</p>
          {result.review_note && <p className="mt-2 text-slate-600">Note: {result.review_note}</p>}
        </div>
      )}
      <Link to="/apply" className="mt-4 block text-center text-sm text-indigo-600">New application</Link>
    </Shell>
  )
}

function Shell({ children, narrow }) {
  return (
    <div className="min-h-screen bg-gradient-to-br from-indigo-600 to-violet-700 px-4 py-10">
      <div className={`mx-auto rounded-2xl bg-white p-6 shadow-2xl sm:p-8 ${narrow ? 'max-w-md' : 'max-w-2xl'}`}>
        <div className="mb-6 flex items-center gap-3">
          <img src="/icon.svg" alt="" className="h-10 w-10" />
          <div>
            <h1 className="text-xl font-semibold">UniSphere Admissions</h1>
            <p className="text-sm text-slate-500">Apply online for undergraduate programs</p>
          </div>
          <Link to="/login" className="ml-auto text-sm text-indigo-600">Sign in</Link>
        </div>
        {children}
      </div>
    </div>
  )
}

function Input({ label, error, children }) {
  return (
    <label className="mb-3 block">
      <span className="label">{label}</span>
      {children}
      {error && <span className="mt-1 block text-xs text-rose-600">{error}</span>}
    </label>
  )
}
