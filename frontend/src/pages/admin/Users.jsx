import { useState } from 'react'
import { api, errorMessage } from '../../api/client'
import { useOptions } from '../../api/useOptions'
import CrudPage from '../../components/CrudPage'
import { useToast } from '../../components/Toast'
import { Badge, Modal, PageHeader } from '../../components/ui'

export const ROLES = {
  student: 'Student',
  teacher: 'Teacher',
  department_head: 'Department Head',
  accounts: 'Accounts Officer',
  registrar: 'Registrar',
  super_admin: 'Super Admin',
}

const isFaculty = (v) => ['teacher', 'department_head'].includes(v.role)

export default function Users() {
  const toast = useToast()
  const [role, setRole] = useState('')
  const [active, setActive] = useState('')
  const [temporary, setTemporary] = useState(null)

  const programs = useOptions('/programs', (p) => p.code)
  const departments = useOptions('/departments', (d) => d.code)
  const teachers = useOptions('/admin/users', (u) => u.name, { role: 'teacher,department_head', per_page: 200 })

  async function toggleActive(user, reload) {
    try {
      await api.put(`/admin/users/${user.id}`, { is_active: !user.is_active })
      toast.success(`${user.name} ${user.is_active ? 'deactivated' : 'activated'}.`)
      reload()
    } catch (err) {
      toast.error(errorMessage(err))
    }
  }

  async function resetPassword(user) {
    try {
      const res = await api.post(`/admin/users/${user.id}/reset-password`)
      setTemporary({ name: user.name, password: res.data.temporary_password })
    } catch (err) {
      toast.error(errorMessage(err))
    }
  }

  const fields = [
    { name: 'name', label: 'Full name', required: true, span: 1 },
    { name: 'email', label: 'Email', type: 'email', required: true, span: 1 },
    { name: 'phone', label: 'Mobile', span: 1, placeholder: '01712345678' },
    { name: 'role', label: 'Role', type: 'select', required: true, span: 1, options: Object.entries(ROLES).map(([value, label]) => ({ value, label })) },
    // Student profile
    { name: 'program_id', label: 'Program', type: 'select', required: true, span: 1, options: programs, show: (v) => v.role === 'student' },
    { name: 'student_id', label: 'Student ID', required: true, span: 1, show: (v) => v.role === 'student' },
    { name: 'batch', label: 'Batch', span: 1, placeholder: '2022', show: (v) => v.role === 'student' },
    { name: 'advisor_id', label: 'Advisor', type: 'select', span: 1, options: teachers, show: (v) => v.role === 'student' },
    // Faculty profile
    { name: 'department_id', label: 'Department', type: 'select', required: true, span: 1, options: departments, show: isFaculty },
    { name: 'employee_id', label: 'Employee ID', required: true, span: 1, show: isFaculty },
    { name: 'designation', label: 'Designation', placeholder: 'Lecturer', show: isFaculty },
  ]

  return (
    <div>
      <PageHeader title="Users" subtitle="Students, faculty and staff accounts. New accounts get a temporary password." />

      <CrudPage
        title="user"
        endpoint="/admin/users"
        params={{ role, is_active: active }}
        defaults={{ role: 'student' }}
        toForm={(u) => ({
          name: u.name,
          email: u.email,
          phone: u.phone,
          role: u.role,
          program_id: u.student?.program_id,
          student_id: u.student?.student_id,
          batch: u.student?.batch,
          advisor_id: u.student?.advisor_id,
          department_id: u.faculty?.department_id,
          employee_id: u.faculty?.employee_id,
          designation: u.faculty?.designation,
        })}
        fields={fields}
        onSaved={(data, isNew) => isNew && data.temporary_password && setTemporary({ name: data.name, password: data.temporary_password })}
        deleteMessage={(u) => `Delete ${u.name}? Accounts with registrations or classes can only be deactivated.`}
        toolbar={
          <>
            <select className="input max-w-44" value={role} onChange={(e) => setRole(e.target.value)}>
              <option value="">All roles</option>
              {Object.entries(ROLES).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select>
            <select className="input max-w-36" value={active} onChange={(e) => setActive(e.target.value)}>
              <option value="">Any status</option>
              <option value="1">Active</option>
              <option value="0">Inactive</option>
            </select>
          </>
        }
        columns={[
          { key: 'name', label: 'Name', sortable: true, render: (u) => <span className="font-medium">{u.name}</span> },
          { key: 'email', label: 'Email', sortable: true },
          { key: 'role', label: 'Role', sortable: true, render: (u) => <Badge tone="indigo">{ROLES[u.role]}</Badge> },
          { key: 'ident', label: 'ID', render: (u) => u.student?.student_id || u.faculty?.employee_id || '—' },
          { key: 'unit', label: 'Program / Dept.', render: (u) => u.student?.program?.code || u.faculty?.department?.code || '—' },
          { key: 'advisor', label: 'Advisor', render: (u) => u.student?.advisor?.name || '—' },
          { key: 'is_active', label: 'Status', render: (u) => (u.is_active ? <Badge tone="emerald">Active</Badge> : <Badge tone="rose">Inactive</Badge>) },
        ]}
        extraActions={(u, reload) => (
          <>
            <button className="text-slate-600 hover:underline" onClick={() => toggleActive(u, reload)}>
              {u.is_active ? 'Deactivate' : 'Activate'}
            </button>
            <button className="text-slate-600 hover:underline" onClick={() => resetPassword(u)}>Reset password</button>
          </>
        )}
      />

      <Modal open={Boolean(temporary)} title="Temporary password" onClose={() => setTemporary(null)}>
        <p className="text-sm text-slate-600">
          Give this password to <b>{temporary?.name}</b>. It is shown only once, and they must change it when they sign in.
        </p>
        <p className="mt-4 rounded-lg bg-slate-100 px-4 py-3 text-center font-mono text-lg tracking-wider">{temporary?.password}</p>
        <button className="btn-primary mt-4 w-full" onClick={() => navigator.clipboard?.writeText(temporary.password)}>Copy</button>
      </Modal>
    </div>
  )
}
