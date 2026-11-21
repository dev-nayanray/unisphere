import { useApi } from '../../api/useApi'
import { Loading } from '../../components/ui'

export default function People({ cls }) {
  const { data, loading } = useApi(`/classes/${cls.id}/people`)
  if (loading) return <Loading />

  return (
    <div className="space-y-6">
      <section className="card">
        <h2 className="mb-3 border-b border-slate-100 pb-2 text-lg font-semibold text-indigo-700">Teacher</h2>
        <Person name={data.teacher?.name} sub={data.teacher?.email} />
      </section>
      <section className="card">
        <h2 className="mb-3 flex justify-between border-b border-slate-100 pb-2 text-lg font-semibold text-indigo-700">
          Students <span className="text-sm font-normal text-slate-500">{data.students.length} students</span>
        </h2>
        <ul className="divide-y divide-slate-50">
          {data.students.map((s) => (
            <li key={s.id} className="py-2">
              <Person name={s.name} sub={s.student?.student_id} />
            </li>
          ))}
        </ul>
      </section>
    </div>
  )
}

function Person({ name, sub }) {
  return (
    <div className="flex items-center gap-3">
      <div className="flex h-9 w-9 items-center justify-center rounded-full bg-slate-100 text-sm font-semibold">{name?.[0]}</div>
      <div>
        <p className="text-sm font-medium">{name}</p>
        <p className="text-xs text-slate-500">{sub}</p>
      </div>
    </div>
  )
}
