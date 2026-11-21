import { useState } from 'react'
import { api, errorMessage } from '../api/client'
import { PageHeader } from '../components/ui'

/** Student QR / code check-in. A camera QR scanner can be added later (e.g. html5-qrcode). */
export default function Attendance() {
  const [token, setToken] = useState(new URLSearchParams(window.location.search).get('token') || '')
  const [result, setResult] = useState(null)

  async function checkIn(e) {
    e.preventDefault()
    try {
      const res = await api.post('/attendance/check-in', { token })
      setResult({ ok: true, text: res.data.message })
      setToken('')
    } catch (err) {
      setResult({ ok: false, text: errorMessage(err) })
    }
  }

  return (
    <div className="mx-auto max-w-md">
      <PageHeader title="Attendance Check-in" subtitle="Enter the code your teacher shows in class" />
      <form onSubmit={checkIn} className="card">
        <input className="input font-mono" placeholder="Check-in code" value={token} onChange={(e) => setToken(e.target.value)} required />
        <button className="btn-primary mt-3 w-full">Check in</button>
        {result && (
          <p className={`mt-3 text-sm ${result.ok ? 'text-emerald-600' : 'text-rose-600'}`}>{result.text}</p>
        )}
      </form>
      <p className="mt-4 text-center text-xs text-slate-400">Your attendance marks for each course appear in that course's Grades tab.</p>
    </div>
  )
}
