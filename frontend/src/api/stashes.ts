import type { StashItemApiResource, StashItemsApiResponse } from '../types/item'
import type { StashApiResource, StashDeleteImpact } from '../types/stash'
import { apiFetch } from './auth'

export interface StashPreflightResponse {
  command_id: string
  command_state: string
  review_url?: string
}

export interface StashPreflightReview {
  command_id: string
  state: string
  preflight?: {
    resolved_input?: {
      provider_key?: string | null
      title?: string | null
      estimated_item_count?: number | null
      size_bytes?: number | null
      size_estimated?: boolean
    }
    discovery?: {
      strategy_key?: string | null
      estimated_item_count?: number | null
      estimated_total_duration_seconds?: number | null
      estimated_total_size_bytes?: number | null
      estimated_total_size_estimated?: boolean
      estimated_total_size_known_items?: number
      estimated_total_size_item_count?: number
    }
  } | null
}

export async function fetchStashes(): Promise<StashApiResource[]> {
  const body = await responseBody<{ stashes?: StashApiResource[] }>(await apiFetch('/api/v1/stashes'))

  return body.stashes ?? []
}

export async function syncStash(stashId: string): Promise<string[]> {
  const response = await apiFetch(`/api/v1/stashes/${encodeURIComponent(stashId)}/sync`, { method: 'POST' })
  const body = await responseBody<{ command_ids?: string[] }>(response)

  return body.command_ids ?? []
}

export async function fetchStash(stashId: string): Promise<StashApiResource> {
  const response = await apiFetch(`/api/v1/stashes/${encodeURIComponent(stashId)}`)
  const body = await responseBody<{ stash: StashApiResource }>(response)

  return body.stash
}

export async function preflightStash(sourceUri: string, sourceTitle?: string | null): Promise<StashPreflightResponse> {
  const response = await apiFetch('/api/v1/stashes/preflight', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ source_uri: sourceUri, source_title: sourceTitle ?? null, origin: 'create_stash' })
  })

  return responseBody<StashPreflightResponse>(response)
}

export async function fetchStashPreflightReview(commandId: string): Promise<StashPreflightReview> {
  const response = await apiFetch(`/api/v1/stashes/preflight/${encodeURIComponent(commandId)}/review`)

  return responseBody<StashPreflightReview>(response)
}

export interface UpdateStashInput {
  name: string
  description: string
  sync_mode: string
  download_policy: string
  organization_mode: string
}

export async function updateStash(stashId: string, input: UpdateStashInput): Promise<StashApiResource> {
  const response = await apiFetch(`/api/v1/stashes/${encodeURIComponent(stashId)}`, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(input)
  })
  const body = await responseBody<{ stash: StashApiResource }>(response)
  return body.stash
}

export async function fetchStashDeleteImpact(stashId: string): Promise<StashDeleteImpact> {
  const response = await apiFetch(`/api/v1/stashes/${encodeURIComponent(stashId)}/delete-impact`)
  const body = await responseBody<{ delete_impact: StashDeleteImpact }>(response)
  return body.delete_impact
}

export async function deleteStash(stashId: string): Promise<void> {
  await responseBody(await apiFetch(`/api/v1/stashes/${encodeURIComponent(stashId)}`, { method: 'DELETE' }))
}

export async function retryFailedStash(stashId: string): Promise<string> {
  const response = await apiFetch('/api/v1/commands', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ type: 'stash.retry_failed', options: { stash_id: stashId } })
  })
  const body = await responseBody<{ command_id: string }>(response)
  return body.command_id
}

export interface StashItemsQuery {
  limit: number
  offset: number
  search?: string
  status?: string
  sort?: string
  direction?: 'asc' | 'desc'
}

export async function fetchStashItems(stashId: string, query: StashItemsQuery): Promise<StashItemsApiResponse> {
  const parameters = new URLSearchParams({ limit: String(query.limit), offset: String(query.offset) })
  if (query.search) parameters.set('search', query.search)
  if (query.status) parameters.set('status', query.status)
  if (query.sort) parameters.set('sort', query.sort)
  if (query.direction) parameters.set('dir', query.direction)

  const response = await apiFetch(`/api/v1/stashes/${encodeURIComponent(stashId)}/items?${parameters}`)
  const body = await responseBody<Partial<StashItemsApiResponse> & { items?: StashItemApiResource[] }>(response)

  return {
    items: body.items ?? [],
    total: body.total ?? 0,
    limit: body.limit ?? query.limit,
    offset: body.offset ?? 0,
    stash_item_count: body.stash_item_count ?? body.total ?? 0,
    ignored_count: body.ignored_count ?? 0,
    status_counts: body.status_counts ?? {}
  }
}

async function responseBody<T>(response: Response): Promise<T> {
  const body = await response.json().catch(() => null) as { error?: { message?: unknown }, message?: unknown } | null

  if (!response.ok) {
    const message = body?.error?.message ?? body?.message
    throw new Error(typeof message === 'string' ? message : `Request failed (${response.status}).`)
  }

  return body as T
}
