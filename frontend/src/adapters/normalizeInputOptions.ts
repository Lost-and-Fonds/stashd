import type { InputOptionDeclaration, InputOptionValue } from '../types/input'
import type { PluginField } from '../types/plugin-ui'

export interface InputOptionDiagnostic {
  key: string
  message: string
}

export interface NormalizedInputOptions {
  fields: PluginField[]
  diagnostics: InputOptionDiagnostic[]
}

export function normalizeInputOptions(options: InputOptionDeclaration[]): NormalizedInputOptions {
  const fields: PluginField[] = []
  const diagnostics: InputOptionDiagnostic[] = []

  for (const option of options) {
    const result = normalizeInputOption(option)

    if ('field' in result) fields.push(result.field)
    else diagnostics.push(result.diagnostic)
  }

  return { fields, diagnostics }
}

function normalizeInputOption(option: InputOptionDeclaration): { field: PluginField } | { diagnostic: InputOptionDiagnostic } {
  switch (option.type) {
    case 'bool':
      if (typeof option.default === 'boolean') return { field: { ...common(option), type: 'boolean', default: option.default } }
      return invalid(option, 'a boolean default')
    case 'text':
      if (typeof option.default === 'string') return { field: { ...common(option), type: 'text', default: option.default } }
      return invalid(option, 'a text default')
    case 'enum':
      if (typeof option.default === 'string' && option.choices?.every(choice => typeof choice === 'string')) {
        return { field: { ...common(option), type: 'select', default: option.default, choices: option.choices } }
      }
      return invalid(option, 'string choices and a text default')
    default:
      return invalid(option, `unsupported type "${option.type}"`)
  }
}

function invalid(option: InputOptionDeclaration, reason: string): { diagnostic: InputOptionDiagnostic } {
  const message = `Input option "${option.key}" requires ${reason}.`
  console.warn(`[stashd] ${message}`)

  return { diagnostic: { key: option.key, message } }
}

function common(option: InputOptionDeclaration): Pick<PluginField, 'key' | 'label' | 'description' | 'required'> {
  return {
    key: option.key,
    label: option.label,
    description: option.description ?? undefined,
    required: option.required === true
  }
}

export function inputOptionValues(fields: PluginField[], provider: Record<string, InputOptionValue> = {}): Record<string, InputOptionValue> {
  return Object.fromEntries(fields.flatMap(field => {
    const value = Object.hasOwn(provider, field.key) ? provider[field.key] : field.default

    return typeof value === 'boolean' || typeof value === 'string' ? [[field.key, value]] : []
  }))
}
