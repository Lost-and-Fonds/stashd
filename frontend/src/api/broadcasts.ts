import type { BroadcastApiResource, BroadcastOptionValue, BroadcastPluginApiResource, CreatedBroadcastApiResource, MediaServerLibraryChoice } from '../types/broadcast-plugin'
import type { LifecycleOperation } from '../types/input'
import { runConnectionOperation } from './connections'

export interface BroadcastPreview {
  eligible_item_count: number
  skipped_item_count: number
  vault_size_bytes: number
  hardlinked_item_count: number
  transcode_item_count: number
}

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

export async function previewBroadcast(stashId: string, type: string, mediaKind?: string): Promise<BroadcastPreview> {
  const response = await fetch(`/api/v1/stashes/${encodeURIComponent(stashId)}/broadcasts/preview`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ type, ...(mediaKind ? { media_kind: mediaKind } : {}) })
  })
  const body = await responseBody<{ preview: BroadcastPreview }>(response)

  return body.preview
}

export async function fetchConnectionLibraries(connectionId: string): Promise<MediaServerLibraryChoice[]> {
  const body = await runConnectionOperation(connectionId, 'list_libraries')

  return body.choices ?? []
}

export async function fetchBroadcast(broadcastId: string): Promise<BroadcastApiResource> {
  const body = await responseBody<{ broadcast: BroadcastApiResource }>(await fetch(`/api/v1/broadcasts/${encodeURIComponent(broadcastId)}`))

  return body.broadcast
}

export async function deleteBroadcast(broadcastId: string): Promise<string> {
  const response = await fetch(`/api/v1/broadcasts/${encodeURIComponent(broadcastId)}`, { method: 'DELETE' })
  const body = await responseBody<{ command_id: string }>(response)

  return body.command_id
}

export async function updateBroadcastDestination(broadcastId: string, destinationPath: string): Promise<BroadcastApiResource> {
  const response = await fetch(`/api/v1/broadcasts/${encodeURIComponent(broadcastId)}/destination`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ destination_path: destinationPath })
  })
  const body = await responseBody<{ broadcast: BroadcastApiResource }>(response)

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

export async function rebuildBroadcast(broadcastId: string): Promise<LifecycleOperation> {
  const response = await fetch(`/api/v1/broadcasts/${encodeURIComponent(broadcastId)}/rebuild`, { method: 'POST' })
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
