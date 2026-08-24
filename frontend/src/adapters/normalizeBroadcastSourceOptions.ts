import type { BroadcastOptionDeclaration, BroadcastOptionValue } from '../types/broadcast-plugin'
import type { PluginField } from '../types/plugin-ui'

export interface BroadcastSourceOptionDiagnostic {
  key: string
  message: string
}

export interface NormalizedBroadcastSourceOptions {
  fields: PluginField[]
  diagnostics: BroadcastSourceOptionDiagnostic[]
}

export function normalizeBroadcastSourceOptions(options: BroadcastOptionDeclaration[]): NormalizedBroadcastSourceOptions {
  const fields: PluginField[] = []
  const diagnostics: BroadcastSourceOptionDiagnostic[] = []

  for (const option of options) {
    const result = normalizeBroadcastSourceOption(option)

    if ('field' in result) fields.push(result.field)
    else diagnostics.push(result.diagnostic)
  }

  return { fields, diagnostics }
}

function normalizeBroadcastSourceOption(option: BroadcastOptionDeclaration): { field: PluginField } | { diagnostic: BroadcastSourceOptionDiagnostic } {
  switch (option.type) {
    case 'number':
      return option.default === undefined || option.default === null || typeof option.default === 'number'
        ? { field: { ...common(option), type: 'number', ...(typeof option.default === 'number' ? { default: option.default } : {}) } }
        : invalid(option, 'a numeric default')
    default:
      return invalid(option, `unsupported type "${option.type}"`)
  }
}

function invalid(option: BroadcastOptionDeclaration, reason: string): { diagnostic: BroadcastSourceOptionDiagnostic } {
  const message = `Broadcast source option "${option.name}" requires ${reason}.`
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

export function broadcastSourceOptionValues(fields: PluginField[], persisted: Record<string, BroadcastOptionValue> = {}): Record<string, BroadcastOptionValue> {
  return Object.fromEntries(fields.flatMap(field => {
    const value = Object.hasOwn(persisted, field.key) ? persisted[field.key] : field.default

    return typeof value === 'boolean' || typeof value === 'number' || typeof value === 'string' ? [[field.key, value]] : []
  }))
}
