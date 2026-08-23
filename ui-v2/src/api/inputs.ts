import type { StashInputApiResource, StashInputOptionsApiResource } from '../types/input'

export async function fetchStashInput(stashId: string, inputId: string): Promise<StashInputApiResource> {
  const response = await fetch(`/api/v1/stashes/${encodeURIComponent(stashId)}/inputs`)
  const body = await responseBody<{ inputs?: StashInputApiResource[] }>(response)
  const input = body.inputs?.find(candidate => candidate.id === inputId)

  if (!input) throw new Error('Input not found.')

  return input
}

export async function updateStashInputOptions(stashId: string, inputId: string, options: StashInputOptionsApiResource): Promise<StashInputApiResource> {
  const response = await fetch(`/api/v1/stashes/${encodeURIComponent(stashId)}/inputs/${encodeURIComponent(inputId)}`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ options })
  })
  const body = await responseBody<{ input: StashInputApiResource }>(response)

  return body.input
}

async function responseBody<T>(response: Response): Promise<T> {
  const body = await response.json().catch(() => null) as { message?: unknown } | null

  if (!response.ok) {
    throw new Error(typeof body?.message === 'string' ? body.message : `Request failed (${response.status}).`)
  }

  return body as T
}
