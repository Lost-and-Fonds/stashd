import type { BroadcastOptionDeclaration, BroadcastOptionValue } from '../types/broadcast-plugin'
import type { PluginField } from '../types/plugin-ui'

export interface BroadcastOptionDiagnostic {
  key: string
  message: string
}

export interface NormalizedBroadcastOptions {
  fields: PluginField[]
  diagnostics: BroadcastOptionDiagnostic[]
}

export function normalizeBroadcastOptions(options: BroadcastOptionDeclaration[]): NormalizedBroadcastOptions {
  const fields: PluginField[] = []
  const diagnostics: BroadcastOptionDiagnostic[] = []

  for (const option of options) {
    const result = normalizeBroadcastOption(option)

    if ('field' in result) fields.push(result.field)
    else diagnostics.push(result.diagnostic)
  }

  return { fields, diagnostics }
}

function normalizeBroadcastOption(option: BroadcastOptionDeclaration): { field: PluginField } | { diagnostic: BroadcastOptionDiagnostic } {
  switch (option.type) {
    case 'bool':
    case 'boolean':
      return option.default === undefined || option.default === null || typeof option.default === 'boolean'
        ? { field: { ...common(option), type: 'boolean', ...(typeof option.default === 'boolean' ? { default: option.default } : {}) } }
        : invalid(option, 'a boolean default')
    case 'text':
      return option.default === undefined || option.default === null || typeof option.default === 'string'
        ? { field: { ...common(option), type: 'text', ...(typeof option.default === 'string' ? { default: option.default } : {}) } }
        : invalid(option, 'a text default')
    case 'number':
      return option.default === undefined || option.default === null || typeof option.default === 'number'
        ? { field: { ...common(option), type: 'number', ...(typeof option.default === 'number' ? { default: option.default } : {}) } }
        : invalid(option, 'a numeric default')
    case 'enum':
    case 'select':
      if ((option.default === undefined || option.default === null || typeof option.default === 'string') && Array.isArray(option.options) && option.options.every(value => typeof value === 'string')) {
        return { field: { ...common(option), type: 'select', choices: option.options, ...(typeof option.default === 'string' ? { default: option.default } : {}) } }
      }
      return invalid(option, 'string options and a text default')
    default:
      return invalid(option, `unsupported type "${option.type}"`)
  }
}

function invalid(option: BroadcastOptionDeclaration, reason: string): { diagnostic: BroadcastOptionDiagnostic } {
  const message = `Broadcast option "${option.name}" requires ${reason}.`
  console.warn(`[stashd] ${message}`)

  return { diagnostic: { key: option.name, message } }
}

function common(option: BroadcastOptionDeclaration): Pick<PluginField, 'key' | 'label' | 'description' | 'required'> {
  return {
    key: option.name,
    label: option.label,
    description: option.description ?? undefined,
    required: option.required === true
  }
}

export function broadcastOptionValues(fields: PluginField[]): Record<string, BroadcastOptionValue> {
  return Object.fromEntries(fields.flatMap(field => {
    const value = field.default

    return typeof value === 'boolean' || typeof value === 'number' || typeof value === 'string' ? [[field.key, value]] : []
  }))
}
