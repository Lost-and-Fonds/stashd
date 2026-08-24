import type { ConnectionApiResource, ConnectionOperationResult } from '../types/connection'

export async function fetchConnections(): Promise<ConnectionApiResource[]> {
  const body = await responseBody<{ connections?: ConnectionApiResource[] }>(await fetch('/api/v1/connections'))

  return body.connections ?? []
}

export async function createConnection(input: { plugin_key: string, name: string, endpoint: string, token?: string }): Promise<ConnectionApiResource> {
  const response = await fetch('/api/v1/connections', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(input)
  })
  const body = await responseBody<{ connection: ConnectionApiResource }>(response)

  return body.connection
}

export async function updateConnection(id: string, input: { name: string, endpoint: string, token?: string }): Promise<ConnectionApiResource> {
  const response = await fetch(`/api/v1/connections/${encodeURIComponent(id)}`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(input)
  })
  const body = await responseBody<{ connection: ConnectionApiResource }>(response)

  return body.connection
}

export async function deleteConnection(id: string): Promise<void> {
  const response = await fetch(`/api/v1/connections/${encodeURIComponent(id)}`, { method: 'DELETE' })

  await responseBody(response)
}

export async function runConnectionOperation(id: string, operation: string): Promise<ConnectionOperationResult> {
  const response = await fetch(`/api/v1/connections/${encodeURIComponent(id)}/operations/${encodeURIComponent(operation)}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({})
  })

  return responseBody<ConnectionOperationResult>(response)
}

async function responseBody<T>(response: Response): Promise<T> {
  const body = await response.json().catch(() => null) as { error?: { message?: unknown }, message?: unknown } | null

  if (!response.ok) {
    const message = body?.error?.message ?? body?.message
    throw new Error(typeof message === 'string' ? message : `Request failed (${response.status}).`)
  }

  return body as T
}
