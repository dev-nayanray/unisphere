import { createContext, useCallback, useContext, useState } from 'react'

const ToastContext = createContext(null)

/** Small toast notifications: const toast = useToast(); toast.success('Saved') */
export function ToastProvider({ children }) {
  const [toasts, setToasts] = useState([])

  const push = useCallback((text, tone) => {
    const id = Math.random().toString(36).slice(2)
    setToasts((t) => [...t, { id, text, tone }])
    setTimeout(() => setToasts((t) => t.filter((x) => x.id !== id)), 4000)
  }, [])

  const api = {
    success: (text) => push(text, 'success'),
    error: (text) => push(text, 'error'),
    info: (text) => push(text, 'info'),
  }

  const tones = {
    success: 'border-emerald-200 bg-emerald-50 text-emerald-800',
    error: 'border-rose-200 bg-rose-50 text-rose-800',
    info: 'border-indigo-200 bg-indigo-50 text-indigo-800',
  }

  return (
    <ToastContext.Provider value={api}>
      {children}
      <div className="fixed right-4 bottom-4 z-[60] flex w-80 flex-col gap-2" role="status" aria-live="polite">
        {toasts.map((t) => (
          <div key={t.id} className={`rounded-lg border px-4 py-3 text-sm shadow-lg ${tones[t.tone]}`}>
            {t.text}
          </div>
        ))}
      </div>
    </ToastContext.Provider>
  )
}

export function useToast() {
  return useContext(ToastContext)
}
