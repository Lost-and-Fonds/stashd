import { apiFetch } from './auth'

export interface PluginCredentialResource {
  key: string
  label: string
  description?: string | null
  required: boolean
  configured: boolean
}

export interface PluginCredentialPluginResource {
  key: string
  label: string
  credentials: PluginCredentialResource[]
}

export async function fetchPluginCredentials(): Promise<PluginCredentialPluginResource[]> {
  const body = await responseBody<{ plugins?: PluginCredentialPluginResource[] }>(await apiFetch('/api/v1/plugin-credentials'))

  return body.plugins ?? []
}

export async function replacePluginCredential(pluginKey: string, credentialKey: string, value: string): Promise<PluginCredentialResource> {
  const body = await responseBody<{ credential: PluginCredentialResource }>(await apiFetch(`/api/v1/plugin-credentials/${encodeURIComponent(pluginKey)}/${encodeURIComponent(credentialKey)}`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ value })
  }))

  return body.credential
}

async function responseBody<T>(response: Response): Promise<T> {
  const body = await response.json().catch(() => null) as { error?: { message?: unknown }, message?: unknown } | null

  if (!response.ok) {
    const message = body?.error?.message ?? body?.message
    throw new Error(typeof message === 'string' ? message : `Request failed (${response.status}).`)
  }

  return body as T
}
