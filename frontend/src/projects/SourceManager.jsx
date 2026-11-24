import { useEffect, useState } from 'react'
import { ApiError, api } from '../lib/api.js'
import './Projects.css'

const MAX_FILES = 10
const MAX_FILE_SIZE = 10 * 1024 * 1024
const ALLOWED_EXTENSION = /\.(pdf|jpe?g|png|webp)$/i
const SOURCE_TYPES = [
  ['pdf', 'PDF'],
  ['price_list', 'Price list'],
  ['image', 'Business photo'],
  ['brochure', 'Brochure'],
  ['other', 'Other'],
]

function suggestedType(file) {
  return file.type.startsWith('image/') || /\.(jpe?g|png|webp)$/i.test(file.name)
    ? 'image'
    : 'pdf'
}

function readableSize(bytes) {
  return bytes < 1024 * 1024
    ? `${Math.max(1, Math.round(bytes / 1024))} KB`
    : `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

function SourceManager({ projectId }) {
  const [sources, setSources] = useState([])
  const [selectedFiles, setSelectedFiles] = useState([])
  const [loading, setLoading] = useState(true)
  const [uploading, setUploading] = useState(false)
  const [deletingId, setDeletingId] = useState(null)
  const [retryingId, setRetryingId] = useState(null)
  const [loadingTextId, setLoadingTextId] = useState(null)
  const [expandedTextId, setExpandedTextId] = useState(null)
  const [textBySource, setTextBySource] = useState({})
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')

  const hasPendingSources = sources.some((source) => (
    ['pending', 'processing'].includes(source.extraction?.status)
  ))

  useEffect(() => {
    let active = true

    api.listProjectSources(projectId)
      .then((result) => {
        if (active) {
          setSources(result)
        }
      })
      .catch((requestError) => {
        if (active) {
          setError(requestError instanceof ApiError
            ? requestError.message
            : 'We couldn’t load uploaded materials.')
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

  useEffect(() => {
    if (!hasPendingSources) {
      return undefined
    }

    const intervalId = window.setInterval(() => {
      api.listProjectSources(projectId)
        .then(setSources)
        .catch((requestError) => {
          setError(requestError instanceof ApiError
            ? requestError.message
            : 'We couldn’t refresh document processing status.')
        })
    }, 3000)

    return () => window.clearInterval(intervalId)
  }, [hasPendingSources, projectId])

  function chooseFiles(event) {
    const incoming = Array.from(event.target.files || [])
    event.target.value = ''
    setError('')
    setNotice('')

    if (selectedFiles.length + incoming.length > MAX_FILES) {
      setError(`Upload up to ${MAX_FILES} files at a time.`)
      return
    }

    const invalid = incoming.find((file) => !ALLOWED_EXTENSION.test(file.name) || file.size > MAX_FILE_SIZE)
    if (invalid) {
      setError('Choose PDF, JPG, PNG, or WebP files up to 10 MB each.')
      return
    }

    setSelectedFiles((current) => [
      ...current,
      ...incoming.map((file, index) => ({
        id: `${file.name}-${file.lastModified}-${current.length + index}`,
        file,
        sourceType: suggestedType(file),
      })),
    ])
  }

  async function uploadFiles() {
    if (selectedFiles.length === 0) {
      return
    }

    setUploading(true)
    setError('')
    setNotice('')

    try {
      const response = await api.uploadProjectSources(projectId, selectedFiles)
      setSources((current) => [...response.data, ...current])
      setSelectedFiles([])
      setNotice('Your files were uploaded securely.')
    } catch (requestError) {
      setError(requestError instanceof ApiError
        ? requestError.message
        : 'We couldn’t upload these files. Please try again.')
    } finally {
      setUploading(false)
    }
  }

  async function removeSource(source) {
    if (!window.confirm(`Remove “${source.original_filename}” from this project?`)) {
      return
    }

    setDeletingId(source.id)
    setError('')
    setNotice('')

    try {
      await api.deleteProjectSource(projectId, source.id)
      setSources((current) => current.filter((item) => item.id !== source.id))
      setTextBySource((current) => {
        const next = { ...current }
        delete next[source.id]
        return next
      })
      setNotice('The uploaded file was removed.')
    } catch (requestError) {
      setError(requestError instanceof ApiError
        ? requestError.message
        : 'We couldn’t remove this file. Please try again.')
    } finally {
      setDeletingId(null)
    }
  }

  async function retryProcessing(source) {
    setRetryingId(source.id)
    setError('')
    setNotice('')

    try {
      const response = await api.processProjectSource(projectId, source.id)
      setSources((current) => current.map((item) => (
        item.id === source.id
          ? { ...item, processing_status: response.data.status, extraction: response.data }
          : item
      )))
      setNotice('Document processing has been queued.')
    } catch (requestError) {
      setError(requestError instanceof ApiError
        ? requestError.message
        : 'We couldn’t retry document processing.')
    } finally {
      setRetryingId(null)
    }
  }

  async function toggleFullText(source) {
    if (expandedTextId === source.id) {
      setExpandedTextId(null)
      return
    }

    setExpandedTextId(source.id)

    if (Object.hasOwn(textBySource, source.id)) {
      return
    }

    setLoadingTextId(source.id)
    setError('')

    try {
      const result = await api.getProjectSourceContent(projectId, source.id)
      setTextBySource((current) => ({ ...current, [source.id]: result.extracted_text }))
    } catch (requestError) {
      setExpandedTextId(null)
      setError(requestError instanceof ApiError
        ? requestError.message
        : 'We couldn’t load the extracted text.')
    } finally {
      setLoadingTextId(null)
    }
  }

  return (
    <section className="project-panel source-manager">
      <div className="project-panel-heading source-heading">
        <div>
          <p className="projects-eyebrow">Business materials</p>
          <h2>Files and photos</h2>
          <p className="source-help">Private uploads · PDF, JPG, PNG or WebP · 10 MB max per file</p>
        </div>
        <label className="button button-quiet source-select-button">
          Add files
          <input
            type="file"
            accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp"
            multiple
            onChange={chooseFiles}
            disabled={uploading || loading}
          />
        </label>
      </div>

      {error && <p className="projects-alert projects-alert-error" role="alert">{error}</p>}
      {notice && <p className="projects-alert projects-alert-success" role="status">{notice}</p>}

      {selectedFiles.length > 0 && (
        <div className="source-selected">
          <h3>Ready to upload ({selectedFiles.length})</h3>
          {selectedFiles.map((item) => (
            <div className="source-row source-selected-row" key={item.id}>
              <span className="source-file-icon" aria-hidden="true">↥</span>
              <div className="source-file-info">
                <strong>{item.file.name}</strong>
                <span>{readableSize(item.file.size)}</span>
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
                aria-label={`Remove ${item.file.name} from upload`}
                onClick={() => setSelectedFiles((current) => current.filter((entry) => entry.id !== item.id))}
              >
                ×
              </button>
            </div>
          ))}
          <button className="button button-primary source-upload-button" type="button" onClick={uploadFiles} disabled={uploading}>
            {uploading ? 'Uploading files…' : `Upload ${selectedFiles.length} ${selectedFiles.length === 1 ? 'file' : 'files'}`}
          </button>
        </div>
      )}

      {loading ? (
        <div className="source-loading" role="status">Loading uploaded files…</div>
      ) : sources.length === 0 ? (
        <div className="source-empty">
          <p>No business materials uploaded yet.</p>
          <span>Add a brochure, price list, or business photo to keep it with this project.</span>
        </div>
      ) : (
        <div className="source-list">
          {sources.map((source) => {
            const extraction = source.extraction
            const status = extraction?.status
              || (source.processing_status === 'pending' ? 'uploaded' : source.processing_status || 'uploaded')
            const statusLabels = {
              uploaded: 'Uploaded',
              pending: 'Pending',
              processing: 'Processing',
              completed: 'Completed',
              failed: 'Failed',
              awaiting_ocr: 'Awaiting OCR',
              awaiting_vision: 'Awaiting vision',
            }

            return (
              <article className="source-item" key={source.id}>
                <div className="source-row">
                  <span className={`source-file-icon ${source.mime_type === 'application/pdf' ? 'source-pdf' : 'source-image'}`} aria-hidden="true">
                    {source.mime_type === 'application/pdf' ? 'PDF' : 'IMG'}
                  </span>
                  <div className="source-file-info">
                    <strong>{source.original_filename}</strong>
                    <span>
                      {readableSize(source.file_size)} · {source.source_type.replace('_', ' ')} ·{' '}
                      {new Date(source.created_at).toLocaleDateString()}
                    </span>
                  </div>
                  <span className={`source-processing-status source-status-${status}`}>
                    {statusLabels[status] || status}
                  </span>
                  <button
                    type="button"
                    className="source-remove"
                    aria-label={`Delete ${source.original_filename}`}
                    disabled={deletingId === source.id}
                    onClick={() => removeSource(source)}
                  >
                    {deletingId === source.id ? '…' : '×'}
                  </button>
                </div>

                {extraction && (
                  <div className="source-extraction">
                    {extraction.extraction_method && (
                      <span>
                        Method: {extraction.extraction_method}
                        {extraction.page_count ? ` · ${extraction.page_count} ${extraction.page_count === 1 ? 'page' : 'pages'}` : ''}
                      </span>
                    )}
                    {extraction.error_message && (
                      <p className={status === 'failed' ? 'source-extraction-error' : ''}>
                        {extraction.error_message}
                      </p>
                    )}
                    {status === 'completed' && (
                      <>
                        <p className="source-preview-note">Extracted text preview · Document content is unverified.</p>
                        <p className="source-text-preview">{extraction.text_preview}</p>
                        <button
                          type="button"
                          className="source-text-toggle"
                          disabled={loadingTextId === source.id}
                          onClick={() => toggleFullText(source)}
                        >
                          {loadingTextId === source.id
                            ? 'Loading text…'
                            : expandedTextId === source.id
                              ? 'Hide full text'
                              : 'View full text'}
                        </button>
                        {expandedTextId === source.id && Object.hasOwn(textBySource, source.id) && (
                          <pre className="source-full-text">{textBySource[source.id]}</pre>
                        )}
                      </>
                    )}
                    {['failed', 'uploaded'].includes(status) && (
                      <button
                        type="button"
                        className="source-text-toggle"
                        disabled={retryingId === source.id}
                        onClick={() => retryProcessing(source)}
                      >
                        {retryingId === source.id
                          ? 'Starting…'
                          : status === 'uploaded'
                            ? 'Start processing'
                            : 'Retry processing'}
                      </button>
                    )}
                  </div>
                )}
              </article>
            )
          })}
        </div>
      )}
    </section>
  )
}

export default SourceManager
