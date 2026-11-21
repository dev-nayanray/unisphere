import { useState } from 'react'
import { api, errorMessage } from '../api/client'
import ConfirmButton from './ConfirmButton'
import DataTable from './DataTable'
import FormModal from './FormModal'
import { useToast } from './Toast'

/**
 * Complete list + create + edit + delete screen for one resource.
 *
 * endpoint:  REST base, e.g. '/admin/courses' (POST endpoint, PUT/DELETE endpoint/{id})
 * listUrl:   where the table loads from (defaults to endpoint)
 * columns, fields: see DataTable and FormModal
 * toForm(row):   turns a row into form values for editing
 * extraActions(row, reload): more buttons per row
 */
export default function CrudPage({
  title,
  endpoint,
  listUrl,
  columns,
  fields,
  defaults = {},
  toForm = (row) => row,
  params,
  extraActions,
  toolbar,
  canCreate = true,
  onSaved,
  searchPlaceholder,
  deleteMessage = (row) => `This permanently deletes ${row.code || row.name || 'this record'}.`,
}) {
  const toast = useToast()
  const [editing, setEditing] = useState(null) // null | 'new' | row
  const [reloadKey, setReloadKey] = useState(0)
  const reload = () => setReloadKey((k) => k + 1)

  async function remove(row) {
    try {
      const res = await api.delete(`${endpoint}/${row.id}`)
      toast.success(res.data.message || 'Deleted.')
      reload()
    } catch (err) {
      toast.error(errorMessage(err))
    }
  }

  return (
    <div>
      <DataTable
        url={listUrl || endpoint}
        columns={columns}
        params={params}
        reloadKey={reloadKey}
        searchPlaceholder={searchPlaceholder}
        toolbar={
          <>
            {toolbar}
            {canCreate && <button className="btn-primary" onClick={() => setEditing('new')}>+ Add {title}</button>}
          </>
        }
        actions={(row) => (
          <span className="space-x-3 text-sm">
            {extraActions?.(row, reload)}
            <button className="text-indigo-600 hover:underline" onClick={() => setEditing(row)}>Edit</button>
            <ConfirmButton title={`Delete ${title}?`} message={deleteMessage(row)} onConfirm={() => remove(row)}>
              Delete
            </ConfirmButton>
          </span>
        )}
      />

      <FormModal
        key={editing === 'new' ? 'new' : editing?.id ?? 'closed'}
        open={editing !== null}
        title={editing === 'new' ? `Add ${title}` : `Edit ${title}`}
        fields={fields}
        initial={editing === 'new' ? defaults : editing ? toForm(editing) : {}}
        method={editing === 'new' ? 'post' : 'put'}
        url={editing === 'new' ? endpoint : `${endpoint}/${editing?.id}`}
        onClose={() => setEditing(null)}
        onSaved={(data) => {
          toast.success(`${title[0].toUpperCase()}${title.slice(1)} saved.`)
          reload()
          onSaved?.(data, editing === 'new')
        }}
      />
    </div>
  )
}
