export interface StashItemApiResource {
  id: string
  stash_id: string
  item_id: string
  state: string
  display_title?: string | null
  item: {
    title: string
    state: string
    thumbnail_uri?: string | null
    duration_seconds?: number | null
    published_at?: string | null
    failure_reason?: string | null
    upstream_state?: string | null
  } | null
  total_asset_size_bytes?: number | null
}

export interface StashItemsApiResponse {
  items: StashItemApiResource[]
  total: number
  limit: number
  offset: number
  stash_item_count: number
  ignored_count?: number
  downloadable_count?: number
  status_counts?: Record<string, number>
}
