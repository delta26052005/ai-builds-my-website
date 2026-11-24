import { useCallback, useEffect, useState } from 'react'
import { ApiError, api, getCsrfCookie } from '../lib/api.js'
import { AuthContext } from './AuthContext.js'

export function AuthProvider({ children }) {
  const [user, setUser] = useState(null)
  const [loading, setLoading] = useState(true)
  const [authError, setAuthError] = useState('')

  const refreshUser = useCallback(async () => {
    setLoading(true)
    setAuthError('')

    try {
      setUser(await api.currentUser())
    } catch (error) {
      setUser(null)

      if (error instanceof ApiError && error.status === 401) {
        setAuthError('')
      } else {
        setAuthError('We couldn’t verify your session. Check your connection and try again.')
      }
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    let active = true

    async function restoreSession() {
      try {
        const currentUser = await api.currentUser()
        if (active) {
          setUser(currentUser)
          setAuthError('')
        }
      } catch (error) {
        if (active) {
          setUser(null)
          setAuthError(
            error instanceof ApiError && error.status === 401
              ? ''
              : 'We couldn’t verify your session. Check your connection and try again.',
          )
        }
      } finally {
        if (active) {
          setLoading(false)
        }
      }
    }

    void restoreSession()

    return () => {
      active = false
    }
  }, [])

  const login = useCallback(async (credentials) => {
    setAuthError('')
    await getCsrfCookie()
    const response = await api.login(credentials)
    setUser(response.user)

    return response.user
  }, [])

  const register = useCallback(async (details) => {
    setAuthError('')
    await getCsrfCookie()
    const response = await api.register(details)
    setUser(response.user)

    return response.user
  }, [])

  const logout = useCallback(async () => {
    await getCsrfCookie()
    await api.logout()
    setUser(null)
    setAuthError('')
  }, [])

  return (
    <AuthContext.Provider value={{
      user,
      loading,
      authError,
      login,
      register,
      logout,
      refreshUser,
    }}>
      {children}
    </AuthContext.Provider>
  )
}
