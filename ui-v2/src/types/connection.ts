export interface ConnectionApiResource {
  id: string
  plugin_key: string
  name: string
  endpoint: string
  state: string
  settings?: Record<string, unknown> | null
  last_checked_at?: string | null
  last_error?: string | null
  created_at?: string
  updated_at?: string
}

export interface ConnectionChoice {
  value: string
  label: string
}

export interface ConnectionOperationResult {
  choices?: ConnectionChoice[]
  values?: { key: string, value: string }[]
}
