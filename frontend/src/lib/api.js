const API_BASE_URL = (
  import.meta.env.VITE_API_BASE_URL || 'http://localhost:8000/api'
).replace(/\/+$/, '')
const BACKEND_ORIGIN = new URL(API_BASE_URL).origin

export class ApiError extends Error {
  constructor(status, message, errors = {}) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.errors = errors
  }
}

function readCookie(name) {
  const cookie = document.cookie
    .split('; ')
    .find((item) => item.startsWith(`${name}=`))

  return cookie ? decodeURIComponent(cookie.slice(name.length + 1)) : null
}

async function responseData(response) {
  if (response.status === 204) {
    return {}
  }

  if (!response.headers.get('content-type')?.includes('application/json')) {
    return {}
  }

  return response.json()
}

async function responseError(response) {
  const data = await responseData(response)

  return new ApiError(
    response.status,
    data.message || 'The request could not be completed.',
    data.errors || {},
  )
}

export async function getCsrfCookie() {
  const response = await fetch(`${BACKEND_ORIGIN}/sanctum/csrf-cookie`, {
    credentials: 'include',
    headers: {
      Accept: 'application/json',
    },
  })

  if (!response.ok) {
    throw await responseError(response)
  }
}

async function request(path, { method = 'GET', body, credentials = 'include' } = {}) {
  const headers = new Headers({
    Accept: 'application/json',
  })
  const options = {
    method,
    credentials,
    headers,
  }

  if (body !== undefined) {
    if (body instanceof FormData) {
      options.body = body
    } else {
      headers.set('Content-Type', 'application/json')
      options.body = JSON.stringify(body)
    }
  }

  if (!['GET', 'HEAD', 'OPTIONS'].includes(method.toUpperCase())) {
    const csrfToken = readCookie('XSRF-TOKEN')

    if (csrfToken) {
      headers.set('X-XSRF-TOKEN', csrfToken)
    }
  }

  const response = await fetch(`${API_BASE_URL}${path}`, options)

  if (!response.ok) {
    if (response.status === 401 && credentials !== 'omit') {
      window.dispatchEvent(new Event('api:unauthorized'))
    }

    throw await responseError(response)
  }

  return responseData(response)
}

export const api = {
  currentUser: () => request('/user'),
  login: (credentials) => request('/login', {
    method: 'POST',
    body: credentials,
  }),
  register: (details) => request('/register', {
    method: 'POST',
    body: details,
  }),
  logout: () => request('/logout', {
    method: 'POST',
    body: {},
  }),
  listProjects: (page = 1) => request(`/projects?page=${page}`),
  createProject: async (project) => (await request('/projects', {
    method: 'POST',
    body: project,
  })).data,
  getProject: async (projectId) => (await request(`/projects/${projectId}`)).data,
  updateProject: async (projectId, project) => (await request(`/projects/${projectId}`, {
    method: 'PATCH',
    body: project,
  })).data,
  deleteProject: (projectId) => request(`/projects/${projectId}`, {
    method: 'DELETE',
  }),
  listProjectSources: async (projectId) => (await request(`/projects/${projectId}/sources`)).data,
  uploadProjectSources: (projectId, files) => {
    const body = new FormData()

    files.forEach(({ file, sourceType }) => {
      body.append('files[]', file)
      body.append('source_types[]', sourceType)
    })

    return request(`/projects/${projectId}/sources`, {
      method: 'POST',
      body,
    })
  },
  deleteProjectSource: (projectId, sourceId) => request(
    `/projects/${projectId}/sources/${sourceId}`,
    { method: 'DELETE' },
  ),
  processProjectSource: (projectId, sourceId) => request(
    `/projects/${projectId}/sources/${sourceId}/process`,
    { method: 'POST', body: {} },
  ),
  getProjectSourceContent: async (projectId, sourceId) => (await request(
    `/projects/${projectId}/sources/${sourceId}/extraction/content`,
  )).data,
  startBusinessResearch: (projectId, refresh = false) => request(
    `/projects/${projectId}/research`,
    { method: 'POST', body: { refresh } },
  ),
  getBusinessResearchStatus: async (projectId) => (await request(
    `/projects/${projectId}/research/status`,
  )).data,
  listBusinessResearchSources: async (projectId) => (await request(
    `/projects/${projectId}/research/sources`,
  )).data,
  startBusinessFactExtraction: (projectId, refresh = false) => request(
    `/projects/${projectId}/facts/extract`,
    { method: 'POST', body: { refresh } },
  ),
  getBusinessFactStatus: async (projectId) => (await request(
    `/projects/${projectId}/facts/status`,
  )).data,
  listBusinessFacts: async (projectId) => (await request(
    `/projects/${projectId}/facts`,
  )).data,
  decideBusinessFactCandidate: (projectId, factId, candidateId, action) => request(
    `/projects/${projectId}/facts/${factId}/candidates/${candidateId}`,
    { method: 'PATCH', body: { action } },
  ),
  getProjectWebsite: async (projectId) => (await request(
    `/projects/${projectId}/website`,
  )).data,
  publishProjectWebsite: async (projectId) => (await request(
    `/projects/${projectId}/website/publish`,
    { method: 'POST', body: {} },
  )).data,
  unpublishProjectWebsite: async (projectId) => (await request(
    `/projects/${projectId}/website/publish`,
    { method: 'DELETE' },
  )).data,
  getPublicWebsite: async (slug) => (await request(
    `/public/websites/${encodeURIComponent(slug)}`,
    { credentials: 'omit' },
  )).data,
  generateProjectWebsite: async (projectId, templateKey) => (await request(
    `/projects/${projectId}/website/generate`,
    { method: 'POST', body: { template_key: templateKey } },
  )).data,
  updateProjectWebsite: async (projectId, contentJson, options = {}) => (await request(
    `/projects/${projectId}/website`,
    {
      method: 'PUT',
      body: {
        content_json: contentJson,
        ...(options.changeSummary ? { change_summary: options.changeSummary } : {}),
        ...(options.expectedVersion !== undefined
          ? { expected_version: options.expectedVersion }
          : {}),
      },
    },
  )).data,
  regenerateProjectWebsiteSection: async (projectId, section) => (await request(
    `/projects/${projectId}/website/regenerate-section`,
    { method: 'POST', body: { section } },
  )).data,
  listProjectWebsiteRevisions: async (projectId) => (await request(
    `/projects/${projectId}/website/revisions`,
  )).data,
  getProjectWebsiteRevision: async (projectId, revisionId) => (await request(
    `/projects/${projectId}/website/revisions/${encodeURIComponent(revisionId)}`,
  )).data,
  deleteProjectWebsiteRevision: (projectId, revisionId) => request(
    `/projects/${projectId}/website/revisions/${encodeURIComponent(revisionId)}`,
    { method: 'DELETE' },
  ),
  projectSourceImageUrl: (projectId, sourceId) => (
    `${API_BASE_URL}/projects/${encodeURIComponent(projectId)}/sources/${encodeURIComponent(sourceId)}/image`
  ),
  publicWebsiteImageUrl: (slug, sourceId) => (
    `${API_BASE_URL}/public/websites/${encodeURIComponent(slug)}/images/${encodeURIComponent(sourceId)}`
  ),
}
