import { useState } from 'react'
import { Modal } from './ui'

/** A button that asks "Are you sure?" before running onConfirm. */
export default function ConfirmButton({ children, title = 'Are you sure?', message, confirmLabel = 'Delete', onConfirm, className = 'text-rose-600 hover:underline' }) {
  const [open, setOpen] = useState(false)
  const [busy, setBusy] = useState(false)

  async function confirm() {
    setBusy(true)
    try {
      await onConfirm()
      setOpen(false)
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <button type="button" className={className} onClick={() => setOpen(true)}>{children}</button>
      <Modal open={open} title={title} onClose={() => setOpen(false)}>
        {message && <p className="mb-5 text-sm text-slate-600">{message}</p>}
        <div className="flex justify-end gap-2">
          <button className="btn-ghost" onClick={() => setOpen(false)}>Cancel</button>
          <button className="btn-primary bg-rose-600 hover:bg-rose-700" disabled={busy} onClick={confirm}>
            {busy ? 'Working…' : confirmLabel}
          </button>
        </div>
      </Modal>
    </>
  )
}
