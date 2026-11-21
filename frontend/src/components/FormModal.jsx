import { useEffect, useState } from 'react'
import { api, errorMessage } from '../api/client'
import { Modal } from './ui'

/**
 * A modal form driven by a field list. Shows Laravel validation errors under each field.
 *
 * fields: [{ name, label, type?: text|email|number|date|textarea|select|checkbox|password,
 *            options?: [{ value, label }], required?, placeholder?, show?(values), span?: 1|2,
 *            render?({ value, set, values, error }) }]
 * Submits { method, url } with the values; calls onSaved(responseData).
 */
export default function FormModal({ open, title, fields, initial, method = 'post', url, onClose, onSaved, submitLabel = 'Save' }) {
  const [values, setValues] = useState(initial || {})
  const [errors, setErrors] = useState({})
  const [message, setMessage] = useState(null)
  const [busy, setBusy] = useState(false)

  // Reset whenever the modal opens (callers build `initial` inline, so it is not a dependency).
  useEffect(() => {
    if (open) {
      setValues(initial || {})
      setErrors({})
      setMessage(null)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open])

  const set = (name, value) => setValues((v) => ({ ...v, [name]: value }))

  async function submit(e) {
    e.preventDefault()
    setBusy(true)
    setErrors({})
    setMessage(null)
    try {
      // Empty strings become null so optional fields can be cleared.
      const body = Object.fromEntries(Object.entries(values).map(([k, v]) => [k, v === '' ? null : v]))
      const res = await api[method](url, body)
      onSaved?.(res.data)
      onClose()
    } catch (err) {
      const fieldErrors = err.response?.data?.errors
      if (fieldErrors) setErrors(fieldErrors)
      else setMessage(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  // Errors for nested keys (schedule.0.end) are shown on their parent field.
  const errorFor = (name) =>
    Object.entries(errors)
      .filter(([k]) => k === name || k.startsWith(`${name}.`))
      .flatMap(([, v]) => v)
      .join(' ')

  return (
    <Modal open={open} title={title} onClose={onClose}>
      <form onSubmit={submit} className="max-h-[75vh] overflow-y-auto pr-1">
        {message && <div className="mb-3 rounded-lg bg-rose-50 px-3 py-2 text-sm text-rose-700">{message}</div>}
        <div className="grid grid-cols-2 gap-x-3">
          {fields
            .filter((f) => !f.show || f.show(values))
            .map((f) => {
              const value = values[f.name] ?? (f.type === 'checkbox' ? false : '')
              const error = errorFor(f.name)
              // Custom editors hold several inputs, so they sit in a div rather than a label.
              const Wrapper = f.render ? 'div' : 'label'
              return (
                <Wrapper key={f.name} className={`mb-3 block ${f.span === 1 ? 'col-span-1' : 'col-span-2'}`}>
                  {f.type !== 'checkbox' && <span className="label">{f.label}{f.required && <span className="text-rose-500"> *</span>}</span>}
                  {f.render ? (
                    f.render({ value, set: (v) => set(f.name, v), values, error })
                  ) : f.type === 'select' ? (
                    <select className="input" value={value ?? ''} onChange={(e) => set(f.name, e.target.value)} required={f.required}>
                      <option value="">{f.placeholder || 'Select…'}</option>
                      {(f.options || []).map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
                    </select>
                  ) : f.type === 'textarea' ? (
                    <textarea className="input min-h-20" value={value} onChange={(e) => set(f.name, e.target.value)} required={f.required} />
                  ) : f.type === 'checkbox' ? (
                    <span className="flex items-center gap-2 text-sm">
                      <input type="checkbox" checked={Boolean(value)} onChange={(e) => set(f.name, e.target.checked)} /> {f.label}
                    </span>
                  ) : (
                    <input
                      className="input"
                      type={f.type || 'text'}
                      value={value}
                      placeholder={f.placeholder}
                      step={f.type === 'number' ? 'any' : undefined}
                      onChange={(e) => set(f.name, e.target.value)}
                      required={f.required}
                    />
                  )}
                  {error && <span className="mt-1 block text-xs text-rose-600">{error}</span>}
                </Wrapper>
              )
            })}
        </div>
        <button className="btn-primary w-full" disabled={busy}>{busy ? 'Saving…' : submitLabel}</button>
      </form>
    </Modal>
  )
}
