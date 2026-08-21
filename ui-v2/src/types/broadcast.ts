export type BroadcastStatus = 'active' | 'paused' | 'needs-attention'
export type BroadcastKind = 'podcast' | 'jellyfin' | 'plex'

/**
 * Distinct from `status`: a broadcast's trigger/health can be fine while its
 * built output is behind the Vault (or vice versa) — see AGENTS.md, "Trigger
 * failures are separate from broadcast file validity."
 */
export type BroadcastBuildState = 'current' | 'rebuilding' | 'stale'

export interface BroadcastFixture {
  id: string
  stashId: string
  kind: BroadcastKind
  name: string
  formLabel: string
  status: BroadcastStatus
  buildState: BroadcastBuildState
  buildPercent?: number | null
  buildStage?: string
  lastRebuild: string
  lastRebuildAt: string
  itemsPublished: number
  itemsTotal: number
  sizeLabel?: string
  publishedUrl?: string
}
