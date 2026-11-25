import { useEffect, useState } from 'react'
import { ApiError, api } from '../lib/api.js'
import { WebsitePreview } from './WebsiteBuilder.jsx'
import './PublicWebsitePage.css'

function PublicWebsitePage({ slug }) {
  const [page, setPage] = useState({
    slug: null,
    website: null,
    error: '',
    loading: true,
  })

  useEffect(() => {
    let active = true
    const previousTitle = document.title

    api.getPublicWebsite(slug)
      .then((result) => {
        if (!active) {
          return
        }

        if (!result?.content_json || typeof result.content_json !== 'object') {
          throw new Error('The published website response was incomplete.')
        }

        setPage({
          slug,
          website: result.content_json,
          error: '',
          loading: false,
        })
        document.title = result.content_json.business_name || 'Business website'
      })
      .catch((requestError) => {
        if (!active) {
          return
        }

        setPage({
          slug,
          website: null,
          error: requestError instanceof ApiError && requestError.status === 404
            ? 'This website is not published or is no longer available.'
            : 'We could not load this website. Please try again later.',
          loading: false,
        })
      })

    return () => {
      active = false
      document.title = previousTitle
    }
  }, [slug])

  const isCurrentSlug = page.slug === slug
  const loading = !isCurrentSlug || page.loading
  const website = isCurrentSlug ? page.website : null
  const error = isCurrentSlug ? page.error : ''

  if (loading) {
    return (
      <main className="public-website-status" role="status">
        <span className="spinner" aria-hidden="true" />
        <p>Loading website…</p>
      </main>
    )
  }

  if (error || !website) {
    return (
      <main className="public-website-status">
        <section className="public-website-error" role="alert">
          <h1>Website unavailable</h1>
          <p>{error || 'This website is not available.'}</p>
        </section>
      </main>
    )
  }

  return (
    <main className="public-website-shell">
      <WebsitePreview website={website} publicSlug={slug} />
    </main>
  )
}

export default PublicWebsitePage
