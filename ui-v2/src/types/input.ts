export type InputStatus = 'active' | 'paused' | 'needs-attention'
export type InputProvider = 'youtube-channel' | 'youtube-playlist' | 'rss'
export type InputSyncMode = 'automatic' | 'manual'
export type InputOptionValue = boolean | string

export interface InputOptionDeclaration {
  key: string
  label: string
  type: string
  default: InputOptionValue
  choices?: string[] | null
  applicable_input_types?: string[]
  description?: string | null
  required?: boolean
}

export interface InputOptionsApiResource {
  input_options: InputOptionDeclaration[]
  options?: StashInputOptionsApiResource | null
}

export interface StashInputOptionsApiResource {
  title_regex_include?: string | null
  title_regex_exclude?: string | null
  provider?: Record<string, InputOptionValue>
}

export interface StashInputApiResource extends InputOptionsApiResource {
  id: string
  stash_id: string
  provider_key: string
  input_type: string
  source_uri: string
  provider_input_id: string
  state: string
  options?: StashInputOptionsApiResource | null
}

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
