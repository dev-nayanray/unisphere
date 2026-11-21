import { useState } from 'react'
import { api, errorMessage } from '../../api/client'
import { useApi } from '../../api/useApi'
import { ErrorBox, Loading, PageHeader } from '../../components/ui'

/** Departments, programs, courses and semesters on one page. */
export default function Academics() {
  const departments = useApi('/departments')
  const programs = useApi('/programs')
  const courses = useApi('/admin/courses')
  const semesters = useApi('/semesters')

  return (
    <div>
      <PageHeader title="Departments & Courses" subtitle="The university's academic structure" />
      <div className="grid gap-6 lg:grid-cols-2">
        <Panel
          title="Departments"
          list={departments}
          columns={[['code', 'Code'], ['name', 'Name'], ['programs_count', 'Programs']]}
          fields={[['name', 'Name'], ['code', 'Code']]}
          endpoint="/admin/departments"
        />
        <Panel
          title="Programs"
          list={programs}
          columns={[['code', 'Code'], ['name', 'Name'], ['students_count', 'Students']]}
          fields={[['department_id', 'Department', departments.data], ['name', 'Name'], ['code', 'Code'], ['total_credits', 'Total credits']]}
          endpoint="/admin/programs"
        />
        <Panel
          title="Semesters"
          list={semesters}
          columns={[['name', 'Name'], ['start_date', 'Starts'], ['is_current', 'Current']]}
          fields={[['name', 'Name (e.g. Spring 2027)'], ['start_date', 'Start date', 'date'], ['end_date', 'End date', 'date']]}
          endpoint="/admin/semesters"
        />
        <Panel
          title="Courses"
          list={courses}
          columns={[['code', 'Code'], ['title', 'Title'], ['credit', 'Credit']]}
          fields={[['program_id', 'Program', programs.data], ['code', 'Code'], ['title', 'Title'], ['credit', 'Credit']]}
          endpoint="/admin/courses"
        />
      </div>
    </div>
  )
}

function Panel({ title, list, columns, fields, endpoint }) {
  const [form, setForm] = useState({})
  const [error, setError] = useState(null)

  async function add(e) {
    e.preventDefault()
    try {
      await api.post(endpoint, form)
      setForm({})
      setError(null)
      list.reload()
    } catch (err) {
      setError(errorMessage(err))
    }
  }

  const show = (v) => (typeof v === 'boolean' ? (v ? 'Yes' : '') : typeof v === 'string' && v.includes('T') ? v.slice(0, 10) : v)

  return (
    <section className="card">
      <h2 className="mb-3 font-semibold">{title}</h2>
      {list.loading ? (
        <Loading />
      ) : (
        <div className="max-h-72 overflow-y-auto">
          <table className="table">
            <thead><tr>{columns.map(([k, l]) => <th key={k}>{l}</th>)}</tr></thead>
            <tbody>
              {list.data?.map((row) => (
                <tr key={row.id}>{columns.map(([k]) => <td key={k}>{show(row[k])}</td>)}</tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <form onSubmit={add} className="mt-4 grid grid-cols-2 gap-2 border-t border-slate-100 pt-4">
        {fields.map(([k, label, extra]) =>
          Array.isArray(extra) ? (
            <select key={k} className="input" value={form[k] || ''} onChange={(e) => setForm({ ...form, [k]: e.target.value })} required>
              <option value="">{label}…</option>
              {extra.map((o) => <option key={o.id} value={o.id}>{o.code}</option>)}
            </select>
          ) : (
            <input
              key={k}
              type={extra === 'date' ? 'date' : 'text'}
              className="input"
              placeholder={label}
              value={form[k] || ''}
              onChange={(e) => setForm({ ...form, [k]: e.target.value })}
              required={k !== 'total_credits'}
            />
          )
        )}
        <button className="btn-primary col-span-2">Add {title.replace(/s$/, '').toLowerCase()}</button>
        <div className="col-span-2"><ErrorBox message={error} /></div>
      </form>
    </section>
  )
}
