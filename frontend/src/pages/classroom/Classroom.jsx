import { useState } from 'react'
import { useParams } from 'react-router-dom'
import { useApi } from '../../api/useApi'
import { ErrorBox, Loading } from '../../components/ui'
import { COVER } from '../Classes'
import Stream from './Stream'
import Classwork from './Classwork'
import People from './People'
import Grades from './Grades'
import AttendanceTab from './AttendanceTab'

export default function Classroom() {
  const { id } = useParams()
  const { data: cls, loading, error } = useApi(`/classes/${id}`)
  const [tab, setTab] = useState('stream')

  if (loading) return <Loading />
  if (error) return <ErrorBox message={error} />

  const tabs = [
    ['stream', 'Stream'],
    ['classwork', 'Classwork'],
    ['people', 'People'],
    ['grades', 'Grades'],
    ...(cls.can_manage ? [['attendance', 'Attendance']] : []),
  ]

  return (
    <div className="mx-auto max-w-5xl">
      <div className={`mb-4 rounded-2xl bg-gradient-to-br ${COVER[cls.cover_color] || COVER.indigo} p-6 text-white md:p-8`}>
        <p className="text-sm opacity-80">{cls.course.code} · Section {cls.section} · {cls.semester?.name}</p>
        <h1 className="mt-1 text-2xl font-semibold md:text-3xl">{cls.course.title}</h1>
        <p className="mt-2 text-sm opacity-90">{cls.teacher?.name}</p>
        {cls.join_code && (
          <p className="mt-4 inline-block rounded-lg bg-white/15 px-3 py-1 text-sm">
            Class code: <b className="tracking-widest">{cls.join_code}</b>
          </p>
        )}
      </div>

      <div className="mb-6 flex gap-1 overflow-x-auto border-b border-slate-200">
        {tabs.map(([key, label]) => (
          <button
            key={key}
            onClick={() => setTab(key)}
            className={`whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium ${
              tab === key ? 'border-indigo-600 text-indigo-700' : 'border-transparent text-slate-500 hover:text-slate-700'
            }`}
          >
            {label}
          </button>
        ))}
      </div>

      {tab === 'stream' && <Stream cls={cls} />}
      {tab === 'classwork' && <Classwork cls={cls} />}
      {tab === 'people' && <People cls={cls} />}
      {tab === 'grades' && <Grades cls={cls} />}
      {tab === 'attendance' && <AttendanceTab cls={cls} />}
    </div>
  )
}
