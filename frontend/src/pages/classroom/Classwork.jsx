import { useState } from 'react'
import { Link } from 'react-router-dom'
import { api, errorMessage } from '../../api/client'
import { useApi } from '../../api/useApi'
import ConfirmButton from '../../components/ConfirmButton'
import { useToast } from '../../components/Toast'
import { Badge, Empty, ErrorBox, Field, Loading, Modal, formatDate } from '../../components/ui'
import AssignmentForm from './AssignmentForm'

const FILTERS = { all: 'All', assigned: 'Assigned', missing: 'Missing', done: 'Done' }

/** Student status for one assignment: done, missing (past due, nothing turned in) or assigned. */
function studentStatus(a) {
  const mine = a.submissions?.[0]
  if (mine?.submitted_at || mine?.marks != null) return 'done'
  if (a.due_at && new Date(a.due_at) < new Date()) return 'missing'
  return 'assigned'
}

export default function Classwork({ cls }) {
  const topics = useApi(`/classes/${cls.id}/topics`)
  const assignments = useApi(`/classes/${cls.id}/assignments`)
  const materials = useApi(`/classes/${cls.id}/materials`)
  const quizzes = useApi(`/classes/${cls.id}/quizzes`)
  const [modal, setModal] = useState(null) // 'assignment' | 'material' | 'topics' | {edit: assignment}
  const [filter, setFilter] = useState('all')
  const [viewing, setViewing] = useState(null)

  if (topics.loading || assignments.loading || materials.loading) return <Loading />

  const reloadAll = () => {
    topics.reload()
    assignments.reload()
    materials.reload()
    quizzes.reload()
  }

  const visibleAssignments = (assignments.data || []).filter((a) => cls.can_manage || filter === 'all' || studentStatus(a) === filter)
  const groups = [...(topics.data || []), { id: null, name: 'No topic' }]
    .map((t) => ({
      ...t,
      assignments: visibleAssignments.filter((a) => (a.topic_id ?? null) === t.id),
      materials: filter === 'all' ? (materials.data || []).filter((m) => (m.topic_id ?? null) === t.id) : [],
      quizzes: filter === 'all' ? (quizzes.data || []).filter((q) => (q.topic_id ?? null) === t.id) : [],
    }))
    .filter((g) => g.assignments.length + g.materials.length + g.quizzes.length > 0 || (cls.can_manage && g.id))

  const counts = Object.fromEntries(Object.keys(FILTERS).map((k) => [k, k === 'all' ? assignments.data.length : assignments.data.filter((a) => studentStatus(a) === k).length]))

  return (
    <div className="space-y-6">
      {cls.can_manage ? (
        <div className="flex flex-wrap gap-2">
          <button className="btn-primary" onClick={() => setModal('assignment')}>+ Assignment</button>
          <button className="btn-ghost" onClick={() => setModal('material')}>+ Material</button>
          <button className="btn-ghost" onClick={() => setModal('topics')}>Manage topics</button>
        </div>
      ) : (
        <div className="flex gap-1">
          {Object.entries(FILTERS).map(([k, label]) => (
            <button
              key={k}
              onClick={() => setFilter(k)}
              className={`rounded-full px-3 py-1 text-sm ${filter === k ? 'bg-indigo-600 text-white' : 'bg-white text-slate-600 ring-1 ring-slate-200'}`}
            >
              {label} <span className="opacity-70">{counts[k]}</span>
            </button>
          ))}
        </div>
      )}

      {groups.length === 0 && <Empty>No classwork yet.</Empty>}

      {groups.map((g) => (
        <section key={g.id ?? 'none'}>
          <h2 className="mb-2 border-b border-slate-200 pb-1 text-lg font-semibold text-indigo-800">{g.name}</h2>
          <ul className="card divide-y divide-slate-100 p-0">
            {g.assignments.map((a) => <AssignmentRow key={`a${a.id}`} a={a} cls={cls} onEdit={() => setModal({ edit: a })} />)}
            {g.quizzes.map((q) => (
              <li key={`q${q.id}`} className="flex items-center justify-between px-5 py-3">
                <div>
                  <p className="font-medium">📝 {q.title}</p>
                  <p className="text-xs text-slate-500">Quiz · {q.questions_count} questions · {q.duration_minutes} min</p>
                </div>
                {!cls.can_manage && q.attempts?.[0]?.score != null && <Badge tone="emerald">{q.attempts[0].score}</Badge>}
              </li>
            ))}
            {g.materials.map((m) => (
              <li key={`m${m.id}`} className="flex items-center justify-between px-5 py-3">
                <button className="text-left" onClick={() => setViewing(m)}>
                  <p className="font-medium text-indigo-700 hover:underline">📎 {m.title}</p>
                  <p className="text-xs text-slate-500">Material · {m.type}</p>
                </button>
              </li>
            ))}
            {g.assignments.length + g.quizzes.length + g.materials.length === 0 && (
              <li className="px-5 py-3 text-sm text-slate-400">Nothing in this topic yet.</li>
            )}
          </ul>
        </section>
      ))}

      {(modal === 'assignment' || modal?.edit) && (
        <AssignmentForm cls={cls} topics={topics.data} assignment={modal?.edit} onClose={() => setModal(null)} onSaved={reloadAll} />
      )}
      {modal === 'material' && <MaterialForm cls={cls} topics={topics.data} onClose={() => setModal(null)} onSaved={reloadAll} />}
      {modal === 'topics' && <TopicManager cls={cls} topics={topics.data} onClose={() => setModal(null)} onChanged={reloadAll} />}
      <MaterialViewer material={viewing} onClose={() => setViewing(null)} />
    </div>
  )
}

function AssignmentRow({ a, cls, onEdit }) {
  const status = studentStatus(a)
  const mine = a.submissions?.[0]
  const scheduled = a.published_at && new Date(a.published_at) > new Date()

  return (
    <li className="flex items-center justify-between gap-3 px-5 py-3">
      <Link to={`/assignments/${a.id}`} className="min-w-0 flex-1 hover:text-indigo-700">
        <p className="truncate font-medium">📄 {a.title}</p>
        <p className="text-xs text-slate-500">
          <span className="capitalize">{a.category}</span> · {Number(a.max_marks)} marks · {a.due_at ? `Due ${formatDate(a.due_at, true)}` : 'No due date'}
          {a.assign_to === 'selected' && ' · selected students'}
        </p>
      </Link>
      {cls.can_manage ? (
        <span className="flex items-center gap-3 text-xs">
          {!a.published_at && <Badge>Draft</Badge>}
          {scheduled && <Badge tone="amber">Scheduled {formatDate(a.published_at, true)}</Badge>}
          <span className="text-slate-500">{a.turned_in_count} turned in · {a.graded_count} graded</span>
          <button className="text-indigo-600 hover:underline" onClick={onEdit}>Edit</button>
        </span>
      ) : mine?.marks != null ? (
        <Badge tone="emerald">{Number(mine.marks)}/{Number(a.max_marks)}</Badge>
      ) : status === 'done' ? (
        <Badge tone="indigo">Turned in</Badge>
      ) : status === 'missing' ? (
        <Badge tone="rose">Missing</Badge>
      ) : (
        <Badge tone="amber">Assigned</Badge>
      )}
    </li>
  )
}

function MaterialForm({ cls, topics, onClose, onSaved }) {
  const [form, setForm] = useState({ title: '', topic_id: '', type: 'link', url: '', description: '' })
  const [file, setFile] = useState(null)
  const [error, setError] = useState(null)
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })

  async function save(e) {
    e.preventDefault()
    const body = new FormData()
    Object.entries(form).forEach(([k, v]) => v && body.append(k, v))
    if (form.type === 'file' && file) body.append('file', file)
    try {
      await api.post(`/classes/${cls.id}/materials`, body)
      onSaved()
      onClose()
    } catch (err) {
      setError(errorMessage(err))
    }
  }

  return (
    <Modal open title="New material" onClose={onClose}>
      <form onSubmit={save}>
        <ErrorBox message={error} />
        <Field label="Title"><input className="input" value={form.title} onChange={set('title')} required /></Field>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Topic">
            <select className="input" value={form.topic_id} onChange={set('topic_id')}>
              <option value="">No topic</option>
              {topics.map((t) => <option key={t.id} value={t.id}>{t.name}</option>)}
            </select>
          </Field>
          <Field label="Type">
            <select className="input" value={form.type} onChange={set('type')}>
              <option value="link">Link</option>
              <option value="video">Video (YouTube)</option>
              <option value="file">File upload</option>
            </select>
          </Field>
        </div>
        {form.type === 'file' ? (
          <Field label="File (PDF previews inline)"><input type="file" className="input" onChange={(e) => setFile(e.target.files[0])} required /></Field>
        ) : (
          <Field label="URL"><input type="url" className="input" value={form.url} onChange={set('url')} required /></Field>
        )}
        <Field label="Description"><textarea className="input" value={form.description} onChange={set('description')} /></Field>
        <button className="btn-primary w-full">Post material</button>
      </form>
    </Modal>
  )
}

/** YouTube links become an embedded player, PDFs an inline viewer, anything else opens in a new tab. */
function MaterialViewer({ material, onClose }) {
  if (!material) return null
  const url = material.file_url || material.url
  const yt = url?.match(/(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/)|youtu\.be\/)([\w-]{11})/)
  const isPdf = /\.pdf($|\?)/i.test(url || '')

  return (
    <Modal open title={material.title} onClose={onClose}>
      {material.description && <p className="mb-3 text-sm text-slate-600">{material.description}</p>}
      {yt ? (
        <iframe
          title={material.title}
          className="aspect-video w-full rounded-lg"
          src={`https://www.youtube-nocookie.com/embed/${yt[1]}`}
          allow="accelerometer; encrypted-media; picture-in-picture"
          allowFullScreen
        />
      ) : isPdf ? (
        <iframe title={material.title} src={url} className="h-[60vh] w-full rounded-lg border border-slate-200" />
      ) : (
        <a href={url} target="_blank" rel="noreferrer" className="btn-primary">Open link</a>
      )}
    </Modal>
  )
}

/** Add, rename, delete and drag-and-drop reorder topics. */
function TopicManager({ cls, topics, onClose, onChanged }) {
  const toast = useToast()
  const [list, setList] = useState(topics)
  const [name, setName] = useState('')
  const [dragging, setDragging] = useState(null)

  async function run(fn, message) {
    try {
      const res = await fn()
      if (message) toast.success(message)
      onChanged()
      return res
    } catch (err) {
      toast.error(errorMessage(err))
    }
  }

  async function add(e) {
    e.preventDefault()
    const res = await run(() => api.post(`/classes/${cls.id}/topics`, { name }), 'Topic added.')
    if (res) {
      setList([...list, res.data])
      setName('')
    }
  }

  function dropOn(index) {
    if (dragging === null || dragging === index) return
    const next = [...list]
    const [moved] = next.splice(dragging, 1)
    next.splice(index, 0, moved)
    setList(next)
    setDragging(null)
    run(() => api.put(`/classes/${cls.id}/topics/order`, { ids: next.map((t) => t.id) }))
  }

  return (
    <Modal open title="Topics" onClose={onClose}>
      <p className="mb-3 text-xs text-slate-500">Drag to reorder. Deleting a topic keeps its classwork under "No topic".</p>
      <ul className="mb-4 space-y-1">
        {list.map((t, i) => (
          <li
            key={t.id}
            draggable
            onDragStart={() => setDragging(i)}
            onDragOver={(e) => e.preventDefault()}
            onDrop={() => dropOn(i)}
            className={`flex items-center gap-2 rounded-lg border px-3 py-2 text-sm ${dragging === i ? 'border-indigo-400 bg-indigo-50' : 'border-slate-200 bg-white'}`}
          >
            <span className="cursor-grab text-slate-400">⠿</span>
            <input
              className="flex-1 bg-transparent outline-none"
              defaultValue={t.name}
              onBlur={(e) => e.target.value !== t.name && run(() => api.patch(`/topics/${t.id}`, { name: e.target.value }), 'Topic renamed.')}
            />
            <ConfirmButton title={`Delete "${t.name}"?`} message="Its classwork moves to No topic." onConfirm={() => run(() => api.delete(`/topics/${t.id}`), 'Topic deleted.').then(() => setList(list.filter((x) => x.id !== t.id)))}>
              ✕
            </ConfirmButton>
          </li>
        ))}
      </ul>
      <form onSubmit={add} className="flex gap-2">
        <input className="input" placeholder="New topic, e.g. Week 3: Trees" value={name} onChange={(e) => setName(e.target.value)} required />
        <button className="btn-primary">Add</button>
      </form>
    </Modal>
  )
}
