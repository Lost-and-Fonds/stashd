import type { BroadcastDetailFieldApiResource } from '../types/broadcast-plugin'

export interface PluginDetailField {
  id: string
  label: string
  presentation: 'link'
  value: string
  href: string
}

export interface BroadcastDetailFieldDiagnostic {
  id: string
  message: string
}

export interface NormalizedBroadcastDetailFields {
  fields: PluginDetailField[]
  diagnostics: BroadcastDetailFieldDiagnostic[]
}

export function normalizeBroadcastDetailFields(fields: BroadcastDetailFieldApiResource[]): NormalizedBroadcastDetailFields {
  const normalized: PluginDetailField[] = []
  const diagnostics: BroadcastDetailFieldDiagnostic[] = []

  for (const field of fields) {
    if (field.kind === 'url' && typeof field.value === 'string' && typeof field.link === 'string' && field.link !== '') {
      normalized.push({ id: field.id, label: field.label, presentation: 'link', value: field.value, href: field.link })
      continue
    }

    const message = `Broadcast detail "${field.label}" has an unsupported presentation.`
    console.warn(`[stashd] ${message}`)
    diagnostics.push({ id: field.id, message })
  }

  return { fields: normalized, diagnostics }
}
