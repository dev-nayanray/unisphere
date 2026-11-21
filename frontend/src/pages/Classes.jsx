import { useState } from 'react'
import { Link } from 'react-router-dom'
import { api, errorMessage } from '../api/client'
import { useApi } from '../api/useApi'
import { Empty, ErrorBox, Field, Loading, Modal, PageHeader } from '../components/ui'
import { useAuth } from '../context/AuthContext'

export const COVER = {
  indigo: 'from-indigo-500 to-indigo-700',
  emerald: 'from-emerald-500 to-emerald-700',
  amber: 'from-amber-500 to-orange-600',
  rose: 'from-rose-500 to-pink-700',
  sky: 'from-sky-500 to-blue-700',
}

export default function Classes() {
  const { user } = useAuth()
  const { data, loading, error, reload } = useApi('/classes')
  const [joinOpen, setJoinOpen] = useState(false)
  const [code, setCode] = useState('')
  const [message, setMessage] = useState(null)

  async function join(e) {
    e.preventDefault()
    try {
      const res = await api.post('/classes/join', { code })
      setMessage(res.data.message)
      setCode('')
      reload()
    } catch (err) {
      setMessage(errorMessage(err))
    }
  }

  return (
    <div>
      <PageHeader
        title="My Classes"
        subtitle="Your virtual classrooms for this semester"
        actions={user.role === 'student' && <button className="btn-primary" onClick={() => setJoinOpen(true)}>+ Join class</button>}
      />
      <ErrorBox message={error} />
      {loading ? (
        <Loading />
      ) : data?.length ? (
        <div className="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
          {data.map((c) => (
            <Link key={c.id} to={`/classes/${c.id}`} className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm transition hover:shadow-md">
              <div className={`bg-gradient-to-br ${COVER[c.cover_color] || COVER.indigo} p-5 text-white`}>
                <p className="text-sm opacity-80">{c.course.code} · Section {c.section}</p>
                <h3 className="mt-1 text-lg font-semibold">{c.course.title}</h3>
                <p className="mt-2 text-sm opacity-90">{c.teacher?.name || 'Teacher TBA'}</p>
              </div>
              <div className="flex justify-between px-5 py-3 text-xs text-slate-500">
                <span>{c.students_count} students</span>
                <span>{c.room ? `Room ${c.room}` : ''}</span>
              </div>
            </Link>
          ))}
        </div>
      ) : (
        <Empty>No classes yet. {user.role === 'student' && 'Register for courses or join with a class code.'}</Empty>
      )}

      <Modal open={joinOpen} title="Join a class" onClose={() => { setJoinOpen(false); setMessage(null) }}>
        <form onSubmit={join}>
          <Field label="Class code (ask your teacher)">
            <input className="input" value={code} onChange={(e) => setCode(e.target.value)} maxLength={8} required />
          </Field>
          {message && <p className="mb-3 text-sm text-slate-600">{message}</p>}
          <button className="btn-primary w-full">Join</button>
        </form>
      </Modal>
    </div>
  )
}
