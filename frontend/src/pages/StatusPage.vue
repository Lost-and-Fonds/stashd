<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import OperationProgress from '../components/OperationProgress.vue'
import { fetchActivity, fetchHealth, fetchJobs, type ActivityApiResource, type HealthApiResponse, type JobApiResource } from '../api/status'
import { subscribeLiveUpdates, type LiveEvent } from '../live/mercure'

const health = ref<HealthApiResponse>()
const jobs = ref<JobApiResource[]>([])
const activity = ref<ActivityApiResource[]>([])
const healthLoading = ref(true)
const jobsLoading = ref(true)
const activityLoading = ref(true)
const healthError = ref<string>()
const jobsError = ref<string>()
const activityError = ref<string>()
let unsubscribe: (() => void) | undefined
let jobsRefreshTimer: ReturnType<typeof setTimeout> | undefined

const activeJobs = computed(() => jobs.value.filter(job => job.state === 'pending' || job.state === 'processing'))
const attention = computed(() => {
  const failedJobs = jobs.value.filter(job => job.state === 'failed')
  const failedJobIds = new Set(failedJobs.map(job => job.id))

  return [
    ...(health.value && health.value.status !== 'ok' ? [{ id: 'health', label: 'System health is degraded', context: health.value.storage.message ?? 'One or more health checks are not ready.' }] : []),
    ...(health.value && !health.value.database.writable ? [{ id: 'database', label: 'Database is not writable', context: 'System health' }] : []),
    ...(health.value && !health.value.storage.ready ? [{ id: 'storage', label: 'Storage is not ready', context: health.value.storage.message ?? 'System health' }] : []),
    ...failedJobs.map(job => ({ id: `job-${job.id}`, label: `${intentLabel(job.intent)} failed`, context: job.last_error ?? entityLabel(job) })),
    ...activity.value
      .filter(event => event.level === 'error' && (!event.job_id || !failedJobIds.has(event.job_id)))
      .map(event => ({ id: `activity-${event.id}`, label: event.message, context: activityContext(event) }))
  ]
})

function intentLabel(intent: string) {
  return intent.replaceAll('_', ' ')
}

function entityLabel(job: JobApiResource) {
  return [job.entity_type, job.entity_id].filter(Boolean).join(' · ') || 'Recent job'
}

function activityContext(event: ActivityApiResource) {
  return [event.entity_type, event.entity_id].filter(Boolean).join(' · ') || event.type
}

function formatDate(value?: string | null) {
  return value ? new Date(value).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' }) : '—'
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
      <p class="text-sm text-muted">Is Stashd okay, what it's doing, and what it's using.</p>
      <p v-if="health" class="font-mono text-xs text-dimmed">Stashd {{ health.version }} · {{ health.status }}</p>
    </header>

    <section class="space-y-3">
      <h2 class="text-base font-medium text-highlighted">Needs attention</h2>
      <UAlert v-if="healthError" color="error" variant="subtle" icon="i-lucide-circle-alert" title="Could not load system health" :description="healthError" />
      <div v-else-if="attention.length" class="divide-y divide-default/60 overflow-hidden rounded-md border border-default bg-muted">
        <div v-for="issue in attention" :key="issue.id" class="space-y-1 p-3">
          <div class="flex items-center gap-2"><span class="size-1.5 rounded-full bg-error" /><span class="text-sm text-highlighted">{{ issue.label }}</span></div>
          <p class="pl-3.5 text-xs text-dimmed">{{ issue.context }}</p>
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
            :label="`${intentLabel(job.intent)} · ${entityLabel(job)}`"
            :percent="jobPercent(job)"
            :stage="job.progress_label ?? undefined"
            :count="jobCount(job)"
            :status="job.state === 'pending' ? 'queued' : 'active'"
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
