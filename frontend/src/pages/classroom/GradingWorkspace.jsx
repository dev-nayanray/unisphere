import { useCallback, useEffect, useMemo, useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api, downloadFile, errorMessage } from '../../api/client'
import { useApi } from '../../api/useApi'
import { useToast } from '../../components/Toast'
import { Badge, ErrorBox, Loading, formatDate } from '../../components/ui'

const STATES = { turned_in: 'Turned in', missing: 'Missing', graded: 'Graded' }
const TONES = { turned_in: 'indigo', missing: 'slate', graded: 'emerald' }

/** Google Classroom-style grading: students on the left, their work on the right. */
export default function GradingWorkspace() {
  const { id } = useParams()
  const toast = useToast()
  const { data, loading, error, reload } = useApi(`/assignments/${id}/workspace`)
  const bank = useApi('/comment-bank')
  const [filter, setFilter] = useState('all')
  const [current, setCurrent] = useState(null) // student id
  const [selected, setSelected] = useState([])

  const rows = useMemo(() => (data?.rows || []).filter((r) => filter === 'all' || r.state === filter), [data, filter])
  const index = rows.findIndex((r) => r.student.id === current)
  const row = rows[index] || rows[0]

  const go = useCallback((step) => {
    if (!rows.length) return
    const next = rows[Math.min(rows.length - 1, Math.max(0, (index < 0 ? 0 : index) + step))]
    setCurrent(next.student.id)
  }, [rows, index])

  // Keyboard: J / ↓ next student, K / ↑ previous (ignored while typing).
  useEffect(() => {
    const onKey = (e) => {
      if (['INPUT', 'TEXTAREA', 'SELECT'].includes(e.target.tagName)) return
      if (e.key === 'j' || e.key === 'ArrowDown') { e.preventDefault(); go(1) }
      if (e.key === 'k' || e.key === 'ArrowUp') { e.preventDefault(); go(-1) }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [go])

  async function returnGrades(ids) {
    try {
      const res = await api.post(`/assignments/${id}/return`, ids ? { submission_ids: ids } : {})
      toast.success(res.data.message)
      setSelected([])
      reload()
    } catch (err) {
      toast.error(errorMessage(err))
    }
  }

  async function importMarks(file) {
    const body = new FormData()
    body.append('file', file)
    try {
      const res = await api.post(`/assignments/${id}/marks-import`, body)
      toast.success(res.data.message)
      reload()
    } catch (err) {
      toast.error(errorMessage(err))
    }
  }

  if (loading && !data) return <Loading />
  if (error) return <ErrorBox message={error} />

  const a = data.assignment
  const unreturned = data.rows.filter((r) => r.submission?.marks != null && !r.submission.returned_at)

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-center gap-3">
        <div className="min-w-0 flex-1">
          <Link to={`/assignments/${a.id}`} className="text-sm text-indigo-600">← {a.section.course.code} · {a.title}</Link>
          <p className="text-sm text-slate-500">
            {Object.entries(STATES).map(([k, v]) => `${data.counts[k] || 0} ${v.toLowerCase()}`).join(' · ')} · {Number(a.max_marks)} points
          </p>
        </div>
        <button className="btn-ghost" onClick={() => downloadFile(`/assignments/${id}/submissions.zip`)}>Download all (ZIP)</button>
        <button className="btn-ghost" onClick={() => downloadFile(`/assignments/${id}/marks.csv`)}>Marks CSV</button>
        <label className="btn-ghost cursor-pointer">
          Import marks
          <input type="file" accept=".csv" className="hidden" onChange={(e) => e.target.files[0] && importMarks(e.target.files[0])} />
        </label>
        <button className="btn-primary" disabled={!unreturned.length} onClick={() => returnGrades(selected.length ? selected : null)}>
          Return {selected.length || unreturned.length || ''}
        </button>
      </div>

      <div className="grid gap-4 lg:grid-cols-[300px_1fr]">
        <aside className="card p-0">
          <div className="flex gap-1 border-b border-slate-100 p-2 text-xs">
            {[['all', 'All'], ...Object.entries(STATES)].map(([k, v]) => (
              <button key={k} onClick={() => setFilter(k)} className={`rounded-full px-2.5 py-1 ${filter === k ? 'bg-indigo-600 text-white' : 'bg-slate-100'}`}>{v}</button>
            ))}
          </div>
          <ul className="max-h-[70vh] overflow-y-auto">
            {rows.map((r) => {
              const s = r.submission
              const waiting = s?.marks != null && !s.returned_at
              return (
                <li key={r.student.id} className={`flex items-center gap-2 border-b border-slate-50 px-3 py-2 text-sm ${row?.student.id === r.student.id ? 'bg-indigo-50' : ''}`}>
                  {waiting && (
                    <input type="checkbox" checked={selected.includes(s.id)} onChange={() => setSelected((x) => (x.includes(s.id) ? x.filter((i) => i !== s.id) : [...x, s.id]))} />
                  )}
                  <button className="min-w-0 flex-1 text-left" onClick={() => setCurrent(r.student.id)}>
                    <p className="truncate font-medium">{r.student.name}</p>
                    <p className="text-xs text-slate-500">{r.student.student_id}</p>
                  </button>
                  <span className="text-right text-xs">
                    {s?.marks != null ? <b>{Number(s.effective_marks)}</b> : <Badge tone={TONES[r.state]}>{STATES[r.state]}</Badge>}
                    {waiting && <span className="block text-amber-600">not returned</span>}
                  </span>
                </li>
              )
            })}
          </ul>
          <p className="p-2 text-center text-[11px] text-slate-400">J / K or ↓ / ↑ to move between students</p>
        </aside>

        {row ? (
          <GradePanel key={row.student.id} a={a} row={row} bank={bank} onSaved={reload} onNext={() => go(1)} />
        ) : (
          <div className="card text-sm text-slate-500">No students in this view.</div>
        )}
      </div>
    </div>
  )
}

function GradePanel({ a, row, bank, onSaved, onNext }) {
  const toast = useToast()
  const s = row.submission
  const hasRubric = a.criteria?.length > 0
  const [marks, setMarks] = useState(s?.marks ?? '')
  const [feedback, setFeedback] = useState(s?.feedback || '')
  const [picked, setPicked] = useState(s?.rubric_scores || {})
  const [busy, setBusy] = useState(false)

  const rubricTotal = a.criteria.reduce((t, c) => t + Number(c.levels.find((l) => l.id === Number(picked[c.id]))?.points || 0), 0)

  async function save(andReturn, andNext) {
    setBusy(true)
    try {
      const body = hasRubric ? { rubric_scores: picked, feedback } : { marks, feedback }
      const path = s ? `/submissions/${s.id}/grade` : `/assignments/${a.id}/grades`
      if (s) await api.patch(path, { ...body, return: andReturn })
      else await api.post(path, { grades: [{ student_id: row.student.id, marks, feedback }] })
      toast.success(andReturn ? 'Graded and returned.' : 'Grade saved (not returned yet).')
      onSaved()
      if (andNext) onNext()
    } catch (err) {
      toast.error(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  async function saveToBank() {
    if (!feedback.trim()) return
    await api.post('/comment-bank', { text: feedback })
    bank.reload()
    toast.success('Saved to your comment bank.')
  }

  return (
    <section className="space-y-4">
      <div className="card">
        <div className="mb-3 flex flex-wrap items-center gap-2">
          <h2 className="text-lg font-semibold">{row.student.name}</h2>
          <Badge tone={TONES[row.state]}>{STATES[row.state]}</Badge>
          {s?.is_late && <Badge tone="amber">Late{s.late_penalty_percent ? ` · −${s.late_penalty_percent}%` : ''}</Badge>}
          {s?.submitted_at && <span className="text-xs text-slate-500">Turned in {formatDate(s.submitted_at, true)}</span>}
        </div>

        {!s?.submitted_at ? (
          <p className="text-sm text-slate-500">Nothing turned in{s ? ' (taken back by the student)' : ''}. You can still enter marks for work done on paper.</p>
        ) : (
          <>
            {s.content && <p className="whitespace-pre-wrap rounded-lg bg-slate-50 p-3 text-sm">{s.content}</p>}
            {s.file_url && <FilePreview url={s.file_url} name={s.file_name} mime={s.file_mime} />}
          </>
        )}

        {s?.versions?.length > 1 && (
          <details className="mt-3 text-xs text-slate-500">
            <summary className="cursor-pointer">{s.versions.length} versions turned in</summary>
            <ul className="mt-1 space-y-1">
              {s.versions.map((v) => (
                <li key={v.id}>#{v.number} · {formatDate(v.submitted_at, true)}{v.is_late && ' (late)'}{v.file_name && ` · ${v.file_name}`}{v.content && ` · “${v.content.slice(0, 60)}”`}</li>
              ))}
            </ul>
          </details>
        )}
      </div>

      <div className="card">
        {hasRubric ? (
          <div className="mb-3 space-y-2">
            {a.criteria.map((c) => (
              <div key={c.id}>
                <p className="text-sm font-medium">{c.title}</p>
                <div className="mt-1 flex flex-wrap gap-2">
                  {c.levels.map((l) => (
                    <button
                      key={l.id}
                      onClick={() => setPicked({ ...picked, [c.id]: l.id })}
                      className={`rounded-lg border px-3 py-1.5 text-xs ${Number(picked[c.id]) === l.id ? 'border-indigo-500 bg-indigo-50' : 'border-slate-200'}`}
                    >
                      <b>{Number(l.points)}</b> {l.title}
                    </button>
                  ))}
                </div>
              </div>
            ))}
            <p className="text-lg font-semibold">{rubricTotal} / {Number(a.max_marks)}</p>
          </div>
        ) : (
          <label className="mb-3 flex items-center gap-2">
            <span className="label mb-0">Marks</span>
            <input type="number" min="0" max={a.max_marks} step="any" className="input w-28" value={marks} onChange={(e) => setMarks(e.target.value)} />
            <span className="text-sm text-slate-500">/ {Number(a.max_marks)}</span>
          </label>
        )}

        <span className="label">Private feedback</span>
        <textarea className="input min-h-20" value={feedback} onChange={(e) => setFeedback(e.target.value)} />
        <div className="mt-2 flex flex-wrap items-center gap-2 text-xs">
          {bank.data?.slice(0, 8).map((c) => (
            <button key={c.id} className="rounded-full bg-slate-100 px-2.5 py-1 hover:bg-indigo-50" onClick={() => setFeedback((f) => (f ? `${f}\n${c.text}` : c.text))} title={c.text}>
              {c.text.length > 30 ? `${c.text.slice(0, 30)}…` : c.text}
            </button>
          ))}
          <button className="text-indigo-600 hover:underline" onClick={saveToBank}>+ Save to comment bank</button>
        </div>

        <div className="mt-4 flex flex-wrap gap-2">
          <button className="btn-ghost" disabled={busy} onClick={() => save(false, false)}>Save draft grade</button>
          {s && <button className="btn-primary" disabled={busy} onClick={() => save(true, false)}>Save & return</button>}
          <button className="btn-ghost" disabled={busy} onClick={() => save(false, true)}>Save & next →</button>
        </div>
        {s?.returned_at && <p className="mt-2 text-xs text-emerald-700">Returned {formatDate(s.returned_at, true)}. Changes are visible to the student right away.</p>}
      </div>
    </section>
  )
}

function FilePreview({ url, name, mime }) {
  if (mime?.startsWith('image/')) return <img src={url} alt={name} className="mt-3 max-h-[60vh] rounded-lg border border-slate-200" />
  if (mime === 'application/pdf') return <iframe title={name} src={url} className="mt-3 h-[60vh] w-full rounded-lg border border-slate-200" />
  return <a href={url} target="_blank" rel="noreferrer" className="btn-ghost mt-3">Download {name}</a>
}
