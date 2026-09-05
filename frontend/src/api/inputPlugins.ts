import type { SourceFieldDeclaration } from '../adapters/normalizeSourceFields'
import type { InputOptionDeclaration } from '../types/input'
import type { StashApiResource } from '../types/stash'
import { invalidateStashesCache } from './stashes'

export interface InputPluginApiResource { key: string, label: string, source_fields: SourceFieldDeclaration[], input_options: InputOptionDeclaration[] }
export interface ResolvedSource { plugin_key: string, canonical_reference: string, provider_input_id: string, kind: string, display_name: string | null, source_title?: string | null, source_avatar_uri?: string | null, size_bytes?: number | null, size_estimated?: boolean }

async function body<T>(response: Response): Promise<T> {
  const value = await response.json().catch(() => null) as { error?: { message?: unknown } } | null
  if (!response.ok) throw new Error(typeof value?.error?.message === 'string' ? value.error.message : `Request failed (${response.status}).`)
  return value as T
}

export async function fetchInputPlugins(): Promise<InputPluginApiResource[]> {
  return (await body<{ plugins?: InputPluginApiResource[] }>(await fetch('/api/v1/input-plugins'))).plugins ?? []
}

export async function preflightInputPlugin(plugin: string, source: Record<string, boolean | number | string>): Promise<ResolvedSource> {
  const response = await fetch(`/api/v1/input-plugins/${encodeURIComponent(plugin)}/preflight`, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ source }) })
  return (await body<{ resolved_source: ResolvedSource }>(response)).resolved_source
}

export async function createStashWithInput(name: string, plugin: string, source: Record<string, boolean | number | string>, options: { title_regex_include: string | null, title_regex_exclude: string | null, provider: Record<string, boolean | string> }, resolved: ResolvedSource): Promise<StashApiResource> {
  const response = await fetch('/api/v1/stashes/with-input', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ name, input: { plugin, source, options, resolved_input: resolved } }) })
  const stash = (await body<{ stash: StashApiResource }>(response)).stash
  invalidateStashesCache()

  return stash
}
