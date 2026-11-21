import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api, errorMessage } from '../../api/client'
import { useApi } from '../../api/useApi'
import { Badge, ErrorBox, Loading, formatDate } from '../../components/ui'
import { useAuth } from '../../context/AuthContext'

export default function AssignmentDetail() {
  const { id } = useParams()
  const { user } = useAuth()
  const { data: a, loading, error, reload } = useApi(`/assignments/${id}`)

  if (loading) return <Loading />
  if (error) return <ErrorBox message={error} />

  const isTeacher = Array.isArray(a.roster)

  return (
    <div className="mx-auto max-w-4xl">
      <Link to={`/classes/${a.course_section_id}`} className="text-sm text-indigo-600">← {a.section?.course?.code} {a.section?.course?.title}</Link>
      <div className="card mt-3">
        <div className="flex items-start justify-between">
          <div>
            <h1 className="text-xl font-semibold">{a.title}</h1>
            <p className="text-sm text-slate-500">
              <span className="capitalize">{a.category}</span> · {a.max_marks} marks · Due {formatDate(a.due_at, true)}
            </p>
          </div>
          {a.due_at && new Date(a.due_at) < new Date() && <Badge tone="rose">Past due</Badge>}
        </div>
        {a.instructions && <p className="mt-4 whitespace-pre-line text-sm text-slate-700">{a.instructions}</p>}
      </div>

      {isTeacher ? <TeacherView a={a} onChange={reload} /> : <StudentView a={a} user={user} onChange={reload} />}
    </div>
  )
}

function StudentView({ a, onChange }) {
  const mine = a.submissions?.[0]
  const [content, setContent] = useState(mine?.content || '')
  const [file, setFile] = useState(null)
  const [error, setError] = useState(null)

  async function submit(e) {
    e.preventDefault()
    const body = new FormData()
    if (content) body.append('content', content)
    if (file) body.append('file', file)
    try {
      await api.post(`/assignments/${a.id}/submit`, body)
      onChange()
    } catch (err) {
      setError(errorMessage(err))
    }
  }

  return (
    <div className="card mt-4">
      <h2 className="mb-3 font-semibold">Your work</h2>
      {mine?.marks != null ? (
        <div>
          <p className="text-2xl font-semibold text-emerald-600">{mine.marks}/{a.max_marks}</p>
          {mine.feedback && <p className="mt-2 text-sm text-slate-600">Feedback: {mine.feedback}</p>}
        </div>
      ) : (
        <form onSubmit={submit}>
          <ErrorBox message={error} />
          {mine && <p className="mb-2 text-sm text-slate-500">Turned in {formatDate(mine.submitted_at, true)}{mine.is_late && ' (late)'} — you can resubmit until graded.</p>}
          <textarea className="input min-h-28" placeholder="Write your answer or add a note" value={content} onChange={(e) => setContent(e.target.value)} />
          <input type="file" className="mt-3 text-sm" onChange={(e) => setFile(e.target.files[0])} />
          <button className="btn-primary mt-3 w-full">{mine ? 'Resubmit' : 'Turn in'}</button>
        </form>
      )}
    </div>
  )
}

function TeacherView({ a, onChange }) {
  const byStudent = Object.fromEntries((a.submissions || []).map((s) => [s.student_id, s]))
  const [marks, setMarks] = useState({})

  async function grade(submission) {
    await api.patch(`/submissions/${submission.id}/grade`, { marks: marks[submission.id] ?? submission.marks })
    onChange()
  }

  return (
    <div className="card mt-4 overflow-x-auto p-0">
      <table className="table">
        <thead>
          <tr><th>Student</th><th>Status</th><th>Work</th><th>Marks</th><th /></tr>
        </thead>
        <tbody>
          {a.roster.map((st) => {
            const s = byStudent[st.id]
            return (
              <tr key={st.id}>
                <td className="font-medium">{st.name}</td>
                <td>{s ? <Badge tone={s.is_late ? 'amber' : 'emerald'}>{s.is_late ? 'Late' : 'Turned in'}</Badge> : <Badge>Missing</Badge>}</td>
                <td className="max-w-xs truncate text-slate-600">{s?.content}{s?.file_path && <a className="ml-1 text-indigo-600" href={`/storage/${s.file_path}`} target="_blank" rel="noreferrer">File</a>}</td>
                <td>
                  {s && (
                    <input
                      type="number"
                      className="input w-24"
                      max={a.max_marks}
                      defaultValue={s.marks ?? ''}
                      onChange={(e) => setMarks({ ...marks, [s.id]: e.target.value })}
                    />
                  )}
                </td>
                <td>{s && <button className="btn-ghost py-1" onClick={() => grade(s)}>Save</button>}</td>
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}
