import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, downloadFile, errorMessage } from '../../api/client'
import { useApi } from '../../api/useApi'
import { useToast } from '../../components/Toast'
import { Badge, Empty, ErrorBox, Loading, Modal } from '../../components/ui'
import { useAuth } from '../../context/AuthContext'

const gradeTone = (gp) => (gp >= 3 ? 'emerald' : gp > 0 ? 'amber' : 'rose')
const num = (v) => (v == null ? '' : Number(v))

/** Gradebook: a grid of every assignment and quiz, category marks, total and grade. */
export default function Grades({ cls }) {
  const { hasRole } = useAuth()
  const toast = useToast()
  const { data, setData, loading, error, reload } = useApi(`/classes/${cls.id}/gradebook`)
  const [view, setView] = useState('items') // items | categories
  const [overrideFor, setOverrideFor] = useState(null)
  const [cellMenu, setCellMenu] = useState(null) // {row, col}
  const staff = cls.can_manage || hasRole('department_head', 'registrar')

  if (loading) return <Loading />
  if (error) return <ErrorBox message={error} />

  const categories = Object.keys(data.scheme)
  const replaceRow = (row) => setData((d) => ({ ...d, rows: d.rows.map((r) => (r.registration_id === row.registration_id ? row : r)) }))

  async function run(promise, ok) {
    try {
      const res = await promise
      if (ok) toast.success(typeof ok === 'function' ? ok(res) : ok)
      return res
    } catch (err) {
      toast.error(errorMessage(err))
      return null
    }
  }

  async function saveCell(row, col, body) {
    const res = await run(api.patch(`/classes/${cls.id}/gradebook/cells`, { student_id: row.student.id, item: col.key, ...body }))
    if (res) replaceRow(res.data)
    return Boolean(res)
  }

  async function toggleMissingZero() {
    const res = await run(api.patch(`/classes/${cls.id}/gradebook/settings`, { missing_as_zero: !data.missing_as_zero }))
    if (res) reload()
  }

  // Students: their own row as a list.
  if (!staff) return <StudentGrades data={data} />

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-center gap-2">
        <div className="flex rounded-lg border border-slate-200 p-0.5 text-sm">
          {[['items', 'By item'], ['categories', 'By category']].map(([k, label]) => (
            <button key={k} onClick={() => setView(k)} className={`rounded-md px-3 py-1 ${view === k ? 'bg-indigo-600 text-white' : 'text-slate-600'}`}>{label}</button>
          ))}
        </div>
        {cls.can_manage && (
          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" checked={data.missing_as_zero} onChange={toggleMissingZero} /> Count missing work as 0 after the due date
          </label>
        )}
        <span className="flex-1" />
        <button className="btn-ghost" onClick={() => run(downloadFile(`/classes/${cls.id}/gradebook/export.csv`))}>Export CSV</button>
        {cls.can_manage && <button className="btn-primary" onClick={() => run(api.post(`/classes/${cls.id}/gradebook/sync`), (r) => r.data.message)}>Send to official results</button>}
        {hasRole('department_head', 'registrar') && <button className="btn-ghost" onClick={() => run(api.post(`/classes/${cls.id}/results/publish`), (r) => r.data.message)}>Publish results</button>}
      </div>

      {!data.rows.length ? (
        <Empty>No students in the gradebook yet.</Empty>
      ) : (
        <div className="card overflow-x-auto p-0">
          <table className="table text-sm">
            <thead>
              <tr>
                <th className="sticky left-0 z-10 bg-slate-50">Student</th>
                {view === 'items'
                  ? data.columns.map((c) => (
                    <th key={c.key} className="min-w-24 whitespace-nowrap">
                      <Link to={c.type === 'quiz' ? `/quizzes/${c.id}/results` : `/assignments/${c.id}/grade`} className="hover:text-indigo-600" title={c.title}>
                        <span className="block max-w-32 truncate">{c.type === 'quiz' ? '📝' : '📄'} {c.title}</span>
                      </Link>
                      <span className="text-xs font-normal capitalize text-slate-400">{c.category} · /{c.max}</span>
                    </th>
                  ))
                  : categories.map((c) => <th key={c} className="capitalize">{c} <span className="text-xs font-normal">({data.scheme[c]})</span></th>)}
                <th>Total</th>
                <th>Grade</th>
              </tr>
            </thead>
            <tbody>
              {data.rows.map((r) => (
                <tr key={r.registration_id}>
                  <td className="sticky left-0 z-10 bg-white font-medium">{r.student?.name}</td>
                  {view === 'items'
                    ? data.columns.map((c) => (
                      <GradeCell key={c.key} cell={r.cells[c.key]} editable={cls.can_manage} onSave={(body) => saveCell(r, c, body)} onMenu={() => setCellMenu({ row: r, col: c })} />
                    ))
                    : categories.map((c) => <td key={c}>{r.breakdown[c]}</td>)}
                  <td className="whitespace-nowrap font-semibold">
                    <button
                      className={cls.can_manage ? 'hover:text-indigo-600' : ''}
                      disabled={!cls.can_manage}
                      onClick={() => setOverrideFor(r)}
                      title={r.override ? `Overridden (calculated ${r.computed_total}): ${r.override.note}` : 'Calculated from classwork'}
                    >
                      {r.total}{r.override && <span className="ml-1 text-amber-600">*</span>}
                    </button>
                  </td>
                  <td><Badge tone={gradeTone(r.grade_point)}>{r.letter_grade} ({r.grade_point.toFixed(2)})</Badge></td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <p className="mt-3 text-xs text-slate-400">
        Click a cell to type marks (Enter saves). ⋯ excuses a student or returns the grade. EX = excused, — = not assigned, red 0 = missing work counted as zero, * = total overridden.
        Grades follow the UGC uniform grading system.
      </p>

      {overrideFor && (
        <OverrideModal
          row={overrideFor}
          onClose={() => setOverrideFor(null)}
          onSave={async (body) => {
            const res = await run(api.put(`/classes/${cls.id}/gradebook/overrides/${overrideFor.student.id}`, body), 'Total updated.')
            if (res) { replaceRow(res.data); setOverrideFor(null) }
          }}
        />
      )}
      {cellMenu && (
        <CellMenu
          {...cellMenu}
          onClose={() => setCellMenu(null)}
          onSave={async (body) => { if (await saveCell(cellMenu.row, cellMenu.col, body)) setCellMenu(null) }}
        />
      )}
    </div>
  )
}

function GradeCell({ cell, editable, onSave, onMenu }) {
  const [value, setValue] = useState(num(cell.raw ?? cell.value))
  const [editing, setEditing] = useState(false)
  useEffect(() => setValue(num(cell.raw ?? cell.value)), [cell.raw, cell.value])

  if (cell.status === 'not_assigned') return <td className="text-slate-300">—</td>
  if (cell.status === 'excused') return <td><button disabled={!editable} onClick={onMenu} className="text-xs font-semibold text-slate-400">EX</button></td>

  const tone = cell.counted_as_zero ? 'text-rose-600' : cell.status === 'graded' ? 'text-slate-500' : ''
  const commit = async () => {
    setEditing(false)
    const next = value === '' ? null : Number(value)
    if (next === (cell.raw ?? cell.value ?? null)) return
    if (!(await onSave({ value: next }))) setValue(num(cell.raw ?? cell.value))
  }

  return (
    <td className="group whitespace-nowrap">
      {editing ? (
        <input
          autoFocus type="number" min="0" max={cell.max} step="0.25" className="input w-16 px-1 py-0.5"
          value={value} onChange={(e) => setValue(e.target.value)} onBlur={commit}
          onKeyDown={(e) => { if (e.key === 'Enter') e.currentTarget.blur(); if (e.key === 'Escape') { setValue(num(cell.raw ?? cell.value)); setEditing(false) } }}
        />
      ) : (
        <button disabled={!editable} onClick={() => setEditing(true)} className={`min-w-8 text-left ${tone}`} title={cell.status}>
          {cell.value != null ? num(cell.value) : cell.counted_as_zero ? '0' : cell.status === 'submitted' ? <span className="text-xs text-indigo-500">to grade</span> : <span className="text-slate-300">·</span>}
          {cell.late && <span className="ml-0.5 text-xs text-amber-500" title="Late">L</span>}
        </button>
      )}
      {editable && !editing && <button className="ml-1 text-slate-300 opacity-0 hover:text-slate-600 group-hover:opacity-100" onClick={onMenu}>⋯</button>}
    </td>
  )
}

function CellMenu({ row, col, onClose, onSave }) {
  const cell = row.cells[col.key]
  return (
    <Modal open title={`${row.student.name}: ${col.title}`} onClose={onClose}>
      <div className="space-y-2 text-sm">
        <p className="text-slate-500">Status: {cell.status.replace('_', ' ')}{cell.value != null && ` · ${num(cell.value)} / ${cell.max}`}</p>
        {cell.status === 'excused' ? (
          <button className="btn-ghost w-full" onClick={() => onSave({ excused: false })}>Remove excuse</button>
        ) : (
          <button className="btn-ghost w-full" onClick={() => onSave({ excused: true })}>Excuse from this {col.type} (left out of the average)</button>
        )}
        {col.type === 'assignment' && cell.value != null && cell.status !== 'returned' && (
          <button className="btn-primary w-full" onClick={() => onSave({ value: cell.raw, return: true })}>Return grade to student</button>
        )}
        {cell.value != null && <button className="btn-ghost w-full text-rose-600" onClick={() => onSave({ value: null })}>Clear grade</button>}
      </div>
    </Modal>
  )
}

function OverrideModal({ row, onClose, onSave }) {
  const [total, setTotal] = useState(row.override?.total ?? row.computed_total)
  const [note, setNote] = useState(row.override?.note ?? '')
  return (
    <Modal open title={`Final total: ${row.student.name}`} onClose={onClose}>
      <div className="space-y-3 text-sm">
        <p className="text-slate-500">Calculated from classwork: <strong>{row.computed_total}</strong>. An override replaces it in the official result.</p>
        <label className="block"><span className="label">Final total (0–100)</span><input type="number" min="0" max="100" step="0.5" className="input" value={total} onChange={(e) => setTotal(e.target.value)} /></label>
        <label className="block"><span className="label">Reason (required, kept on record)</span><textarea className="input" value={note} onChange={(e) => setNote(e.target.value)} /></label>
        <div className="flex justify-between gap-2">
          {row.override ? <button className="btn-ghost text-rose-600" onClick={() => onSave({ total: null })}>Remove override</button> : <span />}
          <button className="btn-primary" disabled={!note.trim()} onClick={() => onSave({ total: Number(total), note })}>Save</button>
        </div>
      </div>
    </Modal>
  )
}

function StudentGrades({ data }) {
  const row = data.rows[0]
  if (!row) return <Empty>You are not enrolled in this class.</Empty>
  const shown = data.columns.filter((c) => row.cells[c.key]?.status !== 'not_assigned')

  return (
    <div className="space-y-4">
      <div className="card flex flex-wrap items-center gap-4">
        <div><p className="text-xs text-slate-500">Current total</p><p className="text-3xl font-semibold">{row.total}</p></div>
        <Badge tone={gradeTone(row.grade_point)}>{row.letter_grade} ({row.grade_point.toFixed(2)})</Badge>
        <div className="ml-auto flex flex-wrap gap-3 text-sm">
          {Object.entries(row.breakdown).map(([c, v]) => <span key={c} className="capitalize text-slate-600">{c}: <strong>{v}</strong>/{data.scheme[c]}</span>)}
        </div>
      </div>
      <ul className="card divide-y divide-slate-100 p-0 text-sm">
        {shown.map((c) => {
          const cell = row.cells[c.key]
          return (
            <li key={c.key} className="flex items-center justify-between px-5 py-3">
              <span>{c.type === 'quiz' ? '📝' : '📄'} {c.title} <span className="text-xs capitalize text-slate-400">· {c.category}</span></span>
              <span>
                {cell.status === 'excused' ? <Badge>Excused</Badge>
                  : cell.value != null ? <strong>{num(cell.value)} / {cell.max}</strong>
                  : cell.counted_as_zero ? <Badge tone="rose">Missing (0)</Badge>
                  : cell.status === 'submitted' || cell.status === 'graded' ? <Badge tone="indigo">Awaiting grade</Badge>
                  : <Badge tone="amber">Not done</Badge>}
              </span>
            </li>
          )
        })}
        {!shown.length && <li className="px-5 py-6 text-center text-slate-500">No graded work yet.</li>}
      </ul>
      <p className="text-xs text-slate-400">Only grades your teacher has returned are counted here.</p>
    </div>
  )
}
