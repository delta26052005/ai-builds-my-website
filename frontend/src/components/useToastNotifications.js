import { useCallback, useEffect, useRef, useState } from 'react'

export function useToastNotifications() {
  const [toasts, setToasts] = useState([])
  const toastsRef = useRef([])
  const timersRef = useRef(new Map())
  const recentKeysRef = useRef(new Map())
  const nextIdRef = useRef(0)

  const dismiss = useCallback((id) => {
    const timer = timersRef.current.get(id)
    if (timer) {
      window.clearTimeout(timer)
      timersRef.current.delete(id)
    }

    toastsRef.current = toastsRef.current.filter((toast) => toast.id !== id)
    setToasts(toastsRef.current)
  }, [])

  const notify = useCallback(({ type = 'information', message, key = message }) => {
    const now = Date.now()
    const lastShown = recentKeysRef.current.get(key)

    if (!message
      || toastsRef.current.some((toast) => toast.key === key)
      || (lastShown !== undefined && now - lastShown < 5000)) {
      return
    }

    const toast = { id: ++nextIdRef.current, type, message, key }
    recentKeysRef.current.set(key, now)
    toastsRef.current = [...toastsRef.current, toast]
    setToasts(toastsRef.current)
    timersRef.current.set(toast.id, window.setTimeout(() => dismiss(toast.id), 5000))
  }, [dismiss])

  useEffect(() => () => {
    timersRef.current.forEach((timer) => window.clearTimeout(timer))
    timersRef.current.clear()
  }, [])

  return { toasts, notify, dismiss }
}
