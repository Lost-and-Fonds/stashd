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

export async function fetchVaultItems(query: { limit: number, offset: number, search?: string, kind?: string }): Promise<VaultItemsResponse> {
  const parameters = new URLSearchParams({ limit: String(query.limit), offset: String(query.offset) })
  if (query.search) parameters.set('search', query.search)
  if (query.kind) parameters.set('kind', query.kind)
  const response = await fetch(`/api/v1/items?${parameters}`)
  const body = await response.json().catch(() => null) as { error?: { message?: unknown } } | null
  if (!response.ok) throw new Error(typeof body?.error?.message === 'string' ? body.error.message : `Request failed (${response.status}).`)
  return body as VaultItemsResponse
}
