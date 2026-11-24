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

async function request(path, { method = 'GET', body } = {}) {
  const headers = new Headers({
    Accept: 'application/json',
  })
  const options = {
    method,
    credentials: 'include',
    headers,
  }

  if (body !== undefined) {
    headers.set('Content-Type', 'application/json')
    options.body = JSON.stringify(body)
  }

  if (!['GET', 'HEAD', 'OPTIONS'].includes(method.toUpperCase())) {
    const csrfToken = readCookie('XSRF-TOKEN')

    if (csrfToken) {
      headers.set('X-XSRF-TOKEN', csrfToken)
    }
  }

  const response = await fetch(`${API_BASE_URL}${path}`, options)

  if (!response.ok) {
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
}
