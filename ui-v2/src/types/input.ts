export type InputStatus = 'active' | 'paused' | 'needs-attention'
export type InputProvider = 'youtube-channel' | 'youtube-playlist' | 'rss'
export type InputSyncMode = 'automatic' | 'manual'

/**
 * Mirrors the real domain shape (StashInputOptions in the PHP app): a
 * universal title-regex include/exclude tier, plus provider-declared
 * boolean options that only apply to certain input types (e.g. YouTube
 * channel's "include Shorts"/"include live").
 */
export interface InputFilters {
  titleRegexInclude?: string
  titleRegexExclude?: string
  includeShorts?: boolean
  includeLive?: boolean
}

export interface InputFixture {
  id: string
  stashId: string
  provider: InputProvider
  providerLabel: string
  identity: string
  url: string
  status: InputStatus
  syncMode: InputSyncMode
  filterSummary?: string
  filters?: InputFilters
  lastChecked: string
  lastCheckedAt: string
}
