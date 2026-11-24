
import { useCallback, useEffect, useState } from 'react'
import { ApiError, api } from '../lib/api.js'
import './Projects.css'

const RUN_LABELS = {
  not_started: 'Not started',
  pending: 'Queued',
  processing: 'In progress',
  completed: 'Completed',
  partial: 'Partially completed',
  failed: 'Failed',
}

const FACT_STATUS_LABELS = {
  pending: 'Needs review',
  confirmed: 'Confirmed by you',
  rejected: 'Rejected',
  conflicting: 'Conflicting',
}

function messageFor(error, fallback) {
  return error instanceof ApiError ? error.message : fallback
}

function BusinessIntelligence({ projectId }) {
  const [research, setResearch] = useState(null)
  const [researchSources, setResearchSources] = useState([])
  const [factRun, setFactRun] = useState(null)
  const [facts, setFacts] = useState([])
  const [loading, setLoading] = useState(true)
  const [action, setAction] = useState('')
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')

  const isRunning = ['pending', 'processing'].includes(research?.status)
    || ['pending', 'processing'].includes(factRun?.status)

  const refresh = useCallback(async () => {
    const [researchResult, sourceResult, factRunResult, factResult] = await Promise.all([
      api.getBusinessResearchStatus(projectId),
      api.listBusinessResearchSources(projectId),
      api.getBusinessFactStatus(projectId),
      api.listBusinessFacts(projectId),
    ])

    setResearch(researchResult)
    setResearchSources(Array.isArray(sourceResult) ? sourceResult : [])
    setFactRun(factRunResult)
    setFacts(Array.isArray(factResult) ? factResult : [])
  }, [projectId])

  useEffect(() => {
    let active = true

    Promise.all([
      api.getBusinessResearchStatus(projectId),
      api.listBusinessResearchSources(projectId),
      api.getBusinessFactStatus(projectId),
      api.listBusinessFacts(projectId),
    ])
      .then(([researchResult, sourceResult, factRunResult, factResult]) => {
        if (active) {
          setResearch(researchResult)
          setResearchSources(Array.isArray(sourceResult) ? sourceResult : [])
          setFactRun(factRunResult)
          setFacts(Array.isArray(factResult) ? factResult : [])
        }
      })
      .catch((requestError) => {
        if (active) {
          setError(messageFor(requestError, 'We couldn’t load business research and facts.'))
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
    if (!isRunning) {
      return undefined
    }

    const intervalId = window.setInterval(() => {
      refresh().catch((requestError) => {
        setError(messageFor(requestError, 'We couldn’t refresh business intelligence status.'))
      })
    }, 3000)

    return () => window.clearInterval(intervalId)
  }, [isRunning, refresh])

  async function startResearch() {
    setAction('research')
    setError('')
    setNotice('')

    try {
      const response = await api.startBusinessResearch(
        projectId,
        research?.status === 'completed',
      )
      setResearch(response.data)
      setNotice(response.message || 'Business research has been queued.')
    } catch (requestError) {
      const requestMessage = messageFor(requestError, 'We couldn’t start business research.')
      setError(requestMessage)
      await refresh().catch((refreshError) => {
        setError(`${requestMessage} ${messageFor(refreshError, 'Status refresh also failed.')}`)
      })
    } finally {
      setAction('')
    }
  }

  async function startFactExtraction() {
    setAction('facts')
    setError('')
    setNotice('')

    try {
      const response = await api.startBusinessFactExtraction(
        projectId,
        factRun?.status === 'completed',
      )
      setFactRun(response.data)
      setNotice(response.message || 'Fact extraction has been queued.')
    } catch (requestError) {
      const requestMessage = messageFor(requestError, 'We couldn’t start fact extraction.')
      setError(requestMessage)
      await refresh().catch((refreshError) => {
        setError(`${requestMessage} ${messageFor(refreshError, 'Status refresh also failed.')}`)
      })
    } finally {
      setAction('')
    }
  }

  async function decideCandidate(fact, candidate, decision) {
    setAction(`candidate-${candidate.id}`)
    setError('')
    setNotice('')

    try {
      const updatedFact = await api.decideBusinessFactCandidate(
        projectId,
        fact.id,
        candidate.id,
        decision,
      )

      setFacts((current) => current.map((item) => (
        item.id === fact.id ? updatedFact.data : item
      )))

      setNotice(decision === 'confirm'
        ? 'Candidate confirmed. Other competing values were rejected for review.'
        : 'Candidate rejected.')
    } catch (requestError) {
      setError(messageFor(requestError, 'We couldn’t update this candidate.'))
    } finally {
      setAction('')
    }
  }

  return (
    <section className="project-panel business-intelligence">
      <div className="project-panel-heading">
        <div>
          <p className="projects-eyebrow">Research &amp; Business Facts</p>
          <h2>Understand your business</h2>
          <p className="source-help">
            Research and extracted facts are suggestions. Review evidence before confirming anything.
          </p>
        </div>
      </div>

      {error && (
        <p className="projects-alert projects-alert-error" role="alert">
          {error}
        </p>
      )}

      {notice && (
        <p className="projects-alert projects-alert-success" role="status">
          {notice}
        </p>
      )}

      {loading ? (
        <div className="source-loading" role="status">
          Loading research and facts…
        </div>
      ) : (
        <>
          <div className="intelligence-actions">
            <div>
              <strong>Public business research</strong>
              <span>
                {RUN_LABELS[research?.status] || 'Not started'}
                {research?.source_count ? ` · ${research.source_count} sources` : ''}
              </span>
              {research?.error_message && <small>{research.error_message}</small>}
            </div>

            <button
              className="button button-quiet"
              type="button"
              onClick={startResearch}
              disabled={Boolean(action) || ['pending', 'processing'].includes(research?.status)}
            >
              {action === 'research'
                ? 'Starting…'
                : research?.status === 'failed'
                  ? 'Retry research'
                  : research?.status === 'completed'
                    ? 'Research again'
                    : 'Start research'}
            </button>
          </div>

          <div className="intelligence-sources">
            <h3>Research sources</h3>

            {researchSources.length === 0 ? (
              <p className="intelligence-empty">No research sources yet.</p>
            ) : (
              researchSources.map((source) => (
                <article className="intelligence-source" key={source.id}>
                  <div className="intelligence-source-heading">
                    <strong>{source.title || source.source_url}</strong>
                    <span className={`intelligence-source-status ${source.access_status}`}>
                      {source.access_status === 'accessible' ? 'Content available' : 'Inaccessible'}
                    </span>
                  </div>

                  <a href={source.source_url} target="_blank" rel="noreferrer nofollow">
                    {source.source_url}
                  </a>

                  {source.snippet && <p>{source.snippet}</p>}
                  {source.access_message && <small>{source.access_message}</small>}
                </article>
              ))
            )}
          </div>

          <div className="intelligence-actions intelligence-fact-action">
            <div>
              <strong>Candidate business facts</strong>
              <span>
                {RUN_LABELS[factRun?.status] || 'Not started'}
                {factRun?.fact_count ? ` · ${factRun.fact_count} candidates` : ''}
                {factRun?.skipped_count ? ` · ${factRun.skipped_count} unverified responses skipped` : ''}
              </span>
              {factRun?.error_message && <small>{factRun.error_message}</small>}
            </div>

            <button
              className="button button-primary"
              type="button"
              onClick={startFactExtraction}
              disabled={Boolean(action) || ['pending', 'processing'].includes(factRun?.status)}
            >
              {action === 'facts'
                ? 'Starting…'
                : factRun?.status === 'failed'
                  ? 'Retry extraction'
                  : factRun?.status === 'completed'
                    ? 'Re-extract facts'
                    : 'Extract facts'}
            </button>
          </div>

          {facts.length === 0 ? (
            <p className="intelligence-empty">
              No candidate facts yet. Run extraction to review supported business information.
            </p>
          ) : (
            <div className="intelligence-facts">
              {facts.map((fact) => {
                const candidates = Array.isArray(fact.candidates) ? fact.candidates : []

                return (
                  <details className="intelligence-fact" key={fact.id}>
                    <summary>
                      <span>
                        <strong>{(fact.fact_key || '').replaceAll('_', ' ')}</strong>
                        <small>{fact.category}</small>
                      </span>

                      <span className={`fact-status fact-status-${fact.status}`}>
                        {FACT_STATUS_LABELS[fact.status] || fact.status}
                      </span>
                    </summary>

                    <div className="fact-candidates">
                      {candidates.map((candidate) => {
                        const evidence = Array.isArray(candidate.evidence)
                          ? candidate.evidence
                          : []

                        return (
                          <article className="fact-candidate" key={candidate.id}>
                            <div className="fact-candidate-heading">
                              <span>{candidate.value}</span>
                              <span className={`fact-status fact-status-${candidate.verification_status}`}>
                                {FACT_STATUS_LABELS[candidate.verification_status] || candidate.verification_status}
                              </span>
                            </div>

                            {candidate.ai_confidence != null && (
                              <small>
                                Model confidence: {Math.round(candidate.ai_confidence * 100)}% · Not verification
                              </small>
                            )}

                            {candidate.requires_clarification && (
                              <p className="fact-clarification">
                                Clarification needed: {candidate.clarification_note || 'Please review this ambiguous evidence.'}
                              </p>
                            )}

                            <div className="fact-evidence-list">
                              {evidence.map((item) => (
                                <blockquote key={item.id}>
                                  <p>“{item.supporting_evidence}”</p>
                                  <footer>
                                    {item.source_reference || item.source_type}
                                    {item.source_url && (
                                      <>
                                        {' · '}
                                        <a href={item.source_url} target="_blank" rel="noreferrer nofollow">
                                          Open source
                                        </a>
                                      </>
                                    )}
                                  </footer>
                                </blockquote>
                              ))}

                              {evidence.length === 0 && (
                                <p className="intelligence-empty">
                                  Source evidence is no longer available.
                                </p>
                              )}
                            </div>

                            {!['confirmed', 'rejected'].includes(candidate.verification_status) && (
                              <div className="fact-candidate-actions">
                                <button
                                  className="button button-primary"
                                  type="button"
                                  disabled={Boolean(action)}
                                  onClick={() => decideCandidate(fact, candidate, 'confirm')}
                                >
                                  {action === `candidate-${candidate.id}` ? 'Saving…' : 'Confirm'}
                                </button>

                                <button
                                  className="button button-quiet"
                                  type="button"
                                  disabled={Boolean(action)}
                                  onClick={() => decideCandidate(fact, candidate, 'reject')}
                                >
                                  Reject
                                </button>
                              </div>
                            )}
                          </article>
                        )
                      })}

                      {candidates.length === 0 && (
                        <p className="intelligence-empty">
                          No candidate values are available for this fact.
                        </p>
                      )}
                    </div>
                  </details>
                )
              })}
            </div>
          )}
        </>
      )}
    </section>
  )
}

export default BusinessIntelligence
