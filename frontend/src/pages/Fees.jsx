import { useState } from 'react'
import { api, errorMessage } from '../api/client'
import { useApi } from '../api/useApi'
import { Badge, Empty, ErrorBox, Field, Loading, Modal, PageHeader, formatDate, statusTone, taka } from '../components/ui'
import { useAuth } from '../context/AuthContext'

export default function Fees() {
  const { user } = useAuth()
  const { data, loading, error, reload } = useApi('/invoices')
  const [message, setMessage] = useState(null)
  const [billOpen, setBillOpen] = useState(false)
  const isStudent = user.role === 'student'

  /** Sandbox flow: create a pending payment, then "visit" the gateway callback. */
  async function pay(invoice, method) {
    try {
      const res = await api.post(`/invoices/${invoice.id}/pay`, { method })
      const callback = new URL(res.data.checkout_url)
      await api.get(callback.pathname.replace(/^\/api/, '') + callback.search)
      setMessage(`Paid ${taka(res.data.payment.amount)} via ${method} (sandbox). Transaction ${res.data.payment.trx_id}`)
      reload()
    } catch (err) {
      setMessage(errorMessage(err))
    }
  }

  return (
    <div>
      <PageHeader
        title="Fees & Payments"
        subtitle={isStudent ? 'Your invoices and payment history' : 'All student invoices'}
        actions={!isStudent && <button className="btn-primary" onClick={() => setBillOpen(true)}>+ Create invoice</button>}
      />
      <ErrorBox message={error} />
      {message && <div className="mb-4 rounded-lg bg-indigo-50 px-4 py-3 text-sm text-indigo-700">{message}</div>}

      {loading ? (
        <Loading />
      ) : data?.data?.length ? (
        <div className="card overflow-x-auto p-0">
          <table className="table">
            <thead>
              <tr>
                {!isStudent && <th>Student</th>}
                <th>Invoice</th><th>Amount</th><th>Waiver</th><th>Paid</th><th>Due</th><th>Due date</th><th>Status</th><th />
              </tr>
            </thead>
            <tbody>
              {data.data.map((inv) => (
                <tr key={inv.id}>
                  {!isStudent && <td>{inv.student?.name}</td>}
                  <td className="font-medium">{inv.title}</td>
                  <td>{taka(inv.amount)}</td>
                  <td>{taka(inv.waiver)}</td>
                  <td>{taka(inv.paid_amount)}</td>
                  <td className="font-semibold">{taka(inv.due_amount)}</td>
                  <td>{formatDate(inv.due_date)}</td>
                  <td><Badge tone={statusTone(inv.status)}>{inv.status}</Badge></td>
                  <td className="space-x-1 text-right">
                    {isStudent && inv.status !== 'paid' && (
                      <>
                        <button className="rounded bg-pink-600 px-2 py-1 text-xs text-white" onClick={() => pay(inv, 'bkash')}>bKash</button>
                        <button className="rounded bg-slate-700 px-2 py-1 text-xs text-white" onClick={() => pay(inv, 'sslcommerz')}>Card</button>
                      </>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : (
        <Empty>No invoices.</Empty>
      )}

      {!isStudent && <BillForm open={billOpen} onClose={() => setBillOpen(false)} onSaved={reload} />}
    </div>
  )
}

function BillForm({ open, onClose, onSaved }) {
  const programs = useApi(open ? '/programs' : null)
  const [form, setForm] = useState({ title: '', amount: '', program_id: '', batch: '', due_date: '' })
  const [error, setError] = useState(null)
  const set = (k) => (e) => setForm({ ...form, [k]: e.target.value })

  async function save(e) {
    e.preventDefault()
    try {
      await api.post('/invoices', form)
      onSaved()
      onClose()
    } catch (err) {
      setError(errorMessage(err))
    }
  }

  return (
    <Modal open={open} title="Bill students" onClose={onClose}>
      <form onSubmit={save}>
        <ErrorBox message={error} />
        <Field label="Title"><input className="input" value={form.title} onChange={set('title')} placeholder="Spring 2027 tuition fee" required /></Field>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Amount (BDT)"><input type="number" className="input" value={form.amount} onChange={set('amount')} required /></Field>
          <Field label="Due date"><input type="date" className="input" value={form.due_date} onChange={set('due_date')} /></Field>
          <Field label="Program">
            <select className="input" value={form.program_id} onChange={set('program_id')} required>
              <option value="">Select…</option>
              {programs.data?.map((p) => <option key={p.id} value={p.id}>{p.code}</option>)}
            </select>
          </Field>
          <Field label="Batch (optional)"><input className="input" value={form.batch} onChange={set('batch')} placeholder="2022" /></Field>
        </div>
        <button className="btn-primary w-full">Create invoices</button>
      </form>
    </Modal>
  )
}
