/* Small shared UI pieces used across pages. */

export function PageHeader({ title, subtitle, actions }) {
  return (
    <div className="mb-6 flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 className="text-2xl font-semibold text-slate-900">{title}</h1>
        {subtitle && <p className="mt-1 text-sm text-slate-500">{subtitle}</p>}
      </div>
      {actions && <div className="flex gap-2">{actions}</div>}
    </div>
  )
}

export function StatCard({ label, value, hint, tone = 'indigo' }) {
  const tones = {
    indigo: 'text-indigo-600',
    emerald: 'text-emerald-600',
    amber: 'text-amber-600',
    rose: 'text-rose-600',
  }
  return (
    <div className="card">
      <p className="text-sm text-slate-500">{label}</p>
      <p className={`mt-1 text-3xl font-semibold ${tones[tone]}`}>{value ?? '—'}</p>
      {hint && <p className="mt-1 text-xs text-slate-400">{hint}</p>}
    </div>
  )
}

export function Badge({ children, tone = 'slate' }) {
  const tones = {
    slate: 'bg-slate-100 text-slate-700',
    indigo: 'bg-indigo-50 text-indigo-700',
    emerald: 'bg-emerald-50 text-emerald-700',
    amber: 'bg-amber-50 text-amber-700',
    rose: 'bg-rose-50 text-rose-700',
  }
  return <span className={`inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium ${tones[tone]}`}>{children}</span>
}

export const statusTone = (status) =>
  ({
    approved: 'emerald', paid: 'emerald', resolved: 'emerald', present: 'emerald', success: 'emerald',
    pending: 'amber', partial: 'amber', in_progress: 'indigo', late: 'amber', waitlisted: 'amber',
    rejected: 'rose', unpaid: 'rose', absent: 'rose', failed: 'rose', dropped: 'slate',
  })[status] || 'slate'

export function Loading() {
  return <div className="py-10 text-center text-sm text-slate-400">Loading…</div>
}

export function ErrorBox({ message }) {
  if (!message) return null
  return <div className="mb-4 rounded-lg border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">{message}</div>
}

export function Empty({ children }) {
  return <div className="rounded-xl border border-dashed border-slate-300 py-10 text-center text-sm text-slate-500">{children}</div>
}

export function Modal({ open, title, onClose, children }) {
  if (!open) return null
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 p-4" onClick={onClose}>
      <div className="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl" onClick={(e) => e.stopPropagation()}>
        <div className="mb-4 flex items-center justify-between">
          <h2 className="text-lg font-semibold">{title}</h2>
          <button onClick={onClose} className="text-slate-400 hover:text-slate-600" aria-label="Close">✕</button>
        </div>
        {children}
      </div>
    </div>
  )
}

export function Field({ label, children }) {
  return (
    <label className="mb-3 block">
      <span className="label">{label}</span>
      {children}
    </label>
  )
}

export function Avatar({ user, size = 'sm' }) {
  const sizes = { sm: 'h-9 w-9 text-sm', lg: 'h-24 w-24 text-3xl' }
  if (user?.avatar_url) {
    return <img src={user.avatar_url} alt="" className={`${sizes[size]} mx-auto rounded-full object-cover`} />
  }
  return (
    <div className={`${sizes[size]} mx-auto flex items-center justify-center rounded-full bg-indigo-100 font-semibold text-indigo-700`}>
      {user?.name?.[0]}
    </div>
  )
}

export const formatDate =(value, withTime = false) =>
  value
    ? new Date(value).toLocaleString('en-GB', {
        day: 'numeric', month: 'short', year: 'numeric',
        ...(withTime ? { hour: '2-digit', minute: '2-digit' } : {}),
      })
    : '—'

export const taka = (n) => `৳${Number(n || 0).toLocaleString('en-IN', { maximumFractionDigits: 2 })}`
