import { useState } from 'react'
import { api, downloadCsv, downloadFile, errorMessage } from '../../api/client'
import { useToast } from '../../components/Toast'
import { Badge, ErrorBox, PageHeader } from '../../components/ui'

const TYPES = {
  students: 'Students',
  faculty: 'Faculty',
}

/** Upload a CSV -> check every row -> import only when the whole file is clean. */
export default function Import() {
  const toast = useToast()
  const [type, setType] = useState('students')
  const [file, setFile] = useState(null)
  const [preview, setPreview] = useState(null)
  const [result, setResult] = useState(null)
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)

  function reset(nextType = type) {
    setType(nextType)
    setFile(null)
    setPreview(null)
    setResult(null)
    setError(null)
  }

  async function send(path) {
    const body = new FormData()
    body.append('file', file)
    setBusy(true)
    setError(null)
    try {
      return await api.post(path, body)
    } catch (err) {
      // A 422 from import still carries the row preview.
      if (err.response?.data?.rows) setPreview(err.response.data)
      setError(errorMessage(err))
      return null
    } finally {
      setBusy(false)
    }
  }

  async function check() {
    const res = await send(`/admin/import/${type}/preview`)
    if (res) setPreview(res.data)
  }

  async function runImport() {
    const res = await send(`/admin/import/${type}`)
    if (res) {
      setResult(res.data)
      setPreview(null)
      toast.success(res.data.message)
    }
  }

  const columns = preview?.rows?.[0] ? Object.keys(preview.rows[0].data) : []

  return (
    <div>
      <PageHeader title="Bulk Import" subtitle="Create many student or faculty accounts from a CSV file" />

      <div className="card mb-6">
        <div className="flex flex-wrap items-end gap-4">
          <label>
            <span className="label">Import</span>
            <select className="input" value={type} onChange={(e) => reset(e.target.value)}>
              {Object.entries(TYPES).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select>
          </label>
          <label>
            <span className="label">CSV file</span>
            <input
              key={type}
              type="file"
              accept=".csv,text/csv"
              className="text-sm"
              onChange={(e) => {
                setFile(e.target.files[0])
                setPreview(null)
                setResult(null)
              }}
            />
          </label>
          <button className="btn-primary" disabled={!file || busy} onClick={check}>{busy ? 'Checking…' : 'Check file'}</button>
          <button className="btn-ghost ml-auto" onClick={() => downloadFile(`/admin/import/${type}/template`)}>Download template</button>
        </div>
        <p className="mt-3 text-xs text-slate-500">
          {type === 'students'
            ? 'Columns: name, email, phone, student_id (leave blank to generate), program_code, batch, advisor_email.'
            : 'Columns: name, email, phone, employee_id, department_code, designation, role (teacher or department_head).'}
          {' '}Save from Excel as "CSV UTF-8". Nothing is saved until every row is valid.
        </p>
      </div>

      <ErrorBox message={error} />

      {preview && (
        <div className="card p-0">
          <div className="flex items-center justify-between border-b border-slate-100 px-5 py-3">
            <p className="text-sm">
              <Badge tone="emerald">{preview.valid} ready</Badge>{' '}
              {preview.invalid > 0 && <Badge tone="rose">{preview.invalid} with errors</Badge>}
            </p>
            <button className="btn-primary" disabled={preview.invalid > 0 || busy} onClick={runImport}>
              Import {preview.valid} {TYPES[type].toLowerCase()}
            </button>
          </div>
          <div className="max-h-[60vh] overflow-auto">
            <table className="table">
              <thead>
                <tr><th>Line</th>{columns.map((c) => <th key={c}>{c}</th>)}<th>Problems</th></tr>
              </thead>
              <tbody>
                {preview.rows.map((r) => (
                  <tr key={r.line} className={r.errors.length ? 'bg-rose-50/60' : ''}>
                    <td className="text-slate-400">{r.line}</td>
                    {columns.map((c) => <td key={c}>{r.data[c]}</td>)}
                    <td className="text-xs text-rose-700">{r.errors.join(' ')}</td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {result && (
        <div className="card">
          <h2 className="font-semibold text-emerald-700">{result.message}</h2>
          <p className="mt-1 text-sm text-slate-600">
            Download the login details now. Temporary passwords are not stored and can't be shown again.
          </p>
          <button className="btn-primary mt-4" onClick={() => downloadCsv(result.credentials, `unisphere-${type}-logins.csv`)}>
            Download login details (CSV)
          </button>
        </div>
      )}
    </div>
  )
}
