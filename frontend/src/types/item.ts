export type ItemStatus = 'ready' | 'processing' | 'queued' | 'needs-attention'

export interface ItemFixture {
  id: string
  stashId: string
  title: string
  publishedAt: string
  duration: string
  sizeLabel: string
  status: ItemStatus
  /** Tailwind gradient classes for the thumbnail; omitted falls back to a plain tile. */
  art?: string
  /** Only meaningful while status is 'processing'. */
  progressPercent?: number | null
  progressStage?: string
}

export interface StashItemApiResource {
  id: string
  stash_id: string
  media_item_id: string
  state: string
  display_title?: string | null
  media_item: {
    title: string
    state: string
    thumbnail_uri?: string | null
    duration_seconds?: number | null
    published_at?: string | null
    failure_reason?: string | null
    upstream_state?: string | null
    size_bytes?: number | null
    size_estimated?: boolean
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
  status_counts?: Record<string, number>
}
