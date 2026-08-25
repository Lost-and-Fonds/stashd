import { apiFetch } from './auth'

export interface ApiTokenResource {
  id: string
  name: string
  token_preview: string
  scopes: string[]
  last_used_at?: string | null
  expires_at?: string | null
  created_at?: string | null
}

export interface CreatedApiToken extends ApiTokenResource {
  token: string
}

export async function fetchApiTokens(): Promise<ApiTokenResource[]> {
  const body = await responseBody<{ tokens?: ApiTokenResource[] }>(await apiFetch('/api/v1/auth/tokens'))

  return body.tokens ?? []
}

export async function createApiToken(name: string): Promise<CreatedApiToken> {
  return responseBody<CreatedApiToken>(await apiFetch('/api/v1/auth/tokens', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ name })
  }))
}

export async function revokeApiToken(id: string): Promise<void> {
  await responseBody(await apiFetch(`/api/v1/auth/tokens/${encodeURIComponent(id)}`, { method: 'DELETE' }))
}

async function responseBody<T>(response: Response): Promise<T> {
  const body = await response.json().catch(() => null) as { error?: { message?: unknown }, message?: unknown } | null

  if (!response.ok) {
    const message = body?.error?.message ?? body?.message
    throw new Error(typeof message === 'string' ? message : `Request failed (${response.status}).`)
  }

  return body as T
}
