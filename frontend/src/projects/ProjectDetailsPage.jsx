import { useEffect, useState } from 'react'
import { ApiError, api } from '../lib/api.js'
import ProjectLayout from './ProjectLayout.jsx'
import SourceManager from './SourceManager.jsx'
import './Projects.css'

const SOCIAL_LABELS = {
  instagram: 'Instagram',
  facebook: 'Facebook',
  linkedin: 'LinkedIn',
  youtube: 'YouTube',
  x: 'X',
  tiktok: 'TikTok',
}

function ProjectDetailsPage({ projectId, user, onNavigate }) {
  const [project, setProject] = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  const [deleting, setDeleting] = useState(false)

  useEffect(() => {
    let active = true

    api.getProject(projectId)
      .then((result) => {
        if (active) {
          setProject(result)
        }
      })
      .catch((requestError) => {
        if (active) {
          setError(requestError instanceof ApiError
            ? requestError.message
            : 'We couldn’t load this project. Please try again.')
        }
      })
      .finally(() => {
        if (active) {
          setLoading(false)
        }
      })

    return () => {
      active = false
    }
  }, [projectId])

  async function deleteProject() {
    if (!window.confirm(`Delete “${project.business_name}” and its uploaded files? This can’t be undone.`)) {
      return
    }

    setDeleting(true)
    setError('')

    try {
      await api.deleteProject(project.id)
      onNavigate('/app')
    } catch (requestError) {
      setError(requestError instanceof ApiError
        ? requestError.message
        : 'We couldn’t delete this project. Please try again.')
    } finally {
      setDeleting(false)
    }
  }

  const location = project
    ? [project.city, project.state, project.country].filter(Boolean).join(', ')
    : ''

  return (
    <ProjectLayout user={user} onNavigate={onNavigate}>
      <main className="projects-main project-detail-main">
        <button className="project-back-link" type="button" onClick={() => onNavigate('/app')}>
          <span aria-hidden="true">←</span> All projects
        </button>

        {loading ? (
          <div className="projects-loading" role="status">
            <span className="spinner" aria-hidden="true" />
            <span>Loading project…</span>
          </div>
        ) : error && !project ? (
          <div className="projects-alert projects-alert-error" role="alert">{error}</div>
        ) : project && (
          <>
            <section className="project-detail-heading">
              <div>
                <p className="projects-eyebrow">{project.business_category}</p>
                <h1>{project.business_name}</h1>
                <p className="projects-subtitle">{location}</p>
              </div>
              <div className="project-detail-actions">
                <span className={`project-status status-${project.status}`}>{project.status}</span>
                <button className="button button-quiet" type="button" onClick={() => onNavigate(`/projects/${project.id}/edit`)}>
                  Edit details
                </button>
                <button className="button button-danger" type="button" disabled={deleting} onClick={deleteProject}>
                  {deleting ? 'Deleting…' : 'Delete'}
                </button>
              </div>
            </section>

            {error && <p className="projects-alert projects-alert-error" role="alert">{error}</p>}

            <section className="project-detail-grid">
              <article className="project-panel project-about">
                <div className="project-panel-heading">
                  <div>
                    <p className="projects-eyebrow">Business profile</p>
                    <h2>About the business</h2>
                  </div>
                  <span className="project-detail-symbol" aria-hidden="true">✦</span>
                </div>
                <p className="project-description">
                  {project.business_description || 'No business description has been added yet.'}
                </p>
                <dl className="project-facts">
                  <div><dt>Location</dt><dd>{project.business_address || location}</dd></div>
                  <div><dt>Business email</dt><dd>{project.business_email || 'Not provided'}</dd></div>
                  <div><dt>Phone</dt><dd>{project.business_phone || 'Not provided'}</dd></div>
                  <div>
                    <dt>Website</dt>
                    <dd>
                      {project.website_url
                        ? <a href={project.website_url} target="_blank" rel="noreferrer">{project.website_url}</a>
                        : 'Not provided'}
                    </dd>
                  </div>
                  <div>
                    <dt>Google Business</dt>
                    <dd>
                      {project.google_business_url
                        ? <a href={project.google_business_url} target="_blank" rel="noreferrer">View listing</a>
                        : 'Not provided'}
                    </dd>
                  </div>
                </dl>
                {Object.entries(project.social_links || {}).some(([, url]) => url) && (
                  <div className="project-socials">
                    <h3>Online presence</h3>
                    <div className="project-social-links">
                      {Object.entries(project.social_links || {}).filter(([, url]) => url).map(([platform, url]) => (
                        <a key={platform} href={url} target="_blank" rel="noreferrer">
                          {SOCIAL_LABELS[platform] || platform}
                        </a>
                      ))}
                    </div>
                  </div>
                )}
              </article>

              <aside className="project-panel project-summary">
                <p className="projects-eyebrow">Project details</p>
                <h2>Website project</h2>
                <div className="project-summary-row">
                  <span>Status</span><span className={`project-status status-${project.status}`}>{project.status}</span>
                </div>
                <div className="project-summary-row">
                  <span>Created</span><span>{new Date(project.created_at).toLocaleDateString()}</span>
                </div>
                <div className="project-summary-row">
                  <span>Last updated</span><span>{new Date(project.updated_at).toLocaleDateString()}</span>
                </div>
                <div className="project-slug">Project ID <code>{project.slug}</code></div>
              </aside>
            </section>

            <SourceManager projectId={project.id} />
          </>
        )}
      </main>
    </ProjectLayout>
  )
}

export default ProjectDetailsPage
