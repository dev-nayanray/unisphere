import { useState } from 'react'
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { useAuth } from '../context/AuthContext'
import ErrorBoundary from './ErrorBoundary'
import NotificationBell from './NotificationBell'

// Each menu item lists the roles that can see it ("*" = everyone).
const NAV = [
  { section: 'Main' },
  { to: '/', label: 'Dashboard', icon: '▦', roles: ['*'] },
  { to: '/classes', label: 'My Classes', icon: '◧', roles: ['student', 'teacher', 'department_head', 'registrar'] },
  { to: '/notices', label: 'Notices', icon: '✉', roles: ['*'] },
  { section: 'Academic' },
  { to: '/registration', label: 'Course Registration', icon: '✎', roles: ['student'] },
  { to: '/approvals', label: 'Registration Approvals', icon: '✓', roles: ['teacher', 'department_head', 'registrar'] },
  { to: '/attendance', label: 'Attendance Check-in', icon: '◉', roles: ['student'] },
  { to: '/results', label: 'Results & Transcript', icon: '★', roles: ['student'] },
  { section: 'Services' },
  { to: '/fees', label: 'Fees & Payments', icon: '৳', roles: ['student', 'accounts'] },
  { to: '/complaints', label: 'Help Desk', icon: '?', roles: ['*'] },
  { section: 'Administration', roles: ['registrar'] },
  { to: '/admin/users', label: 'Users', icon: '☺', roles: ['registrar'] },
  { to: '/admin/academics', label: 'Departments & Courses', icon: '▤', roles: ['registrar'] },
  { to: '/admin/offerings', label: 'Course Offering', icon: '⊞', roles: ['registrar'] },
]

export default function Layout() {
  const { user, logout, hasRole } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [open, setOpen] = useState(false)

  const visible = (roles) => !roles || roles.includes('*') || hasRole(...roles)

  async function handleLogout() {
    await logout()
    navigate('/login')
  }

  return (
    <div className="flex min-h-screen">
      {/* Sidebar */}
      <aside
        className={`fixed inset-y-0 left-0 z-40 w-64 transform border-r border-slate-200 bg-white transition md:static md:translate-x-0 ${
          open ? 'translate-x-0' : '-translate-x-full'
        }`}
      >
        <div className="flex h-16 items-center gap-2 border-b border-slate-200 px-5">
          <img src="/icon.svg" alt="" className="h-8 w-8" />
          <span className="text-lg font-semibold text-slate-900">UniSphere</span>
        </div>
        <nav className="space-y-0.5 overflow-y-auto p-3" onClick={() => setOpen(false)}>
          {NAV.map((item, i) =>
            item.section ? (
              visible(item.roles) && (
                <p key={i} className="px-3 pt-4 pb-1 text-xs font-semibold uppercase tracking-wide text-slate-400">
                  {item.section}
                </p>
              )
            ) : (
              visible(item.roles) && (
                <NavLink
                  key={item.to}
                  to={item.to}
                  end={item.to === '/'}
                  className={({ isActive }) =>
                    `flex items-center gap-3 rounded-lg px-3 py-2 text-sm ${
                      isActive ? 'bg-indigo-50 font-medium text-indigo-700' : 'text-slate-600 hover:bg-slate-50'
                    }`
                  }
                >
                  <span className="w-5 text-center">{item.icon}</span>
                  {item.label}
                </NavLink>
              )
            )
          )}
        </nav>
      </aside>

      {open && <div className="fixed inset-0 z-30 bg-slate-900/30 md:hidden" onClick={() => setOpen(false)} />}

      {/* Main column */}
      <div className="flex min-w-0 flex-1 flex-col">
        <header className="sticky top-0 z-20 flex h-16 items-center justify-between border-b border-slate-200 bg-white/90 px-4 backdrop-blur md:px-8">
          <button className="text-2xl md:hidden" onClick={() => setOpen(true)} aria-label="Open menu">
            ☰
          </button>
          <div className="hidden md:block" />
          <div className="flex items-center gap-4">
            <NotificationBell />
            <div className="text-right">
              <p className="text-sm font-medium text-slate-900">{user?.name}</p>
              <p className="text-xs text-slate-500">{user?.role_label}</p>
            </div>
            <button onClick={handleLogout} className="btn-ghost py-1.5">
              Log out
            </button>
          </div>
        </header>
        <main className="flex-1 p-4 md:p-8">
          {/* Reset the boundary on navigation so one broken page doesn't stick. */}
          <ErrorBoundary key={location.pathname}>
            <Outlet />
          </ErrorBoundary>
        </main>
      </div>
    </div>
  )
}
