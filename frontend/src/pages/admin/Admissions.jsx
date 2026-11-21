import { useState } from 'react'
import { api, downloadFile, errorMessage } from '../../api/client'
import { useApi } from '../../api/useApi'
import DataTable from '../../components/DataTable'
import { useToast } from '../../components/Toast'
import { Badge, Field, Loading, Modal, PageHeader, formatDate, statusTone } from '../../components/ui'

const STATUSES = { submitted: 'Submitted', under_review: 'Under review', accepted: 'Accepted', rejected: 'Rejected' }
const tone = (s) => ({ submitted: 'slate', under_review: 'indigo', accepted: 'emerald', rejected: 'rose' })[s] || statusTone(s)

export default function Admissions() {
  const [status, setStatus] = useState('submitted')
  const [openId, setOpenId] = useState(null)
  const [reloadKey, setReloadKey] = useState(0)

  return (
    <div>
      <PageHeader title="Admissions" subtitle="Review online applications from the public /apply page" />
      <DataTable
        url="/admin/applications"
        params={{ status }}
        reloadKey={reloadKey}
        searchPlaceholder="Name, email, phone or tracking code"
        toolbar={
          <select className="input max-w-44" value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">All statuses</option>
            {Object.entries(STATUSES).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
        }
        columns={[
          { key: 'tracking_code', label: 'Code', render: (a) => <span className="font-mono text-xs">{a.tracking_code}</span> },
          { key: 'name', label: 'Applicant', sortable: true, render: (a) => <span className="font-medium">{a.name}</span> },
          { key: 'program', label: 'Program', render: (a) => a.program?.code },
          { key: 'ssc_gpa', label: 'SSC', sortable: true },
          { key: 'hsc_gpa', label: 'HSC', sortable: true },
          { key: 'created_at', label: 'Applied', sortable: true, render: (a) => formatDate(a.created_at) },
          { key: 'status', label: 'Status', render: (a) => <Badge tone={tone(a.status)}>{STATUSES[a.status]}</Badge> },
        ]}
        actions={(a) => <button className="text-sm text-indigo-600 hover:underline" onClick={() => setOpenId(a.id)}>Review</button>}
      />
      <Review id={openId} onClose={() => setOpenId(null)} onDecided={() => setReloadKey((k) => k + 1)} />
    </div>
  )
}

function Review({ id, onClose, onDecided }) {
  const toast = useToast()
  const { data: a, loading, reload } = useApi(id ? `/admin/applications/${id}` : null)
  const [note, setNote] = useState('')
  const [credentials, setCredentials] = useState(null)

  async function decide(status) {
    try {
      const res = await api.post(`/admin/applications/${id}/decide`, { status, note })
      toast.success(res.data.message)
      if (res.data.temporary_password) setCredentials(res.data)
      setNote('')
      reload()
      onDecided()
    } catch (err) {
      toast.error(errorMessage(err))
    }
  }

  const decided = a && ['accepted', 'rejected'].includes(a.status)

  return (
    <Modal open={Boolean(id)} title={a ? `${a.name} · ${a.tracking_code}` : 'Application'} onClose={() => { setCredentials(null); onClose() }}>
      {loading || !a ? (
        <Loading />
      ) : (
        <div className="max-h-[70vh] space-y-4 overflow-y-auto text-sm">
          <div className="flex items-center gap-2">
            <Badge tone={tone(a.status)}>{STATUSES[a.status]}</Badge>
            <span className="text-slate-500">{a.program?.name}</span>
          </div>
          <dl className="grid grid-cols-2 gap-x-4 gap-y-2">
            <Item label="Email" value={a.email} />
            <Item label="Phone" value={a.phone} />
            <Item label="Date of birth" value={formatDate(a.date_of_birth)} />
            <Item label="Gender" value={a.gender} />
            <Item label="SSC" value={`GPA ${a.ssc_gpa} (${a.ssc_year})`} />
            <Item label="HSC" value={`GPA ${a.hsc_gpa} (${a.hsc_year})`} />
            <div className="col-span-2"><Item label="Address" value={a.address} /></div>
          </dl>

          <div>
            <p className="mb-1 font-medium">Documents</p>
            <ul className="space-y-1">
              {(a.documents || []).map((d, i) => (
                <li key={i}>
                  <button className="text-indigo-600 hover:underline" onClick={() => downloadFile(`/admin/applications/${a.id}/documents/${i}`, d.original_name)}>
                    {d.original_name}
                  </button>
                </li>
              ))}
            </ul>
          </div>

          {a.review_note && <p className="rounded-lg bg-slate-50 p-3 text-slate-600">Note: {a.review_note}</p>}
          {a.reviewer && <p className="text-xs text-slate-400">Reviewed by {a.reviewer.name} on {formatDate(a.reviewed_at, true)}</p>}

          {credentials && (
            <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-3">
              <p className="font-medium text-emerald-800">Student ID {credentials.student_id}</p>
              <p className="text-emerald-700">Temporary password: <span className="font-mono">{credentials.temporary_password}</span></p>
              <p className="mt-1 text-xs text-emerald-700">These login details were also emailed to the applicant.</p>
            </div>
          )}

          {!decided && (
            <div className="border-t border-slate-100 pt-4">
              <Field label="Note to the applicant (optional)">
                <textarea className="input" value={note} onChange={(e) => setNote(e.target.value)} />
              </Field>
              <div className="flex flex-wrap gap-2">
                <button className="btn-primary bg-emerald-600 hover:bg-emerald-700" onClick={() => decide('accepted')}>Accept & create account</button>
                {a.status === 'submitted' && <button className="btn-ghost" onClick={() => decide('under_review')}>Mark under review</button>}
                <button className="btn-ghost text-rose-600" onClick={() => decide('rejected')}>Reject</button>
              </div>
            </div>
          )}
        </div>
      )}
    </Modal>
  )
}

function Item({ label, value }) {
  return (
    <div>
      <dt className="text-xs text-slate-500">{label}</dt>
      <dd>{value || '—'}</dd>
    </div>
  )
}
