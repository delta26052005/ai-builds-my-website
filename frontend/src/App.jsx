import { useEffect, useState } from 'react'
import AuthPage from './auth/AuthPage.jsx'
import { AuthProvider } from './auth/AuthProvider.jsx'
import { useAuth } from './auth/useAuth.js'
import ProjectDashboard from './projects/ProjectDashboard.jsx'
import ProjectDetailsPage from './projects/ProjectDetailsPage.jsx'
import ProjectFormPage from './projects/ProjectFormPage.jsx'
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

function RoutedApp() {
  const pathname = usePathname()
  const { user, loading, authError, refreshUser } = useAuth()
  const projectEdit = pathname.match(/^\/projects\/(\d+)\/edit$/)
  const projectDetails = pathname.match(/^\/projects\/(\d+)$/)
  const isProtectedPath = pathname === '/app' || pathname.startsWith('/projects/')

  if (loading) {
    return <LoadingScreen />
  }

  if (isProtectedPath && authError) {
    return <SessionError onRetry={refreshUser} />
  }

  if (isProtectedPath && !user) {
    return <Redirect to="/login" />
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

  if (pathname === '/app') {
    return <ProjectDashboard user={user} onNavigate={navigate} />
  }

  if (pathname === '/projects/new') {
    return <ProjectFormPage user={user} onNavigate={navigate} />
  }

  if (projectEdit) {
    return <ProjectFormPage projectId={projectEdit[1]} user={user} onNavigate={navigate} />
  }

  if (projectDetails) {
    return <ProjectDetailsPage projectId={projectDetails[1]} user={user} onNavigate={navigate} />
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
