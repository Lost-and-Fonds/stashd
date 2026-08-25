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

export interface MediaServerLibraryChoice {
  value: string
  label: string
  kind?: string
}

export interface BroadcastPluginApiResource {
  key: string
  label: string
  description?: string | null
  supported_file_kinds?: string[]
  ui_controls?: BroadcastOptionDeclaration[]
  connection_setting_key?: string | null
  library_setting_key?: string | null
}

export interface CreatedBroadcastApiResource {
  id: string
  name: string
}

export interface BroadcastApiResource {
  id: string
  stash_id: string
  type: string
  name: string
  state: string
  published_url?: string
  last_built_at?: string | null
  created_at?: string
  updated_at?: string
  settings?: {
    destination_path?: string | null
    source_settings?: Record<string, Record<string, BroadcastOptionValue>>
  } | null
  plugin_actions?: BroadcastPluginActionApiResource[]
  plugin_detail_fields?: BroadcastDetailFieldApiResource[]
  plugin_source_options?: BroadcastOptionDeclaration[]
  rebuild_operation?: import('./input').LifecycleOperation | null
}

export interface BroadcastDetailFieldApiResource {
  id: string
  label: string
  value: unknown
  kind?: string
  link?: unknown
}

export interface BroadcastPluginActionApiResource {
  id: string
  label: string
  intent: string
  confirmation?: unknown
}
