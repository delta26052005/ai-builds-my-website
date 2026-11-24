import { useEffect, useState } from 'react'
import { ApiError, api } from '../lib/api.js'
import ProjectLayout from './ProjectLayout.jsx'
import './Projects.css'

const SOCIAL_PLATFORMS = [
  ['instagram', 'Instagram'],
  ['facebook', 'Facebook'],
  ['linkedin', 'LinkedIn'],
  ['youtube', 'YouTube'],
  ['x', 'X / Twitter'],
  ['tiktok', 'TikTok'],
]

const SOURCE_TYPES = [
  ['pdf', 'PDF'],
  ['price_list', 'Price list'],
  ['image', 'Business photo'],
  ['brochure', 'Brochure'],
  ['other', 'Other'],
]

const ALLOWED_EXTENSION = /\.(pdf|jpe?g|png|webp)$/i
const MAX_FILE_SIZE = 10 * 1024 * 1024
const MAX_FILES = 10

function emptyValues() {
  return {
    business_name: '',
    city: '',
    state: '',
    country: '',
    business_category: '',
    business_description: '',
    business_email: '',
    business_phone: '',
    business_address: '',
    website_url: '',
    google_business_url: '',
    social_links: Object.fromEntries(SOCIAL_PLATFORMS.map(([key]) => [key, ''])),
  }
}

function valuesFromProject(project) {
  const values = emptyValues()

  for (const field of Object.keys(values)) {
    if (field !== 'social_links') {
      values[field] = project[field] || ''
    }
  }

  values.social_links = {
    ...values.social_links,
    ...(project.social_links || {}),
  }

  return values
}

function getFieldError(errors, field) {
  const error = errors[field]

  return Array.isArray(error) ? error[0] : error
}

function looksLikeWebUrl(value) {
  try {
    const url = new URL(value)
    return ['http:', 'https:'].includes(url.protocol) && Boolean(url.hostname)
  } catch {
    return false
  }
}

function suggestedSourceType(file) {
  return file.type.startsWith('image/') || /\.(jpe?g|png|webp)$/i.test(file.name)
    ? 'image'
    : 'pdf'
}

function ProjectFormPage({ projectId, user, onNavigate }) {
  const isEditing = Boolean(projectId)
  const [values, setValues] = useState(emptyValues)
  const [selectedFiles, setSelectedFiles] = useState([])
  const [fieldErrors, setFieldErrors] = useState({})
  const [error, setError] = useState('')
  const [savedProjectId, setSavedProjectId] = useState(null)
  const [loading, setLoading] = useState(isEditing)
  const [saving, setSaving] = useState(false)

  useEffect(() => {
    if (!projectId) {
      return undefined
    }

    let active = true

    api.getProject(projectId)
      .then((project) => {
        if (active) {
          setValues(valuesFromProject(project))
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

  function updateField(event) {
    const { name, value } = event.target

    if (name.startsWith('social_links.')) {
      const platform = name.slice('social_links.'.length)
      setValues((current) => ({
        ...current,
        social_links: { ...current.social_links, [platform]: value },
      }))
    } else {
      setValues((current) => ({ ...current, [name]: value }))
    }

    setFieldErrors((current) => ({ ...current, [name]: undefined }))
    setError('')
  }

  function selectFiles(event) {
    const incoming = Array.from(event.target.files || [])
    event.target.value = ''
    setError('')

    if (selectedFiles.length + incoming.length > MAX_FILES) {
      setError(`Choose no more than ${MAX_FILES} files per upload.`)
      return
    }

    const invalidFile = incoming.find((file) => !ALLOWED_EXTENSION.test(file.name) || file.size > MAX_FILE_SIZE)
    if (invalidFile) {
      setError('Choose PDF, JPG, PNG, or WebP files up to 10 MB each.')
      return
    }

    setSelectedFiles((current) => [
      ...current,
      ...incoming.map((file, index) => ({
        id: `${file.name}-${file.lastModified}-${current.length + index}`,
        file,
        sourceType: suggestedSourceType(file),
      })),
    ])
  }

  function validateForm() {
    const errors = {}
    const requiredFields = [
      ['business_name', 'Business name'],
      ['city', 'City'],
      ['country', 'Country'],
      ['business_category', 'Business category'],
    ]

    requiredFields.forEach(([field, label]) => {
      if (!values[field].trim()) {
        errors[field] = [`${label} is required.`]
      }
    })

    if (values.business_email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(values.business_email)) {
      errors.business_email = ['Enter a valid email address.']
    }

    if (values.business_phone && !/^[0-9+().\s-]{7,30}$/.test(values.business_phone)) {
      errors.business_phone = ['Enter a valid phone number.']
    }

    for (const field of ['website_url', 'google_business_url']) {
      if (values[field] && !looksLikeWebUrl(values[field])) {
        errors[field] = ['Enter a valid http or https URL.']
      }
    }

    for (const [platform] of SOCIAL_PLATFORMS) {
      const value = values.social_links[platform]
      if (value && !looksLikeWebUrl(value)) {
        errors[`social_links.${platform}`] = ['Enter a valid http or https URL.']
      }
    }

    return errors
  }

  async function submit(event) {
    event.preventDefault()
    setError('')
    setFieldErrors({})

    const errors = validateForm()
    if (Object.keys(errors).length > 0) {
      setFieldErrors(errors)
      setError('Please review the highlighted information.')
      return
    }

    setSaving(true)

    try {
      const projectData = {
        ...values,
        social_links: Object.fromEntries(
          Object.entries(values.social_links).map(([key, value]) => [key, value.trim() || null]),
        ),
      }

      const existingId = projectId || savedProjectId
      const project = existingId
        ? await api.updateProject(existingId, projectData)
        : await api.createProject(projectData)

      if (!isEditing && !savedProjectId) {
        setSavedProjectId(project.id)
      }

      if (selectedFiles.length > 0) {
        await api.uploadProjectSources(project.id, selectedFiles)
      }

      onNavigate(`/projects/${project.id}`)
    } catch (requestError) {
      if (requestError instanceof ApiError) {
        setFieldErrors(requestError.errors)
        setError(requestError.message)
      } else {
        setError('Your project could not be saved. Please try again.')
      }
    } finally {
      setSaving(false)
    }
  }

  function renderField(field, label, options = {}) {
    const errorMessage = getFieldError(fieldErrors, field)
    const shared = {
      id: field,
      name: field,
      value: values[field],
      onChange: updateField,
      required: options.required,
      placeholder: options.placeholder || '',
      'aria-invalid': Boolean(errorMessage),
      'aria-describedby': errorMessage ? `${field}-error` : undefined,
      maxLength: options.maxLength,
    }

    return (
      <div className={`project-form-field ${options.wide ? 'field-wide' : ''}`}>
        <label htmlFor={field}>{label}{options.required && <span aria-hidden="true"> *</span>}</label>
        {options.multiline ? (
          <textarea {...shared} rows={options.rows || 4} />
        ) : (
          <input {...shared} type={options.type || 'text'} autoComplete={options.autoComplete} />
        )}
        {errorMessage && <p className="project-field-error" id={`${field}-error`}>{errorMessage}</p>}
      </div>
    )
  }

  if (loading) {
    return (
      <ProjectLayout user={user} onNavigate={onNavigate}>
        <main className="projects-main"><div className="projects-loading" role="status"><span className="spinner" /> Loading project…</div></main>
      </ProjectLayout>
    )
  }

  if (isEditing && error && !values.business_name) {
    return (
      <ProjectLayout user={user} onNavigate={onNavigate}>
        <main className="projects-main">
          <p className="projects-alert projects-alert-error" role="alert">{error}</p>
          <button type="button" className="project-back-link" onClick={() => onNavigate('/app')}>← Back to projects</button>
        </main>
      </ProjectLayout>
    )
  }

  return (
    <ProjectLayout user={user} onNavigate={onNavigate}>
      <main className="projects-main project-form-main">
        <button className="project-back-link" type="button" onClick={() => onNavigate(isEditing ? `/projects/${projectId}` : '/app')}>
          <span aria-hidden="true">←</span> {isEditing ? 'Back to project' : 'All projects'}
        </button>
        <div className="project-form-heading">
          <p className="projects-eyebrow">{isEditing ? 'Project settings' : 'New website project'}</p>
          <h1>{isEditing ? 'Edit business details' : 'Tell us about your business'}</h1>
          <p className="projects-subtitle">A few details help us keep everything for your website together.</p>
        </div>

        {error && <p className="projects-alert projects-alert-error" role="alert">{error}</p>}
        {savedProjectId && (
          <p className="project-saved-note">
            Your project has been saved. You can retry the upload or continue to its details.
            <button type="button" className="text-link" onClick={() => onNavigate(`/projects/${savedProjectId}`)}>
              Open project
            </button>
          </p>
        )}

        <form className="project-form" onSubmit={submit} noValidate>
          <section className="project-panel project-form-section">
            <div className="project-panel-heading">
              <div>
                <p className="projects-eyebrow">Section 01</p>
                <h2>Business information</h2>
                <p className="project-section-copy">The essentials about your business and where customers can find you.</p>
              </div>
            </div>
            <div className="project-form-grid">
              {renderField('business_name', 'Business name', { required: true, maxLength: 255, placeholder: 'e.g. Northstar Studio' })}
              {renderField('business_category', 'Business category', { required: true, maxLength: 120, placeholder: 'e.g. Interior design' })}
              {renderField('city', 'City', { required: true, maxLength: 120, placeholder: 'Portland' })}
              {renderField('state', 'State / region', { maxLength: 120, placeholder: 'Oregon' })}
              {renderField('country', 'Country', { required: true, maxLength: 120, placeholder: 'United States' })}
              {renderField('business_email', 'Business email', { type: 'email', maxLength: 255, placeholder: 'hello@example.com' })}
              {renderField('business_phone', 'Business phone', { type: 'tel', maxLength: 30, placeholder: '+1 (555) 010-1234' })}
              {renderField('business_address', 'Business address', { maxLength: 500, wide: true, placeholder: 'Street address or service area' })}
              {renderField('business_description', 'Business description', { multiline: true, rows: 5, maxLength: 5000, wide: true, placeholder: 'What do you do, who do you help, and what makes your business unique?' })}
              {renderField('website_url', 'Existing website', { type: 'url', maxLength: 2048, placeholder: 'https://example.com' })}
              {renderField('google_business_url', 'Google Business listing', { type: 'url', maxLength: 2048, placeholder: 'https://maps.google.com/…' })}
            </div>
          </section>

          <section className="project-panel project-form-section">
            <div className="project-panel-heading">
              <div>
                <p className="projects-eyebrow">Section 02</p>
                <h2>Online presence</h2>
                <p className="project-section-copy">Add the social profiles you’d like associated with this business.</p>
              </div>
            </div>
            <div className="project-form-grid">
              {SOCIAL_PLATFORMS.map(([platform, label]) => {
                const field = `social_links.${platform}`
                const errorMessage = getFieldError(fieldErrors, field)

                return (
                  <div className="project-form-field" key={platform}>
                    <label htmlFor={field}>{label}</label>
                    <input
                      id={field}
                      name={field}
                      type="url"
                      value={values.social_links[platform] || ''}
                      onChange={updateField}
                      placeholder={`https://${platform === 'x' ? 'x' : platform}.com/yourbusiness`}
                      maxLength={2048}
                      aria-invalid={Boolean(errorMessage)}
                      aria-describedby={errorMessage ? `${field}-error` : undefined}
                    />
                    {errorMessage && <p className="project-field-error" id={`${field}-error`}>{errorMessage}</p>}
                  </div>
                )
              })}
            </div>
          </section>

          <section className="project-panel project-form-section">
            <div className="project-panel-heading">
              <div>
                <p className="projects-eyebrow">Section 03</p>
                <h2>Business materials</h2>
                <p className="project-section-copy">Upload brochures, price lists, and business photos. Files stay private to your project.</p>
              </div>
            </div>
            <label className="project-file-drop">
              <input
                type="file"
                accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp"
                multiple
                onChange={selectFiles}
                disabled={saving}
              />
              <span className="project-file-icon" aria-hidden="true">↑</span>
              <strong>Choose files to upload</strong>
              <span>PDF, JPG, PNG, or WebP · up to 10 files at a time · 10 MB per file</span>
            </label>

            {selectedFiles.length > 0 && (
              <div className="project-selected-files">
                {selectedFiles.map((item) => (
                  <div className="source-row source-selected-row" key={item.id}>
                    <span className="source-file-icon" aria-hidden="true">↥</span>
                    <div className="source-file-info">
                      <strong>{item.file.name}</strong>
                      <span>{(item.file.size / 1024).toFixed(0)} KB</span>
                    </div>
                    <select
                      aria-label={`Category for ${item.file.name}`}
                      value={item.sourceType}
                      onChange={(event) => setSelectedFiles((current) => current.map((entry) => (
                        entry.id === item.id ? { ...entry, sourceType: event.target.value } : entry
                      )))}
                    >
                      {SOURCE_TYPES.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
                    </select>
                    <button
                      type="button"
                      className="source-remove"
                      aria-label={`Remove ${item.file.name}`}
                      onClick={() => setSelectedFiles((current) => current.filter((entry) => entry.id !== item.id))}
                    >
                      ×
                    </button>
                  </div>
                ))}
              </div>
            )}

            {isEditing && (
              <p className="project-section-copy project-upload-note">
                Existing uploads can be reviewed and removed from the project details page.
                {' '}
                <button type="button" className="text-link" onClick={() => onNavigate(`/projects/${projectId}`)}>
                  View uploaded files
                </button>
              </p>
            )}
          </section>

          <div className="project-form-actions">
            <button className="button button-quiet" type="button" onClick={() => onNavigate(isEditing ? `/projects/${projectId}` : '/app')}>
              Cancel
            </button>
            <button className="button button-primary" type="submit" disabled={saving}>
              {saving
                ? (selectedFiles.length ? 'Saving and uploading…' : 'Saving…')
                : (isEditing ? 'Save changes' : 'Create website project')}
            </button>
          </div>
        </form>
      </main>
    </ProjectLayout>
  )
}

export default ProjectFormPage
