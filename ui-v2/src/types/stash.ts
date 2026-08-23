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
  created_at?: string
  updated_at?: string
}
