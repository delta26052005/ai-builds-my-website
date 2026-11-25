import { useCallback, useEffect, useId, useMemo, useRef, useState } from 'react'
import { ApiError, api } from '../lib/api.js'
import { ToastViewport } from '../components/ToastNotifications.jsx'
import { useToastNotifications } from '../components/useToastNotifications.js'
import './WebsiteBuilder.css'

const TEMPLATES = [
  { key: 'modern_business', label: 'Modern business', description: 'Clean, confident, and contemporary.' },
  { key: 'local_business', label: 'Local business', description: 'Warm, welcoming, and community-focused.' },
]

const SECTIONS = [
  ['hero', 'Hero'],
  ['about', 'About'],
  ['services_or_products', 'Services & products'],
  ['highlights', 'Highlights'],
  ['contact', 'Contact'],
  ['footer', 'Footer'],
]

const EMPTY_WEBSITE = {
  business_name: '',
  tagline: '',
  template_key: 'modern_business',
  theme: { primary_color: '#3157d5', secondary_color: '#e8edff', font_style: 'modern' },
  navigation: [],
  hero: { heading: '', description: '', image: '', button_text: '' },
  about: { heading: '', description: '' },
  services_or_products: [],
  highlights: [],
  gallery: [],
  contact: { address: '', phone: '', email: '', social_links: [] },
  footer: { copyright_text: '', show_business_name: true },
  content_provenance: {},
}

function messageFor(error, fallback) {
  if (!(error instanceof ApiError)) {
    return fallback
  }

  const validationMessage = Object.values(error.errors || {})
    .flat()
    .find((message) => typeof message === 'string' && message.length > 0)

  return validationMessage || error.message || fallback
}

function safeColor(value, fallback) {
  return typeof value === 'string'
    && /^#[\da-f]{6}$/i.test(value)
    ? value
    : fallback
}

function normalizeWebsite(value, project) {
  const raw = value && typeof value === 'object'
    ? value
    : {}
  const theme = raw.theme && typeof raw.theme === 'object' ? raw.theme : {}
  const hero = raw.hero && typeof raw.hero === 'object' ? raw.hero : {}
  const about = raw.about && typeof raw.about === 'object' ? raw.about : {}
  const contact = raw.contact && typeof raw.contact === 'object' ? raw.contact : {}
  const footer = raw.footer && typeof raw.footer === 'object' ? raw.footer : {}
  const field = (object, key, fallback = '') => (
    object[key] === undefined ? fallback : object[key]
  )
  const provenance = raw.content_provenance && typeof raw.content_provenance === 'object'
    ? { ...raw.content_provenance }
    : {}
  const markUnclassified = (path, text) => {
    if (typeof text === 'string' && text.trim() && !provenance[path]) {
      provenance[path] = 'legacy_unclassified'
    }
  }

  markUnclassified('business_name', raw.business_name)
  markUnclassified('tagline', raw.tagline)
  markUnclassified('hero.heading', hero.heading)
  markUnclassified('hero.description', hero.description)
  markUnclassified('hero.button_text', hero.button_text)
  markUnclassified('about.heading', about.heading)
  markUnclassified('about.description', about.description)
  markUnclassified('contact.address', contact.address)
  markUnclassified('contact.phone', contact.phone)
  markUnclassified('contact.email', contact.email)
  markUnclassified('footer.copyright_text', footer.copyright_text)
  ;(Array.isArray(raw.navigation) ? raw.navigation : []).forEach((item, index) => {
    markUnclassified(`navigation.${index}.label`, item?.label)
  })
  ;(Array.isArray(raw.services_or_products) ? raw.services_or_products : []).forEach((item, index) => {
    markUnclassified(`services_or_products.${index}.name`, item?.name)
    markUnclassified(`services_or_products.${index}.description`, item?.description)
  })
  ;(Array.isArray(raw.highlights) ? raw.highlights : []).forEach((item, index) => {
    markUnclassified(`highlights.${index}`, typeof item === 'string' ? item : textValue(item, ['text', 'label', 'title']))
  })
  ;(Array.isArray(raw.gallery) ? raw.gallery : []).forEach((item, index) => {
    markUnclassified(`gallery.${index}.alt`, item?.alt)
  })
  ;(Array.isArray(contact.social_links) ? contact.social_links : []).forEach((item, index) => {
    markUnclassified(`contact.social_links.${index}.label`, item?.label)
    markUnclassified(`contact.social_links.${index}.url`, item?.url)
  })

  return {
    business_name: raw.business_name ?? project.business_name ?? '',
    tagline: field(raw, 'tagline'),
    template_key: TEMPLATES.some(({ key }) => key === raw.template_key)
      ? raw.template_key
      : 'modern_business',
    theme: {
      primary_color: safeColor(theme.primary_color, EMPTY_WEBSITE.theme.primary_color),
      secondary_color: safeColor(theme.secondary_color, EMPTY_WEBSITE.theme.secondary_color),
      font_style: ['modern', 'classic', 'editorial'].includes(theme.font_style)
        ? theme.font_style
        : 'modern',
    },
    navigation: Array.isArray(raw.navigation)
      ? raw.navigation.map((item) => ({
        label: field(item || {}, 'label'),
        href: safeAnchor(field(item || {}, 'href', '#hero')),
      }))
      : [],
    hero: {
      heading: field(hero, 'heading'),
      description: field(hero, 'description'),
      image: field(hero, 'image'),
      button_text: field(hero, 'button_text'),
    },
    about: {
      heading: field(about, 'heading'),
      description: field(about, 'description'),
    },
    services_or_products: Array.isArray(raw.services_or_products)
      ? raw.services_or_products.map((item) => ({
        name: field(item || {}, 'name', typeof item === 'string' ? item : ''),
        description: field(item || {}, 'description'),
      }))
      : [],
    highlights: Array.isArray(raw.highlights)
      ? raw.highlights.map((item) => typeof item === 'string' ? item : textValue(item, ['text', 'label', 'title']))
      : [],
    gallery: Array.isArray(raw.gallery)
      ? raw.gallery.map((item) => ({
        image_url: field(item || {}, 'image_url'),
        alt: field(item || {}, 'alt'),
      }))
      : [],
    contact: {
      address: field(contact, 'address', project.business_address || ''),
      phone: field(contact, 'phone', project.business_phone || ''),
      email: field(contact, 'email', project.business_email || ''),
      social_links: Array.isArray(contact.social_links)
        ? contact.social_links.map((item) => ({
          label: field(item || {}, 'label', field(item || {}, 'platform')),
          url: field(item || {}, 'url'),
        }))
        : [],
    },
    footer: {
      copyright_text: field(footer, 'copyright_text'),
      show_business_name: footer.show_business_name !== false,
    },
    content_provenance: provenance,
  }
}

function websiteFromRevision(revision) {
  if (!revision || typeof revision !== 'object') {
    return null
  }

  const content = revision.content_json
  if (typeof content === 'string') {
    try {
      return JSON.parse(content)
    } catch {
      return {}
    }
  }

  return content
    || revision.website
    || revision.content
    || revision.website_content
    || revision.snapshot
    || revision
}

function contentFromRecord(record) {
  if (!record || typeof record !== 'object') {
    return null
  }

  let content = record.content_json
    || record.website
    || record.content
    || record.website_content

  if (!content && ('business_name' in record || 'hero' in record)) {
    content = record
  }
  if (typeof content === 'string') {
    try {
      content = JSON.parse(content)
    } catch {
      return null
    }
  }

  return content && typeof content === 'object' && !Array.isArray(content) ? content : null
}

function textValue(item, keys) {
  if (typeof item === 'string' || typeof item === 'number') {
    return String(item)
  }

  if (!item || typeof item !== 'object') {
    return ''
  }

  const key = keys.find((candidate) => item[candidate] !== undefined && item[candidate] !== null)
  return key ? String(item[key]) : ''
}

function titleOf(item, mode) {
  return textValue(item, mode === 'gallery'
    ? ['alt', 'caption', 'title', 'name', 'label', 'heading', 'text']
    : mode === 'navigation'
      ? ['label', 'title', 'name', 'text']
      : mode === 'social'
        ? ['label', 'platform', 'name', 'title']
        : ['title', 'name', 'heading', 'label', 'text'])
}

function imageOf(item) {
  return textValue(item, ['image_url', 'image', 'url'])
}

function altOf(item) {
  return textValue(item, ['alt'])
}

function safeImageUrl(value) {
  if (!value) {
    return ''
  }

  try {
    const url = new URL(value, window.location.origin)
    return url.protocol === 'https:' ? url.href : ''
  } catch {
    return ''
  }
}

function imageSourceOf(item, projectId) {
  const sourceId = Number(item?.source_id)

  if (Number.isSafeInteger(sourceId) && sourceId > 0) {
    return api.projectSourceImageUrl(projectId, sourceId)
  }

  return safeImageUrl(imageOf(item))
}

function linkOf(item) {
  return textValue(item, ['url', 'href', 'link'])
}

function safeAnchor(value) {
  const anchors = ['#hero', '#about', '#services', '#highlights', '#gallery', '#contact']
  return anchors.includes(value) ? value : '#hero'
}

function safeExternalHref(value) {
  if (!value) {
    return ''
  }

  try {
    const url = new URL(value)
    return url.protocol === 'https:' ? url.href : ''
  } catch {
    return ''
  }
}

function patchEntry(item, field, value, mode) {
  const keys = {
    title: mode === 'gallery'
      ? ['alt', 'caption', 'title', 'name', 'label', 'heading', 'text']
      : mode === 'navigation'
        ? ['label', 'title', 'name', 'text']
        : mode === 'social'
          ? ['label', 'platform', 'name', 'title']
          : ['title', 'name', 'heading', 'label', 'text'],
    image_url: ['image_url', 'image'],
    url: ['url', 'href', 'link'],
    href: ['href', 'url', 'link'],
    alt: ['alt'],
  }

  if (typeof item === 'string' && field === 'title') {
    return value
  }

  const result = item && typeof item === 'object'
    ? { ...item }
    : mode === 'navigation' || mode === 'social'
      ? { label: String(item || '') }
      : mode === 'gallery'
        ? { image_url: '', alt: String(item || '') }
        : { name: String(item || '') }
  const property = (keys[field] || [field]).find((key) => key in result) || (keys[field] || [field])[0]
  result[property] = value
  if (field === 'image_url') {
    delete result.source_id
  }

  return result
}

function OriginLabel({ origin }) {
  const labels = {
    ai_generated_unverified: 'AI-generated · review',
    owner_authored: 'Owner-authored',
    legacy_unclassified: 'Origin not recorded',
  }

  return origin && labels[origin]
    ? <small className={`website-origin website-origin-${origin}`}>{labels[origin]}</small>
    : null
}

function EditorField({ label, value, onChange, multiline = false, type = 'text', origin }) {
  const id = `website-${useId()}`

  return (
    <label className="website-editor-field" htmlFor={id}>
      <span>{label}<OriginLabel origin={origin} /></span>
      {multiline ? (
        <textarea id={id} value={value || ''} onChange={(event) => onChange(event.target.value)} rows={3} />
      ) : (
        <input id={id} type={type} value={value || ''} onChange={(event) => onChange(event.target.value)} />
      )}
    </label>
  )
}

function EditSection({ title, section, onRegenerate, action, disabled, children }) {
  return (
    <section className="website-edit-section">
      <div className="website-edit-section-heading">
        <h3>{title}</h3>
        {section && (
          <button
            type="button"
            className="website-text-button"
            onClick={() => onRegenerate(section)}
            disabled={Boolean(action) || disabled}
          >
            {action === section ? 'Regenerating…' : 'Regenerate section'}
          </button>
        )}
      </div>
      {children}
    </section>
  )
}

function CollectionEditor({ label, mode, items, onChange, provenance = {} }) {
  const fields = mode === 'navigation'
    ? [['title', 'Label'], ['href', 'Section anchor']]
    : mode === 'social'
      ? [['title', 'Platform'], ['url', 'Link']]
      : mode === 'gallery'
        ? [['image_url', 'Image URL'], ['alt', 'Alternative text']]
        : mode === 'highlight'
          ? [['title', 'Highlight']]
          : [['title', 'Name'], ['description', 'Description']]

  function updateEntry(index, field, value) {
    onChange(items.map((item, itemIndex) => (
      itemIndex === index ? patchEntry(item, field, value, mode) : item
    )))
  }

  function addEntry() {
    const item = mode === 'navigation'
        ? { label: '', href: '#hero' }
      : mode === 'social'
          ? { label: '', url: '' }
        : mode === 'gallery'
            ? { image_url: '', alt: '' }
            : mode === 'highlight'
              ? ''
              : { name: '', description: '' }
    onChange([...items, item])
  }

  return (
    <div className="website-collection">
      <div className="website-collection-heading">
        <span>{label}</span>
        <button type="button" className="website-text-button" onClick={addEntry}>Add item</button>
      </div>
      {items.length === 0 && <p className="website-empty-note">No items yet.</p>}
      {items.map((item, index) => (
        <div className="website-collection-item" key={`${mode}-${index}`}>
          {fields.map(([field, fieldLabel]) => (
            <label key={field}>
              <span>
                {fieldLabel}
                <OriginLabel origin={mode === 'service'
                  ? provenance[`services_or_products.${index}.${field === 'title' ? 'name' : field}`]
                  : mode === 'highlight'
                    ? provenance[`highlights.${index}`]
                    : mode === 'gallery'
                      ? provenance[`gallery.${index}.alt`]
                      : mode === 'navigation'
                        ? provenance[`navigation.${index}.label`]
                        : mode === 'social'
                          ? provenance[`contact.social_links.${index}.${field === 'title' ? 'label' : field}`]
                          : undefined}
                />
              </span>
              {field === 'description' ? (
                <textarea
                  value={textValue(item, ['description', 'text'])}
                  onChange={(event) => updateEntry(index, field, event.target.value)}
                  rows={2}
                />
              ) : field === 'href' ? (
                <select
                  value={safeAnchor(linkOf(item))}
                  onChange={(event) => updateEntry(index, field, event.target.value)}
                >
                  {['#hero', '#about', '#services', '#highlights', '#gallery', '#contact'].map((anchor) => (
                    <option value={anchor} key={anchor}>{anchor.slice(1)}</option>
                  ))}
                </select>
              ) : (
                <input
                  value={
                    field === 'title' || field === 'alt'
                      ? textValue(item, field === 'alt' ? ['alt'] : mode === 'social' ? ['label', 'platform'] : mode === 'navigation' ? ['label'] : ['name', 'title', 'caption', 'label'])
                      : field === 'url' || field === 'href'
                        ? linkOf(item)
                        : imageOf(item)
                  }
                  placeholder={field === 'image_url' && item?.source_id
                    ? 'Private uploaded image (URL hidden)'
                    : ''}
                  onChange={(event) => updateEntry(index, field, event.target.value)}
                />
              )}
            </label>
          ))}
          <button
            type="button"
            className="website-remove-button"
            aria-label={`Remove ${label} item ${index + 1}`}
            onClick={() => onChange(items.filter((_, itemIndex) => itemIndex !== index))}
          >
            Remove
          </button>
        </div>
      ))}
    </div>
  )
}

function WebsitePreview({ website, projectId }) {
  const isLocal = website.template_key === 'local_business'
  const templateClass = isLocal ? 'website-preview-local' : 'website-preview-modern'
  const fontFamily = {
    modern: 'Inter, ui-sans-serif, system-ui, sans-serif',
    classic: 'Georgia, "Times New Roman", serif',
    editorial: '"Trebuchet MS", Arial, sans-serif',
  }[website.theme.font_style] || 'Inter, ui-sans-serif, system-ui, sans-serif'
  const themeStyle = {
    '--website-primary': safeColor(website.theme.primary_color, '#3157d5'),
    '--website-secondary': safeColor(website.theme.secondary_color, '#e8edff'),
    '--website-font': fontFamily,
  }
  const heroImage = safeImageUrl(website.hero.image)
  const services = website.services_or_products
  const gallery = website.gallery
  const highlights = website.highlights

  return (
    <div className={`website-preview ${templateClass}`} style={themeStyle}>
      <header className="website-preview-header">
        <strong>{website.business_name || 'Your business'}</strong>
        <nav aria-label="Website preview navigation">
          {website.navigation.map((item, index) => (
            <a href={safeAnchor(linkOf(item))} key={`nav-${index}`}>{titleOf(item, 'navigation')}</a>
          ))}
        </nav>
        {website.contact.phone && <span className="website-preview-phone">{website.contact.phone}</span>}
      </header>

      <main>
        <section className={`website-preview-hero ${heroImage ? 'has-image' : ''}`} id="hero">
          <div className="website-preview-hero-copy">
            {website.tagline && <p className="website-preview-kicker">{website.tagline}</p>}
            <h1>{website.hero.heading || website.business_name || 'Welcome'}</h1>
            {website.hero.description && <p>{website.hero.description}</p>}
            {website.hero.button_text && (
              <a className="website-preview-button" href="#contact">{website.hero.button_text}</a>
            )}
          </div>
          {heroImage && <img src={heroImage} alt="" />}
          {!heroImage && <div className="website-preview-art" aria-hidden="true"><span>✦</span></div>}
        </section>

        {highlights.length === 0 && <span id="highlights" className="website-preview-anchor" />}
        {highlights.length > 0 && (
          <section className="website-preview-highlights" id="highlights" aria-label="Business highlights">
            {highlights.map((item, index) => (
              <div key={`highlight-${index}`}>
                <span aria-hidden="true">✦</span>
                <strong>{titleOf(item, 'highlight') || textValue(item, ['description'])}</strong>
              </div>
            ))}
          </section>
        )}

        <section className="website-preview-about" id="about">
          <p className="website-preview-kicker">About this business</p>
          <h2>{website.about.heading || 'About us'}</h2>
          {website.about.description && <p>{website.about.description}</p>}
        </section>

        {services.length === 0 && <span id="services" className="website-preview-anchor" />}
        {services.length > 0 && (
          <section className="website-preview-services" id="services">
            <div className="website-preview-section-title">
              <p className="website-preview-kicker">Services &amp; products</p>
              <h2>What we offer</h2>
            </div>
            <div className="website-preview-service-grid">
              {services.map((item, index) => (
                <article key={`service-${index}`}>
                  <span className="website-preview-service-icon" aria-hidden="true">0{index + 1}</span>
                  <h3>{titleOf(item, 'service') || `Service ${index + 1}`}</h3>
                  {textValue(item, ['description', 'text']) && <p>{textValue(item, ['description', 'text'])}</p>}
                </article>
              ))}
            </div>
          </section>
        )}

        {gallery.length === 0 && <span id="gallery" className="website-preview-anchor" />}
        {gallery.length > 0 && (
          <section className="website-preview-gallery" id="gallery">
            <div className="website-preview-section-title">
              <p className="website-preview-kicker">Gallery</p>
              <h2>Images</h2>
            </div>
            <div className="website-preview-gallery-grid">
              {gallery.map((item, index) => {
                const image = imageSourceOf(item, projectId)
                return (
                  <figure key={`gallery-${index}`} className={image ? '' : 'is-missing'}>
                    {image
                      ? (
                        <>
                          <img
                            src={image}
                            alt={altOf(item)}
                            crossOrigin={item?.source_id ? 'use-credentials' : undefined}
                            onError={(event) => event.currentTarget.parentElement?.classList.add('is-missing')}
                          />
                          <div className="website-preview-gallery-placeholder" aria-hidden="true">✦</div>
                        </>
                      )
                      : <div className="website-preview-gallery-placeholder" aria-hidden="true">✦</div>}
                  </figure>
                )
              })}
            </div>
          </section>
        )}

        <section className="website-preview-contact" id="contact">
          <div>
            <p className="website-preview-kicker">Get in touch</p>
            <h2>Contact</h2>
          </div>
          <address>
            {website.contact.address && <span>{website.contact.address}</span>}
            {website.contact.phone && <a href={`tel:${website.contact.phone.replace(/[^\d+]/g, '')}`}>{website.contact.phone}</a>}
            {website.contact.email && <a href={`mailto:${website.contact.email}`}>{website.contact.email}</a>}
            {website.contact.social_links.map((item, index) => (
              safeExternalHref(linkOf(item))
                ? <a href={safeExternalHref(linkOf(item))} key={`social-${index}`} target="_blank" rel="noreferrer">{titleOf(item, 'social')}</a>
                : <span key={`social-${index}`}>{titleOf(item, 'social')}</span>
            ))}
          </address>
        </section>
      </main>

      <footer className="website-preview-footer">
        <span>{website.footer.show_business_name ? website.business_name : ''}</span>
        <span>{website.footer.copyright_text}</span>
      </footer>
    </div>
  )
}

function ConfirmDialog({
  title,
  description,
  confirmLabel = 'Confirm',
  confirming = false,
  onCancel,
  onConfirm,
  danger = false,
}) {
  const cancelButtonRef = useRef(null)
  const confirmButtonRef = useRef(null)

  useEffect(() => {
    cancelButtonRef.current?.focus()
  }, [])

  return (
    <div className="website-confirm-backdrop">
      <section
        className="website-confirm-dialog"
        role="alertdialog"
        aria-modal="true"
        aria-labelledby="website-confirm-title"
        aria-describedby="website-confirm-description"
        onKeyDown={(event) => {
          if (event.key === 'Escape' && !confirming) {
            onCancel()
          } else if (event.key === 'Tab') {
            const first = cancelButtonRef.current
            const last = confirmButtonRef.current

            if (event.shiftKey && document.activeElement === first) {
              event.preventDefault()
              last?.focus()
            } else if (!event.shiftKey && document.activeElement === last) {
              event.preventDefault()
              first?.focus()
            }
          }
        }}
      >
        <p className="projects-eyebrow">Please confirm</p>
        <h3 id="website-confirm-title">{title}</h3>
        <p id="website-confirm-description">{description}</p>
        <div className="website-confirm-actions">
          <button ref={cancelButtonRef} type="button" className="button button-quiet" onClick={onCancel} disabled={confirming}>
            Cancel
          </button>
          <button
            ref={confirmButtonRef}
            type="button"
            className={`button ${danger ? 'button-danger' : 'button-primary'}`}
            onClick={onConfirm}
            disabled={confirming}
          >
            {confirming ? 'Deleting…' : confirmLabel}
          </button>
        </div>
      </section>
    </div>
  )
}

function WebsiteBuilder({ project }) {
  const [website, setWebsite] = useState(null)
  const [websiteRecord, setWebsiteRecord] = useState(null)
  const [savedSnapshot, setSavedSnapshot] = useState('')
  const [templateChoice, setTemplateChoice] = useState('modern_business')
  const [loading, setLoading] = useState(true)
  const [loadError, setLoadError] = useState('')
  const [error, setError] = useState('')
  const [statusError, setStatusError] = useState('')
  const [conflict, setConflict] = useState(false)
  const [notice, setNotice] = useState('')
  const [action, setAction] = useState('')
  const [revisions, setRevisions] = useState([])
  const [revisionError, setRevisionError] = useState('')
  const [revisionsOpen, setRevisionsOpen] = useState(false)
  const [selectedRevision, setSelectedRevision] = useState(null)
  const [revisionLoading, setRevisionLoading] = useState('')
  const [revisionToDelete, setRevisionToDelete] = useState(null)
  const [revisionDeleting, setRevisionDeleting] = useState(false)
  const [confirmReload, setConfirmReload] = useState(false)
  const websiteRef = useRef(null)
  const savedSnapshotRef = useRef('')
  const lastGenerationStatusRef = useRef(null)
  const { toasts, notify, dismiss } = useToastNotifications()

  const hasChanges = useMemo(
    () => Boolean(website && JSON.stringify(website) !== savedSnapshot),
    [website, savedSnapshot],
  )
  const isGenerating = ['pending', 'processing'].includes(websiteRecord?.status)

  useEffect(() => {
    if (['pending', 'processing'].includes(websiteRecord?.status)) {
      lastGenerationStatusRef.current = websiteRecord.status
      return
    }

    if (websiteRecord?.status === 'completed'
      && ['pending', 'processing'].includes(lastGenerationStatusRef.current)) {
      notify({
        type: 'success',
        message: 'Your website version is ready.',
        key: `website-completed-${websiteRecord.current_version}`,
      })
    } else if (websiteRecord?.status === 'failed'
      && ['pending', 'processing'].includes(lastGenerationStatusRef.current)) {
      notify({
        type: 'error',
        message: websiteRecord.error_message || 'Website generation failed. Your saved version is unchanged.',
        key: `website-failed-${websiteRecord.updated_at}`,
      })
    }

    lastGenerationStatusRef.current = null
  }, [websiteRecord?.status, websiteRecord?.current_version, websiteRecord?.error_message, websiteRecord?.updated_at, notify])

  useEffect(() => {
    websiteRef.current = website
    savedSnapshotRef.current = savedSnapshot
  }, [website, savedSnapshot])

  const applyServerRecord = useCallback((record, { markSaved = true, preserveEdits = false } = {}) => {
    setWebsiteRecord(record)
    const content = contentFromRecord(record)
    if (!content) {
      if (!preserveEdits) {
        setWebsite(null)
        setSavedSnapshot('')
        websiteRef.current = null
        savedSnapshotRef.current = ''
      }
      return
    }

    const normalized = normalizeWebsite(content, project)
    const current = websiteRef.current
    const hasLocalEdits = current && JSON.stringify(current) !== savedSnapshotRef.current
    if (!preserveEdits || !hasLocalEdits) {
      setWebsite(normalized)
      websiteRef.current = normalized
      if (markSaved) {
        const snapshot = JSON.stringify(normalized)
        setSavedSnapshot(snapshot)
        savedSnapshotRef.current = snapshot
      }
    }
  }, [project])

  async function loadWebsite() {
    setLoading(true)
    setLoadError('')
    setStatusError('')

    try {
      const result = await api.getProjectWebsite(project.id)
      applyServerRecord(result)
      setConflict(false)
      setStatusError('')
      const content = contentFromRecord(result)
      if (content) {
        setTemplateChoice(normalizeWebsite(content, project).template_key)
      }
    } catch (requestError) {
      setLoadError(messageFor(requestError, 'We couldn’t load this website. Please try again.'))
      notify({
        type: 'error',
        message: messageFor(requestError, 'We couldn’t load this website. Please try again.'),
        key: `website-load-error-${project.id}`,
      })
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    let active = true

    api.getProjectWebsite(project.id)
      .then((result) => {
        if (!active) {
          return
        }
        applyServerRecord(result)
        const content = contentFromRecord(result)
        if (content) {
          setTemplateChoice(normalizeWebsite(content, project).template_key)
        }
      })
      .catch((requestError) => {
        if (active) {
          setLoadError(messageFor(requestError, 'We couldn’t load this website. Please try again.'))
          notify({
            type: 'error',
            message: messageFor(requestError, 'We couldn’t load this website. Please try again.'),
            key: `website-load-error-${project.id}`,
          })
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
  }, [project, applyServerRecord, notify])

  useEffect(() => {
    if (!['pending', 'processing'].includes(websiteRecord?.status)) {
      return undefined
    }

    let active = true
    let timerId
    const poll = async () => {
      try {
        const result = await api.getProjectWebsite(project.id)
        if (!active) {
          return
        }
        if (result) {
          applyServerRecord(result, { preserveEdits: true })
        }
        setStatusError('')
        timerId = window.setTimeout(poll, 1800)
      } catch (requestError) {
        if (active) {
          setStatusError(messageFor(requestError, 'We couldn’t check website generation status. Retrying…'))
          notify({
            type: 'warning',
            message: 'We couldn’t check generation status. Retrying automatically.',
            key: `website-poll-error-${project.id}`,
          })
          timerId = window.setTimeout(poll, 3500)
        }
      }
    }

    timerId = window.setTimeout(poll, 1200)
    return () => {
      active = false
      window.clearTimeout(timerId)
    }
  }, [websiteRecord?.status, project.id, applyServerRecord, notify])

  function updateWebsite(field, value) {
    setWebsite((current) => ({ ...current, [field]: value }))
  }

  function updateNested(group, field, value) {
    setWebsite((current) => ({
      ...current,
      [group]: { ...current[group], [field]: value },
    }))
  }

  async function generateWebsite() {
    setAction('generate')
    setError('')
    setStatusError('')
    setNotice('')

    try {
      const result = await api.generateProjectWebsite(
        project.id,
        website?.template_key || templateChoice,
      )
      applyServerRecord(result, { preserveEdits: true })
      setSelectedRevision(null)
      notify({
        type: 'information',
        message: ['pending', 'processing'].includes(result?.status)
          ? 'Website generation is queued.'
          : 'Your website draft is ready.',
        key: `website-queued-${result?.id}-${result?.updated_at}`,
      })
      setNotice(['pending', 'processing'].includes(result?.status)
        ? 'Website generation is queued. This page will update when it is ready.'
        : 'Your website draft is ready to preview and edit.')
    } catch (requestError) {
      setError(messageFor(requestError, 'We couldn’t generate the website. Please try again.'))
      notify({
        type: 'error',
        message: messageFor(requestError, 'We couldn’t generate the website. Please try again.'),
        key: 'website-generate-error',
      })
    } finally {
      setAction('')
    }
  }

  async function saveWebsite() {
    setAction('save')
    setError('')
    setNotice('')
    setConflict(false)

    try {
      const currentVersion = Number(websiteRecord?.current_version)
      const result = await api.updateProjectWebsite(project.id, website, {
        changeSummary: 'Website content updated',
        ...(Number.isSafeInteger(currentVersion) && currentVersion > 0
          ? { expectedVersion: currentVersion }
          : {}),
      })
      const normalized = normalizeWebsite(contentFromRecord(result) || result, project)
      setWebsite(normalized)
      websiteRef.current = normalized
      const snapshot = JSON.stringify(normalized)
      setSavedSnapshot(snapshot)
      savedSnapshotRef.current = snapshot
      setWebsiteRecord(result?.status
        ? result
        : (current) => ({
          ...current,
          status: 'completed',
          content_json: normalized,
          current_version: Number.isSafeInteger(currentVersion) ? currentVersion + 1 : current?.current_version,
        }))
      setNotice('Website changes saved.')
      notify({ type: 'success', message: 'Website changes saved.', key: `website-saved-${result?.current_version}` })
    } catch (requestError) {
      if (requestError instanceof ApiError && requestError.status === 409) {
        setConflict(true)
        setError(messageFor(requestError, 'This website changed since you loaded it. Review the latest version before saving again.'))
      } else {
        setError(messageFor(requestError, 'We couldn’t save your website changes. Please try again.'))
      }
      notify({
        type: 'error',
        message: messageFor(requestError, 'We couldn’t save your website changes. Please try again.'),
        key: 'website-save-error',
      })
    } finally {
      setAction('')
    }
  }

  async function regenerateSection(section) {
    if (hasChanges) {
      setError('Save your current edits before regenerating a section.')
      return
    }

    setAction(section)
    setError('')
    setNotice('')

    try {
      const result = await api.regenerateProjectWebsiteSection(project.id, section)
      setWebsiteRecord(result)
      setSelectedRevision(null)
      setNotice(`${SECTIONS.find(([key]) => key === section)?.[1] || 'Section'} regeneration is queued.`)
      notify({
        type: 'information',
        message: `${SECTIONS.find(([key]) => key === section)?.[1] || 'Section'} regeneration is queued.`,
        key: `website-section-${section}-${result?.updated_at}`,
      })
    } catch (requestError) {
      setError(messageFor(requestError, 'We couldn’t regenerate this section. Please try again.'))
      notify({
        type: 'error',
        message: messageFor(requestError, 'We couldn’t regenerate this section. Please try again.'),
        key: `website-section-error-${section}`,
      })
    } finally {
      setAction('')
    }
  }

  async function toggleRevisions() {
    const shouldOpen = !revisionsOpen
    setRevisionsOpen(shouldOpen)
    setRevisionError('')

    if (shouldOpen) {
      setRevisionLoading('list')
      try {
        const result = await api.listProjectWebsiteRevisions(project.id)
        setRevisions(Array.isArray(result) ? result : [])
      } catch (requestError) {
        setRevisionError(messageFor(requestError, 'We couldn’t load website revisions.'))
        notify({
          type: 'error',
          message: messageFor(requestError, 'We couldn’t load website revisions.'),
          key: 'website-revision-list-error',
        })
      } finally {
        setRevisionLoading('')
      }
    }
  }

  async function browseRevision(revision) {
    const revisionId = revision.id ?? revision.revision_id
    if (revisionId === undefined || revisionId === null) {
      setRevisionError('This revision does not have an identifier.')
      return
    }

    setRevisionLoading(String(revisionId))
    setRevisionError('')
    notify({
      type: 'information',
      message: `Loading version ${revision.version_number ?? revisionId}.`,
      key: `website-revision-loading-${revisionId}`,
    })

    try {
      const result = await api.getProjectWebsiteRevision(project.id, revisionId)
      const content = websiteFromRevision(result)
      setSelectedRevision({
        id: revisionId,
        label: revision.version_number ?? revision.revision_number ?? revision.version ?? revisionId,
        createdAt: revision.created_at,
        website: normalizeWebsite(content, project),
      })
      notify({
        type: 'success',
        message: `Version ${revision.version_number ?? revisionId} is ready to preview.`,
        key: `website-revision-loaded-${revisionId}`,
      })
    } catch (requestError) {
      setRevisionError(messageFor(requestError, 'We couldn’t load this revision.'))
      notify({
        type: 'error',
        message: messageFor(requestError, 'We couldn’t load this revision.'),
        key: `website-revision-error-${revisionId}`,
      })
    } finally {
      setRevisionLoading('')
    }
  }

  function loadRevisionIntoEditor() {
    if (!selectedRevision) {
      return
    }
    setWebsite(selectedRevision.website)
    setNotice('Revision loaded into the editor. Save to make it the current website.')
    notify({
      type: 'information',
      message: 'Revision loaded into the editor. Save to make it the current website.',
      key: `website-revision-restored-${selectedRevision.id}`,
    })
    setSelectedRevision(null)
  }

  async function deleteRevision() {
    if (!revisionToDelete) {
      return
    }

    setRevisionDeleting(true)
    setRevisionError('')
    let deletionConfirmed = false

    try {
      await api.deleteProjectWebsiteRevision(project.id, revisionToDelete.id)
      deletionConfirmed = true
      setRevisionToDelete(null)
      if (String(selectedRevision?.id) === String(revisionToDelete.id)) {
        setSelectedRevision(null)
      }
      notify({
        type: 'success',
        message: `Version ${revisionToDelete.version_number} deleted.`,
        key: `website-revision-deleted-${revisionToDelete.id}`,
      })
      setRevisionLoading('list')
      const result = await api.listProjectWebsiteRevisions(project.id)
      setRevisions(Array.isArray(result) ? result : [])
    } catch (requestError) {
      const message = messageFor(
        requestError,
        deletionConfirmed
          ? 'The revision was deleted, but we couldn’t refresh the list.'
          : 'We couldn’t delete this revision. Please try again.',
      )
      setRevisionError(message)
      notify({
        type: deletionConfirmed ? 'warning' : 'error',
        message,
        key: `website-revision-delete-error-${revisionToDelete.id}`,
      })
    } finally {
      setRevisionDeleting(false)
      setRevisionLoading('')
    }
  }

  if (loading) {
    return (
      <section className="website-builder project-panel" aria-labelledby="website-builder-title">
        <ToastViewport toasts={toasts} onDismiss={dismiss} />
        <div className="website-builder-loading" role="status">
          <span className="spinner" aria-hidden="true" />
          <span>Loading website builder…</span>
        </div>
      </section>
    )
  }

  if (loadError) {
    return (
      <section className="website-builder project-panel" aria-labelledby="website-builder-title">
        <ToastViewport toasts={toasts} onDismiss={dismiss} />
        <div className="website-builder-heading">
          <div><p className="projects-eyebrow">Website studio</p><h2 id="website-builder-title">Your website</h2></div>
        </div>
        <p className="projects-alert projects-alert-error" role="alert">{loadError}</p>
        <button type="button" className="button button-quiet" onClick={loadWebsite} disabled={loading}>
          Try again
        </button>
      </section>
    )
  }

  if (!website && ['pending', 'processing'].includes(websiteRecord?.status)) {
    return (
      <section className="website-builder project-panel" aria-labelledby="website-builder-title">
        <ToastViewport toasts={toasts} onDismiss={dismiss} />
        <div className="website-builder-heading">
          <div><p className="projects-eyebrow">Website studio</p><h2 id="website-builder-title">Building your website</h2></div>
          <span className="website-builder-mark" aria-hidden="true">✦</span>
        </div>
        <div className="website-generation-status" role="status">
          <span className="spinner" aria-hidden="true" />
          <div><strong>Generation is in progress</strong><p>We’re preparing your draft. This page checks for updates automatically.</p></div>
        </div>
        {error && <p className="projects-alert projects-alert-error" role="alert">{error}</p>}
        {statusError && <p className="projects-alert projects-alert-error" role="alert">{statusError}</p>}
      </section>
    )
  }

  if (!website) {
    return (
      <section className="website-builder project-panel" aria-labelledby="website-builder-title">
        <ToastViewport toasts={toasts} onDismiss={dismiss} />
        <div className="website-builder-heading">
          <div>
            <p className="projects-eyebrow">Website studio</p>
            <h2 id="website-builder-title">Build your website</h2>
            <p className="project-section-copy">Start with a safe, editable layout built from your project details.</p>
          </div>
          <span className="website-builder-mark" aria-hidden="true">✦</span>
        </div>
        {error && <p className="projects-alert projects-alert-error" role="alert">{error}</p>}
        {websiteRecord?.status === 'failed' && (
          <p className="projects-alert projects-alert-error" role="alert">
            {websiteRecord.error_message || 'Website generation failed. Your previous version, if any, has not been changed.'}
          </p>
        )}
        <div className="website-template-picker">
          {TEMPLATES.map((template) => (
            <label className={`website-template-choice ${templateChoice === template.key ? 'is-selected' : ''}`} key={template.key}>
              <input
                type="radio"
                name={`website-template-${project.id}`}
                value={template.key}
                checked={templateChoice === template.key}
                onChange={() => setTemplateChoice(template.key)}
              />
              <span className={`website-template-swatch ${template.key}`} aria-hidden="true"><i /><i /><i /></span>
              <span><strong>{template.label}</strong><small>{template.description}</small></span>
            </label>
          ))}
        </div>
        <button type="button" className="button button-primary" onClick={generateWebsite} disabled={Boolean(action)}>
          {action === 'generate' ? 'Generating website…' : 'Generate website'}
        </button>
      </section>
    )
  }

  return (
    <section className="website-builder project-panel" aria-labelledby="website-builder-title">
      <ToastViewport toasts={toasts} onDismiss={dismiss} />
      <div className="website-builder-heading">
        <div>
          <p className="projects-eyebrow">Website studio</p>
          <h2 id="website-builder-title">Edit your website</h2>
          <p className="project-section-copy">Changes stay in your project until you save them.</p>
        </div>
        <div className="website-builder-actions">
          <button
            type="button"
            className="button button-quiet"
            onClick={generateWebsite}
            disabled={Boolean(action) || isGenerating || hasChanges}
          >
            {action === 'generate' ? 'Generating…' : 'Generate new version'}
          </button>
          <button type="button" className="button button-quiet" onClick={toggleRevisions} disabled={Boolean(action)}>
            {revisionsOpen ? 'Hide revisions' : 'Browse revisions'}
          </button>
          <button type="button" className="button button-primary" onClick={saveWebsite} disabled={Boolean(action) || isGenerating || !hasChanges}>
            {action === 'save' ? 'Saving…' : hasChanges ? 'Save changes' : 'Saved'}
          </button>
        </div>
      </div>

      {error && <p className="projects-alert projects-alert-error" role="alert">{error}</p>}
      {conflict && (
        <button
          type="button"
          className="website-text-button website-conflict-reload"
          onClick={() => hasChanges ? setConfirmReload(true) : loadWebsite()}
          disabled={loading}
        >
          Reload latest saved version
        </button>
      )}
      {notice && <p className="website-builder-notice" role="status">{notice}</p>}
      <p className="website-ai-note" role="note">
        AI-generated copy is unverified. Review it against your confirmed information; text you change is marked owner-authored when saved.
      </p>
      {['pending', 'processing'].includes(websiteRecord?.status) && (
        <div className="website-generation-status website-generation-status-inline" role="status">
          <span className="spinner" aria-hidden="true" />
          <span>Generating a new version. Your current draft remains available.</span>
        </div>
      )}
      {statusError && <p className="projects-alert projects-alert-error" role="alert">{statusError}</p>}
      {websiteRecord?.status === 'failed' && (
        <p className="projects-alert projects-alert-error" role="alert">
          {websiteRecord.error_message || 'The latest generation failed. Your previously saved website is still available.'}
        </p>
      )}

      {revisionsOpen && (
        <section className="website-revisions" aria-label="Website revisions">
          <div className="website-revisions-heading">
            <div><h3>Previous versions</h3><p>Select a saved version to preview it or load it into the editor.</p></div>
            {revisionLoading === 'list' && <span role="status">Loading…</span>}
          </div>
          {revisionError && <p className="projects-alert projects-alert-error" role="alert">{revisionError}</p>}
          {!revisionLoading && revisions.length === 0 && !revisionError && <p className="website-empty-note">No saved revisions yet.</p>}
          {revisions.length > 0 && (
            <div className="website-revision-list">
              {revisions.map((revision, index) => {
                const revisionId = revision.id ?? revision.revision_id
                const isCurrent = Number(revision.version_number) === Number(websiteRecord?.current_version)
                const label = revision.version_number
                  ?? revision.revision_number
                  ?? revision.version
                  ?? `Version ${revisions.length - index}`
                const isSelected = selectedRevision && String(selectedRevision.id) === String(revisionId)
                return (
                  <div className={`website-revision-row ${isSelected ? 'is-selected' : ''}`} key={revisionId ?? index}>
                    <div><strong>{label}</strong><span>{revision.created_at ? new Date(revision.created_at).toLocaleString() : 'Saved version'}</span></div>
                    <div className="website-revision-actions">
                      {isCurrent && <span className="website-current-version">Current</span>}
                      <button
                        type="button"
                        className="website-text-button"
                        onClick={() => browseRevision(revision)}
                        disabled={Boolean(revisionLoading)}
                      >
                        {revisionLoading === String(revisionId) ? 'Loading…' : isSelected ? 'Previewing' : 'Preview'}
                      </button>
                      {!isCurrent && (
                        <button
                          type="button"
                          className="website-text-button website-delete-revision"
                          aria-label={`Delete version ${label}`}
                          onClick={() => setRevisionToDelete({
                            id: revisionId,
                            version_number: revision.version_number ?? label,
                          })}
                          disabled={revisionDeleting || Boolean(revisionLoading)}
                        >
                          Delete
                        </button>
                      )}
                    </div>
                  </div>
                )
              })}
            </div>
          )}
          {selectedRevision && (
            <div className="website-selected-revision">
              <div className="website-selected-revision-heading">
                <h4>Previewing version {selectedRevision.label}</h4>
                <button type="button" className="button button-quiet" onClick={loadRevisionIntoEditor} disabled={Boolean(action)}>
                  Load this version
                </button>
              </div>
              <WebsitePreview website={selectedRevision.website} projectId={project.id} />
            </div>
          )}
        </section>
      )}

      <div className="website-editor-layout">
        <div className="website-editor">
          <div className="website-editor-fields">
            <EditorField
              label="Business name"
              value={website.business_name}
              onChange={(value) => updateWebsite('business_name', value)}
              origin={website.content_provenance.business_name}
            />
            <EditorField
              label="Tagline"
              value={website.tagline}
              onChange={(value) => updateWebsite('tagline', value)}
              origin={website.content_provenance.tagline}
            />
          </div>
          <label className="website-editor-field website-template-select">
            <span>Template</span>
            <select
              value={website.template_key}
              onChange={(event) => {
                updateWebsite('template_key', event.target.value)
                setTemplateChoice(event.target.value)
              }}
            >
              {TEMPLATES.map(({ key, label }) => <option value={key} key={key}>{label}</option>)}
            </select>
          </label>

          <EditSection title="Brand theme" section="" onRegenerate={regenerateSection} action={action} disabled={isGenerating || hasChanges}>
            <div className="website-theme-fields">
              <label className="website-color-field">
                <span>Primary color</span>
                <input
                  type="color"
                  value={safeColor(website.theme.primary_color, '#3157d5')}
                  onChange={(event) => updateNested('theme', 'primary_color', event.target.value)}
                />
              </label>
              <label className="website-color-field">
                <span>Secondary color</span>
                <input
                  type="color"
                  value={safeColor(website.theme.secondary_color, '#e8edff')}
                  onChange={(event) => updateNested('theme', 'secondary_color', event.target.value)}
                />
              </label>
              <label className="website-editor-field">
                <span>Font style</span>
                <select
                  value={website.theme.font_style}
                  onChange={(event) => updateNested('theme', 'font_style', event.target.value)}
                >
                  <option value="modern">Modern sans</option><option value="classic">Classic serif</option><option value="editorial">Editorial</option>
                </select>
              </label>
            </div>
          </EditSection>

          <EditSection title="Navigation" section="" onRegenerate={regenerateSection} action={action} disabled={isGenerating || hasChanges}>
            <CollectionEditor
              label="Navigation links"
              mode="navigation"
              items={website.navigation}
              onChange={(items) => updateWebsite('navigation', items)}
            />
          </EditSection>

          <EditSection title="Hero" section="hero" onRegenerate={regenerateSection} action={action} disabled={isGenerating || hasChanges}>
            <div className="website-editor-fields">
              <EditorField
                label="Heading"
                value={website.hero.heading}
                onChange={(value) => updateNested('hero', 'heading', value)}
                origin={website.content_provenance['hero.heading']}
              />
              <EditorField
                label="Button text"
                value={website.hero.button_text}
                onChange={(value) => updateNested('hero', 'button_text', value)}
                origin={website.content_provenance['hero.button_text']}
              />
              <EditorField
                label="Description"
                value={website.hero.description}
                onChange={(value) => updateNested('hero', 'description', value)}
                multiline
                origin={website.content_provenance['hero.description']}
              />
              <EditorField label="Image URL" value={website.hero.image} onChange={(value) => updateNested('hero', 'image', value)} />
            </div>
          </EditSection>

          <EditSection title="About" section="about" onRegenerate={regenerateSection} action={action} disabled={isGenerating || hasChanges}>
            <div className="website-editor-fields">
              <EditorField
                label="Heading"
                value={website.about.heading}
                onChange={(value) => updateNested('about', 'heading', value)}
                origin={website.content_provenance['about.heading']}
              />
              <EditorField
                label="Description"
                value={website.about.description}
                onChange={(value) => updateNested('about', 'description', value)}
                multiline
                origin={website.content_provenance['about.description']}
              />
            </div>
          </EditSection>

          <EditSection title="Services & products" section="services_or_products" onRegenerate={regenerateSection} action={action} disabled={isGenerating || hasChanges}>
            <CollectionEditor
              label="Services or products"
              mode="service"
              items={website.services_or_products}
              provenance={website.content_provenance}
              onChange={(items) => updateWebsite('services_or_products', items)}
            />
          </EditSection>

          <EditSection title="Highlights" section="highlights" onRegenerate={regenerateSection} action={action} disabled={isGenerating || hasChanges}>
            <CollectionEditor
              label="Business highlights"
              mode="highlight"
              items={website.highlights}
              provenance={website.content_provenance}
              onChange={(items) => updateWebsite('highlights', items)}
            />
          </EditSection>

          <EditSection title="Gallery" section="" onRegenerate={regenerateSection} action={action} disabled={isGenerating || hasChanges}>
            <CollectionEditor
              label="Gallery items"
              mode="gallery"
              items={website.gallery}
              provenance={website.content_provenance}
              onChange={(items) => updateWebsite('gallery', items)}
            />
          </EditSection>

          <EditSection title="Contact" section="contact" onRegenerate={regenerateSection} action={action} disabled={isGenerating || hasChanges}>
            <div className="website-editor-fields">
              <EditorField label="Address" value={website.contact.address} onChange={(value) => updateNested('contact', 'address', value)} origin={website.content_provenance['contact.address']} />
              <EditorField label="Phone" value={website.contact.phone} onChange={(value) => updateNested('contact', 'phone', value)} origin={website.content_provenance['contact.phone']} />
              <EditorField label="Email" value={website.contact.email} onChange={(value) => updateNested('contact', 'email', value)} origin={website.content_provenance['contact.email']} />
            </div>
            <CollectionEditor
              label="Social links"
              mode="social"
              items={website.contact.social_links}
              provenance={website.content_provenance}
              onChange={(items) => updateNested('contact', 'social_links', items)}
            />
          </EditSection>

          <EditSection title="Footer" section="footer" onRegenerate={regenerateSection} action={action} disabled={isGenerating || hasChanges}>
            <div className="website-editor-fields">
              <EditorField
                label="Copyright text"
                value={website.footer.copyright_text}
                onChange={(value) => updateNested('footer', 'copyright_text', value)}
                origin={website.content_provenance['footer.copyright_text']}
              />
              <label className="website-checkbox-field">
                <input
                  type="checkbox"
                  checked={website.footer.show_business_name}
                  onChange={(event) => updateNested('footer', 'show_business_name', event.target.checked)}
                />
                <span>Show business name in footer</span>
              </label>
            </div>
          </EditSection>
        </div>

        <aside className="website-preview-panel">
          <div className="website-preview-heading">
            <div><p className="projects-eyebrow">Live preview</p><h3>{website.template_key === 'local_business' ? 'Local business' : 'Modern business'}</h3></div>
            <span>Preview only</span>
          </div>
          <WebsitePreview website={website} projectId={project.id} />
        </aside>
      </div>
      {confirmReload && (
        <ConfirmDialog
          title="Discard unsaved edits?"
          description="Reloading the latest saved version will discard the changes currently in this editor."
          confirmLabel="Reload saved version"
          onCancel={() => setConfirmReload(false)}
          onConfirm={() => {
            setConfirmReload(false)
            loadWebsite()
          }}
        />
      )}
      {revisionToDelete && (
        <ConfirmDialog
          title={`Delete version ${revisionToDelete.version_number}?`}
          description="This removes only the selected historical revision. The current website will not change, and this action cannot be undone."
          confirmLabel="Delete revision"
          confirming={revisionDeleting}
          danger
          onCancel={() => setRevisionToDelete(null)}
          onConfirm={deleteRevision}
        />
      )}
    </section>
  )
}

export default WebsiteBuilder
