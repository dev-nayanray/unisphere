import { useState } from 'react'
import { api, errorMessage } from '../../api/client'
import { useApi } from '../../api/useApi'
import { Badge, Empty, ErrorBox, Loading, formatDate } from '../../components/ui'

export default function Stream({ cls }) {
  const { data, loading, error, reload } = useApi(`/classes/${cls.id}/posts`)
  const [body, setBody] = useState('')
  const [pin, setPin] = useState(false)
  const [postError, setPostError] = useState(null)

  async function post(e) {
    e.preventDefault()
    try {
      await api.post(`/classes/${cls.id}/posts`, { body, is_pinned: pin })
      setBody('')
      setPin(false)
      reload()
    } catch (err) {
      setPostError(errorMessage(err))
    }
  }

  return (
    <div className="space-y-4">
      <form onSubmit={post} className="card">
        <ErrorBox message={postError} />
        <textarea
          className="input min-h-20"
          placeholder="Announce something to your class"
          value={body}
          onChange={(e) => setBody(e.target.value)}
          required
        />
        <div className="mt-3 flex items-center justify-between">
          {cls.can_manage ? (
            <label className="flex items-center gap-2 text-sm text-slate-600">
              <input type="checkbox" checked={pin} onChange={(e) => setPin(e.target.checked)} /> Pin to top
            </label>
          ) : (
            <span />
          )}
          <button className="btn-primary">Post</button>
        </div>
      </form>

      <ErrorBox message={error} />
      {loading ? (
        <Loading />
      ) : data?.data?.length ? (
        data.data.map((p) => <PostCard key={p.id} post={p} onChange={reload} />)
      ) : (
        <Empty>No announcements yet.</Empty>
      )}
    </div>
  )
}

function PostCard({ post, onChange }) {
  const [comment, setComment] = useState('')

  async function send(e) {
    e.preventDefault()
    await api.post(`/posts/${post.id}/comments`, { body: comment })
    setComment('')
    onChange()
  }

  return (
    <div className="card">
      <div className="mb-2 flex items-center gap-3">
        <div className="flex h-9 w-9 items-center justify-center rounded-full bg-indigo-100 text-sm font-semibold text-indigo-700">
          {post.author?.name?.[0]}
        </div>
        <div className="flex-1">
          <p className="text-sm font-medium">{post.author?.name}</p>
          <p className="text-xs text-slate-400">{formatDate(post.created_at, true)}</p>
        </div>
        {post.is_pinned && <Badge tone="indigo">Pinned</Badge>}
      </div>
      <p className="whitespace-pre-line text-sm text-slate-700">{post.body}</p>

      {post.comments?.length > 0 && (
        <ul className="mt-4 space-y-2 border-t border-slate-100 pt-3">
          {post.comments.map((c) => (
            <li key={c.id} className="text-sm">
              <b>{c.author?.name}</b> <span className="text-slate-600">{c.body}</span>
            </li>
          ))}
        </ul>
      )}
      <form onSubmit={send} className="mt-3 flex gap-2">
        <input className="input" placeholder="Add class comment…" value={comment} onChange={(e) => setComment(e.target.value)} required />
        <button className="btn-ghost">Send</button>
      </form>
    </div>
  )
}
