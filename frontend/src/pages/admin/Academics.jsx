import { useState } from 'react'
import { api, errorMessage } from '../../api/client'
import { useApi } from '../../api/useApi'
import { useOptions } from '../../api/useOptions'
import CrudPage from '../../components/CrudPage'
import { useToast } from '../../components/Toast'
import { Badge, Loading, Modal, PageHeader, formatDate } from '../../components/ui'

const TABS = [
  ['departments', 'Departments'],
  ['programs', 'Programs'],
  ['courses', 'Courses'],
  ['semesters', 'Semesters'],
]

/** Departments, programs, courses and semesters, one tab each. */
export default function Academics() {
  const [tab, setTab] = useState('departments')

  return (
    <div>
      <PageHeader title="Academic Structure" subtitle="Departments, programs, courses and semesters" />
      <div className="mb-5 flex gap-1 border-b border-slate-200">
        {TABS.map(([key, label]) => (
          <button
            key={key}
            onClick={() => setTab(key)}
            className={`border-b-2 px-4 py-2 text-sm font-medium ${tab === key ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-500'}`}
          >
            {label}
          </button>
        ))}
      </div>
      {tab === 'departments' && <Departments />}
      {tab === 'programs' && <Programs />}
      {tab === 'courses' && <Courses />}
      {tab === 'semesters' && <Semesters />}
    </div>
  )
}

function Departments() {
  const heads = useOptions('/admin/users', (u) => `${u.name} (${u.role === 'department_head' ? 'Head' : 'Teacher'})`, {
    role: 'teacher,department_head',
    per_page: 200,
  })

  return (
    <CrudPage
      title="department"
      endpoint="/admin/departments"
      listUrl="/departments"
      fields={[
        { name: 'name', label: 'Name', required: true },
        { name: 'code', label: 'Code', required: true, span: 1, placeholder: 'CSE' },
        { name: 'head_id', label: 'Department head', type: 'select', span: 1, options: heads, placeholder: 'None' },
      ]}
      toForm={(d) => ({ name: d.name, code: d.code, head_id: d.head_id })}
      columns={[
        { key: 'code', label: 'Code', sortable: true, render: (d) => <b>{d.code}</b> },
        { key: 'name', label: 'Name', sortable: true },
        { key: 'head', label: 'Head', render: (d) => d.head?.name || '—' },
        { key: 'programs_count', label: 'Programs' },
        { key: 'faculty_members_count', label: 'Faculty' },
      ]}
    />
  )
}

function Programs() {
  const departments = useOptions('/departments', (d) => `${d.code} · ${d.name}`)

  return (
    <CrudPage
      title="program"
      endpoint="/admin/programs"
      listUrl="/programs"
      defaults={{ total_credits: 160 }}
      fields={[
        { name: 'department_id', label: 'Department', type: 'select', required: true, options: departments },
        { name: 'name', label: 'Name', required: true, placeholder: 'B.Sc. in CSE' },
        { name: 'code', label: 'Code', required: true, span: 1 },
        { name: 'total_credits', label: 'Total credits', type: 'number', span: 1 },
      ]}
      toForm={(p) => ({ department_id: p.department_id, name: p.name, code: p.code, total_credits: p.total_credits })}
      columns={[
        { key: 'code', label: 'Code', sortable: true, render: (p) => <b>{p.code}</b> },
        { key: 'name', label: 'Name', sortable: true },
        { key: 'department', label: 'Department', render: (p) => p.department?.code },
        { key: 'total_credits', label: 'Credits', sortable: true },
        { key: 'courses_count', label: 'Courses' },
        { key: 'students_count', label: 'Students' },
      ]}
    />
  )
}

function Courses() {
  const programs = useOptions('/programs', (p) => p.code)
  const courses = useOptions('/admin/courses', (c) => `${c.code} · ${c.title}`)
  const [usage, setUsage] = useState(null)

  return (
    <>
      <CrudPage
        title="course"
        endpoint="/admin/courses"
        defaults={{ credit: 3 }}
        fields={[
          { name: 'program_id', label: 'Program', type: 'select', required: true, span: 1, options: programs },
          { name: 'credit', label: 'Credit', type: 'number', required: true, span: 1 },
          { name: 'code', label: 'Code', required: true, span: 1, placeholder: 'CSE 4101' },
          { name: 'prerequisite_id', label: 'Prerequisite', type: 'select', span: 1, options: courses, placeholder: 'None' },
          { name: 'title', label: 'Title', required: true },
          { name: 'description', label: 'Description', type: 'textarea' },
        ]}
        toForm={(c) => ({
          program_id: c.program_id, credit: c.credit, code: c.code, prerequisite_id: c.prerequisite_id,
          title: c.title, description: c.description,
        })}
        columns={[
          { key: 'code', label: 'Code', sortable: true, render: (c) => <b>{c.code}</b> },
          { key: 'title', label: 'Title', sortable: true },
          { key: 'credit', label: 'Credit', sortable: true },
          { key: 'program', label: 'Program', render: (c) => c.program?.code },
          { key: 'prerequisite', label: 'Prerequisite', render: (c) => c.prerequisite?.code || '—' },
          { key: 'sections_count', label: 'Sections' },
        ]}
        extraActions={(c) => (
          <button className="text-slate-600 hover:underline" onClick={() => setUsage(c.id)}>Usage</button>
        )}
      />
      <CourseUsage id={usage} onClose={() => setUsage(null)} />
    </>
  )
}

/** Where a course is used: its sections in every semester and courses that require it. */
function CourseUsage({ id, onClose }) {
  const { data, loading } = useApi(id ? `/admin/courses/${id}` : null)

  return (
    <Modal open={Boolean(id)} title={data ? `${data.code} · ${data.title}` : 'Course usage'} onClose={onClose}>
      {loading || !data ? (
        <Loading />
      ) : (
        <div className="space-y-4 text-sm">
          <p>Program: <b>{data.program?.code}</b> · Prerequisite: <b>{data.prerequisite?.code || 'none'}</b></p>
          <div>
            <p className="mb-1 font-medium">Sections</p>
            {data.sections.length ? (
              <ul className="divide-y divide-slate-100">
                {data.sections.map((s) => (
                  <li key={s.id} className="flex justify-between py-1.5">
                    <span>{s.semester?.name} · Section {s.section}</span>
                    <span className="text-slate-500">{s.teacher?.name || 'No teacher'} · {s.students_count} students</span>
                  </li>
                ))}
              </ul>
            ) : <p className="text-slate-500">Not offered yet.</p>}
          </div>
          <div>
            <p className="mb-1 font-medium">Required by</p>
            <p className="text-slate-600">{data.required_by.length ? data.required_by.map((c) => c.code).join(', ') : 'No other course.'}</p>
          </div>
        </div>
      )}
    </Modal>
  )
}

function Semesters() {
  const toast = useToast()

  async function run(request, done) {
    try {
      const res = await request()
      toast.success(res.data.message || 'Saved.')
      done()
    } catch (err) {
      toast.error(errorMessage(err))
    }
  }

  return (
    <CrudPage
      title="semester"
      endpoint="/admin/semesters"
      listUrl="/semesters"
      fields={[
        { name: 'name', label: 'Name', required: true, placeholder: 'Spring 2027' },
        { name: 'start_date', label: 'Starts', type: 'date', required: true, span: 1 },
        { name: 'end_date', label: 'Ends', type: 'date', required: true, span: 1 },
        { name: 'registration_deadline', label: 'Add/drop deadline', type: 'date', span: 1 },
        { name: 'registration_open', label: 'Registration open', type: 'checkbox', span: 1 },
      ]}
      toForm={(s) => ({
        name: s.name,
        start_date: s.start_date?.slice(0, 10),
        end_date: s.end_date?.slice(0, 10),
        registration_deadline: s.registration_deadline?.slice(0, 10),
        registration_open: s.registration_open,
      })}
      columns={[
        { key: 'name', label: 'Name', sortable: true, render: (s) => <b>{s.name}</b> },
        { key: 'start_date', label: 'Starts', sortable: true, render: (s) => formatDate(s.start_date) },
        { key: 'end_date', label: 'Ends', sortable: true, render: (s) => formatDate(s.end_date) },
        { key: 'registration_deadline', label: 'Add/drop until', render: (s) => formatDate(s.registration_deadline) },
        { key: 'state', label: 'Status', render: (s) => (
          <span className="space-x-1">
            {s.is_current && <Badge tone="indigo">Current</Badge>}
            <Badge tone={s.registration_open ? 'emerald' : 'slate'}>{s.registration_open ? 'Registration open' : 'Closed'}</Badge>
          </span>
        ) },
        { key: 'sections_count', label: 'Sections' },
      ]}
      extraActions={(s, reload) => (
        <>
          {!s.is_current && (
            <button className="text-slate-600 hover:underline" onClick={() => run(() => api.post(`/admin/semesters/${s.id}/make-current`), reload)}>
              Make current
            </button>
          )}
          <button
            className="text-slate-600 hover:underline"
            onClick={() => run(() => api.put(`/admin/semesters/${s.id}`, { registration_open: !s.registration_open }), reload)}
          >
            {s.registration_open ? 'Close registration' : 'Open registration'}
          </button>
        </>
      )}
    />
  )
}
