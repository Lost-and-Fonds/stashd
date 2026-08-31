import { ref } from 'vue'

const relativeTimeTick = ref(Date.now())

if (typeof window !== 'undefined') {
  window.setInterval(() => { relativeTimeTick.value = Date.now() }, 30_000)
}

export function formatRelativeDate(value?: string | null): string {
  if (!value) return '—'
  relativeTimeTick.value

  const date = new Date(value)
  const seconds = Math.max(0, Math.floor((Date.now() - date.getTime()) / 1000))
  if (seconds < 60) return 'just now'
  if (seconds < 3600) return `${Math.floor(seconds / 60)} minutes ago`
  if (seconds < 86400) return `${Math.floor(seconds / 3600)} hours ago`
  if (seconds < 604800) return `${Math.floor(seconds / 86400)} days ago`

  return date.toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' })
}

export function formatExactDate(value?: string | null): string {
  return value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : ''
}
