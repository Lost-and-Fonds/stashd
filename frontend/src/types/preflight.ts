/**
 * "Preflight" is the internal name for the "What Stashd will do" review
 * surface shown before committing a creation/configuration workflow (Add
 * Input, New Broadcast, …). Never surface the word "Preflight" itself.
 * See planning/DECISIONS.md, "Creation Preflight".
 */

export type StorageEstimate =
  | { kind: 'none' }
  | { kind: 'estimate', label: string, estimated?: boolean }
  | { kind: 'range', lowLabel: string, highLabel: string }
  | { kind: 'calculating' }
  | { kind: 'unavailable' }

/**
 * One aggregate row in the plan — deliberately NOT typed around hardlink/
 * transcode/download specifically. A future operation kind needs nothing
 * more than one more fixture row with these same fields.
 */
export interface PreflightOperation {
  key: string
  label: string
  itemCount: number
  storageLabel: string
  icon?: string
}

export interface PreflightPlan {
  /** Fully composed by the caller — wording differs by context ("412 items" vs "96 items found"). */
  itemCountLabel?: string
  operations: PreflightOperation[]
  storage: StorageEstimate
  /** Calm, informational — never rendered as a warning/error. */
  notes?: string[]
}

export interface PreflightState {
  /** 'analyzing': plan may be partial (storage often still 'calculating'). 'ready': analysis has settled — the storage kind will not be 'calculating'. */
  status: 'analyzing' | 'ready'
  plan: PreflightPlan
}
