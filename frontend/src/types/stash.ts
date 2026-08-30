export type StashStatus = 'active' | 'paused' | 'needs-attention'

export interface StashOperation {
  label: string
  percent: number | null
  stage?: string
  count?: string
}

export interface StashFixture {
  id: string
  name: string
  itemCount: number
  sizeLabel: string
  lastActivity: string
  lastActivityAt: string
  status: StashStatus
  inputCount: number
  broadcastCount: number
  operation?: StashOperation
}

export interface StashApiResource {
  id: string
  name: string
  description?: string | null
  sync_mode?: string
  download_policy?: string
  organization_mode?: string
  state: string
  icon_uri?: string | null
  item_count?: number
  storage_bytes?: number
  input_summary?: string[]
  created_at?: string
  updated_at?: string
}

export interface StashDeleteImpact {
  shared_items: Array<{
    media_item_id: string
    title: string
    shared_with_stashes: Array<{ id: string, name: string }>
  }>
  orphaned_items: Array<{
    media_item_id: string
    title: string
  }>
}
