import { useState } from 'react'
import { useApi } from '../../api/useApi'
import { useOptions } from '../../api/useOptions'
import CrudPage from '../../components/CrudPage'
import { Badge, PageHeader } from '../../components/ui'

const DAYS = ['Sat', 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri']
const CATEGORIES = { attendance: 'Attendance', assignment: 'Assignment', quiz: 'Class tests / quizzes', mid: 'Midterm', final: 'Final' }
const DEFAULT_SCHEME = { attendance: 10, assignment: 10, quiz: 10, mid: 20, final: 50 }
const COLORS = ['indigo', 'emerald', 'amber', 'rose', 'sky']

/** Course offering: each section opened here becomes a virtual classroom. */
export default function Offerings() {
  const semesters = useApi('/semesters')
  const current = semesters.data?.find((s) => s.is_current)
  const [chosen, setChosen] = useState('')
  const semesterId = chosen || current?.id || ''

  const courses = useOptions('/admin/courses', (c) => `${c.code} · ${c.title}`)
  const teachers = useOptions('/admin/users', (u) => u.name, { role: 'teacher,department_head', per_page: 200 })

  return (
    <div>
      <PageHeader
        title="Course Offering"
        subtitle="Open sections for a semester, assign teachers, rooms, the weekly schedule and grading weights"
        actions={
          <select className="input" value={semesterId} onChange={(e) => setChosen(e.target.value)}>
            {semesters.data?.map((s) => <option key={s.id} value={s.id}>{s.name}{s.is_current ? ' (current)' : ''}</option>)}
          </select>
        }
      />

      {semesterId && (
        <CrudPage
          key={semesterId}
          title="section"
          endpoint="/admin/sections"
          params={{ semester_id: semesterId }}
          searchPlaceholder="Search course"
          defaults={{ semester_id: semesterId, section: 'A', capacity: 40, cover_color: 'indigo', schedule: [], grading_scheme: DEFAULT_SCHEME }}
          fields={[
            { name: 'course_id', label: 'Course', type: 'select', required: true, options: courses },
            { name: 'teacher_id', label: 'Teacher', type: 'select', options: teachers, placeholder: 'Assign later' },
            { name: 'section', label: 'Section', required: true, span: 1 },
            { name: 'room', label: 'Room', span: 1 },
            { name: 'capacity', label: 'Seats', type: 'number', span: 1 },
            { name: 'cover_color', label: 'Class colour', type: 'select', span: 1, options: COLORS.map((c) => ({ value: c, label: c })) },
            { name: 'schedule', label: 'Weekly schedule', render: ScheduleEditor },
            { name: 'grading_scheme', label: 'Grading weights (must total 100)', render: SchemeEditor },
          ]}
          toForm={(s) => ({
            semester_id: s.semester_id,
            course_id: s.course_id,
            teacher_id: s.teacher_id,
            section: s.section,
            room: s.room,
            capacity: s.capacity,
            cover_color: s.cover_color,
            schedule: s.schedule || [],
            grading_scheme: s.grading_scheme || DEFAULT_SCHEME,
          })}
          columns={[
            { key: 'course_id', label: 'Course', sortable: true, render: (s) => <span><b>{s.course.code}</b> {s.course.title}</span> },
            { key: 'section', label: 'Sec.', sortable: true },
            { key: 'teacher', label: 'Teacher', render: (s) => s.teacher?.name || <Badge tone="amber">Unassigned</Badge> },
            { key: 'room', label: 'Room', sortable: true },
            { key: 'schedule', label: 'Schedule', render: (s) => <span className="text-xs">{(s.schedule || []).map((t) => `${t.day} ${t.start}–${t.end}`).join(', ') || '—'}</span> },
            { key: 'capacity', label: 'Enrolled', sortable: true, render: (s) => `${s.students_count}/${s.capacity}` },
            { key: 'join_code', label: 'Class code', render: (s) => <span className="font-mono text-xs">{s.join_code}</span> },
          ]}
          deleteMessage={(s) => `Delete ${s.course.code} section ${s.section}? Sections with registrations cannot be deleted.`}
        />
      )}
    </div>
  )
}

/** Rows of day + start + end time. */
function ScheduleEditor({ value, set, error }) {
  const rows = Array.isArray(value) ? value : []
  const update = (i, key, v) => set(rows.map((r, j) => (j === i ? { ...r, [key]: v } : r)))

  return (
    <div className={`space-y-2 rounded-lg border p-3 ${error ? 'border-rose-300' : 'border-slate-200'}`}>
      {rows.map((r, i) => (
        <div key={i} className="flex items-center gap-2">
          <select className="input w-24" value={r.day} onChange={(e) => update(i, 'day', e.target.value)}>
            {DAYS.map((d) => <option key={d}>{d}</option>)}
          </select>
          <input type="time" className="input" value={r.start} onChange={(e) => update(i, 'start', e.target.value)} required />
          <span className="text-slate-400">to</span>
          <input type="time" className="input" value={r.end} onChange={(e) => update(i, 'end', e.target.value)} required />
          <button type="button" className="px-2 text-rose-500" onClick={() => set(rows.filter((_, j) => j !== i))} aria-label="Remove">✕</button>
        </div>
      ))}
      <button type="button" className="text-sm text-indigo-600" onClick={() => set([...rows, { day: 'Sun', start: '09:00', end: '10:30' }])}>
        + Add class time
      </button>
    </div>
  )
}

/** One number per grading category, with a live total. */
function SchemeEditor({ value, set, error }) {
  const scheme = { ...DEFAULT_SCHEME, ...(value || {}) }
  const total = Object.values(scheme).reduce((t, v) => t + Number(v || 0), 0)

  return (
    <div className={`rounded-lg border p-3 ${error ? 'border-rose-300' : 'border-slate-200'}`}>
      <div className="grid grid-cols-5 gap-2">
        {Object.entries(CATEGORIES).map(([key, label]) => (
          <label key={key} className="text-xs text-slate-500">
            {label}
            <input
              type="number"
              min="0"
              max="100"
              className="input mt-1"
              value={scheme[key]}
              onChange={(e) => set({ ...scheme, [key]: Number(e.target.value) })}
            />
          </label>
        ))}
      </div>
      <p className={`mt-2 text-sm font-medium ${total === 100 ? 'text-emerald-600' : 'text-rose-600'}`}>Total: {total} / 100</p>
    </div>
  )
}
