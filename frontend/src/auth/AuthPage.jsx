import { useState } from 'react'
import { useAuth } from './useAuth.js'
import { ApiError } from '../lib/api.js'

function BrandPanel() {
  return (
    <aside className="brand-panel">
      <a className="wordmark brand-top" href="/">
        <span className="wordmark-mark" aria-hidden="true">A</span>
        <span>AI Builds My Website</span>
      </a>
      <div className="brand-story">
        <span className="brand-kicker">Your next idea starts here</span>
        <h1>Make room for what you want to build.</h1>
        <p>Bring your vision to life with a website builder designed to make the first step feel effortless.</p>
        <ul className="brand-points">
          <li><span aria-hidden="true">✓</span> A clear, simple place to begin</li>
          <li><span aria-hidden="true">✓</span> Your projects, all in one space</li>
          <li><span aria-hidden="true">✓</span> Built around your ideas</li>
        </ul>
      </div>
      <p className="brand-footer">Create something you’re proud to share.</p>
    </aside>
  )
}

function fieldMessage(errors, field) {
  const message = errors[field]

  return Array.isArray(message) ? message[0] : message
}

function AuthPage({ mode, onNavigate, startupError }) {
  const isRegister = mode === 'register'
  const { login, register } = useAuth()
  const [values, setValues] = useState({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
  })
  const [fieldErrors, setFieldErrors] = useState({})
  const [message, setMessage] = useState('')
  const [showPasswords, setShowPasswords] = useState(false)
  const [submitting, setSubmitting] = useState(false)

  function updateField(event) {
    const { name, value } = event.target
    setValues((current) => ({ ...current, [name]: value }))
    setFieldErrors((current) => ({ ...current, [name]: undefined }))
    setMessage('')
  }

  async function handleSubmit(event) {
    event.preventDefault()
    setFieldErrors({})
    setMessage('')

    const errors = {}
    if (isRegister && !values.name.trim()) {
      errors.name = ['Please enter your name.']
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(values.email)) {
      errors.email = ['Enter a valid email address.']
    }
    if (!values.password) {
      errors.password = ['Please enter your password.']
    } else if (isRegister && values.password.length < 8) {
      errors.password = ['Use at least 8 characters.']
    }
    if (isRegister && values.password !== values.password_confirmation) {
      errors.password_confirmation = ['Passwords do not match.']
    }

    if (Object.keys(errors).length > 0) {
      setFieldErrors(errors)
      setMessage('Please check the highlighted fields.')
      return
    }

    setSubmitting(true)

    try {
      if (isRegister) {
        await register(values)
      } else {
        await login({
          email: values.email,
          password: values.password,
        })
      }

      onNavigate('/app')
    } catch (error) {
      if (error instanceof ApiError) {
        setFieldErrors(error.errors)
        setMessage(error.message)
      } else {
        setMessage('We couldn’t connect to the server. Please try again.')
      }
    } finally {
      setSubmitting(false)
    }
  }

  function renderPasswordField(name, label, autocomplete) {
    const error = fieldMessage(fieldErrors, name)

    return (
      <div className="field">
        <label htmlFor={name}>{label}</label>
        <div className="field-control has-toggle">
          <input
            id={name}
            name={name}
            type={showPasswords ? 'text' : 'password'}
            value={values[name]}
            onChange={updateField}
            autoComplete={autocomplete}
            required
            aria-invalid={Boolean(error)}
            aria-describedby={error ? `${name}-error` : undefined}
          />
          <button
            className="password-toggle"
            type="button"
            aria-pressed={showPasswords}
            onClick={() => setShowPasswords((visible) => !visible)}
          >
            {showPasswords ? 'Hide' : 'Show'}
          </button>
        </div>
        {error && <p className="field-error" id={`${name}-error`}>{error}</p>}
      </div>
    )
  }

  return (
    <main className="auth-layout">
      <BrandPanel />
      <section className="auth-side">
        <div className="auth-card">
          <header className="auth-card-header">
            <h2>{isRegister ? 'Create your account' : 'Welcome back'}</h2>
            <p>
              {isRegister
                ? 'Start with a free account. Your next idea is closer than you think.'
                : 'Sign in to pick up where your ideas left off.'}
            </p>
          </header>

          {startupError && <p className="form-alert form-alert-info" role="status">{startupError}</p>}
          {message && <p className="form-alert form-alert-error" role="alert">{message}</p>}

          <form className="auth-form" onSubmit={handleSubmit} noValidate>
            {isRegister && (
              <div className="field">
                <label htmlFor="name">Your name</label>
                <input
                  id="name"
                  name="name"
                  type="text"
                  value={values.name}
                  onChange={updateField}
                  autoComplete="name"
                  placeholder="Alex Morgan"
                  required
                  aria-invalid={Boolean(fieldMessage(fieldErrors, 'name'))}
                  aria-describedby={fieldMessage(fieldErrors, 'name') ? 'name-error' : undefined}
                />
                {fieldMessage(fieldErrors, 'name') && (
                  <p className="field-error" id="name-error">{fieldMessage(fieldErrors, 'name')}</p>
                )}
              </div>
            )}

            <div className="field">
              <label htmlFor="email">Email address</label>
              <input
                id="email"
                name="email"
                type="email"
                value={values.email}
                onChange={updateField}
                autoComplete="email"
                placeholder="you@example.com"
                required
                aria-invalid={Boolean(fieldMessage(fieldErrors, 'email'))}
                aria-describedby={fieldMessage(fieldErrors, 'email') ? 'email-error' : undefined}
              />
              {fieldMessage(fieldErrors, 'email') && (
                <p className="field-error" id="email-error">{fieldMessage(fieldErrors, 'email')}</p>
              )}
            </div>

            {renderPasswordField(
              'password',
              'Password',
              isRegister ? 'new-password' : 'current-password',
            )}

            {isRegister && renderPasswordField(
              'password_confirmation',
              'Confirm password',
              'new-password',
            )}

            <button className="button button-primary" type="submit" disabled={submitting}>
              {submitting
                ? (isRegister ? 'Creating account…' : 'Signing in…')
                : (isRegister ? 'Create account' : 'Sign in')}
            </button>
          </form>

          <p className="auth-switch">
            {isRegister ? 'Already have an account? ' : 'New to AI Builds My Website? '}
            <button
              className="text-link"
              type="button"
              onClick={() => onNavigate(isRegister ? '/login' : '/register')}
            >
              {isRegister ? 'Sign in' : 'Create an account'}
            </button>
          </p>

          <div className="auth-note">
            <svg viewBox="0 0 16 16" fill="none" aria-hidden="true">
              <path d="M4.25 6.75V4.9a3.75 3.75 0 0 1 7.5 0v1.85M3.3 6.75h9.4v7H3.3v-7Z" stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" strokeLinejoin="round" />
              <path d="M8 9.4v1.7" stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" />
            </svg>
            <span>Your session is protected with secure cookies. Authentication tokens are never stored in browser storage.</span>
          </div>
        </div>
      </section>
    </main>
  )
}

export default AuthPage
