/**
 * Renders light Markdown safely, as React elements (never raw HTML):
 *   **bold**, *italic*, and http(s) links. Line breaks are kept.
 */
const TOKEN = /(\*\*[^*]+\*\*|\*[^*\s][^*]*\*|https?:\/\/[^\s<]+[^\s<.,;:!?)\]'"])/g

function renderLine(line, keyPrefix) {
  return line.split(TOKEN).map((part, i) => {
    const key = `${keyPrefix}-${i}`
    if (/^\*\*[^*]+\*\*$/.test(part)) return <strong key={key}>{part.slice(2, -2)}</strong>
    if (/^\*[^*]+\*$/.test(part)) return <em key={key}>{part.slice(1, -1)}</em>
    if (/^https?:\/\//.test(part)) {
      return (
        <a key={key} href={part} target="_blank" rel="noopener noreferrer nofollow" className="break-all text-indigo-600 hover:underline">
          {part}
        </a>
      )
    }
    return part
  })
}

export default function RichText({ text, className = '' }) {
  const lines = String(text || '').split('\n')
  return (
    <p className={`whitespace-pre-wrap break-words ${className}`}>
      {lines.map((line, i) => (
        <span key={i}>
          {renderLine(line, i)}
          {i < lines.length - 1 && '\n'}
        </span>
      ))}
    </p>
  )
}
