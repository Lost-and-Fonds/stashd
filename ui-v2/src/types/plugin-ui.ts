export type PluginFieldType = 'boolean' | 'text' | 'number' | 'select'

export type PluginFieldValue = boolean | number | string

// This is the normalized rendering contract. Manifest adapters belong at the
// API boundary; for example, YouTube's `bool` becomes `boolean` there.
export interface PluginField {
  key: string
  label: string
  type: PluginFieldType
  default?: PluginFieldValue
  choices?: string[]
  description?: string
  required?: boolean
}
