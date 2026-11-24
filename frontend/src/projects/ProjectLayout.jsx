import { useState } from 'react'
import { useAuth } from '../auth/useAuth.js'
import './Projects.css'

function ProjectLayout({ children, user, onNavigate }) {
  const { logout } = useAuth()
  const [signingOut, setSigningOut] = useState(false)
  const [logoutError, setLogoutError] = useState('')

  async function handleLogout() {
    setLogoutError('')
    setSigningOut(true)

    try {
      await logout()
      onNavigate('/login')
    } catch {
      setLogoutError('We couldn’t sign you out. Please try again.')
    } finally {
      setSigningOut(false)
    }
  }

  return (
    <div className="projects-shell">
      <header className="projects-header">
        <a
          className="projects-brand"
          href="/app"
          onClick={(event) => {
            event.preventDefault()
            onNavigate('/app')
          }}
        >
          <span className="projects-brand-mark" aria-hidden="true">A</span>
          <span>AI Builds My Website</span>
        </a>
        <nav className="projects-nav" aria-label="Main navigation">
          <button type="button" className="projects-nav-link" onClick={() => onNavigate('/app')}>
            My projects
          </button>
          <button type="button" className="button button-primary projects-new-button" onClick={() => onNavigate('/projects/new')}>
            <span aria-hidden="true">+</span> New website
          </button>
          <div className="projects-user">
            <span className="projects-user-avatar" aria-hidden="true">
              {user.name.charAt(0).toUpperCase()}
            </span>
            <span className="projects-user-name">{user.name}</span>
          </div>
          <button className="projects-logout" type="button" onClick={handleLogout} disabled={signingOut}>
            {signingOut ? 'Signing out…' : 'Sign out'}
          </button>
        </nav>
      </header>
      {logoutError && <p className="projects-global-error" role="alert">{logoutError}</p>}
      {children}
    </div>
  )
}

export default ProjectLayout
