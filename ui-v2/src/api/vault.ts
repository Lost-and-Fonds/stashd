export interface VaultItemApiResource {
  id: string
  title: string
  providerKey: string
  kind: string | null
  stashCount: number
  broadcastCount: number
  preservedSizeBytes: number
  createdAt: string
}

export interface VaultItemsResponse {
  items: VaultItemApiResource[]
  total: number
  vaultTotal: number
  preservedSizeBytes: number
  limit: number
  offset: number
}

export interface VaultAssetApiResource {
  id: string
  role: string
  kind: string
  size_bytes: number | null
  display_path: string | null
  mime_type: string | null
  language: string | null
  duration_seconds: number | null
  created_at: string | null
}

export interface VaultItemDetailResponse {
  item: {
    id: string
    title: string
    provider_key: string
    provider_item_id: string
    canonical_uri: string
    description: string | null
    content_type: string | null
    published_at: string | null
    updated_at: string | null
  }
  assets: VaultAssetApiResource[]
  stashes: Array<{ id: string, name: string }>
  broadcasts: Array<{ id: string, stash_id: string, type: string, name: string, slug: string }>
  preserved_size_bytes: number
}

export async function fetchVaultItems(query: { limit: number, offset: number, search?: string, kind?: string }): Promise<VaultItemsResponse> {
  const parameters = new URLSearchParams({ limit: String(query.limit), offset: String(query.offset) })
  if (query.search) parameters.set('search', query.search)
  if (query.kind) parameters.set('kind', query.kind)
  const response = await fetch(`/api/v1/items?${parameters}`)
  const body = await response.json().catch(() => null) as { error?: { message?: unknown } } | null
  if (!response.ok) throw new Error(typeof body?.error?.message === 'string' ? body.error.message : `Request failed (${response.status}).`)
  return body as VaultItemsResponse
}

export async function fetchVaultItem(id: string): Promise<VaultItemDetailResponse> {
  const response = await fetch(`/api/v1/items/${encodeURIComponent(id)}`)
  const body = await response.json().catch(() => null) as { error?: { message?: unknown } } | null
  if (!response.ok) throw new Error(typeof body?.error?.message === 'string' ? body.error.message : `Request failed (${response.status}).`)
  return body as VaultItemDetailResponse
}
