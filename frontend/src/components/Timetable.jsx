/**
 * Weekly timetable grid: days as columns (Sat–Thu, Fri only if used), 08:00–17:00 as rows.
 * slots: [{ section_id, code, title, section, room, teacher, color, day, start, end, status? }]
 */
const DAYS = ['Sat', 'Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri']
const START_HOUR = 8
const END_HOUR = 17
const HOUR_PX = 56

const COLORS = {
  indigo: 'border-indigo-300 bg-indigo-50 text-indigo-900',
  emerald: 'border-emerald-300 bg-emerald-50 text-emerald-900',
  amber: 'border-amber-300 bg-amber-50 text-amber-900',
  rose: 'border-rose-300 bg-rose-50 text-rose-900',
  sky: 'border-sky-300 bg-sky-50 text-sky-900',
}

const minutes = (hhmm) => {
  const [h, m] = hhmm.split(':').map(Number)
  return h * 60 + m
}

export default function Timetable({ slots = [], highlightToday = true }) {
  const days = DAYS.filter((d) => d !== 'Fri' || slots.some((s) => s.day === 'Fri'))
  const first = Math.min(START_HOUR, ...slots.map((s) => Math.floor(minutes(s.start) / 60)))
  const last = Math.max(END_HOUR, ...slots.map((s) => Math.ceil(minutes(s.end) / 60)))
  const hours = Array.from({ length: last - first }, (_, i) => first + i)
  const today = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'][new Date().getDay()]

  return (
    <div className="card overflow-x-auto p-0">
      <div className="grid min-w-[720px]" style={{ gridTemplateColumns: `56px repeat(${days.length}, 1fr)` }}>
        <div />
        {days.map((d) => (
          <div
            key={d}
            className={`border-b border-l border-slate-100 py-2 text-center text-sm font-medium ${highlightToday && d === today ? 'bg-indigo-50 text-indigo-700' : 'text-slate-600'}`}
          >
            {d}
          </div>
        ))}

        {/* Hour labels */}
        <div className="relative" style={{ height: hours.length * HOUR_PX }}>
          {hours.map((h, i) => (
            <span key={h} className="absolute right-2 text-[11px] text-slate-400" style={{ top: i * HOUR_PX - 6 }}>
              {String(h).padStart(2, '0')}:00
            </span>
          ))}
        </div>

        {days.map((d) => (
          <div key={d} className="relative border-l border-slate-100" style={{ height: hours.length * HOUR_PX }}>
            {hours.map((h, i) => (
              <div key={h} className="absolute inset-x-0 border-t border-slate-100" style={{ top: i * HOUR_PX }} />
            ))}
            {slots
              .filter((s) => s.day === d)
              .map((s, i) => {
                const top = ((minutes(s.start) - first * 60) / 60) * HOUR_PX
                const height = Math.max(28, ((minutes(s.end) - minutes(s.start)) / 60) * HOUR_PX - 2)
                return (
                  <div
                    key={`${s.section_id}-${i}`}
                    className={`absolute inset-x-1 overflow-hidden rounded-md border px-2 py-1 text-xs ${COLORS[s.color] || COLORS.indigo} ${s.status === 'pending' ? 'border-dashed opacity-80' : ''}`}
                    style={{ top, height }}
                    title={`${s.code} ${s.title} · ${s.start}–${s.end} · ${s.room ? `Room ${s.room}` : 'Room TBA'}`}
                  >
                    <p className="font-semibold">{s.code} <span className="font-normal">({s.section})</span></p>
                    <p>{s.start}–{s.end}</p>
                    <p className="truncate opacity-80">{s.room ? `Room ${s.room}` : 'Room TBA'}{s.teacher ? ` · ${s.teacher}` : ''}</p>
                  </div>
                )
              })}
          </div>
        ))}
      </div>
    </div>
  )
}
