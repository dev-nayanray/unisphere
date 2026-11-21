import { api } from '../api/client'
import { useApi } from '../api/useApi'
import { Empty, ErrorBox, Loading, PageHeader } from '../components/ui'

export default function Approvals() {
  const { data, loading, error, reload } = useApi('/registration/pending')

  async function decide(id, status) {
    await api.patch(`/registration/${id}/decide`, { status })
    reload()
  }

  return (
    <div>
      <PageHeader title="Registration Approvals" subtitle="Course registration requests from your advisees and department" />
      <ErrorBox message={error} />
      {loading ? (
        <Loading />
      ) : data?.data?.length ? (
        <div className="card overflow-x-auto p-0">
          <table className="table">
            <thead>
              <tr><th>Student</th><th>ID</th><th>CGPA</th><th>Course</th><th>Credit</th><th /></tr>
            </thead>
            <tbody>
              {data.data.map((r) => (
                <tr key={r.id}>
                  <td className="font-medium">{r.student?.name}</td>
                  <td>{r.student?.student?.student_id}</td>
                  <td>{r.student?.student?.cgpa}</td>
                  <td>{r.section?.course?.code} · {r.section?.course?.title}</td>
                  <td>{r.section?.course?.credit}</td>
                  <td className="space-x-2 text-right">
                    <button className="btn-primary py-1" onClick={() => decide(r.id, 'approved')}>Approve</button>
                    <button className="btn-ghost py-1" onClick={() => decide(r.id, 'rejected')}>Reject</button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <Empty>No pending requests.</Empty>
      )}
    </div>
  )
}
