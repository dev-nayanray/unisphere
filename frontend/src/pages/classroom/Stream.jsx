import { useCallback, useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, errorMessage } from '../../api/client'
import { useApi } from '../../api/useApi'
import Attachments from '../../components/Attachments'
import ConfirmButton from '../../components/ConfirmButton'
import RichText from '../../components/RichText'
import { useToast } from '../../components/Toast'
import { Avatar, Badge, Empty, ErrorBox, Loading, formatDate } from '../../components/ui'
import { useAuth } from '../../context/AuthContext'

const PERMISSIONS = {
  post_comment: 'Students can post and comment',
  comment: 'Students can only comment',
  none: 'Only the teacher can post or comment',
}

export default function Stream({ cls }) {
  const toast = useToast()
  const [posts, setPosts] = useState([])
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [meta, setMeta] = useState({ can_post: false, stream_permission: 'post_comment' })
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const sentinel = useRef(null)

  const load = useCallback(async (p, replace = false) => {
    setLoading(true)
    try {
      const res = await api.get(`/classes/${cls.id}/posts`, { params: { page: p } })
      setPosts((old) => (replace ? res.data.data : [...old, ...res.data.data]))
      setPage(res.data.current_page)
      setLastPage(res.data.last_page)
      setMeta({ can_post: res.data.can_post, stream_permission: res.data.stream_permission })
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setLoading(false)
    }
  }, [cls.id])

  const refresh = useCallback(() => load(1, true), [load])

  useEffect(() => {
    refresh()
  }, [refresh])

  // Infinite scroll: load the next page when the bottom marker comes into view.
  useEffect(() => {
    const el = sentinel.current
    if (!el) return
    const observer = new IntersectionObserver((entries) => {
      if (entries[0].isIntersecting && !loading && page < lastPage) load(page + 1)
    })
    observer.observe(el)
    return () => observer.disconnect()
  }, [loading, page, lastPage, load])

  async function changePermission(value) {
    try {
      await api.put(`/classes/${cls.id}/stream-settings`, { stream_permission: value })
      toast.success('Stream setting saved.')
      refresh()
    } catch (err) {
      toast.error(errorMessage(err))
    }
  }

  return (
    <div className="grid gap-6 lg:grid-cols-[1fr_260px]">
      <div className="space-y-4">
        {meta.can_post ? (
          <Composer cls={cls} onPosted={refresh} />
        ) : (
          <div className="card text-sm text-slate-500">{PERMISSIONS[meta.stream_permission]}.</div>
        )}

        <ErrorBox message={error} />
        {posts.length === 0 && !loading ? (
          <Empty>No announcements yet.</Empty>
        ) : (
          posts.map((p) => <PostCard key={p.id} post={p} cls={cls} onChange={refresh} canComment={meta.stream_permission !== 'none' || cls.can_manage} />)
        )}
        <div ref={sentinel} />
        {loading && <Loading />}
        {!loading && page >= lastPage && posts.length > 5 && <p className="text-center text-xs text-slate-400">You're all caught up.</p>}
      </div>

      <aside className="space-y-4">
        <Upcoming cls={cls} />
        {cls.can_manage && (
          <div className="card">
            <h3 className="mb-2 text-sm font-semibold">Stream settings</h3>
            <select className="input" value={meta.stream_permission} onChange={(e) => changePermission(e.target.value)}>
              {Object.entries(PERMISSIONS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select>
          </div>
        )}
      </aside>
    </div>
  )
}

function Composer({ cls, onPosted }) {
  const [body, setBody] = useState('')
  const [files, setFiles] = useState([])
  const [pin, setPin] = useState(false)
  const [scheduleAt, setScheduleAt] = useState('')
  const [error, setError] = useState(null)
  const [busy, setBusy] = useState(false)

  async function submit(e) {
    e.preventDefault()
    const data = new FormData()
    data.append('body', body)
    if (pin) data.append('is_pinned', '1')
    if (scheduleAt) data.append('scheduled_at', scheduleAt)
    files.forEach((f) => data.append('attachments[]', f))
    setBusy(true)
    setError(null)
    try {
      await api.post(`/classes/${cls.id}/posts`, data)
      setBody('')
      setFiles([])
      setPin(false)
      setScheduleAt('')
      onPosted()
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <form onSubmit={submit} className="card">
      <ErrorBox message={error} />
      <textarea
        className="input min-h-24"
        placeholder="Announce something to your class. **bold**, *italic* and links work."
        value={body}
        onChange={(e) => setBody(e.target.value)}
        required
      />
      {files.length > 0 && (
        <ul className="mt-2 flex flex-wrap gap-2 text-xs">
          {files.map((f, i) => (
            <li key={i} className="flex items-center gap-1 rounded-full bg-slate-100 px-3 py-1">
              {f.name}
              <button type="button" className="text-slate-400 hover:text-rose-500" onClick={() => setFiles(files.filter((_, j) => j !== i))}>✕</button>
            </li>
          ))}
        </ul>
      )}
      <div className="mt-3 flex flex-wrap items-center gap-3">
        <label className="btn-ghost cursor-pointer py-1.5">
          📎 Attach
          <input
            type="file"
            multiple
            className="hidden"
            onChange={(e) => setFiles([...files, ...e.target.files].slice(0, 5))}
          />
        </label>
        {cls.can_manage && (
          <>
            <label className="flex items-center gap-2 text-sm text-slate-600">
              <input type="checkbox" checked={pin} onChange={(e) => setPin(e.target.checked)} /> Pin
            </label>
            <label className="flex items-center gap-2 text-sm text-slate-600">
              Schedule
              <input type="datetime-local" className="input py-1" value={scheduleAt} onChange={(e) => setScheduleAt(e.target.value)} />
            </label>
          </>
        )}
        <button className="btn-primary ml-auto" disabled={busy}>{busy ? 'Posting…' : scheduleAt ? 'Schedule' : 'Post'}</button>
      </div>
      <p className="mt-2 text-xs text-slate-400">Up to 5 files, 20 MB each.</p>
    </form>
  )
}

function PostCard({ post, cls, onChange, canComment }) {
  const { user } = useAuth()
  const toast = useToast()
  const [editing, setEditing] = useState(false)
  const [text, setText] = useState(post.body)
  const [comment, setComment] = useState('')
  const isAuthor = post.author_id === user.id
  const scheduled = post.published_at && new Date(post.published_at) > new Date()

  const run = async (fn, done) => {
    try {
      await fn()
      done?.()
      onChange()
    } catch (err) {
      toast.error(errorMessage(err))
    }
  }

  const classworkLink = post.type === 'classwork' && post.link?.kind === 'assignment' ? `/assignments/${post.link.id}` : null

  return (
    <article className={`card ${post.type === 'classwork' ? 'border-l-4 border-l-indigo-400' : ''}`}>
      <header className="mb-2 flex items-center gap-3">
        <div className="w-9"><Avatar user={post.author} /></div>
        <div className="flex-1">
          <p className="text-sm font-medium">{post.author?.name}</p>
          <p className="text-xs text-slate-400">
            {formatDate(post.published_at || post.created_at, true)}
            {post.edited_at && ' · edited'}
          </p>
        </div>
        {scheduled && <Badge tone="amber">Scheduled</Badge>}
        {post.is_pinned && <Badge tone="indigo">Pinned</Badge>}
        <span className="flex gap-3 text-xs">
          {cls.can_manage && (
            <button className="text-slate-500 hover:underline" onClick={() => run(() => api.patch(`/posts/${post.id}`, { is_pinned: !post.is_pinned }))}>
              {post.is_pinned ? 'Unpin' : 'Pin'}
            </button>
          )}
          {isAuthor && post.type !== 'classwork' && (
            <button className="text-slate-500 hover:underline" onClick={() => setEditing(!editing)}>Edit</button>
          )}
          {(isAuthor || cls.can_manage) && (
            <ConfirmButton className="text-rose-600 hover:underline" title="Delete post?" message="The post, its comments and attachments are removed." onConfirm={() => run(() => api.delete(`/posts/${post.id}`))}>
              Delete
            </ConfirmButton>
          )}
        </span>
      </header>

      {editing ? (
        <div>
          <textarea className="input min-h-20" value={text} onChange={(e) => setText(e.target.value)} />
          <div className="mt-2 flex gap-2">
            <button className="btn-primary py-1" onClick={() => run(() => api.patch(`/posts/${post.id}`, { body: text }), () => setEditing(false))}>Save</button>
            <button className="btn-ghost py-1" onClick={() => setEditing(false)}>Cancel</button>
          </div>
        </div>
      ) : classworkLink ? (
        <Link to={classworkLink} className="text-sm font-medium text-indigo-700 hover:underline">{post.body}</Link>
      ) : (
        <RichText text={post.body} className="text-sm text-slate-700" />
      )}

      <Attachments files={post.attachments} />

      {post.comments?.length > 0 && (
        <ul className="mt-4 space-y-2 border-t border-slate-100 pt-3">
          {post.comments.map((c) => <Comment key={c.id} comment={c} canModerate={cls.can_manage} onChange={onChange} />)}
        </ul>
      )}

      {canComment && !scheduled && (
        <form
          onSubmit={(e) => {
            e.preventDefault()
            run(() => api.post(`/posts/${post.id}/comments`, { body: comment }), () => setComment(''))
          }}
          className="mt-3 flex gap-2"
        >
          <input className="input" placeholder="Add class comment…" value={comment} onChange={(e) => setComment(e.target.value)} required />
          <button className="btn-ghost">Send</button>
        </form>
      )}
    </article>
  )
}

function Comment({ comment, canModerate, onChange }) {
  const { user } = useAuth()
  const toast = useToast()
  const [editing, setEditing] = useState(false)
  const [text, setText] = useState(comment.body)
  const mine = comment.author_id === user.id

  async function save() {
    try {
      await api.patch(`/comments/${comment.id}`, { body: text })
      setEditing(false)
      onChange()
    } catch (err) {
      toast.error(errorMessage(err))
    }
  }

  async function remove() {
    try {
      await api.delete(`/comments/${comment.id}`)
      onChange()
    } catch (err) {
      toast.error(errorMessage(err))
    }
  }

  return (
    <li className="group text-sm">
      {editing ? (
        <span className="flex gap-2">
          <input className="input py-1" value={text} onChange={(e) => setText(e.target.value)} />
          <button className="text-indigo-600" onClick={save}>Save</button>
          <button className="text-slate-500" onClick={() => setEditing(false)}>Cancel</button>
        </span>
      ) : (
        <span>
          <b>{comment.author?.name}</b> <RichText text={comment.body} className="inline text-slate-600" />
          {comment.edited_at && <span className="text-xs text-slate-400"> (edited)</span>}
          <span className="ml-2 hidden gap-2 text-xs group-hover:inline-flex">
            {mine && <button className="text-slate-500 hover:underline" onClick={() => setEditing(true)}>Edit</button>}
            {(mine || canModerate) && <button className="text-rose-600 hover:underline" onClick={remove}>Delete</button>}
          </span>
        </span>
      )}
    </li>
  )
}

function Upcoming({ cls }) {
  const { data } = useApi(`/classes/${cls.id}/upcoming`)

  return (
    <div className="card">
      <h3 className="mb-2 text-sm font-semibold">Upcoming</h3>
      {data?.length ? (
        <ul className="space-y-2 text-sm">
          {data.map((item) => (
            <li key={`${item.kind}-${item.id}`}>
              <p className="text-xs text-slate-500">Due {formatDate(item.due_at, true)}</p>
              {item.kind === 'assignment' ? (
                <Link to={`/assignments/${item.id}`} className="font-medium text-indigo-700 hover:underline">{item.title}</Link>
              ) : (
                <span className="font-medium">{item.title}</span>
              )}
            </li>
          ))}
        </ul>
      ) : (
        <p className="text-sm text-slate-500">Woohoo, no work due soon!</p>
      )}
    </div>
  )
}
