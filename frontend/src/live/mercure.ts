export const liveEventNames = [
  'job.created',
  'job.progress',
  'job.completed',
  'job.failed',
  'activity.created'
] as const

export type LiveEventName = typeof liveEventNames[number]

export interface JobLiveEvent {
  id: string
  entityType?: string | null
  entityId?: string | null
  stashId?: string | null
  mediaItemId?: string | null
  progressCurrent?: number | null
  progressTotal?: number | null
  progressPercent?: number | null
  progressLabel?: string | null
  lastError?: string | null
  type?: string
  entity_type?: string | null
  entity_id?: string | null
  state?: string
  progress_current?: number | null
  progress_total?: number | null
  progress_percent?: number | null
  progress_label?: string | null
  last_error?: string | null
  [key: string]: unknown
}

export interface ActivityLiveEvent {
  id: string
  level?: string
  type?: string
  message?: string
  entityType?: string | null
  entityId?: string | null
  stashId?: string | null
  mediaItemId?: string | null
  broadcastId?: string | null
  jobId?: string | null
  entity_type?: string | null
  entity_id?: string | null
  stash_id?: string | null
  media_item_id?: string | null
  broadcast_id?: string | null
  job_id?: string | null
  [key: string]: unknown
}

export type LiveEvent =
  | { event: `job.${'created' | 'progress' | 'completed' | 'failed'}`, payload: JobLiveEvent }
  | { event: 'activity.created', payload: ActivityLiveEvent }
  | { event: 'connection.restored', payload: Record<string, never> }

type Listener = (event: LiveEvent) => void

const topic = 'stashd/events'
const listeners = new Set<Listener>()
let source: EventSource | undefined
let reconnectTimer: ReturnType<typeof setTimeout> | undefined
let reconnectDelay = 1000
let generation = 0
let started = false
let stopping = false
let connected = false

export function parseLiveEvent(data: string): LiveEvent | undefined {
  let value: Record<string, unknown>

  try {
    value = JSON.parse(data) as Record<string, unknown>
  } catch {
    return undefined
  }

  const event = value.event
  if (typeof event !== 'string' || !liveEventNames.includes(event as LiveEventName)) return undefined

  const payload = { ...value }
  delete payload.event

  return { event, payload } as LiveEvent
}

async function bootstrap(): Promise<void> {
  const response = await apiFetch('/api/v1/events/subscription')
  if (!response.ok) throw new Error(`Live subscription failed (${response.status}).`)
}

function scheduleReconnect(): void {
  if (stopping || reconnectTimer) return

  const delay = reconnectDelay
  reconnectDelay = Math.min(reconnectDelay * 2, 30000)
  reconnectTimer = setTimeout(() => {
    reconnectTimer = undefined
    void connect()
  }, delay)
}

async function connect(): Promise<void> {
  const currentGeneration = generation

  try {
    await bootstrap()
  } catch {
    scheduleReconnect()
    return
  }

  if (stopping || currentGeneration !== generation) return

  const next = new EventSource(`/.well-known/mercure?topic=${encodeURIComponent(topic)}`, { withCredentials: true })
  source = next
  next.onopen = () => {
    const wasConnected = connected
    connected = true
    reconnectDelay = 1000
    if (wasConnected) listeners.forEach(listener => listener({ event: 'connection.restored', payload: {} }))
  }
  next.onmessage = (message) => {
    const event = parseLiveEvent(message.data)
    if (!event) return
    listeners.forEach(listener => listener(event))
  }
  next.onerror = () => {
    if (source !== next) return
    next.close()
    source = undefined
    scheduleReconnect()
  }
}

export function startLiveUpdates(): void {
  if (started) return
  started = true
  stopping = false
  void connect()
}

export function subscribeLiveUpdates(listener: Listener): () => void {
  listeners.add(listener)
  startLiveUpdates()
  return () => listeners.delete(listener)
}

export function stopLiveUpdates(): void {
  stopping = true
  started = false
  generation += 1
  source?.close()
  source = undefined
  connected = false
  if (reconnectTimer) clearTimeout(reconnectTimer)
  reconnectTimer = undefined
}
import { apiFetch } from '../api/auth'
