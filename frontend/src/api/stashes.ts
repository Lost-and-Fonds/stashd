import type { StashItemApiResource, StashItemsApiResponse } from '../types/item'
import type { StashApiResource } from '../types/stash'

export async function fetchStash(stashId: string): Promise<StashApiResource> {
  const response = await fetch(`/api/v1/stashes/${encodeURIComponent(stashId)}`)
  const body = await responseBody<{ stash: StashApiResource }>(response)

  return body.stash
}

export interface StashItemsQuery {
  limit: number
  offset: number
  search?: string
  status?: string
}

export async function fetchStashItems(stashId: string, query: StashItemsQuery): Promise<StashItemsApiResponse> {
  const parameters = new URLSearchParams({ limit: String(query.limit), offset: String(query.offset) })
  if (query.search) parameters.set('search', query.search)
  if (query.status) parameters.set('status', query.status)

  const response = await fetch(`/api/v1/stashes/${encodeURIComponent(stashId)}/items?${parameters}`)
  const body = await responseBody<Partial<StashItemsApiResponse> & { items?: StashItemApiResource[] }>(response)

  return {
    items: body.items ?? [],
    total: body.total ?? 0,
    limit: body.limit ?? query.limit,
    offset: body.offset ?? 0,
    stash_item_count: body.stash_item_count ?? body.total ?? 0
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
