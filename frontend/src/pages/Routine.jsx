import { useState } from 'react'
import { downloadFile } from '../api/client'
import { useApi } from '../api/useApi'
import Timetable from '../components/Timetable'
import { Empty, ErrorBox, Loading, PageHeader } from '../components/ui'
import { useAuth } from '../context/AuthContext'

/** Weekly class routine: my routine for students and teachers, room routine for staff. */
export default function Routine() {
  const { user, hasRole } = useAuth()
  const canSeeRooms = hasRole('registrar', 'department_head', 'teacher')
  const [view, setView] = useState(['student', 'teacher', 'department_head'].includes(user.role) ? 'mine' : 'room')
  const [room, setRoom] = useState('')

  const mine = useApi(view === 'mine' ? '/routine/me' : null)
  const rooms = useApi(canSeeRooms ? '/routine/rooms' : null)
  const roomRoutine = useApi(view === 'room' && room ? `/routine/rooms/${encodeURIComponent(room)}` : null)
  const active = view === 'mine' ? mine : roomRoutine

  return (
    <div className="print:p-0">
      <PageHeader
        title="Class Routine"
        subtitle={active.data?.semester ? `${active.data.semester.name}${view === 'room' && room ? ` · Room ${room}` : ''}` : 'Weekly timetable'}
        actions={
          <span className="flex flex-wrap gap-2 print:hidden">
            {canSeeRooms && (
              <select className="input" value={view === 'mine' ? '' : room} onChange={(e) => { setRoom(e.target.value); setView(e.target.value ? 'room' : 'mine') }}>
                <option value="">My routine</option>
                {rooms.data?.map((r) => <option key={r} value={r}>Room {r}</option>)}
              </select>
            )}
            <button className="btn-ghost" onClick={() => window.print()}>Print / PDF</button>
            {view === 'mine' && (
              <button className="btn-ghost" onClick={() => downloadFile('/routine/me.ics', 'unisphere-routine.ics')}>
                Add to calendar (.ics)
              </button>
            )}
          </span>
        }
      />

      <ErrorBox message={active.error} />
      {view === 'room' && !room ? (
        <Empty>Choose a room to see its weekly use.</Empty>
      ) : active.loading ? (
        <Loading />
      ) : active.data?.slots?.length ? (
        <>
          <Timetable slots={active.data.slots} />
          {user.role === 'student' && view === 'mine' && (
            <p className="mt-3 text-xs text-slate-400">Dashed blocks are waiting for advisor approval.</p>
          )}
          {active.data.unscheduled?.length > 0 && (
            <p className="mt-2 text-sm text-amber-700">No schedule set yet for: {active.data.unscheduled.join(', ')}.</p>
          )}
        </>
      ) : (
        <Empty>No classes scheduled.</Empty>
      )}
    </div>
  )
}
