import { Link } from 'react-router-dom'
import { useApi } from '../api/useApi'
import { Badge, ErrorBox, Loading, PageHeader, StatCard, formatDate, taka } from '../components/ui'
import { useAuth } from '../context/AuthContext'

export default function Dashboard() {
  const { user } = useAuth()
  const { data, loading, error } = useApi('/dashboard')

  if (loading) return <Loading />
  const s = data?.stats || {}

  return (
    <div>
      <PageHeader
        title={`Welcome, ${user.name.split(' ')[0]}`}
        subtitle={data?.semester ? `${data.semester.name} · ${user.role_label}` : user.role_label}
      />
      <ErrorBox message={error} />

      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        {user.role === 'student' && (
          <>
            <StatCard label="Enrolled courses" value={s.enrolled_courses} />
            <StatCard label="Pending work" value={s.pending_work} tone="amber" />
            <StatCard label="CGPA" value={s.cgpa?.toFixed(2)} tone="emerald" />
            <StatCard
              label="Attendance"
              value={s.attendance_rate != null ? `${s.attendance_rate}%` : '—'}
              tone={s.attendance_rate != null && s.attendance_rate < 75 ? 'rose' : 'emerald'}
              hint="Minimum 75% required"
            />
          </>
        )}
        {user.role === 'teacher' && (
          <>
            <StatCard label="My classes" value={s.classes} />
            <StatCard label="Students" value={s.students} tone="emerald" />
            <StatCard label="Waiting to be graded" value={s.to_grade} tone="amber" />
          </>
        )}
        {user.role === 'accounts' && (
          <>
            <StatCard label="Unpaid invoices" value={s.unpaid_invoices} tone="rose" />
            <StatCard label="Collected this month" value={taka(s.collected_this_month)} tone="emerald" />
            <StatCard label="Pending verifications" value={s.pending_verifications} tone="amber" />
          </>
        )}
        {['super_admin', 'registrar', 'department_head'].includes(user.role) && (
          <>
            <StatCard label="Students" value={s.students} />
            <StatCard label="Faculty" value={s.faculty} tone="emerald" />
            <StatCard label="Pending registrations" value={s.pending_registrations} tone="amber" />
            <StatCard label="At-risk students" value={s.at_risk_students} tone="rose" hint="CGPA below 2.50" />
          </>
        )}
      </div>

      <div className="mt-6 grid gap-6 lg:grid-cols-3">
        <div className="card lg:col-span-2">
          {user.role === 'student' && (
            <>
              <h2 className="mb-3 font-semibold">To do</h2>
              {data?.todo?.length ? (
                <ul className="divide-y divide-slate-100">
                  {data.todo.map((a) => (
                    <li key={a.id} className="flex items-center justify-between py-3">
                      <Link to={`/assignments/${a.id}`} className="hover:text-indigo-600">
                        <p className="font-medium">{a.title}</p>
                        <p className="text-xs text-slate-500">{a.section?.course?.code} · {a.section?.course?.title}</p>
                      </Link>
                      <span className="text-xs text-slate-500">Due {formatDate(a.due_at, true)}</span>
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="text-sm text-slate-500">You're all caught up. 🎉</p>
              )}
              <p className="mt-4 text-sm text-slate-500">Fees due: <b>{taka(s.fees_due)}</b></p>
            </>
          )}
          {user.role === 'teacher' && (
            <>
              <h2 className="mb-3 font-semibold">This semester's classes</h2>
              <ul className="divide-y divide-slate-100">
                {data?.classes?.map((c) => (
                  <li key={c.id} className="flex items-center justify-between py-3">
                    <Link to={`/classes/${c.id}`} className="font-medium hover:text-indigo-600">
                      {c.course.code} · {c.course.title} ({c.section})
                    </Link>
                    <span className="text-xs text-slate-500">{c.students_count} students</span>
                  </li>
                ))}
              </ul>
            </>
          )}
          {!['student', 'teacher'].includes(user.role) && (
            <>
              <h2 className="mb-3 font-semibold">Quick actions</h2>
              <div className="flex flex-wrap gap-2">
                <Link to="/notices" className="btn-ghost">Post a notice</Link>
                <Link to="/complaints" className="btn-ghost">Help desk ({s.open_complaints ?? 0} open)</Link>
                {user.role !== 'accounts' && <Link to="/approvals" className="btn-ghost">Approve registrations</Link>}
                {user.role === 'accounts' && <Link to="/fees" className="btn-ghost">Manage invoices</Link>}
              </div>
            </>
          )}
        </div>

        <div className="card">
          <div className="mb-3 flex items-center justify-between">
            <h2 className="font-semibold">Latest notices</h2>
            <Link to="/notices" className="text-xs text-indigo-600">View all</Link>
          </div>
          <ul className="space-y-3">
            {data?.notices?.map((n) => (
              <li key={n.id}>
                <p className="text-sm font-medium">
                  {n.is_urgent && <Badge tone="rose">Urgent</Badge>} {n.title}
                </p>
                <p className="text-xs text-slate-400">{formatDate(n.published_at)}</p>
              </li>
            ))}
          </ul>
        </div>
      </div>
    </div>
  )
}
