import { useEffect, useState } from 'react'
import { api } from '../../api/client'
import { useApi } from '../../api/useApi'
import { useToast } from '../../components/Toast'
import { Modal } from '../../components/ui'

const toLocal = (iso) => (iso ? new Date(iso).toLocaleString('sv-SE').slice(0, 16).replace(' ', 'T') : '')

const emptyLevel = (points) => ({ title: '', points })
const emptyCriterion = () => ({ title: '', levels: [{ title: 'Excellent', points: 5 }, { title: 'Good', points: 3 }, { title: 'Needs work', points: 1 }] })

/** Create or edit an assignment: details, late policy, who it's for, publishing, files and rubric. */
export default function AssignmentForm({ cls, topics, assignment, onClose, onSaved }) {
  const toast = useToast()
  const people = useApi(`/classes/${cls.id}/people`)
  const editing = Boolean(assignment)
  const status = !assignment?.published_at ? (editing ? 'draft' : 'publish') : new Date(assignment.published_at) > new Date() ? 'schedule' : 'publish'

  const [form, setForm] = useState({
    title: assignment?.title || '',
    instructions: assignment?.instructions || '',
    topic_id: assignment?.topic_id || '',
    category: assignment?.category || 'assignment',
    max_marks: assignment ? Number(assignment.max_marks) : 20,
    due_at: toLocal(assignment?.due_at),
    late_policy: assignment?.late_policy || 'accept',
    late_penalty_percent: assignment?.late_penalty_percent || 10,
    assign_to: assignment?.assign_to || 'all',
    student_ids: [],
    status,
    publish_at: status === 'schedule' ? toLocal(assignment.published_at) : '',
  })
  // When editing, load the full assignment to know which students it is assigned to.
  const detail = useApi(editing ? `/assignments/${assignment.id}` : null)
  useEffect(() => {
    const ids = detail.data?.assigned_students?.map((s) => s.id)
    if (ids?.length) setForm((f) => ({ ...f, student_ids: ids }))
  }, [detail.data])

  const [files, setFiles] = useState([])
  const [useRubric, setUseRubric] = useState(false)
  const [rubric, setRubric] = useState([emptyCriterion()])
  const [errors, setErrors] = useState({})
  const [busy, setBusy] = useState(false)

  const set = (k) => (e) => setForm({ ...form, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value })
  const err = (k) => Object.entries(errors).filter(([key]) => key === k || key.startsWith(`${k}.`)).flatMap(([, v]) => v).join(' ')
  const rubricMax = rubric.reduce((t, c) => t + Math.max(0, ...c.levels.map((l) => Number(l.points) || 0)), 0)

  const updateCriterion = (i, patch) => setRubric(rubric.map((c, j) => (j === i ? { ...c, ...patch } : c)))
  const updateLevel = (i, li, patch) => updateCriterion(i, { levels: rubric[i].levels.map((l, j) => (j === li ? { ...l, ...patch } : l)) })

  async function submit(e) {
    e.preventDefault()
    const body = new FormData()
    const fields = { ...form }
    if (fields.late_policy !== 'penalty') delete fields.late_penalty_percent
    if (fields.status !== 'schedule') delete fields.publish_at
    if (!fields.due_at) fields.due_at = ''
    Object.entries(fields).forEach(([k, v]) => {
      if (k === 'student_ids') return
      body.append(k, v ?? '')
    })
    if (form.assign_to === 'selected') form.student_ids.forEach((id) => body.append('student_ids[]', id))
    files.forEach((f) => body.append('attachments[]', f))
    if (useRubric) body.append('rubric', JSON.stringify(rubric))
    if (editing) body.append('_method', 'PATCH')

    setBusy(true)
    setErrors({})
    try {
      await api.post(editing ? `/assignments/${assignment.id}` : `/classes/${cls.id}/assignments`, body)
      toast.success(editing ? 'Assignment updated.' : form.status === 'schedule' ? 'Assignment scheduled.' : form.status === 'draft' ? 'Draft saved.' : 'Assignment posted.')
      onSaved()
      onClose()
    } catch (error) {
      setErrors(error.response?.data?.errors || { title: [error.response?.data?.message || 'Could not save.'] })
    } finally {
      setBusy(false)
    }
  }

  const Error = ({ name }) => (err(name) ? <span className="mt-1 block text-xs text-rose-600">{err(name)}</span> : null)

  return (
    <Modal open title={editing ? 'Edit assignment' : 'New assignment'} onClose={onClose}>
      <form onSubmit={submit} className="max-h-[75vh] space-y-3 overflow-y-auto pr-1">
        <label className="block">
          <span className="label">Title</span>
          <input className="input" value={form.title} onChange={set('title')} required />
          <Error name="title" />
        </label>
        <label className="block">
          <span className="label">Instructions (**bold**, *italic*, links)</span>
          <textarea className="input min-h-24" value={form.instructions} onChange={set('instructions')} />
        </label>

        <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
          <label className="block">
            <span className="label">Topic</span>
            <select className="input" value={form.topic_id} onChange={set('topic_id')}>
              <option value="">No topic</option>
              {topics.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
            </select>
          </label>
          <label className="block">
            <span className="label">Counts as</span>
            <select className="input" value={form.category} onChange={set('category')}>
              <option value="assignment">Assignment</option>
              <option value="quiz">Class test</option>
              <option value="mid">Midterm</option>
              <option value="final">Final</option>
            </select>
          </label>
          <label className="block">
            <span className="label">Points</span>
            <input type="number" className="input" value={useRubric ? rubricMax : form.max_marks} onChange={set('max_marks')} disabled={useRubric} />
          </label>
          <label className="block">
            <span className="label">Due (Dhaka time)</span>
            <input type="datetime-local" className="input" value={form.due_at} onChange={set('due_at')} />
          </label>
        </div>

        <div className="grid grid-cols-2 gap-3">
          <label className="block">
            <span className="label">Late work</span>
            <select className="input" value={form.late_policy} onChange={set('late_policy')}>
              <option value="accept">Accept, no penalty</option>
              <option value="penalty">Accept with a penalty</option>
              <option value="reject">Don't accept</option>
            </select>
          </label>
          {form.late_policy === 'penalty' && (
            <label className="block">
              <span className="label">% off per day late</span>
              <input type="number" min="1" max="100" className="input" value={form.late_penalty_percent} onChange={set('late_penalty_percent')} />
              <Error name="late_penalty_percent" />
            </label>
          )}
        </div>

        <div>
          <span className="label">For</span>
          <div className="flex gap-4 text-sm">
            <label className="flex items-center gap-2"><input type="radio" checked={form.assign_to === 'all'} onChange={() => setForm({ ...form, assign_to: 'all' })} /> All students</label>
            <label className="flex items-center gap-2"><input type="radio" checked={form.assign_to === 'selected'} onChange={() => setForm({ ...form, assign_to: 'selected' })} /> Selected students</label>
          </div>
          {form.assign_to === 'selected' && (
            <div className="mt-2 grid max-h-40 grid-cols-2 gap-1 overflow-y-auto rounded-lg border border-slate-200 p-2 text-sm">
              {people.data?.students?.map((s) => (
                <label key={s.id} className="flex items-center gap-2">
                  <input
                    type="checkbox"
                    checked={form.student_ids.includes(s.id)}
                    onChange={() => setForm({ ...form, student_ids: form.student_ids.includes(s.id) ? form.student_ids.filter((x) => x !== s.id) : [...form.student_ids, s.id] })}
                  />
                  {s.name}
                </label>
              ))}
            </div>
          )}
          <Error name="student_ids" />
        </div>

        <div className="grid grid-cols-2 gap-3">
          <label className="block">
            <span className="label">Publish</span>
            <select className="input" value={form.status} onChange={set('status')}>
              <option value="publish">Post now</option>
              <option value="schedule">Schedule</option>
              <option value="draft">Save as draft</option>
            </select>
          </label>
          {form.status === 'schedule' && (
            <label className="block">
              <span className="label">Post on</span>
              <input type="datetime-local" className="input" value={form.publish_at} onChange={set('publish_at')} required />
              <Error name="publish_at" />
            </label>
          )}
        </div>

        <label className="block">
          <span className="label">Attachments (up to 5 files, 20 MB each)</span>
          <input type="file" multiple className="text-sm" onChange={(e) => setFiles([...e.target.files].slice(0, 5))} />
          {assignment?.attachments?.length > 0 && <span className="mt-1 block text-xs text-slate-500">Already attached: {assignment.attachments.map((a) => a.original_name).join(', ')}</span>}
          <Error name="attachments" />
        </label>

        <div className="rounded-lg border border-slate-200 p-3">
          <label className="flex items-center gap-2 text-sm font-medium">
            <input type="checkbox" checked={useRubric} onChange={(e) => setUseRubric(e.target.checked)} />
            {editing && assignment.criteria?.length ? 'Replace the rubric' : 'Grade with a rubric'}
          </label>
          {useRubric && (
            <div className="mt-3 space-y-3">
              {rubric.map((c, i) => (
                <div key={i} className="rounded-lg bg-slate-50 p-3">
                  <div className="flex gap-2">
                    <input className="input" placeholder="Criterion, e.g. Correctness" value={c.title} onChange={(e) => updateCriterion(i, { title: e.target.value })} required />
                    {rubric.length > 1 && <button type="button" className="text-rose-500" onClick={() => setRubric(rubric.filter((_, j) => j !== i))}>✕</button>}
                  </div>
                  <div className="mt-2 grid grid-cols-2 gap-2 sm:grid-cols-3">
                    {c.levels.map((l, li) => (
                      <div key={li} className="flex gap-1">
                        <input className="input py-1 text-xs" placeholder="Level" value={l.title} onChange={(e) => updateLevel(i, li, { title: e.target.value })} required />
                        <input type="number" className="input w-16 py-1 text-xs" value={l.points} onChange={(e) => updateLevel(i, li, { points: e.target.value })} required />
                      </div>
                    ))}
                    {c.levels.length < 6 && (
                      <button type="button" className="text-left text-xs text-indigo-600" onClick={() => updateCriterion(i, { levels: [...c.levels, emptyLevel(0)] })}>+ level</button>
                    )}
                  </div>
                </div>
              ))}
              <button type="button" className="text-sm text-indigo-600" onClick={() => setRubric([...rubric, emptyCriterion()])}>+ Add criterion</button>
              <p className="text-xs text-slate-500">Total points: {rubricMax}. Points become the rubric's maximum.</p>
              <Error name="rubric" />
            </div>
          )}
        </div>

        <button className="btn-primary w-full" disabled={busy}>{busy ? 'Saving…' : editing ? 'Save changes' : 'Create'}</button>
      </form>
    </Modal>
  )
}
