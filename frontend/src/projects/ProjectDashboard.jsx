import { useEffect, useState } from 'react'
import { ApiError, api } from '../lib/api.js'
import ProjectLayout from './ProjectLayout.jsx'
import './Projects.css'

function locationLabel(project) {
  return [project.city, project.state, project.country].filter(Boolean).join(', ')
}

function formatDate(value) {
  return new Intl.DateTimeFormat(undefined, {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  }).format(new Date(value))
}

function ProjectDashboard({ user, onNavigate }) {
  const [projects, setProjects] = useState([])
  const [pagination, setPagination] = useState(null)
  const [page, setPage] = useState(1)
  const [loadedRequest, setLoadedRequest] = useState('')
  const [reloadKey, setReloadKey] = useState(0)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [deletingId, setDeletingId] = useState(null)
  const requestKey = `${page}-${reloadKey}`
  const loading = loadedRequest !== requestKey

  useEffect(() => {
    let active = true

    api.listProjects(page)
      .then((response) => {
        if (active) {
          setProjects(response.data)
          setPagination(response.meta)
          setError('')
        }
      })
      .catch((requestError) => {
        if (active) {
          setError(requestError instanceof ApiError
            ? requestError.message
            : 'We couldn’t load your projects. Please try again.')
        }
      })
      .finally(() => {
        if (active) {
          setLoadedRequest(requestKey)
        }
      })

    return () => {
      active = false
    }
  }, [page, reloadKey, requestKey])

  async function deleteProject(project) {
    if (!window.confirm(`Delete “${project.business_name}” and its uploaded files? This can’t be undone.`)) {
      return
    }

    setDeletingId(project.id)
    setError('')
    setNotice('')

    try {
      await api.deleteProject(project.id)
      setProjects((current) => current.filter((item) => item.id !== project.id))
      setNotice(`${project.business_name} was deleted.`)
    } catch (requestError) {
      setError(requestError instanceof ApiError
        ? requestError.message
        : 'We couldn’t delete this project. Please try again.')
    } finally {
      setDeletingId(null)
    }
  }

  return (
    <ProjectLayout user={user} onNavigate={onNavigate}>
      <main className="projects-main">
        <section className="projects-welcome">
          <div>
            <p className="projects-eyebrow">Your workspace</p>
            <h1>Welcome back, {user.name.split(' ')[0]}.</h1>
            <p className="projects-subtitle">Keep your business details and website projects organized in one place.</p>
          </div>
          <button className="button button-primary projects-create-cta" type="button" onClick={() => onNavigate('/projects/new')}>
            <span aria-hidden="true">+</span> Create new website
          </button>
        </section>

        <div className="projects-list-heading">
          <div>
            <h2>Your websites</h2>
            {!loading && pagination && (
              <span className="projects-count">
                {pagination.total} {pagination.total === 1 ? 'project' : 'projects'}
              </span>
            )}
          </div>
          {pagination?.last_page > 1 && (
            <div className="projects-pagination" aria-label="Project pages">
              <button type="button" disabled={page <= 1} onClick={() => setPage((current) => current - 1)}>
                Previous
              </button>
              <span>{pagination.current_page} / {pagination.last_page}</span>
              <button type="button" disabled={page >= pagination.last_page} onClick={() => setPage((current) => current + 1)}>
                Next
              </button>
            </div>
          )}
        </div>

        {error && <p className="projects-alert projects-alert-error" role="alert">{error}</p>}
        {error && (
          <button className="project-back-link" type="button" onClick={() => setReloadKey((current) => current + 1)}>
            Try loading projects again
          </button>
        )}
        {notice && <p className="projects-alert projects-alert-success" role="status">{notice}</p>}

        {loading ? (
          <div className="projects-loading" role="status">
            <span className="spinner" aria-hidden="true" />
            <span>Loading your projects…</span>
          </div>
        ) : projects.length === 0 ? (
          <section className="projects-empty">
            <div className="projects-empty-icon" aria-hidden="true">
              <svg viewBox="0 0 48 48" fill="none">
                <rect x="7" y="9" width="34" height="30" rx="5" stroke="currentColor" strokeWidth="1.7" />
                <path d="M7 17h34M14 13h.01M18 13h.01M22 13h.01M18 27h12M18 32h8" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" />
              </svg>
            </div>
            <h3>Your next website starts here</h3>
            <p>Add your business details and materials to create your first website project.</p>
            <button className="button button-primary" type="button" onClick={() => onNavigate('/projects/new')}>
              Create your first website
            </button>
          </section>
        ) : (
          <section className="project-grid" aria-label="Website projects">
            {projects.map((project) => (
              <article className="project-card" key={project.id}>
                <button
                  type="button"
                  className="project-card-main"
                  onClick={() => onNavigate(`/projects/${project.id}`)}
                >
                  <span className="project-card-icon" aria-hidden="true">
                    {project.business_name.charAt(0).toUpperCase()}
                  </span>
                  <span className="project-card-title">{project.business_name}</span>
                  <span className="project-card-location">{locationLabel(project)}</span>
                  <span className={`project-status status-${project.status}`}>{project.status}</span>
                </button>
                <div className="project-card-meta">
                  <span>{project.business_category}</span>
                  <span>Updated {formatDate(project.updated_at)}</span>
                </div>
                <div className="project-card-actions">
                  <button type="button" onClick={() => onNavigate(`/projects/${project.id}`)}>
                    Open project
                  </button>
                  <button type="button" onClick={() => onNavigate(`/projects/${project.id}/edit`)}>
                    Edit
                  </button>
                  <button
                    type="button"
                    className="project-delete-action"
                    disabled={deletingId === project.id}
                    onClick={() => deleteProject(project)}
                  >
                    {deletingId === project.id ? 'Deleting…' : 'Delete'}
                  </button>
                </div>
              </article>
            ))}
          </section>
        )}
      </main>
    </ProjectLayout>
  )
}

export default ProjectDashboard
