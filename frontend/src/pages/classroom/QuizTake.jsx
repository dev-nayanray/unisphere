import { useCallback, useEffect, useRef, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api, errorMessage } from '../../api/client'
import { useApi } from '../../api/useApi'
import ConfirmButton from '../../components/ConfirmButton'
import { useToast } from '../../components/Toast'
import { Badge, ErrorBox, Loading } from '../../components/ui'

const AUTOSAVE_MS = 10000

const clock = (s) => `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`

/** Student quiz page: intro → taking (server timer, autosave, integrity events) → results. */
export default function QuizTake() {
  const { quizId } = useParams()
  const quiz = useApi(`/quizzes/${quizId}`)
  const [stage, setStage] = useState(null) // intro | taking | done
  const [session, setSession] = useState(null)

  useEffect(() => {
    if (!quiz.data) return
    setStage(quiz.data.attempt?.submitted_at ? 'done' : 'intro')
  }, [quiz.data])

  if (quiz.loading || !stage) return <Loading />
  if (quiz.error) return <ErrorBox message={quiz.error} />

  const q = quiz.data
  return (
    <div className="mx-auto max-w-3xl">
      <Link to={`/classes/${q.course_section_id}`} className="text-sm text-indigo-600">← Back to class</Link>
      <h1 className="mb-4 text-2xl font-semibold">{q.title}</h1>
      {stage === 'intro' && <Intro quiz={q} onStarted={(s) => { setSession(s); setStage('taking') }} onDone={() => setStage('done')} />}
      {stage === 'taking' && <Taking quizId={quizId} session={session} onDone={() => setStage('done')} />}
      {stage === 'done' && <Result quizId={quizId} />}
    </div>
  )
}

function Intro({ quiz, onStarted, onDone }) {
  const toast = useToast()
  const [busy, setBusy] = useState(false)
  const resuming = Boolean(quiz.attempt)

  async function start() {
    setBusy(true)
    try {
      const res = await api.post(`/quizzes/${quiz.id}/start`)
      onStarted(res.data)
    } catch (err) {
      toast.error(errorMessage(err))
      if (err.response?.data?.attempt?.submitted_at) onDone()
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="card space-y-3">
      {quiz.instructions && <p className="whitespace-pre-line text-sm text-slate-700">{quiz.instructions}</p>}
      <ul className="space-y-1 text-sm text-slate-600">
        <li>⏱ {quiz.duration_minutes} minutes from when you start. The timer keeps running if you leave the page.</li>
        {quiz.ends_at && <li>🔒 Closes {new Date(quiz.ends_at).toLocaleString()}. Time ends then even if you started late.</li>}
        <li>💾 Answers save automatically every few seconds.</li>
        <li>👁 Leaving this tab or pasting text is recorded and shown to your teacher.</li>
      </ul>
      {!quiz.is_open && !resuming ? (
        <Badge tone="amber">This quiz is not open right now.</Badge>
      ) : (
        <button className="btn-primary" disabled={busy} onClick={start}>{resuming ? 'Resume quiz' : 'Start quiz'}</button>
      )}
    </div>
  )
}

function Taking({ quizId, session, onDone }) {
  const toast = useToast()
  const { questions, display_mode: mode } = session
  const [answers, setAnswers] = useState(session.attempt.answers || {})
  const [left, setLeft] = useState(session.attempt.seconds_left)
  const [page, setPage] = useState(0)
  const [saved, setSaved] = useState(null)
  const [submitting, setSubmitting] = useState(false)
  const answersRef = useRef(answers)
  const dirty = useRef(false)
  const submitted = useRef(false)
  // The deadline comes from the server; the local clock only counts down to it.
  const endsAt = useRef(Date.now() + session.attempt.seconds_left * 1000)

  const submit = useCallback(async (auto = false) => {
    if (submitted.current) return
    submitted.current = true
    setSubmitting(true)
    try {
      const res = await api.post(`/quizzes/${quizId}/submit`, { answers: answersRef.current })
      toast.success(auto ? 'Time is up. Your quiz was submitted.' : res.data.message)
      onDone()
    } catch (err) {
      submitted.current = false
      toast.error(errorMessage(err))
    } finally {
      setSubmitting(false)
    }
  }, [quizId, toast, onDone])

  const save = useCallback(async () => {
    if (!dirty.current || submitted.current) return
    dirty.current = false
    try {
      const res = await api.patch(`/quizzes/${quizId}/answers`, { answers: answersRef.current })
      setSaved(new Date(res.data.saved_at))
      endsAt.current = Date.now() + res.data.seconds_left * 1000 // resync with the server
    } catch {
      dirty.current = true // retry next tick
    }
  }, [quizId])

  // Countdown, auto-submit at zero.
  useEffect(() => {
    const t = setInterval(() => {
      const s = Math.max(0, Math.round((endsAt.current - Date.now()) / 1000))
      setLeft(s)
      if (s === 0) submit(true)
    }, 1000)
    return () => clearInterval(t)
  }, [submit])

  // Autosave on a timer and when the page is hidden.
  useEffect(() => {
    const t = setInterval(save, AUTOSAVE_MS)
    return () => clearInterval(t)
  }, [save])

  // Integrity events.
  useEffect(() => {
    const log = (type) => api.post(`/quizzes/${quizId}/events`, { type }).catch(() => {})
    const onVisibility = () => { if (document.hidden) { log('blur'); save() } }
    const onCopy = () => log('copy')
    const onPaste = () => log('paste')
    document.addEventListener('visibilitychange', onVisibility)
    document.addEventListener('copy', onCopy)
    document.addEventListener('paste', onPaste)
    return () => {
      document.removeEventListener('visibilitychange', onVisibility)
      document.removeEventListener('copy', onCopy)
      document.removeEventListener('paste', onPaste)
    }
  }, [quizId, save])

  const answer = (id, value) => {
    const next = { ...answersRef.current, [id]: value }
    answersRef.current = next
    dirty.current = true
    setAnswers(next)
  }

  const answeredCount = questions.filter((q) => {
    const a = answers[q.id]
    return a !== undefined && a !== null && a !== '' && !(Array.isArray(a) && a.length === 0)
  }).length
  const shown = mode === 'one' ? [questions[page]] : questions

  return (
    <div>
      <div className="sticky top-0 z-10 mb-4 flex items-center gap-3 rounded-lg border border-slate-200 bg-white/95 px-4 py-2 backdrop-blur">
        <span className={`font-mono text-lg font-semibold ${left < 60 ? 'text-rose-600' : ''}`}>{clock(left)}</span>
        <span className="text-sm text-slate-500">{answeredCount}/{questions.length} answered</span>
        <span className="ml-auto text-xs text-slate-400">{saved ? `Saved ${saved.toLocaleTimeString()}` : 'Not saved yet'}</span>
        <ConfirmButton
          className="btn-primary py-1.5"
          title="Submit quiz?"
          confirmLabel={submitting ? 'Submitting…' : 'Submit'}
          message={`You answered ${answeredCount} of ${questions.length} questions. You cannot change your answers afterwards.`}
          onConfirm={() => submit()}
        >
          Submit
        </ConfirmButton>
      </div>

      {mode === 'one' && (
        <div className="mb-3 flex flex-wrap gap-1">
          {questions.map((q, i) => (
            <button key={q.id} onClick={() => setPage(i)} className={`h-8 w-8 rounded text-xs ${i === page ? 'bg-indigo-600 text-white' : answers[q.id] !== undefined ? 'bg-indigo-100' : 'bg-slate-100'}`}>{i + 1}</button>
          ))}
        </div>
      )}

      <ol className="space-y-3">
        {shown.map((q) => (
          <li key={q.id} className="card">
            <p className="mb-2 font-medium">{questions.indexOf(q) + 1}. {q.question} <span className="text-xs font-normal text-slate-400">({Number(q.marks)} pts)</span></p>
            <AnswerInput q={q} value={answers[q.id]} onChange={(v) => answer(q.id, v)} />
          </li>
        ))}
      </ol>

      {mode === 'one' && (
        <div className="mt-3 flex justify-between">
          <button className="btn-ghost" disabled={page === 0} onClick={() => setPage(page - 1)}>← Previous</button>
          <button className="btn-ghost" disabled={page === questions.length - 1} onClick={() => setPage(page + 1)}>Next →</button>
        </div>
      )}
    </div>
  )
}

function AnswerInput({ q, value, onChange }) {
  if (q.type === 'single' || q.type === 'true_false') {
    return (
      <div className="space-y-1">
        {q.options.map((o) => (
          <label key={o.index} className="flex items-center gap-2 rounded px-2 py-1 hover:bg-slate-50">
            <input type="radio" name={`q${q.id}`} checked={value === o.index} onChange={() => onChange(o.index)} /> {o.text}
          </label>
        ))}
      </div>
    )
  }
  if (q.type === 'multiple') {
    const set = Array.isArray(value) ? value : []
    return (
      <div className="space-y-1">
        {q.options.map((o) => (
          <label key={o.index} className="flex items-center gap-2 rounded px-2 py-1 hover:bg-slate-50">
            <input type="checkbox" checked={set.includes(o.index)} onChange={() => onChange(set.includes(o.index) ? set.filter((x) => x !== o.index) : [...set, o.index])} /> {o.text}
          </label>
        ))}
        <p className="text-xs text-slate-400">Select every correct answer.</p>
      </div>
    )
  }
  if (q.type === 'numeric') {
    return <input type="number" step="any" className="input w-48" value={value ?? ''} onChange={(e) => onChange(e.target.value)} />
  }
  return <textarea className="input" rows={4} value={value ?? ''} onChange={(e) => onChange(e.target.value)} />
}

/** Results page: score, each answer against the key, explanations. */
export function Result({ quizId }) {
  const { data, loading, error } = useApi(`/quizzes/${quizId}/result`)
  if (loading) return <Loading />
  if (error) return <ErrorBox message={error} />

  if (!data.results_visible) {
    return (
      <div className="card text-sm text-slate-600">
        ✅ Submitted {new Date(data.submitted_at).toLocaleString()}.{' '}
        {data.show_results === 'after_close' ? 'Results appear when the quiz closes.' : 'Your teacher will release the results.'}
      </div>
    )
  }

  return (
    <div className="space-y-3">
      <div className="card flex items-center gap-3">
        <span className="text-3xl font-semibold">{data.score == null ? '—' : Number(data.score)}</span>
        <span className="text-slate-500">/ {Number(data.max_score)}</span>
        {data.score == null && <Badge tone="amber">Written answers are being graded</Badge>}
      </div>
      <ReviewList questions={data.questions} />
    </div>
  )
}

export function ReviewList({ questions, renderScore }) {
  return (
    <ol className="space-y-3">
      {questions.map((q, i) => {
        const r = q.result
        const tone = r?.score == null ? 'amber' : r.correct ? 'emerald' : 'rose'
        return (
          <li key={q.id} className="card text-sm">
            <div className="mb-2 flex items-start gap-2">
              <p className="flex-1 font-medium">{i + 1}. {q.question}</p>
              {renderScore ? renderScore(q) : <Badge tone={tone}>{r?.score == null ? 'Pending' : `${Number(r.score)} / ${Number(q.marks)}`}</Badge>}
            </div>
            {q.options?.length > 0 ? (
              <ul className="space-y-1">
                {q.options.map((o) => {
                  const chosen = Array.isArray(q.answer) ? q.answer.includes(o.index) : q.answer === o.index
                  const right = q.type === 'multiple' ? (q.correct_options || []).includes(o.index) : q.correct_option === o.index
                  return (
                    <li key={o.index} className={`rounded px-2 py-1 ${right ? 'bg-emerald-50 text-emerald-800' : chosen ? 'bg-rose-50 text-rose-800' : ''}`}>
                      {chosen ? '●' : '○'} {o.text} {right && '✓'}
                    </li>
                  )
                })}
              </ul>
            ) : (
              <div className="space-y-1">
                <p className="whitespace-pre-line rounded bg-slate-50 px-2 py-1">{q.answer ?? <em className="text-slate-400">No answer</em>}</p>
                {q.type === 'numeric' && <p className="text-xs text-slate-500">Correct: {Number(q.correct_numeric)}{Number(q.tolerance) ? ` ± ${Number(q.tolerance)}` : ''}</p>}
              </div>
            )}
            {q.explanation && <p className="mt-2 text-xs text-slate-500">💡 {q.explanation}</p>}
          </li>
        )
      })}
    </ol>
  )
}
