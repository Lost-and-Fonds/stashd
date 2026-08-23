export type BroadcastOptionValue = boolean | number | string

export interface BroadcastOptionDeclaration {
  name: string
  label: string
  type: string
  default?: unknown
  options?: unknown
  description?: string | null
  required?: boolean
}

export interface BroadcastPluginApiResource {
  key: string
  label: string
  description?: string | null
  supported_file_kinds?: string[]
  ui_controls?: BroadcastOptionDeclaration[]
}

export interface StashApiResource {
  id: string
  name: string
}

export interface CreatedBroadcastApiResource {
  id: string
  name: string
}

export interface BroadcastApiResource {
  id: string
  stash_id: string
  name: string
  settings?: {
    source_settings?: Record<string, Record<string, BroadcastOptionValue>>
  } | null
  plugin_source_options?: BroadcastOptionDeclaration[]
}
