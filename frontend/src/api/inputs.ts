import type { LifecycleOperation, StashInputApiResource, StashInputOptionsApiResource } from '../types/input'

export async function fetchStashInput(stashId: string, inputId: string): Promise<StashInputApiResource> {
  const input = (await fetchStashInputs(stashId)).find(candidate => candidate.id === inputId)

  if (!input) throw new Error('Input not found.')

  return input
}

export async function fetchStashInputs(stashId: string): Promise<StashInputApiResource[]> {
  const response = await fetch(`/api/v1/stashes/${encodeURIComponent(stashId)}/inputs`)
  const body = await responseBody<{ inputs?: StashInputApiResource[] }>(response)

  return body.inputs ?? []
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

export async function addInputToStash(stashId: string, plugin: string, source: Record<string, boolean | number | string>, options: Record<string, boolean | string>): Promise<void> {
  const response = await fetch(`/api/v1/stashes/${encodeURIComponent(stashId)}/inputs`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ plugin, source, options: { provider: options } })
  })
  await responseBody<{ stash_input_id?: string }>(response)
}

export async function syncStashInput(stashId: string, inputId: string): Promise<LifecycleOperation> {
  const response = await fetch(`/api/v1/stashes/${encodeURIComponent(stashId)}/inputs/${encodeURIComponent(inputId)}/sync`, {
    method: 'POST'
  })
  const body = await responseBody<{ operation: LifecycleOperation }>(response)

  return body.operation
}

async function responseBody<T>(response: Response): Promise<T> {
  const body = await response.json().catch(() => null) as { error?: { message?: unknown }, message?: unknown } | null

  if (!response.ok) {
    const message = body?.error?.message ?? body?.message
    throw new Error(typeof message === 'string' ? message : `Request failed (${response.status}).`)
  }

  return body as T
}
