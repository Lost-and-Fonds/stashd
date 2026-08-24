import type { BroadcastPluginActionApiResource } from '../types/broadcast-plugin'

export interface PluginAction {
  id: string
  label: string
  intent: string
  confirmation: boolean
}

export interface BroadcastActionDiagnostic {
  id: string
  message: string
}

export interface NormalizedBroadcastActions {
  actions: PluginAction[]
  diagnostics: BroadcastActionDiagnostic[]
}

export function normalizeBroadcastActions(actions: BroadcastPluginActionApiResource[]): NormalizedBroadcastActions {
  const normalized: PluginAction[] = []
  const diagnostics: BroadcastActionDiagnostic[] = []

  for (const action of actions) {
    if (typeof action.id === 'string' && action.id !== ''
      && typeof action.label === 'string' && action.label !== ''
      && typeof action.intent === 'string' && action.intent !== ''
      && (action.confirmation === undefined || typeof action.confirmation === 'boolean')) {
      normalized.push({ id: action.id, label: action.label, intent: action.intent, confirmation: action.confirmation === true })
      continue
    }

    const identifier = typeof action.label === 'string' && action.label !== ''
      ? action.label
      : typeof action.id === 'string' && action.id !== '' ? action.id : 'unknown'
    const message = `Broadcast action "${identifier}" has an unsupported descriptor.`
    console.warn(`[stashd] ${message}`)
    diagnostics.push({ id: typeof action.id === 'string' ? action.id : identifier, message })
  }

  return { actions: normalized, diagnostics }
}
