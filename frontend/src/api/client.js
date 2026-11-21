import axios from 'axios'

const TOKEN_KEY = 'unisphere_token'

export const api = axios.create({
  baseURL: `${import.meta.env.VITE_API_URL || ''}/api`,
  headers: { Accept: 'application/json' },
})

export const tokenStore = {
  get: () => localStorage.getItem(TOKEN_KEY),
  set: (token) => localStorage.setItem(TOKEN_KEY, token),
  clear: () => localStorage.removeItem(TOKEN_KEY),
}

api.interceptors.request.use((config) => {
  const token = tokenStore.get()
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      tokenStore.clear()
      if (window.location.pathname !== '/login') window.location.href = '/login'
    }
    return Promise.reject(error)
  }
)

/** Download a protected file (sends the auth token), e.g. downloadFile('/admin/students/export'). */
export async function downloadFile(url, filename, params) {
  const res = await api.get(url, { params, responseType: 'blob' })
  const header = res.headers['content-disposition'] || ''
  const name = filename || header.match(/filename="?([^";]+)"?/)?.[1] || 'download'
  const link = document.createElement('a')
  link.href = URL.createObjectURL(res.data)
  link.download = name
  link.click()
  URL.revokeObjectURL(link.href)
}

/** Build and download a CSV in the browser from an array of objects. */
export function downloadCsv(rows, filename) {
  if (!rows.length) return
  const keys = Object.keys(rows[0])
  const escape = (v) => `"${String(v ?? '').replace(/"/g, '""')}"`
  const csv = [keys.join(','), ...rows.map((r) => keys.map((k) => escape(r[k])).join(','))].join('\n')
  const link = document.createElement('a')
  link.href = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv' }))
  link.download = filename
  link.click()
  URL.revokeObjectURL(link.href)
}

/** Turn a Laravel error response into one readable message. */
export function errorMessage(error) {
  const data = error?.response?.data
  if (data?.errors) return Object.values(data.errors).flat().join(' ')
  return data?.message || error?.message || 'Something went wrong.'
}
