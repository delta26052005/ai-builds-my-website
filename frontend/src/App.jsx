import { useEffect, useState } from 'react'
import AuthPage from './auth/AuthPage.jsx'
import { AuthProvider } from './auth/AuthProvider.jsx'
import { useAuth } from './auth/useAuth.js'
import './App.css'

function navigate(path) {
  window.history.pushState({}, '', path)
  window.dispatchEvent(new PopStateEvent('popstate'))
}

function usePathname() {
  const [pathname, setPathname] = useState(window.location.pathname)

  useEffect(() => {
    const updatePathname = () => setPathname(window.location.pathname)
    window.addEventListener('popstate', updatePathname)

    return () => window.removeEventListener('popstate', updatePathname)
  }, [])

  return pathname
}

function LoadingScreen() {
  return (
    <main className="status-screen" aria-live="polite">
      <span className="spinner" aria-hidden="true" />
      <p>Checking your account…</p>
    </main>
  )
}

function SessionError({ onRetry }) {
  return (
    <main className="status-screen">
      <div className="status-card">
        <div className="status-icon" aria-hidden="true">!</div>
        <h1>We couldn’t verify your session</h1>
        <p>Check your connection and try again. Your workspace stays private until we can confirm your account.</p>
        <button className="button button-primary" type="button" onClick={onRetry}>
          Try again
        </button>
        <button className="text-button" type="button" onClick={() => navigate('/login')}>
          Back to sign in
        </button>
      </div>
    </main>
  )
}

function Workspace({ user }) {
  const { logout } = useAuth()
  const [isLoggingOut, setIsLoggingOut] = useState(false)
  const [error, setError] = useState('')

  async function handleLogout() {
    setError('')
    setIsLoggingOut(true)

    try {
      await logout()
      navigate('/login')
    } catch {
      setError('We couldn’t sign you out. Please try again.')
    } finally {
      setIsLoggingOut(false)
    }
  }

  return (
    <main className="workspace">
      <header className="workspace-header">
        <a className="wordmark" href="/app" onClick={(event) => {
          event.preventDefault()
          navigate('/app')
        }}>
          <span className="wordmark-mark" aria-hidden="true">A</span>
          <span>AI Builds My Website</span>
        </a>
        <button className="button button-quiet" type="button" onClick={handleLogout} disabled={isLoggingOut}>
          {isLoggingOut ? 'Signing out…' : 'Sign out'}
        </button>
      </header>
      <section className="workspace-content">
        <div className="workspace-eyebrow"><span className="status-dot" /> Account connected</div>
        <h1>Welcome, {user.name.split(' ')[0]}.</h1>
        <p className="workspace-intro">Your account is ready. Your website-building workspace will be waiting here.</p>
        <div className="account-card">
          <div className="account-avatar" aria-hidden="true">{user.name.charAt(0).toUpperCase()}</div>
          <div>
            <p className="account-label">Signed in as</p>
            <p className="account-email">{user.email}</p>
          </div>
          <span className="account-check" aria-label="Authenticated">✓</span>
        </div>
        {error && <p className="form-alert form-alert-error" role="alert">{error}</p>}
      </section>
      <footer className="workspace-footer">A simpler way to bring your ideas online.</footer>
    </main>
  )
}

function RoutedApp() {
  const pathname = usePathname()
  const { user, loading, authError, refreshUser } = useAuth()

  if (loading) {
    return <LoadingScreen />
  }

  if (pathname === '/app') {
    if (authError) {
      return <SessionError onRetry={refreshUser} />
    }

    if (!user) {
      return <Redirect to="/login" />
    }

    return <Workspace user={user} />
  }

  if (pathname === '/login' || pathname === '/register') {
    if (user) {
      return <Redirect to="/app" />
    }

    return (
      <AuthPage
        mode={pathname.slice(1)}
        onNavigate={navigate}
        startupError={authError}
      />
    )
  }

  return <Redirect to={user ? '/app' : '/login'} />
}

function Redirect({ to }) {
  useEffect(() => {
    window.history.replaceState({}, '', to)
    window.dispatchEvent(new PopStateEvent('popstate'))
  }, [to])

  return <LoadingScreen />
}

function App() {
  return (
    <AuthProvider>
      <RoutedApp />
    </AuthProvider>
  )
}

export default App
