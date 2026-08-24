import type { LifecycleOperation } from '../types/input'
import { apiFetch } from './auth'

export interface CommandOperation extends LifecycleOperation {
  label?: string | null
  percent?: number | null
}

export async function fetchCommandOperation(commandId: string): Promise<CommandOperation> {
  const response = await apiFetch(`/api/v1/commands/${encodeURIComponent(commandId)}`)
  const body = await response.json().catch(() => null) as {
    command?: { id?: unknown, state?: unknown }
    jobs?: Array<{ progress_label?: unknown }>
    error?: { message?: unknown }
  } | null

  if (!response.ok || !body?.command || typeof body.command.id !== 'string' || typeof body.command.state !== 'string') {
    const message = body?.error?.message
    throw new Error(typeof message === 'string' ? message : `Request failed (${response.status}).`)
  }

  const job = body.jobs?.[0]

  return {
    id: body.command.id,
    state: body.command.state as LifecycleOperation['state'],
    label: typeof job?.progress_label === 'string' ? job.progress_label : null
  }
}
