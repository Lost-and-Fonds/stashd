<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import OperationProgress from '../components/OperationProgress.vue'
import { fetchActivity, fetchHealth, fetchJobs, type ActivityApiResource, type HealthApiResponse, type JobApiResource } from '../api/status'
import { subscribeLiveUpdates, type LiveEvent } from '../live/mercure'
import { fetchStashItems, fetchStashes, retryFailedStash } from '../api/stashes'
import { fetchStashBroadcasts } from '../api/broadcasts'
import { fetchStashInputs } from '../api/inputs'
import { formatRelativeDate } from '../utils/formatDate'
import type { StashApiResource } from '../types/stash'
import type { StashItemApiResource } from '../types/item'
import type { BroadcastApiResource } from '../types/broadcast-plugin'
import type { StashInputApiResource } from '../types/input'

const health = ref<HealthApiResponse>()
const jobs = ref<JobApiResource[]>([])
const activity = ref<ActivityApiResource[]>([])
const stashes = ref<StashApiResource[]>([])
const itemsById = ref<Record<string, StashItemApiResource>>({})
const broadcastsById = ref<Record<string, BroadcastApiResource>>({})
const inputsById = ref<Record<string, StashInputApiResource>>({})
const retryingStash = ref<string>()
const healthLoading = ref(true)
const jobsLoading = ref(true)
const activityLoading = ref(true)
const healthError = ref<string>()
const jobsError = ref<string>()
const activityError = ref<string>()
let unsubscribe: (() => void) | undefined
let jobsRefreshTimer: ReturnType<typeof setTimeout> | undefined

const activeJobs = computed(() => jobs.value.filter(job => ['pending', 'processing', 'retrying'].includes(job.state)))
const attention = computed(() => {
  const recentCutoff = Date.now() - 24 * 60 * 60 * 1000
  const failedJobs = jobs.value
    .filter(job => job.state === 'failed' && Date.parse(job.finished_at ?? job.updated_at ?? job.created_at ?? '') >= recentCutoff)
    .sort((left, right) => Date.parse(right.finished_at ?? right.updated_at ?? right.created_at ?? '') - Date.parse(left.finished_at ?? left.updated_at ?? left.created_at ?? ''))
  const visibleFailedJobs = [] as JobApiResource[]
  const failureKeys = new Set<string>()
  const downloadFailures = failedJobs.filter(job => job.type === 'core.download')
  const failuresByStash = new Map<string, JobApiResource[]>()
  for (const job of downloadFailures) {
    const stashId = job.entity_id ? itemsById.value[job.entity_id]?.stash_id : undefined
    if (stashId) failuresByStash.set(stashId, [...(failuresByStash.get(stashId) ?? []), job])
  }

  for (const job of failedJobs) {
    const key = `${job.type}:${job.entity_type ?? ''}:${job.entity_id ?? ''}`
    if (failureKeys.has(key)) continue
    failureKeys.add(key)
    visibleFailedJobs.push(job)
    if (visibleFailedJobs.length === 8) break
  }
  const failedJobIds = new Set(visibleFailedJobs.map(job => job.id))

  return [
    ...(health.value && health.value.status !== 'ok' ? [{ id: 'health', label: 'System health is degraded', context: health.value.storage.message ?? 'One or more health checks are not ready.', stashId: undefined }] : []),
    ...(health.value && !health.value.database.writable ? [{ id: 'database', label: 'Database is not writable', context: 'System health', stashId: undefined }] : []),
    ...(health.value && !health.value.storage.ready ? [{ id: 'storage', label: 'Storage is not ready', context: health.value.storage.message ?? 'System health', stashId: undefined }] : []),
    ...[...failuresByStash.entries()].map(([stashId, failures]) => ({ id: `download-failures-${stashId}`, label: `${failures.length} downloads failed`, context: `${stashLabel(stashId)} · ${formatRelativeDate(failures[0].finished_at ?? failures[0].updated_at)}`, stashId, detail: failures[0].last_error ?? undefined })),
    ...(downloadFailures.length && failuresByStash.size === 0 ? [{ id: 'download-failures', label: `${downloadFailures.length} downloads failed`, context: formatRelativeDate(downloadFailures[0].finished_at ?? downloadFailures[0].updated_at), stashId: undefined, detail: downloadFailures[0].last_error ?? undefined }] : []),
    ...visibleFailedJobs.map(job => ({ id: `job-${job.id}`, label: `${jobTypeLabel(job.type)} failed`, context: `${entityLabel(job)} · ${formatRelativeDate(job.finished_at ?? job.updated_at)}`, stashId: undefined })),
    ...activity.value
      .filter(event => event.level === 'error' && Date.parse(event.created_at) >= recentCutoff && (!event.job_id || !failedJobIds.has(event.job_id)))
      .slice(0, 8)
      .map(event => ({ id: `activity-${event.id}`, label: event.message, context: activityContext(event), stashId: undefined }))
  ]
})

function jobTypeLabel(type: string) {
  return type.replace(/^core\./, '').replaceAll('_', ' ')
}

function entityLabel(job: JobApiResource) {
  if (job.entity_type === 'stash') return stashes.value.find(stash => stash.id === job.entity_id)?.name ?? 'Stash'
  if (job.entity_type === 'media_item') return itemsById.value[job.entity_id ?? '']?.media_item?.title ?? 'Media item'
  if (job.entity_type === 'broadcast') return broadcastsById.value[job.entity_id ?? '']?.name ?? 'Broadcast'
  if (job.entity_type === 'stash_input') return inputsById.value[job.entity_id ?? '']?.title ?? 'Input'
  return [job.entity_type, job.entity_id].filter(Boolean).join(' · ') || 'Recent job'
}

function stashLabel(stashId: string) {
  return stashes.value.find(stash => stash.id === stashId)?.name ?? 'Stash'
}

function activityContext(event: ActivityApiResource) {
  if (event.media_item_id) return itemsById.value[event.media_item_id]?.media_item?.title ?? 'Media item'
  if (event.broadcast_id) return broadcastsById.value[event.broadcast_id]?.name ?? 'Broadcast'
  if (event.entity_type === 'stash_input') return inputsById.value[event.entity_id ?? '']?.title ?? 'Input'
  if (event.stash_id) return stashLabel(event.stash_id)
  return [event.entity_type, event.entity_id].filter(Boolean).join(' · ') || event.type
}

async function retryStash(stashId: string) {
  if (retryingStash.value) return
  retryingStash.value = stashId
  try {
    await retryFailedStash(stashId)
    await loadJobs()
  } finally {
    retryingStash.value = undefined
  }
}

function formatDate(value?: string | null) {
  return formatRelativeDate(value)
}

function formatBytes(bytes?: number | null) {
  if (bytes === null || bytes === undefined) return '—'
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`
  if (bytes < 1024 * 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
  return `${(bytes / (1024 * 1024 * 1024)).toFixed(1)} GB`
}

function locationLabel(key: string) {
  return key.replaceAll('_', ' ')
}

function jobPercent(job: JobApiResource) {
  return job.progress_percent ?? null
}

function jobCount(job: JobApiResource) {
  if (job.progress_current === null || job.progress_current === undefined || job.progress_total === null || job.progress_total === undefined) return undefined
  return `${job.progress_current} / ${job.progress_total}`
}

async function loadHealth() {
  healthLoading.value = true
  healthError.value = undefined

  try {
    health.value = await fetchHealth()
  } catch (exception) {
    health.value = undefined
    healthError.value = exception instanceof Error ? exception.message : 'Could not load system health.'
  } finally {
    healthLoading.value = false
  }
}

async function loadJobs() {
  jobsLoading.value = true
  jobsError.value = undefined

  try {
    jobs.value = await fetchJobs()
  } catch (exception) {
    jobs.value = []
    jobsError.value = exception instanceof Error ? exception.message : 'Could not load jobs.'
  } finally {
    jobsLoading.value = false
  }
}

async function loadActivity() {
  activityLoading.value = true
  activityError.value = undefined

  try {
    activity.value = await fetchActivity()
  } catch (exception) {
    activity.value = []
    activityError.value = exception instanceof Error ? exception.message : 'Could not load recent activity.'
  } finally {
    activityLoading.value = false
  }
}

async function loadContext() {
  const resources = await Promise.all(stashes.value.map(async stash => {
    const [items, inputs, broadcasts] = await Promise.all([
      fetchStashItems(stash.id, { limit: 200, offset: 0 }),
      fetchStashInputs(stash.id),
      fetchStashBroadcasts(stash.id)
    ])
    return { items: items.items, inputs, broadcasts }
  }))
  itemsById.value = Object.fromEntries(resources.flatMap(resource => resource.items.map(item => [item.media_item_id, item])))
  inputsById.value = Object.fromEntries(resources.flatMap(resource => resource.inputs.map(input => [input.id, input])))
  broadcastsById.value = Object.fromEntries(resources.flatMap(resource => resource.broadcasts.map(broadcast => [broadcast.id, broadcast])))
}

function refreshJobsSoon() {
  if (jobsRefreshTimer) return
  jobsRefreshTimer = setTimeout(() => {
    jobsRefreshTimer = undefined
    void loadJobs()
  }, 250)
}

function handleLiveEvent(event: LiveEvent) {
  if (event.event === 'job.progress') {
    refreshJobsSoon()
    return
  }

  if (event.event.startsWith('job.')) {
    refreshJobsSoon()

    if (event.event === 'job.completed' || event.event === 'job.failed') {
      void loadHealth()
      void loadActivity()
    }

    return
  }

  void loadActivity()
  void loadHealth()
}

onMounted(() => {
  unsubscribe = subscribeLiveUpdates(handleLiveEvent)
  void loadHealth()
  void loadJobs()
  void loadActivity()
  void fetchStashes().then(async value => { stashes.value = value; await loadContext() }).catch(() => undefined)
})

onBeforeUnmount(() => {
  unsubscribe?.()
  if (jobsRefreshTimer) clearTimeout(jobsRefreshTimer)
})
</script>

<template>
  <main class="mx-auto max-w-2xl space-y-8 px-4 py-8 sm:px-8">
    <header class="space-y-1">
      <h1 class="text-2xl font-semibold text-highlighted">Status</h1>
      <p class="text-sm text-muted">A quick view of Stashd health, active work, and recent problems.</p>
      <p v-if="health" class="font-mono text-xs" :class="health.status === 'ok' ? 'text-success' : 'text-warning'">Stashd {{ health.version }} · {{ health.status === 'ok' ? 'System healthy' : 'Degraded' }}</p>
    </header>

    <section class="space-y-3">
      <h2 class="text-base font-medium text-highlighted">Needs attention</h2>
      <UAlert v-if="healthError" color="error" variant="subtle" icon="i-lucide-circle-alert" title="Could not load system health" :description="healthError" />
      <div v-else-if="attention.length" class="divide-y divide-default/60 overflow-hidden rounded-md border border-default bg-muted">
          <div v-for="issue in attention" :key="issue.id" class="space-y-1 p-3">
            <div class="flex items-center gap-2"><span class="size-1.5 rounded-full bg-error" /><span class="text-sm text-highlighted">{{ issue.label }}</span></div>
            <p class="pl-3.5 text-xs text-dimmed">{{ issue.context }}</p>
            <div v-if="issue.stashId || issue.detail" class="flex items-center gap-3 pl-3.5 pt-1">
              <UButton v-if="issue.stashId" label="Retry failed" icon="i-lucide-refresh-cw" size="xs" variant="soft" color="error" :loading="retryingStash === issue.stashId" @click="retryStash(issue.stashId)" />
              <details v-if="issue.detail" class="text-xs text-dimmed"><summary class="cursor-pointer">Details</summary><p class="mt-1 max-w-xl whitespace-pre-wrap">{{ issue.detail }}</p></details>
            </div>
          </div>
      </div>
      <p v-else-if="!healthLoading && !jobsLoading && !activityLoading" class="flex items-center gap-2 text-sm text-muted">
        <span class="size-1.5 rounded-full bg-success" />
        No issues reported by current health, jobs, or activity data
      </p>
      <p v-else class="text-sm text-muted">Loading current health signals…</p>
    </section>

    <section class="space-y-3">
      <h2 class="text-base font-medium text-highlighted">In progress</h2>
      <UAlert v-if="jobsError" color="error" variant="subtle" icon="i-lucide-circle-alert" title="Could not load jobs" :description="jobsError" />
      <div v-else-if="activeJobs.length" class="space-y-3">
        <div v-for="job in activeJobs" :key="job.id" class="rounded-md bg-muted p-3">
          <OperationProgress
            :label="`${jobTypeLabel(job.type)} · ${entityLabel(job)}`"
            :percent="jobPercent(job)"
            :stage="job.progress_label ?? undefined"
            :count="jobCount(job)"
            :status="job.state === 'pending' || job.state === 'retrying' ? 'queued' : 'active'"
          />
        </div>
      </div>
      <p v-else-if="!jobsLoading" class="rounded-md bg-muted p-4 text-sm text-muted">No jobs are currently in progress.</p>
      <p v-else class="text-sm text-muted">Loading current jobs…</p>
    </section>

    <section class="space-y-3">
      <h2 class="text-base font-medium text-highlighted">System health</h2>
      <UAlert v-if="healthError" color="error" variant="subtle" icon="i-lucide-circle-alert" title="Could not load system health" :description="healthError" />
      <div v-else-if="health" class="space-y-4 rounded-md border border-default bg-muted p-4">
        <div class="grid grid-cols-2 gap-4">
          <div><p class="text-xs text-dimmed">Database</p><p class="mt-0.5 text-sm" :class="health.database.writable ? 'text-success' : 'text-error'">{{ health.database.writable ? 'writable' : 'not writable' }}</p></div>
          <div><p class="text-xs text-dimmed">Storage</p><p class="mt-0.5 text-sm" :class="health.storage.ready ? 'text-success' : 'text-error'">{{ health.storage.ready ? 'ready' : 'not ready' }}</p></div>
        </div>
        <p v-if="health.storage.message" class="text-xs text-warning">{{ health.storage.message }}</p>
        <div class="space-y-2 border-t border-default/60 pt-4">
          <p class="text-sm font-medium text-highlighted">Storage locations</p>
          <div v-for="location in health.storage.locations" :key="location.key" class="space-y-1 rounded-md bg-elevated/50 p-3">
            <div class="flex flex-wrap items-baseline justify-between gap-2"><span class="text-sm text-toned">{{ locationLabel(location.key) }}</span><span class="font-mono text-xs text-dimmed">{{ location.state }}</span></div>
            <p class="truncate font-mono text-xs text-dimmed">{{ location.path }}</p>
            <p class="font-mono text-xs text-muted">{{ formatBytes(location.free_bytes) }} free · {{ formatBytes(location.total_bytes) }} total</p>
            <p v-if="location.last_error" class="text-xs text-error">{{ location.last_error }}</p>
          </div>
        </div>
      </div>
      <p v-else-if="!healthLoading" class="rounded-md bg-muted p-4 text-sm text-muted">No health data available.</p>
      <p v-else class="text-sm text-muted">Loading system health…</p>
    </section>

    <section class="space-y-2">
      <h2 class="text-base font-medium text-highlighted">Recent activity</h2>
      <UAlert v-if="activityError" color="error" variant="subtle" icon="i-lucide-circle-alert" title="Could not load recent activity" :description="activityError" />
      <div v-else-if="activity.length" class="divide-y divide-default/60 border-t border-default/60">
        <div v-for="event in activity" :key="event.id" class="flex items-baseline gap-3 py-1.5">
          <time class="shrink-0 font-mono text-xs text-dimmed">{{ formatDate(event.created_at) }}</time>
          <span class="min-w-0 flex-1 text-xs" :class="event.level === 'error' ? 'text-error' : 'text-toned'">{{ event.message }}</span>
          <span class="shrink-0 text-xs text-dimmed">· {{ activityContext(event) }}</span>
        </div>
      </div>
      <p v-else-if="!activityLoading" class="rounded-md bg-muted p-4 text-sm text-muted">No recent activity.</p>
      <p v-else class="text-sm text-muted">Loading recent activity…</p>
    </section>
  </main>
</template>
