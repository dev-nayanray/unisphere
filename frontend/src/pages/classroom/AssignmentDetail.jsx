import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api, errorMessage } from '../../api/client'
import { useApi } from '../../api/useApi'
import Attachments from '../../components/Attachments'
import RichText from '../../components/RichText'
import { useToast } from '../../components/Toast'
import { Badge, ErrorBox, Loading, Modal, formatDate } from '../../components/ui'

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

      {isTeacher ? <TeacherView a={a} onChange={reload} /> : <StudentView a={a} onChange={reload} />}
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

  async function submit(e) {
    e.preventDefault()
    const body = new FormData()
    if (content) body.append('content', content)
    if (file) body.append('file', file)
    try {
      const res = await api.post(`/assignments/${a.id}/submit`, body)
      toast.success(res.data.message)
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
          <p className="text-2xl font-semibold text-emerald-600">{Number(mine.effective_marks)}/{Number(a.max_marks)}</p>
          {mine.late_penalty_percent > 0 && (
            <p className="text-sm text-amber-700">Marked {Number(mine.marks)}, minus {mine.late_penalty_percent}% for being late.</p>
          )}
          {mine.feedback && <p className="mt-2 text-sm text-slate-600">Feedback: {mine.feedback}</p>}
          {a.criteria?.length > 0 && mine.rubric_scores && <RubricTable criteria={a.criteria} selected={mine.rubric_scores} />}
        </div>
      ) : closed ? (
        <p className="text-sm text-rose-600">The due date has passed and late work is not accepted.</p>
      ) : (
        <form onSubmit={submit}>
          <ErrorBox message={error} />
          {mine && <p className="mb-2 text-sm text-slate-500">Turned in {formatDate(mine.submitted_at, true)}{mine.is_late && ' (late)'}. You can resubmit until it's graded.</p>}
          <textarea className="input min-h-28" placeholder="Write your answer or add a note" value={content} onChange={(e) => setContent(e.target.value)} />
          <input type="file" className="mt-3 text-sm" onChange={(e) => setFile(e.target.files[0])} />
          <button className="btn-primary mt-3 w-full">{mine ? 'Resubmit' : 'Turn in'}</button>
        </form>
      )}
    </div>
  )
}

function TeacherView({ a, onChange }) {
  const toast = useToast()
  const byStudent = Object.fromEntries((a.submissions || []).map((s) => [s.student_id, s]))
  const [marks, setMarks] = useState({})
  const [rubricFor, setRubricFor] = useState(null)
  const hasRubric = a.criteria?.length > 0

  async function grade(submission, body) {
    try {
      await api.patch(`/submissions/${submission.id}/grade`, body)
      toast.success('Grade saved.')
      onChange()
    } catch (err) {
      toast.error(errorMessage(err))
    }
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
                <td>{s?.submitted_at ? <Badge tone={s.is_late ? 'amber' : 'emerald'}>{s.is_late ? 'Late' : 'Turned in'}</Badge> : <Badge>Missing</Badge>}</td>
                <td className="max-w-xs truncate text-slate-600">{s?.content}</td>
                <td>
                  {s && hasRubric ? (
                    <span>{s.marks != null ? `${Number(s.effective_marks)}/${Number(a.max_marks)}` : '—'}</span>
                  ) : s ? (
                    <input type="number" className="input w-24" max={a.max_marks} defaultValue={s.marks ?? ''} onChange={(e) => setMarks({ ...marks, [s.id]: e.target.value })} />
                  ) : null}
                  {s?.late_penalty_percent > 0 && <span className="block text-xs text-amber-700">−{s.late_penalty_percent}% late</span>}
                </td>
                <td>
                  {s && (hasRubric ? (
                    <button className="btn-ghost py-1" onClick={() => setRubricFor(s)}>Grade with rubric</button>
                  ) : (
                    <button className="btn-ghost py-1" onClick={() => grade(s, { marks: marks[s.id] ?? s.marks })}>Save</button>
                  ))}
                </td>
              </tr>
            )
          })}
        </tbody>
      </table>
      {rubricFor && <RubricGrader a={a} submission={rubricFor} onClose={() => setRubricFor(null)} onSave={(body) => grade(rubricFor, body).then(() => setRubricFor(null))} />}
    </div>
  )
}

/** Click one level per criterion; the total fills in. */
function RubricGrader({ a, submission, onClose, onSave }) {
  const [picked, setPicked] = useState(submission.rubric_scores || {})
  const [feedback, setFeedback] = useState(submission.feedback || '')
  const total = a.criteria.reduce((t, c) => t + Number(c.levels.find((l) => l.id === Number(picked[c.id]))?.points || 0), 0)
  const complete = a.criteria.every((c) => picked[c.id])

  return (
    <Modal open title="Grade with rubric" onClose={onClose}>
      {submission.content && <p className="mb-3 max-h-32 overflow-y-auto rounded-lg bg-slate-50 p-3 text-sm">{submission.content}</p>}
      <RubricTable criteria={a.criteria} selected={picked} onPick={(cid, lid) => setPicked({ ...picked, [cid]: lid })} />
      <textarea className="input mt-3" placeholder="Feedback for the student (optional)" value={feedback} onChange={(e) => setFeedback(e.target.value)} />
      <div className="mt-3 flex items-center justify-between">
        <p className="text-lg font-semibold">{total} / {Number(a.max_marks)}</p>
        <button className="btn-primary" disabled={!complete} onClick={() => onSave({ rubric_scores: picked, feedback })}>Save grade</button>
      </div>
    </Modal>
  )
}
