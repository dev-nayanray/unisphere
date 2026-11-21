import { useState } from 'react'

const size = (bytes) => (bytes > 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`)

/** Image thumbnails, inline PDF preview on click, and download chips for other files. */
export default function Attachments({ files = [] }) {
  const [pdf, setPdf] = useState(null)
  if (!files.length) return null

  const images = files.filter((f) => f.is_image)
  const others = files.filter((f) => !f.is_image)

  return (
    <div className="mt-3 space-y-2">
      {images.length > 0 && (
        <div className="flex flex-wrap gap-2">
          {images.map((f) => (
            <a key={f.id} href={f.url} target="_blank" rel="noreferrer">
              <img src={f.url} alt={f.original_name} className="h-32 max-w-56 rounded-lg border border-slate-200 object-cover" loading="lazy" />
            </a>
          ))}
        </div>
      )}
      {others.map((f) => (
        <div key={f.id} className="flex items-center gap-3 rounded-lg border border-slate-200 px-3 py-2 text-sm">
          <span className="rounded bg-slate-100 px-2 py-0.5 text-xs font-semibold uppercase text-slate-600">
            {f.is_pdf ? 'PDF' : (f.original_name.split('.').pop() || 'file').slice(0, 4)}
          </span>
          <span className="flex-1 truncate">{f.original_name}</span>
          <span className="text-xs text-slate-400">{size(f.size)}</span>
          {f.is_pdf && (
            <button className="text-indigo-600 hover:underline" onClick={() => setPdf(pdf === f.id ? null : f.id)}>
              {pdf === f.id ? 'Hide' : 'Preview'}
            </button>
          )}
          <a className="text-indigo-600 hover:underline" href={f.url} target="_blank" rel="noreferrer">Open</a>
        </div>
      ))}
      {pdf && <iframe title="PDF preview" src={files.find((f) => f.id === pdf).url} className="h-[480px] w-full rounded-lg border border-slate-200" />}
    </div>
  )
}
