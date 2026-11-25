import './ToastNotifications.css'

export function ToastViewport({ toasts, onDismiss }) {
  if (toasts.length === 0) {
    return null
  }

  return (
    <div className="toast-viewport" aria-label="Notifications">
      {toasts.map((toast) => (
        <div
          className={`toast-notification toast-${toast.type}`}
          key={toast.id}
          role={toast.type === 'error' ? 'alert' : 'status'}
          aria-live={toast.type === 'error' ? 'assertive' : 'polite'}
        >
          <span>{toast.message}</span>
          <button type="button" aria-label="Dismiss notification" onClick={() => onDismiss(toast.id)}>
            <span aria-hidden="true">×</span>
          </button>
        </div>
      ))}
    </div>
  )
}
