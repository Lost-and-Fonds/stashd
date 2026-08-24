/**
 * The canonical Vault Item's preservation record — richer than
 * `VaultItemFixture` (the lean row shown on the Vault overview table).
 * Looked up by the same `id`. See planning/DECISIONS.md, "Preservation
 * confidence is a primary user-facing property of a canonical Vault Item."
 */
export type PreservationStateKey = 'verified' | 'needs-attention'

export interface PreservationAsset {
  role: string
  filename: string
  detail: string
  derivedFrom?: string
  action: { label: string, icon: string }
}

export interface ProvenanceFact {
  label: string
  value: string
}

export interface PreservationHistoryEvent {
  dateLabel: string
  label: string
}

export interface PreservationStatus {
  state: PreservationStateKey
  label: string
  summary: string
  assetCount: number
  /** Only present once at least one verification has actually succeeded — never implies "last attempt." */
  lastVerifiedLabel?: string
  /** Only present for a failed/mismatched verification attempt. Distinct from lastVerifiedLabel on purpose. */
  lastMismatchLabel?: string
}

export interface VaultItemRecord {
  /** Matches VaultItemFixture.id */
  id: string
  title: string
  typeLabel: string
  sourceLabel: string
  preservedLabel: string
  publishedLabel?: string
  preservation: PreservationStatus
  sourceAvailability?: { label: string, note: string }
  assets: PreservationAsset[]
  provenanceIntro: string
  provenanceFacts: ProvenanceFact[]
  organisedIn: string[]
  usedBy: { name: string, kind: string }[]
  history: PreservationHistoryEvent[]
}
