export interface AuthUser {
  id: string
  username: string
  role: string
}

export type AuthState = 'authenticated' | 'unauthenticated' | 'setup-required'

export async function authState(): Promise<AuthState> {
  const response = await fetch('/api/v1/auth/me', {
    credentials: 'same-origin',
    headers: { Accept: 'application/json' }
  })

  if (response.status === 401) return 'unauthenticated'

  if (response.status === 403) {
    const body = await response.json().catch(() => ({})) as { error?: { code?: string } }
    if (body.error?.code === 'setup_required') return 'setup-required'
  }

  if (!response.ok) throw new Error(`Authentication check failed (${response.status}).`)
  return 'authenticated'
}

export async function currentUser(): Promise<AuthUser | null> {
  const response = await fetch('/api/v1/auth/me', {
    credentials: 'same-origin',
    headers: { Accept: 'application/json' }
  })
  if (response.status === 401) return null
  if (!response.ok) throw new Error(`Authentication check failed (${response.status}).`)

  const body = await response.json() as { user?: AuthUser }
  return body.user ?? null
}

export async function apiFetch(input: RequestInfo | URL, init: RequestInit = {}): Promise<Response> {
  const headers = new Headers(init.headers)
  headers.set('Accept', 'application/json')
  const response = await fetch(input, {
    ...init,
    headers,
    credentials: init.credentials ?? 'same-origin'
  })

  if (response.status === 401) {
    window.dispatchEvent(new CustomEvent('stashd:auth-required'))
  }

  return response
}

export async function logout(): Promise<void> {
  await apiFetch('/api/v1/auth/logout', { method: 'POST' })
}
