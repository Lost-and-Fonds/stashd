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
  last_discovery_at?: string
}

export interface StashDeleteImpact {
  shared_items: Array<{
    item_id: string
    title: string
    shared_with_stashes: Array<{ id: string, name: string }>
  }>
  orphaned_items: Array<{
    item_id: string
    title: string
  }>
}
