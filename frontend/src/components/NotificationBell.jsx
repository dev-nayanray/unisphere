import { useState } from 'react'
import { api } from '../api/client'
import { useApi } from '../api/useApi'
import { formatDate } from './ui'

export default function NotificationBell() {
  const [open, setOpen] = useState(false)
  const { data, reload } = useApi('/notifications')
  const unread = (data || []).filter((n) => !n.read_at).length

  async function markAll() {
    await api.post('/notifications/read-all')
    reload()
  }

  return (
    <div className="relative">
      <button onClick={() => setOpen(!open)} className="relative text-xl text-slate-500" aria-label="Notifications">
        🔔
        {unread > 0 && (
          <span className="absolute -top-1 -right-2 rounded-full bg-rose-500 px-1.5 text-[10px] font-semibold text-white">
            {unread}
          </span>
        )}
      </button>
      {open && (
        <div className="absolute right-0 z-50 mt-2 w-80 rounded-xl border border-slate-200 bg-white shadow-lg">
          <div className="flex items-center justify-between border-b border-slate-100 px-4 py-2">
            <span className="text-sm font-medium">Notifications</span>
            <button onClick={markAll} className="text-xs text-indigo-600">Mark all read</button>
          </div>
          <ul className="max-h-80 overflow-y-auto">
            {(data || []).length === 0 && <li className="px-4 py-6 text-center text-sm text-slate-400">No notifications</li>}
            {(data || []).map((n) => (
              <li key={n.id} className={`border-b border-slate-50 px-4 py-3 text-sm ${n.read_at ? '' : 'bg-indigo-50/50'}`}>
                <p>{n.data?.title}</p>
                <p className="mt-0.5 text-xs text-slate-400">{formatDate(n.created_at, true)}</p>
              </li>
            ))}
          </ul>
        </div>
      )}
    </div>
  )
}
