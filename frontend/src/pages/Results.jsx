import { useApi } from '../api/useApi'
import { Empty, ErrorBox, Loading, PageHeader, StatCard } from '../components/ui'

export default function Results() {
  const { data, loading, error } = useApi('/results/mine')

  if (loading) return <Loading />

  return (
    <div className="mx-auto max-w-4xl">
      <PageHeader
        title="Results & Transcript"
        subtitle="Published results only"
        actions={<button className="btn-ghost" onClick={() => window.print()}>Print transcript</button>}
      />
      <ErrorBox message={error} />

      <div className="mb-6 grid gap-4 sm:grid-cols-2">
        <StatCard label="CGPA" value={data?.cgpa?.toFixed(2)} tone="emerald" />
        <StatCard label="Credits completed" value={data?.credits_completed} />
      </div>

      {data?.semesters?.length ? (
        data.semesters.map((s) => (
          <div key={s.semester.id} className="card mb-4 overflow-x-auto p-0">
            <div className="flex justify-between border-b border-slate-100 px-5 py-3">
              <h2 className="font-semibold">{s.semester.name}</h2>
              <span className="text-sm">GPA <b>{Number(s.gpa).toFixed(2)}</b> · {s.credits} credits</span>
            </div>
            <table className="table">
              <thead><tr><th>Code</th><th>Course</th><th>Credit</th><th>Marks</th><th>Grade</th><th>Point</th></tr></thead>
              <tbody>
                {s.courses.map((c) => (
                  <tr key={c.code}>
                    <td>{c.code}</td><td>{c.title}</td><td>{c.credit}</td><td>{c.total}</td>
                    <td className="font-semibold">{c.letter_grade}</td><td>{c.grade_point.toFixed(2)}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ))
      ) : (
        <Empty>No published results yet.</Empty>
      )}
    </div>
  )
}
