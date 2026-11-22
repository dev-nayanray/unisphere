import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api, errorMessage } from '../../api/client'
import { useApi } from '../../api/useApi'
import ConfirmButton from '../../components/ConfirmButton'
import { useToast } from '../../components/Toast'
import { Badge, ErrorBox, Loading, Modal } from '../../components/ui'
import { ReviewList } from './QuizTake'

const flags = (i) => Object.entries(i || {}).filter(([, n]) => n > 0)

/** Teacher view of a quiz: every attempt, integrity flags, per-question stats, marking. */
export default function QuizAttempts() {
  const { quizId } = useParams()
  const toast = useToast()
  const quiz = useApi(`/quizzes/${quizId}`)
  const list = useApi(`/quizzes/${quizId}/attempts`)
  const [open, setOpen] = useState(null)

  if (quiz.loading || list.loading) return <Loading />
  if (quiz.error || list.error) return <ErrorBox message={quiz.error || list.error} />

  const { attempts, stats } = list.data
  const scored = attempts.filter((a) => a.score != null)
  const avg = scored.length ? scored.reduce((t, a) => t + Number(a.score), 0) / scored.length : null

  async function release() {
    try {
      await api.post(`/quizzes/${quizId}/release-results`)
      toast.success('Results released to students.')
      quiz.reload()
    } catch (err) {
      toast.error(errorMessage(err))
    }
  }

  async function reset(a) {
    try {
      const res = await api.delete(`/quiz-attempts/${a.id}`)
      toast.success(res.data.message)
      list.reload()
    } catch (err) {
      toast.error(errorMessage(err))
    }
  }

  return (
    <div className="mx-auto max-w-5xl">
      <div className="mb-4 flex flex-wrap items-center gap-2">
        <div className="flex-1">
          <Link to={`/quizzes/${quizId}/edit`} className="text-sm text-indigo-600">← Edit quiz</Link>
          <h1 className="text-2xl font-semibold">{quiz.data.title}: results</h1>
        </div>
        {quiz.data.show_results === 'manual' && !quiz.data.results_released_at && <button className="btn-primary" onClick={release}>Release results</button>}
      </div>

      <div className="mb-4 grid gap-3 sm:grid-cols-3">
        <div className="card"><p className="text-xs text-slate-500">Submitted</p><p className="text-2xl font-semibold">{attempts.filter((a) => a.submitted_at).length}</p></div>
        <div className="card"><p className="text-xs text-slate-500">Average</p><p className="text-2xl font-semibold">{avg == null ? '—' : avg.toFixed(1)}</p></div>
        <div className="card"><p className="text-xs text-slate-500">Need marking</p><p className="text-2xl font-semibold">{attempts.filter((a) => a.needs_grading).length}</p></div>
      </div>

      <section className="card mb-4 overflow-x-auto p-0">
        <table className="w-full text-sm">
          <thead className="bg-slate-50 text-left text-xs text-slate-500">
            <tr><th className="px-4 py-2">Student</th><th>Status</th><th>Score</th><th>Integrity</th><th /></tr>
          </thead>
          <tbody>
            {attempts.map((a) => (
              <tr key={a.id} className="border-t border-slate-100">
                <td className="px-4 py-2 font-medium">{a.student?.name}</td>
                <td>
                  {!a.submitted_at ? <Badge tone="amber">In progress</Badge> : a.needs_grading ? <Badge tone="indigo">Needs marking</Badge> : <Badge tone="emerald">Done</Badge>}
                  {a.auto_submitted && <span className="ml-1 text-xs text-slate-400">auto</span>}
                </td>
                <td>{a.score == null ? '—' : `${Number(a.score)} / ${Number(a.max_score)}`}</td>
                <td className="text-xs">
                  {flags(a.integrity).length ? flags(a.integrity).map(([k, n]) => <Badge key={k} tone="rose">{k.replace('_', ' ')} ×{n}</Badge>) : <span className="text-slate-400">none</span>}
                </td>
                <td className="space-x-2 whitespace-nowrap pr-4 text-right">
                  {a.submitted_at && <button className="text-indigo-600" onClick={() => setOpen(a.id)}>Review</button>}
                  <ConfirmButton className="text-rose-600" title="Allow a retake?" confirmLabel="Reset attempt" message={`Let ${a.student?.name} take the quiz again? Their attempt is deleted.`} onConfirm={() => reset(a)}>Retake</ConfirmButton>
                </td>
              </tr>
            ))}
            {!attempts.length && <tr><td colSpan="5" className="px-4 py-6 text-center text-slate-500">No one has started this quiz yet.</td></tr>}
          </tbody>
        </table>
      </section>

      <section className="card">
        <h2 className="mb-2 font-semibold">Question statistics</h2>
        <ul className="space-y-2 text-sm">
          {stats.map((s, i) => (
            <li key={s.question_id} className="flex items-center gap-3">
              <span className="w-8 text-slate-400">Q{i + 1}</span>
              <span className="flex-1 truncate">{s.question}</span>
              {s.percent_correct == null ? (
                <span className="text-xs text-slate-500">{s.pending ? `${s.pending} to mark` : 'written'}</span>
              ) : (
                <span className="flex w-48 items-center gap-2">
                  <span className="h-2 flex-1 rounded bg-slate-100"><span className={`block h-2 rounded ${s.percent_correct < 40 ? 'bg-rose-500' : 'bg-emerald-500'}`} style={{ width: `${s.percent_correct}%` }} /></span>
                  <span className="w-12 text-right text-xs">{s.percent_correct}%</span>
                </span>
              )}
            </li>
          ))}
        </ul>
      </section>

      {open && <AttemptReview id={open} onClose={() => setOpen(null)} onSaved={list.reload} />}
    </div>
  )
}

function AttemptReview({ id, onClose, onSaved }) {
  const toast = useToast()
  const { data, loading, reload } = useApi(`/quiz-attempts/${id}`)
  const [scores, setScores] = useState({})

  async function save() {
    try {
      await api.patch(`/quiz-attempts/${id}/answers`, { scores })
      toast.success('Marks saved.')
      setScores({})
      reload()
      onSaved()
    } catch (err) {
      toast.error(errorMessage(err))
    }
  }

  return (
    <Modal open title={data ? `${data.student?.name}: ${data.score == null ? 'pending' : `${Number(data.score)} / ${Number(data.max_score)}`}` : 'Attempt'} onClose={onClose}>
      {loading ? (
        <Loading />
      ) : (
        <div className="max-h-[70vh] overflow-y-auto">
          {flags(data.integrity).length > 0 && (
            <p className="mb-3 rounded bg-rose-50 px-3 py-2 text-xs text-rose-700">
              Recorded: {flags(data.integrity).filter(([k]) => k !== 'events').map(([k, n]) => `${k.replace('_', ' ')} ×${n}`).join(', ')}
            </p>
          )}
          <ReviewList
            questions={data.questions}
            renderScore={(q) => (
              <label className="flex items-center gap-1 text-xs">
                <input
                  type="number" min="0" max={Number(q.marks)} step="0.25" className="input w-20 py-1"
                  value={scores[q.id] ?? q.result?.score ?? ''}
                  placeholder="—"
                  onChange={(e) => setScores({ ...scores, [q.id]: e.target.value })}
                />
                / {Number(q.marks)}
              </label>
            )}
          />
          <div className="sticky bottom-0 mt-3 flex justify-end bg-white pt-2">
            <button className="btn-primary" disabled={!Object.keys(scores).length} onClick={save}>Save marks</button>
          </div>
        </div>
      )}
    </Modal>
  )
}
