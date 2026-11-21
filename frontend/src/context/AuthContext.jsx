import { createContext, useContext, useEffect, useState } from 'react'
import { api, tokenStore } from '../api/client'

const AuthContext = createContext(null)

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [ready, setReady] = useState(false)

  useEffect(() => {
    if (!tokenStore.get()) {
      setReady(true)
      return
    }
    api
      .get('/auth/me')
      .then((res) => setUser(res.data))
      .catch(() => tokenStore.clear())
      .finally(() => setReady(true))
  }, [])

  async function login(email, password) {
    const res = await api.post('/auth/login', { email, password, device_name: 'web' })
    tokenStore.set(res.data.token)
    setUser(res.data.user)
    return res.data.user
  }

  async function logout() {
    try {
      await api.post('/auth/logout')
    } finally {
      tokenStore.clear()
      setUser(null)
    }
  }

  const hasRole = (...roles) => Boolean(user) && (user.role === 'super_admin' || roles.includes(user.role))

  return (
    <AuthContext.Provider value={{ user, ready, login, logout, hasRole }}>
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth() {
  return useContext(AuthContext)
}
