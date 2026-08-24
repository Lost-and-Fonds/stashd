import type { PluginField } from '../types/plugin-ui'

export interface SourceFieldDeclaration {
  key: string
  label: string
  type: string
  required?: boolean
  choices?: string[] | null
  description?: string | null
}

export function normalizeSourceFields(fields: SourceFieldDeclaration[]): { fields: PluginField[], diagnostics: string[] } {
  const normalized: PluginField[] = []
  const diagnostics: string[] = []

  for (const field of fields) {
    const type = field.type === 'bool' ? 'boolean' : field.type === 'enum' ? 'select' : field.type
    if (!['boolean', 'number', 'text', 'select'].includes(type) || (type === 'select' && !field.choices?.every(choice => typeof choice === 'string'))) {
      diagnostics.push(`Source field "${field.key}" is unsupported.`)
      continue
    }
    normalized.push({ key: field.key, label: field.label, type: type as PluginField['type'], required: field.required === true, choices: field.choices ?? undefined, description: field.description ?? undefined })
  }

  return { fields: normalized, diagnostics }
}
