export interface HealthStorageLocation {
  key: string
  path: string
  state: string
  readable: boolean
  writable: boolean
  supports_hardlinks: boolean
  last_error?: string | null
  free_bytes?: number | null
  total_bytes?: number | null
}

export interface HealthApiResponse {
  status: string
  version: string
  database: { writable: boolean }
  storage: {
    ready: boolean
    vault_broadcast_hardlink: boolean
    message?: string | null
    locations: HealthStorageLocation[]
  }
}

export interface JobApiResource {
  id: string
  command_id?: string | null
  intent: string
  entity_type?: string | null
  entity_id?: string | null
  state: string
  progress_current?: number | null
  progress_total?: number | null
  progress_percent?: number | null
  progress_label?: string | null
  last_error?: string | null
  created_at?: string | null
  started_at?: string | null
  finished_at?: string | null
  updated_at?: string | null
  payload?: { stash_id?: string | null, media_item_id?: string | null } | null
}

export interface ActivityApiResource {
  id: string
  level: string
  type: string
  message: string
  entity_type?: string | null
  entity_id?: string | null
  stash_id?: string | null
  media_item_id?: string | null
  broadcast_id?: string | null
  job_id?: string | null
  command_id?: string | null
  created_at: string
}

import { apiFetch } from './auth'

export async function fetchHealth(): Promise<HealthApiResponse> {
  const response = await apiFetch('/api/v1/system/health')
  const body = await response.json().catch(() => null) as HealthApiResponse | { error?: { message?: unknown }, message?: unknown } | null

  if (body && 'status' in body && 'database' in body && 'storage' in body) return body

  const message = body && 'error' in body ? body.error?.message : body && 'message' in body ? body.message : undefined
  throw new Error(typeof message === 'string' ? message : `Request failed (${response.status}).`)
}

export async function fetchJobs(): Promise<JobApiResource[]> {
  const body = await responseBody<{ jobs?: JobApiResource[] }>(await apiFetch('/api/v1/jobs'))

  return body.jobs ?? []
}

export async function fetchActivity(): Promise<ActivityApiResource[]> {
  const body = await responseBody<{ events?: ActivityApiResource[] }>(await apiFetch('/api/v1/activity'))

  return body.events ?? []
}

async function responseBody<T>(response: Response): Promise<T> {
  const body = await response.json().catch(() => null) as { error?: { message?: unknown }, message?: unknown } | null

  if (!response.ok) {
    const message = body?.error?.message ?? body?.message
    throw new Error(typeof message === 'string' ? message : `Request failed (${response.status}).`)
  }

  return body as T
}
