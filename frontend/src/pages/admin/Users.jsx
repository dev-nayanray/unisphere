import { useState } from 'react'
import { api, errorMessage } from '../../api/client'
import { useApi } from '../../api/useApi'
import { Badge, ErrorBox, Field, Loading, Modal, PageHeader } from '../../components/ui'

const ROLES = {
  student: 'Student',
  teacher: 'Teacher',
  department_head: 'Department Head',
  accounts: 'Accounts Officer',
  registrar: 'Registrar',
  super_admin: 'Super Admin',
}

const EMPTY = { name: '', email: '', role: 'student', password: '', program_id: '', student_id: '', batch: '', department_id: '', employee_id: '', designation: '' }

export default function Users() {
  const [role, setRole] = useState('')
  const [search, setSearch] = useState('')
  const { data, loading, error, reload } = useApi('/admin/users', { role, search })
  const programs = useApi('/programs')
  const departments = useApi('/departments')
  const [open, setOpen] = useState(false)
  const [form, setForm] = useState(EMPTY)
  const [formError, setFormError] = useState(null)
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })

  async function save(e) {
    e.preventDefault()
    try {
      await api.post('/admin/users', form)
      setOpen(false)
      setForm(EMPTY)
      reload()
    } catch (err) {
      setFormError(errorMessage(err))
    }
  }

  return (
    <div>
      <PageHeader title="Users" subtitle="Students, faculty and staff accounts" actions={<button className="btn-primary" onClick={() => setOpen(true)}>+ Add user</button>} />
      <div className="mb-4 flex flex-wrap gap-3">
        <input className="input max-w-xs" placeholder="Search name or email" value={search} onChange={(e) => setSearch(e.target.value)} />
        <select className="input max-w-xs" value={role} onChange={(e) => setRole(e.target.value)}>
          <option value="">All roles</option>
          {Object.entries(ROLES).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
        </select>
      </div>
      <ErrorBox message={error} />
      {loading ? (
        <Loading />
      ) : (
        <div className="card overflow-x-auto p-0">
          <table className="table">
            <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>ID</th><th>Program / Dept.</th><th>Status</th></tr></thead>
            <tbody>
              {data?.data?.map((u) => (
                <tr key={u.id}>
                  <td className="font-medium">{u.name}</td>
                  <td>{u.email}</td>
                  <td><Badge tone="indigo">{ROLES[u.role]}</Badge></td>
                  <td>{u.student?.student_id || u.faculty?.employee_id || '—'}</td>
                  <td>{u.student?.program?.code || u.faculty?.department?.code || '—'}</td>
                  <td>{u.is_active ? <Badge tone="emerald">Active</Badge> : <Badge tone="rose">Inactive</Badge>}</td>
                </tr>
              ))}
            </tbody>
          </table>
          <p className="px-5 py-3 text-xs text-slate-400">{data?.total} users</p>
        </div>
      )}

      <Modal open={open} title="Add user" onClose={() => setOpen(false)}>
        <form onSubmit={save}>
          <ErrorBox message={formError} />
          <div className="grid grid-cols-2 gap-3">
            <Field label="Full name"><input className="input" value={form.name} onChange={set('name')} required /></Field>
            <Field label="Email"><input type="email" className="input" value={form.email} onChange={set('email')} required /></Field>
            <Field label="Role">
              <select className="input" value={form.role} onChange={set('role')}>
                {Object.entries(ROLES).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
              </select>
            </Field>
            <Field label="Password (default: password)"><input type="password" className="input" value={form.password} onChange={set('password')} /></Field>
          </div>
          {form.role === 'student' && (
            <div className="grid grid-cols-3 gap-3">
              <Field label="Program">
                <select className="input" value={form.program_id} onChange={set('program_id')} required>
                  <option value="">Select…</option>
                  {programs.data?.map((p) => <option key={p.id} value={p.id}>{p.code}</option>)}
                </select>
              </Field>
              <Field label="Student ID"><input className="input" value={form.student_id} onChange={set('student_id')} required /></Field>
              <Field label="Batch"><input className="input" value={form.batch} onChange={set('batch')} /></Field>
            </div>
          )}
          {['teacher', 'department_head'].includes(form.role) && (
            <div className="grid grid-cols-3 gap-3">
              <Field label="Department">
                <select className="input" value={form.department_id} onChange={set('department_id')} required>
                  <option value="">Select…</option>
                  {departments.data?.map((d) => <option key={d.id} value={d.id}>{d.code}</option>)}
                </select>
              </Field>
              <Field label="Employee ID"><input className="input" value={form.employee_id} onChange={set('employee_id')} required /></Field>
              <Field label="Designation"><input className="input" value={form.designation} onChange={set('designation')} placeholder="Lecturer" /></Field>
            </div>
          )}
          <button className="btn-primary w-full">Create account</button>
        </form>
      </Modal>
    </div>
  )
}
