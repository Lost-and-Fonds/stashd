import type { BroadcastOptionValue, BroadcastPluginApiResource, CreatedBroadcastApiResource, StashApiResource } from '../types/broadcast-plugin'

export async function fetchBroadcastPlugins(): Promise<BroadcastPluginApiResource[]> {
  const body = await responseBody<{ plugins?: BroadcastPluginApiResource[] }>(await fetch('/api/v1/broadcast-plugins'))

  return body.plugins ?? []
}

export async function fetchStash(stashId: string): Promise<StashApiResource> {
  const body = await responseBody<{ stash: StashApiResource }>(await fetch(`/api/v1/stashes/${encodeURIComponent(stashId)}`))

  return body.stash
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

async function responseBody<T>(response: Response): Promise<T> {
  const body = await response.json().catch(() => null) as { error?: { message?: unknown }, message?: unknown } | null

  if (!response.ok) {
    const message = body?.error?.message ?? body?.message
    throw new Error(typeof message === 'string' ? message : `Request failed (${response.status}).`)
  }

  return body as T
}
