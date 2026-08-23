import type { BroadcastApiResource, BroadcastOptionValue, BroadcastPluginApiResource, CreatedBroadcastApiResource } from '../types/broadcast-plugin'

export async function fetchBroadcastPlugins(): Promise<BroadcastPluginApiResource[]> {
  const body = await responseBody<{ plugins?: BroadcastPluginApiResource[] }>(await fetch('/api/v1/broadcast-plugins'))

  return body.plugins ?? []
}

export async function fetchStashBroadcasts(stashId: string): Promise<BroadcastApiResource[]> {
  const body = await responseBody<{ broadcasts?: BroadcastApiResource[] }>(await fetch(`/api/v1/stashes/${encodeURIComponent(stashId)}/broadcasts`))

  return body.broadcasts ?? []
}

export async function createStashBroadcast(stashId: string, type: string, settings: Record<string, BroadcastOptionValue>): Promise<CreatedBroadcastApiResource> {
  const response = await fetch(`/api/v1/stashes/${encodeURIComponent(stashId)}/broadcasts`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ type, settings })
  })
  const body = await responseBody<{ broadcast: CreatedBroadcastApiResource }>(response)

  return body.broadcast
}

export async function fetchBroadcast(broadcastId: string): Promise<BroadcastApiResource> {
  const body = await responseBody<{ broadcast: BroadcastApiResource }>(await fetch(`/api/v1/broadcasts/${encodeURIComponent(broadcastId)}`))

  return body.broadcast
}

export async function updateBroadcastSourceSettings(broadcastId: string, sourceReference: string, settings: Record<string, BroadcastOptionValue>): Promise<BroadcastApiResource> {
  const response = await fetch(`/api/v1/broadcasts/${encodeURIComponent(broadcastId)}/source-settings`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ source_reference: sourceReference, settings })
  })
  const body = await responseBody<{ broadcast: BroadcastApiResource }>(response)

  return body.broadcast
}

export async function invokeBroadcastAction(broadcastId: string, intent: string): Promise<BroadcastApiResource> {
  const response = await fetch(`/api/v1/broadcasts/${encodeURIComponent(broadcastId)}/actions`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ intent })
  })
  const body = await responseBody<{ completed: true, broadcast: BroadcastApiResource }>(response)

  return body.broadcast
}

async function responseBody<T>(response: Response): Promise<T> {
  const body = await response.json().catch(() => null) as { error?: { message?: unknown }, message?: unknown } | null

  if (!response.ok) {
    const message = body?.error?.message ?? body?.message
    throw new Error(typeof message === 'string' ? message : `Request failed (${response.status}).`)
  }

  return body as T
}
