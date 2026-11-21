import { Navigate, Route, Routes } from 'react-router-dom'
import Layout from './components/Layout'
import { Loading } from './components/ui'
import { useAuth } from './context/AuthContext'
import Login from './pages/Login'
import Dashboard from './pages/Dashboard'
import Classes from './pages/Classes'
import Classroom from './pages/classroom/Classroom'
import AssignmentDetail from './pages/classroom/AssignmentDetail'
import Notices from './pages/Notices'
import Registration from './pages/Registration'
import Approvals from './pages/Approvals'
import Attendance from './pages/Attendance'
import Results from './pages/Results'
import Fees from './pages/Fees'
import Complaints from './pages/Complaints'
import Users from './pages/admin/Users'
import Academics from './pages/admin/Academics'
import Offerings from './pages/admin/Offerings'
import NotFound from './pages/NotFound'
import Profile from './pages/Profile'
import ForgotPassword from './pages/ForgotPassword'
import ResetPassword from './pages/ResetPassword'
import AuthCard from './components/AuthCard'
import PasswordForm from './components/PasswordForm'

function RequireAuth({ children, roles }) {
  const { user, ready, hasRole, logout } = useAuth()
  if (!ready) return <Loading />
  if (!user) return <Navigate to="/login" replace />

  // Accounts created by an admin must replace their temporary password first.
  if (user.must_change_password) {
    return (
      <AuthCard title="Choose your own password" subtitle="Your account was created with a temporary password.">
        <PasswordForm />
        <button className="mt-3 w-full text-center text-sm text-slate-500" onClick={logout}>Sign out</button>
      </AuthCard>
    )
  }

  if (roles && !hasRole(...roles)) return <Navigate to="/" replace />
  return children
}

export default function App() {
  return (
    <Routes>
      <Route path="/login" element={<Login />} />
      <Route path="/forgot-password" element={<ForgotPassword />} />
      <Route path="/reset-password" element={<ResetPassword />} />
      <Route
        element={
          <RequireAuth>
            <Layout />
          </RequireAuth>
        }
      >
        <Route index element={<Dashboard />} />
        <Route path="classes" element={<Classes />} />
        <Route path="classes/:id/*" element={<Classroom />} />
        <Route path="assignments/:id" element={<AssignmentDetail />} />
        <Route path="notices" element={<Notices />} />
        <Route path="complaints" element={<Complaints />} />
        <Route path="profile" element={<Profile />} />

        <Route path="registration" element={<RequireAuth roles={['student']}><Registration /></RequireAuth>} />
        <Route path="attendance" element={<RequireAuth roles={['student']}><Attendance /></RequireAuth>} />
        <Route path="results" element={<RequireAuth roles={['student']}><Results /></RequireAuth>} />
        <Route path="approvals" element={<RequireAuth roles={['teacher', 'department_head', 'registrar']}><Approvals /></RequireAuth>} />
        <Route path="fees" element={<RequireAuth roles={['student', 'accounts']}><Fees /></RequireAuth>} />

        <Route path="admin/users" element={<RequireAuth roles={['registrar']}><Users /></RequireAuth>} />
        <Route path="admin/academics" element={<RequireAuth roles={['registrar']}><Academics /></RequireAuth>} />
        <Route path="admin/offerings" element={<RequireAuth roles={['registrar']}><Offerings /></RequireAuth>} />
      </Route>
      <Route path="*" element={<NotFound />} />
    </Routes>
  )
}
