import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api, errorMessage } from '../../api/client'
import { useApi } from '../../api/useApi'
import Attachments from '../../components/Attachments'
import RichText from '../../components/RichText'
import { useToast } from '../../components/Toast'
import { Badge, ErrorBox, Loading, formatDate } from '../../components/ui'

const LATE = {
  accept: 'Late work accepted',
  penalty: (p) => `Late work loses ${p}% per day`,
  reject: 'Late work not accepted',
}

export default function AssignmentDetail() {
  const { id } = useParams()
  const { data: a, loading, error, reload } = useApi(`/assignments/${id}`)

  if (loading) return <Loading />
  if (error) return <ErrorBox message={error} />

  const isTeacher = Array.isArray(a.roster)
  const lateText = a.late_policy === 'penalty' ? LATE.penalty(a.late_penalty_percent) : LATE[a.late_policy]

  return (
    <div className="mx-auto max-w-5xl">
      <Link to={`/classes/${a.course_section_id}`} className="text-sm text-indigo-600">← {a.section?.course?.code} {a.section?.course?.title}</Link>
      <div className="card mt-3">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h1 className="text-xl font-semibold">{a.title}</h1>
            <p className="text-sm text-slate-500">
              <span className="capitalize">{a.category}</span> · {Number(a.max_marks)} points · {a.due_at ? `Due ${formatDate(a.due_at, true)}` : 'No due date'}
              {a.topic && ` · ${a.topic.name}`}
            </p>
            <p className="mt-1 text-xs text-slate-400">{lateText}</p>
          </div>
          {a.due_at && new Date(a.due_at) < new Date() && <Badge tone="rose">Past due</Badge>}
        </div>
        {a.instructions && <RichText text={a.instructions} className="mt-4 text-sm text-slate-700" />}
        <Attachments files={a.attachments} />
        {a.criteria?.length > 0 && <RubricTable criteria={a.criteria} />}
      </div>

      {isTeacher ? <TeacherView a={a} /> : <StudentView a={a} onChange={reload} />}
    </div>
  )
}

function RubricTable({ criteria, selected = {}, onPick }) {
  return (
    <div className="mt-4 overflow-x-auto">
      <p className="mb-2 text-sm font-semibold">Rubric</p>
      <table className="w-full border-collapse text-xs">
        <tbody>
          {criteria.map((c) => (
            <tr key={c.id} className="border-t border-slate-100">
              <th className="w-40 py-2 pr-3 text-left align-top font-medium">{c.title}</th>
              {c.levels.map((l) => {
                const active = Number(selected[c.id]) === l.id
                return (
                  <td key={l.id} className="p-1 align-top">
                    <button
                      type="button"
                      disabled={!onPick}
                      onClick={() => onPick?.(c.id, l.id)}
                      className={`w-full rounded-lg border px-2 py-1.5 text-left ${active ? 'border-indigo-500 bg-indigo-50' : 'border-slate-200'} ${onPick ? 'hover:border-indigo-300' : 'cursor-default'}`}
                    >
                      <span className="font-semibold">{Number(l.points)}</span> · {l.title}
                    </button>
                  </td>
                )
              })}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function StudentView({ a, onChange }) {
  const toast = useToast()
  const mine = a.submissions?.[0]
  const [content, setContent] = useState(mine?.content || '')
  const [file, setFile] = useState(null)
  const [error, setError] = useState(null)
  const closed = a.late_policy === 'reject' && a.due_at && new Date(a.due_at) < new Date()
  const turnedIn = Boolean(mine?.submitted_at)
  const returned = Boolean(mine?.returned_at)

  async function call(fn) {
    setError(null)
    try {
      const res = await fn()
      toast.success(res.data.message)
      onChange()
    } catch (err) {
      setError(errorMessage(err))
    }
  }

  function submit(e) {
    e.preventDefault()
    const body = new FormData()
    if (content) body.append('content', content)
    if (file) body.append('file', file)
    call(() => api.post(`/assignments/${a.id}/submit`, body))
  }

  return (
    <div className="card mt-4">
      <div className="mb-3 flex items-center justify-between">
        <h2 className="font-semibold">Your work</h2>
        {returned ? <Badge tone="emerald">Returned</Badge> : turnedIn ? <Badge tone="indigo">Turned in</Badge> : <Badge tone="amber">Assigned</Badge>}
      </div>
      <ErrorBox message={error} />

      {returned ? (
        <div>
          <p className="text-2xl font-semibold text-emerald-600">{Number(mine.effective_marks)}/{Number(a.max_marks)}</p>
          {mine.late_penalty_percent > 0 && <p className="text-sm text-amber-700">Marked {Number(mine.marks)}, minus {mine.late_penalty_percent}% for being late.</p>}
          {mine.feedback && <p className="mt-2 whitespace-pre-wrap text-sm text-slate-600">Feedback: {mine.feedback}</p>}
          {a.criteria?.length > 0 && mine.rubric_scores && <RubricTable criteria={a.criteria} selected={mine.rubric_scores} />}
        </div>
      ) : turnedIn ? (
        <div>
          <p className="text-sm text-slate-600">Turned in {formatDate(mine.submitted_at, true)}{mine.is_late && ' (late)'}.</p>
          {mine.content && <p className="mt-2 whitespace-pre-wrap rounded-lg bg-slate-50 p-3 text-sm">{mine.content}</p>}
          {mine.file_url && <a href={mine.file_url} target="_blank" rel="noreferrer" className="mt-2 inline-block text-sm text-indigo-600">📎 {mine.file_name}</a>}
          <button className="btn-ghost mt-3 w-full" onClick={() => call(() => api.post(`/assignments/${a.id}/unsubmit`))}>Unsubmit to make changes</button>
        </div>
      ) : closed ? (
        <p className="text-sm text-rose-600">The due date has passed and late work is not accepted.</p>
      ) : (
        <form onSubmit={submit}>
          <textarea className="input min-h-28" placeholder="Write your answer or add a note" value={content} onChange={(e) => setContent(e.target.value)} />
          <input type="file" className="mt-3 text-sm" onChange={(e) => setFile(e.target.files[0])} />
          {mine?.file_name && !file && <p className="mt-1 text-xs text-slate-500">Previous file: {mine.file_name} (choose a new one to replace it)</p>}
          <button className="btn-primary mt-3 w-full">Turn in</button>
        </form>
      )}

      {mine?.versions?.length > 0 && (
        <details className="mt-4 text-xs text-slate-500">
          <summary className="cursor-pointer">History: {mine.versions.length} turn-in(s)</summary>
          <ul className="mt-1 space-y-0.5">
            {mine.versions.map((v) => <li key={v.id}>#{v.number} · {formatDate(v.submitted_at, true)}{v.is_late && ' (late)'}{v.file_name && ` · ${v.file_name}`}</li>)}
          </ul>
        </details>
      )}
    </div>
  )
}

function TeacherView({ a }) {
  const subs = a.submissions || []
  const turnedIn = subs.filter((s) => s.submitted_at).length
  const graded = subs.filter((s) => s.marks != null).length
  const returned = subs.filter((s) => s.returned_at).length

  return (
    <div className="card mt-4 flex flex-wrap items-center gap-6">
      <Stat label="Assigned" value={a.roster.length} />
      <Stat label="Turned in" value={turnedIn} />
      <Stat label="Graded" value={graded} />
      <Stat label="Returned" value={returned} />
      <Link to={`/assignments/${a.id}/grade`} className="btn-primary ml-auto">Open grading workspace</Link>
    </div>
  )
}

function Stat({ label, value }) {
  return (
    <div>
      <p className="text-2xl font-semibold">{value}</p>
      <p className="text-xs text-slate-500">{label}</p>
    </div>
  )
}
