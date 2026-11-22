import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api, downloadFile, errorMessage } from '../../api/client'
import { useApi } from '../../api/useApi'
import { useToast } from '../../components/Toast'
import { Badge, ErrorBox, Loading, Modal } from '../../components/ui'

const TYPES = {
  single: 'Multiple choice (one answer)',
  multiple: 'Checkboxes (several answers)',
  true_false: 'True / False',
  numeric: 'Number',
  short: 'Short answer (graded by you)',
}

const blank = (type = 'single') => ({
  key: Math.random().toString(36).slice(2),
  type,
  question: '',
  options: type === 'single' || type === 'multiple' ? ['', ''] : [],
  correct_option: type === 'true_false' ? 0 : null,
  correct_options: [],
  correct_numeric: '',
  tolerance: 0,
  marks: 1,
  explanation: '',
})

const toLocal = (iso) => (iso ? new Date(iso).toLocaleString('sv-SE').slice(0, 16).replace(' ', 'T') : '')

/** Create or edit a quiz: settings, questions of five types, bank, CSV import and preview. */
export default function QuizBuilder() {
  const { sectionId, quizId } = useParams()
  const navigate = useNavigate()
  const toast = useToast()
  const editing = Boolean(quizId)
  const existing = useApi(editing ? `/quizzes/${quizId}` : null)
  const classId = sectionId || existing.data?.course_section_id
  const cls = useApi(classId ? `/classes/${classId}` : null)
  const topics = useApi(classId ? `/classes/${classId}/topics` : null)

  const [settings, setSettings] = useState({
    title: '', instructions: '', category: 'quiz', topic_id: '', duration_minutes: 20, starts_at: '', ends_at: '',
    shuffle_questions: true, shuffle_options: false, show_results: 'immediately', draw_count: '', display_mode: 'all',
  })
  const [questions, setQuestions] = useState([blank()])
  const [errors, setErrors] = useState({})
  const [busy, setBusy] = useState(false)
  const [modal, setModal] = useState(null) // 'bank' | 'preview'
  const [locked, setLocked] = useState(false)

  // Load an existing quiz into the editor.
  useEffect(() => {
    const q = existing.data
    if (!q) return
    setSettings({
      title: q.title, instructions: q.instructions || '', category: q.category, topic_id: q.topic_id || '',
      duration_minutes: q.duration_minutes, starts_at: toLocal(q.starts_at), ends_at: toLocal(q.ends_at),
      shuffle_questions: q.shuffle_questions, shuffle_options: q.shuffle_options, show_results: q.show_results, draw_count: q.draw_count || '', display_mode: q.display_mode || 'all',
    })
    setQuestions(q.questions.map((x) => ({ ...blank(x.type), ...x, key: String(x.id), options: x.options || [], correct_options: x.correct_options || [], explanation: x.explanation || '' })))
    setLocked(q.submitted_count > 0)
  }, [existing.data])

  const set = (k) => (e) => setSettings({ ...settings, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value })
  const update = (i, patch) => setQuestions(questions.map((q, j) => (j === i ? { ...q, ...patch } : q)))
  const move = (i, step) => {
    const next = [...questions]
    const [q] = next.splice(i, 1)
    next.splice(Math.max(0, Math.min(next.length, i + step)), 0, q)
    setQuestions(next)
  }
  const total = questions.reduce((t, q) => t + Number(q.marks || 0), 0)
  const errFor = (prefix) => Object.entries(errors).filter(([k]) => k === prefix || k.startsWith(`${prefix}.`)).flatMap(([, v]) => v).join(' ')

  const payloadQuestions = () => questions.map((q) => ({
    type: q.type,
    question: q.question,
    options: ['single', 'multiple'].includes(q.type) ? q.options : undefined,
    correct_option: ['single', 'true_false'].includes(q.type) ? q.correct_option : undefined,
    correct_options: q.type === 'multiple' ? q.correct_options : undefined,
    correct_numeric: q.type === 'numeric' ? q.correct_numeric : undefined,
    tolerance: q.type === 'numeric' ? q.tolerance || 0 : undefined,
    marks: q.marks,
    explanation: q.explanation || null,
  }))

  async function save(publish) {
    setBusy(true)
    setErrors({})
    const body = {
      ...settings,
      topic_id: settings.topic_id || null,
      starts_at: settings.starts_at || null,
      ends_at: settings.ends_at || null,
      draw_count: settings.draw_count || null,
      publish,
      ...(locked ? {} : { questions: payloadQuestions() }),
    }
    try {
      const res = editing ? await api.patch(`/quizzes/${quizId}`, body) : await api.post(`/classes/${classId}/quizzes`, body)
      toast.success(publish ? 'Quiz published.' : 'Quiz saved as a draft.')
      if (!editing) navigate(`/quizzes/${res.data.id}/edit`, { replace: true })
      else existing.reload()
    } catch (err) {
      setErrors(err.response?.data?.errors || {})
      toast.error(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  async function importCsv(file) {
    const body = new FormData()
    body.append('file', file)
    try {
      const res = await api.post('/quiz-questions/parse-csv', body)
      setQuestions([...questions.filter((q) => q.question.trim()), ...res.data.questions.map((q) => ({ ...blank(q.type), ...q, key: Math.random().toString(36).slice(2) }))])
      toast.success(`${res.data.questions.length} questions imported. Review them, then save.`)
    } catch (err) {
      toast.error(errorMessage(err))
    }
  }

  async function saveToBank() {
    try {
      const res = await api.post(`/courses/${cls.data.course_id}/question-bank`, { questions: payloadQuestions(), tags: settings.title })
      toast.success(res.data.message)
    } catch (err) {
      toast.error(errorMessage(err))
    }
  }

  if ((editing && existing.loading) || cls.loading) return <Loading />
  if (existing.error) return <ErrorBox message={existing.error} />

  return (
    <div className="mx-auto max-w-4xl">
      <div className="mb-4 flex flex-wrap items-center gap-2">
        <div className="flex-1">
          <Link to={`/classes/${classId}`} className="text-sm text-indigo-600">← {cls.data?.course?.code} {cls.data?.course?.title}</Link>
          <h1 className="text-2xl font-semibold">{editing ? 'Edit quiz' : 'New quiz'}</h1>
        </div>
        {editing && <Link className="btn-ghost" to={`/quizzes/${quizId}/results`}>Results ({existing.data?.submitted_count ?? 0})</Link>}
        {editing && <button className="btn-ghost" onClick={() => setModal('preview')}>Preview as student</button>}
        <button className="btn-ghost" disabled={busy} onClick={() => save(false)}>Save draft</button>
        <button className="btn-primary" disabled={busy} onClick={() => save(true)}>{existing.data?.published_at ? 'Save' : 'Publish'}</button>
      </div>

      {locked && (
        <div className="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
          Students have already submitted this quiz, so its questions are locked. You can still change the settings.
        </div>
      )}

      <section className="card mb-4 grid gap-3 sm:grid-cols-2">
        <label className="sm:col-span-2">
          <span className="label">Title</span>
          <input className="input" value={settings.title} onChange={set('title')} required />
          <Err text={errFor('title')} />
        </label>
        <label className="sm:col-span-2">
          <span className="label">Instructions</span>
          <textarea className="input" value={settings.instructions} onChange={set('instructions')} />
        </label>
        <label>
          <span className="label">Counts as</span>
          <select className="input" value={settings.category} onChange={set('category')}>
            <option value="quiz">Class test / quiz</option><option value="mid">Midterm</option><option value="final">Final</option>
          </select>
        </label>
        <label>
          <span className="label">Topic</span>
          <select className="input" value={settings.topic_id} onChange={set('topic_id')}>
            <option value="">No topic</option>
            {topics.data?.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
          </select>
        </label>
        <label>
          <span className="label">Time limit (minutes)</span>
          <input type="number" min="1" className="input" value={settings.duration_minutes} onChange={set('duration_minutes')} />
        </label>
        <label>
          <span className="label">Show results</span>
          <select className="input" value={settings.show_results} onChange={set('show_results')}>
            <option value="immediately">Right after submitting</option>
            <option value="after_close">After the quiz closes</option>
            <option value="manual">When I release them</option>
          </select>
        </label>
        <label>
          <span className="label">Opens</span>
          <input type="datetime-local" className="input" value={settings.starts_at} onChange={set('starts_at')} />
        </label>
        <label>
          <span className="label">Closes</span>
          <input type="datetime-local" className="input" value={settings.ends_at} onChange={set('ends_at')} />
          <Err text={errFor('ends_at')} />
        </label>
        <label>
          <span className="label">Questions per student (blank = all {questions.length})</span>
          <input type="number" min="1" className="input" value={settings.draw_count} onChange={set('draw_count')} placeholder="e.g. 10 random" />
          <Err text={errFor('draw_count')} />
        </label>
        <label>
          <span className="label">Layout</span>
          <select className="input" value={settings.display_mode} onChange={set('display_mode')}>
            <option value="all">All questions on one page</option>
            <option value="one">One question at a time</option>
          </select>
        </label>
        <div className="flex flex-col justify-end gap-1 text-sm">
          <label className="flex items-center gap-2"><input type="checkbox" checked={settings.shuffle_questions} onChange={set('shuffle_questions')} /> Shuffle question order</label>
          <label className="flex items-center gap-2"><input type="checkbox" checked={settings.shuffle_options} onChange={set('shuffle_options')} /> Shuffle answer options</label>
        </div>
      </section>

      <div className="mb-3 flex flex-wrap items-center gap-2">
        <h2 className="flex-1 font-semibold">Questions <span className="text-sm font-normal text-slate-500">· {questions.length} · {total} points</span></h2>
        {!locked && (
          <>
            <button className="btn-ghost py-1.5" onClick={() => downloadFile('/quiz-questions/template')}>CSV template</button>
            <label className="btn-ghost cursor-pointer py-1.5">
              Import CSV
              <input type="file" accept=".csv" className="hidden" onChange={(e) => e.target.files[0] && importCsv(e.target.files[0])} />
            </label>
            <button className="btn-ghost py-1.5" onClick={() => setModal('bank')}>Add from bank</button>
          </>
        )}
        <button className="btn-ghost py-1.5" onClick={saveToBank}>Save all to bank</button>
      </div>
      <Err text={errFor('questions') && !Object.keys(errors).some((k) => k.startsWith('questions.')) ? errFor('questions') : ''} />

      <ol className="space-y-3">
        {questions.map((q, i) => (
          <QuestionEditor
            key={q.key}
            q={q}
            index={i}
            count={questions.length}
            locked={locked}
            error={errFor(`questions.${i}`)}
            onChange={(patch) => update(i, patch)}
            onMove={(step) => move(i, step)}
            onDuplicate={() => setQuestions([...questions.slice(0, i + 1), { ...q, key: Math.random().toString(36).slice(2), id: undefined }, ...questions.slice(i + 1)])}
            onDelete={() => setQuestions(questions.filter((_, j) => j !== i))}
          />
        ))}
      </ol>

      {!locked && (
        <div className="mt-3 flex flex-wrap gap-2">
          {Object.entries(TYPES).map(([type, label]) => (
            <button key={type} className="btn-ghost py-1.5 text-xs" onClick={() => setQuestions([...questions, blank(type)])}>+ {label}</button>
          ))}
        </div>
      )}

      {modal === 'bank' && cls.data && (
        <BankPicker
          courseId={cls.data.course_id}
          onClose={() => setModal(null)}
          onPick={(picked) => {
            setQuestions([...questions.filter((q) => q.question.trim()), ...picked.map((q) => ({ ...blank(q.type), ...q, key: Math.random().toString(36).slice(2), options: q.options || [], correct_options: q.correct_options || [] }))])
            setModal(null)
          }}
        />
      )}
      {modal === 'preview' && <Preview quizId={quizId} onClose={() => setModal(null)} />}
    </div>
  )
}

function Err({ text }) {
  return text ? <span className="mt-1 block text-xs text-rose-600">{text}</span> : null
}

function QuestionEditor({ q, index, count, locked, error, onChange, onMove, onDuplicate, onDelete }) {
  const hasOptions = q.type === 'single' || q.type === 'multiple'
  const setOption = (i, text) => onChange({ options: q.options.map((o, j) => (j === i ? text : o)) })
  const removeOption = (i) => onChange({
    options: q.options.filter((_, j) => j !== i),
    correct_option: q.correct_option === i ? null : q.correct_option > i ? q.correct_option - 1 : q.correct_option,
    correct_options: q.correct_options.filter((c) => c !== i).map((c) => (c > i ? c - 1 : c)),
  })
  const toggleCorrect = (i) => onChange({ correct_options: q.correct_options.includes(i) ? q.correct_options.filter((c) => c !== i) : [...q.correct_options, i] })

  return (
    <li className={`card ${error ? 'border-rose-300' : ''}`}>
      <fieldset disabled={locked}>
        <div className="mb-2 flex flex-wrap items-center gap-2">
          <span className="text-sm font-semibold text-slate-500">Q{index + 1}</span>
          <select
            className="input w-auto py-1 text-sm"
            value={q.type}
            onChange={(e) => onChange({ ...blank(e.target.value), key: q.key, question: q.question, marks: q.marks })}
          >
            {Object.entries(TYPES).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
          <label className="ml-auto flex items-center gap-1 text-sm">
            <input type="number" min="0.25" step="0.25" className="input w-20 py-1" value={q.marks} onChange={(e) => onChange({ marks: e.target.value })} /> pts
          </label>
          <span className="flex gap-1 text-slate-400">
            <button type="button" disabled={index === 0} onClick={() => onMove(-1)} title="Move up">↑</button>
            <button type="button" disabled={index === count - 1} onClick={() => onMove(1)} title="Move down">↓</button>
            <button type="button" onClick={onDuplicate} title="Duplicate">⧉</button>
            <button type="button" className="hover:text-rose-600" onClick={onDelete} title="Delete">✕</button>
          </span>
        </div>

        <textarea className="input" placeholder="Question" value={q.question} onChange={(e) => onChange({ question: e.target.value })} />

        {hasOptions && (
          <div className="mt-3 space-y-2">
            {q.options.map((o, i) => (
              <div key={i} className="flex items-center gap-2">
                {q.type === 'single' ? (
                  <input type="radio" name={`q-${q.key}`} checked={q.correct_option === i} onChange={() => onChange({ correct_option: i })} title="Correct answer" />
                ) : (
                  <input type="checkbox" checked={q.correct_options.includes(i)} onChange={() => toggleCorrect(i)} title="Correct answer" />
                )}
                <input className="input py-1.5" placeholder={`Option ${i + 1}`} value={o} onChange={(e) => setOption(i, e.target.value)} />
                {q.options.length > 2 && <button type="button" className="text-slate-400 hover:text-rose-600" onClick={() => removeOption(i)}>✕</button>}
              </div>
            ))}
            {q.options.length < 8 && <button type="button" className="text-sm text-indigo-600" onClick={() => onChange({ options: [...q.options, ''] })}>+ Add option</button>}
            <p className="text-xs text-slate-400">{q.type === 'single' ? 'Select the correct answer.' : 'Tick every correct answer (students must match them all).'}</p>
          </div>
        )}

        {q.type === 'true_false' && (
          <div className="mt-3 flex gap-4 text-sm">
            {['True', 'False'].map((label, i) => (
              <label key={label} className="flex items-center gap-2">
                <input type="radio" name={`q-${q.key}`} checked={Number(q.correct_option) === i} onChange={() => onChange({ correct_option: i })} /> {label}
              </label>
            ))}
          </div>
        )}

        {q.type === 'numeric' && (
          <div className="mt-3 flex flex-wrap gap-3 text-sm">
            <label className="flex items-center gap-2">Answer <input type="number" step="any" className="input w-32 py-1" value={q.correct_numeric} onChange={(e) => onChange({ correct_numeric: e.target.value })} /></label>
            <label className="flex items-center gap-2">± <input type="number" step="any" min="0" className="input w-24 py-1" value={q.tolerance} onChange={(e) => onChange({ tolerance: e.target.value })} /></label>
          </div>
        )}

        {q.type === 'short' && <p className="mt-2 text-xs text-slate-500">Students type an answer; you grade it after they submit.</p>}

        <input className="input mt-3 py-1.5 text-sm" placeholder="Explanation shown with the results (optional)" value={q.explanation || ''} onChange={(e) => onChange({ explanation: e.target.value })} />
        <Err text={error} />
      </fieldset>
    </li>
  )
}

function BankPicker({ courseId, onClose, onPick }) {
  const toast = useToast()
  const [type, setType] = useState('')
  const { data, loading } = useApi(`/courses/${courseId}/question-bank`, { type, per_page: 100 })
  const [selected, setSelected] = useState([])
  const [random, setRandom] = useState(5)

  async function pick(body) {
    try {
      const res = await api.post(`/courses/${courseId}/question-bank/pick`, { ...body, type: type || null })
      onPick(res.data)
    } catch (err) {
      toast.error(errorMessage(err))
    }
  }

  return (
    <Modal open title="Question bank" onClose={onClose}>
      <div className="mb-3 flex gap-2">
        <select className="input" value={type} onChange={(e) => setType(e.target.value)}>
          <option value="">All types</option>
          {Object.entries(TYPES).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
        </select>
      </div>
      {loading ? (
        <Loading />
      ) : data?.data?.length ? (
        <ul className="max-h-72 space-y-1 overflow-y-auto text-sm">
          {data.data.map((item) => (
            <li key={item.id} className="flex items-start gap-2 rounded-lg px-2 py-1.5 hover:bg-slate-50">
              <input type="checkbox" className="mt-1" checked={selected.includes(item.id)} onChange={() => setSelected((s) => (s.includes(item.id) ? s.filter((x) => x !== item.id) : [...s, item.id]))} />
              <span className="flex-1">{item.question}</span>
              <Badge>{item.type.replace('_', '/')}</Badge>
            </li>
          ))}
        </ul>
      ) : (
        <p className="text-sm text-slate-500">The bank is empty. Use "Save all to bank" in a quiz to fill it.</p>
      )}
      <div className="mt-4 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
        <button className="btn-primary" disabled={!selected.length} onClick={() => pick({ ids: selected })}>Add {selected.length || ''} selected</button>
        <span className="text-sm text-slate-500">or</span>
        <input type="number" min="1" className="input w-20" value={random} onChange={(e) => setRandom(e.target.value)} />
        <button className="btn-ghost" onClick={() => pick({ random: Number(random) })}>Add random</button>
      </div>
    </Modal>
  )
}

function Preview({ quizId, onClose }) {
  const { data, loading } = useApi(`/quizzes/${quizId}/preview`)

  return (
    <Modal open title={`Preview: ${data?.title || ''}`} onClose={onClose}>
      {loading ? (
        <Loading />
      ) : (
        <div className="max-h-[70vh] space-y-4 overflow-y-auto">
          <p className="text-xs text-slate-500">This is what a student sees ({data.duration_minutes} minutes). Shuffling changes the order for each student.</p>
          {data.questions.map((q, i) => (
            <div key={q.id} className="rounded-lg border border-slate-200 p-3 text-sm">
              <p className="font-medium">{i + 1}. {q.question} <span className="text-xs font-normal text-slate-400">({q.marks} pts)</span></p>
              {q.options.length > 0 && (
                <ul className="mt-2 space-y-1">
                  {q.options.map((o) => <li key={o.index} className="flex items-center gap-2"><input type={q.type === 'multiple' ? 'checkbox' : 'radio'} disabled /> {o.text}</li>)}
                </ul>
              )}
              {q.type === 'numeric' && <input className="input mt-2 w-40" disabled placeholder="Number" />}
              {q.type === 'short' && <textarea className="input mt-2" disabled placeholder="Answer" />}
            </div>
          ))}
        </div>
      )}
    </Modal>
  )
}
