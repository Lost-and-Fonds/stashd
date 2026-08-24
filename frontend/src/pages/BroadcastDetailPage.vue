<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { normalizeBroadcastActions, type PluginAction } from '../adapters/normalizeBroadcastActions'
import { normalizeBroadcastDetailFields } from '../adapters/normalizeBroadcastDetailFields'
import { fetchBroadcast, invokeBroadcastAction } from '../api/broadcasts'
import PluginActions from '../components/plugin/PluginActions.vue'
import PluginDetailFields from '../components/plugin/PluginDetailFields.vue'
import type { BroadcastApiResource } from '../types/broadcast-plugin'
import { subscribeLiveUpdates, type LiveEvent } from '../live/mercure'

const route = useRoute()
const broadcastId = String(route.params.broadcastId)
const broadcast = ref<BroadcastApiResource>()
const loading = ref(true)
const error = ref<string>()
const errorTitle = ref('Could not load Broadcast details')
const pendingActionId = ref<string>()
const confirmingAction = ref<PluginAction>()
const confirmationOpen = ref(false)
const completedActionLabel = ref<string>()
let refreshTimer: ReturnType<typeof setTimeout> | undefined
let unsubscribe: (() => void) | undefined

const details = computed(() => normalizeBroadcastDetailFields(broadcast.value?.plugin_detail_fields ?? []))
const actions = computed(() => normalizeBroadcastActions(broadcast.value?.plugin_actions ?? []))

async function load() {
  loading.value = true
  error.value = undefined
  errorTitle.value = 'Could not load Broadcast details'

  try {
    broadcast.value = await fetchBroadcast(broadcastId)
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Could not load Broadcast details.'
  } finally {
    loading.value = false
  }
}

function scheduleLiveRefresh() {
  if (refreshTimer) return
  refreshTimer = setTimeout(() => {
    refreshTimer = undefined
    void load()
  }, 50)
}

function handleLiveEvent(event: LiveEvent) {
  const id = String(route.params.broadcastId)

  if (event.event === 'activity.created') {
    const type = event.payload.type ?? ''
    const matches = event.payload.broadcastId === id
      || (event.payload.entityType === 'broadcast' && event.payload.entityId === id)
    if (matches && type.startsWith('broadcast.')) scheduleLiveRefresh()
    return
  }

  if ((event.event === 'job.completed' || event.event === 'job.failed')
    && event.payload.entityType === 'broadcast'
    && event.payload.entityId === id) scheduleLiveRefresh()
}

onMounted(() => {
  void load()
  unsubscribe = subscribeLiveUpdates(handleLiveEvent)
})
onBeforeUnmount(() => {
  unsubscribe?.()
  if (refreshTimer) clearTimeout(refreshTimer)
})

function requestAction(action: PluginAction) {
  error.value = undefined
  errorTitle.value = 'Could not run Broadcast action'
  completedActionLabel.value = undefined

  if (action.confirmation) {
    confirmingAction.value = action
    confirmationOpen.value = true
    return
  }

  void runAction(action)
}

async function runAction(action: PluginAction) {
  if (!broadcast.value || pendingActionId.value !== undefined) return

  pendingActionId.value = action.id
  error.value = undefined
  errorTitle.value = 'Could not run Broadcast action'
  completedActionLabel.value = undefined

  try {
    broadcast.value = await invokeBroadcastAction(broadcast.value.id, action.intent)
    completedActionLabel.value = action.label
    confirmationOpen.value = false
    confirmingAction.value = undefined
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Could not run Broadcast action.'
  } finally {
    pendingActionId.value = undefined
  }
}
</script>

<template>
  <main class="mx-auto max-w-2xl space-y-8 px-4 py-8 sm:px-8">
    <RouterLink to="/broadcasts" class="inline-flex items-center gap-1 font-mono text-xs text-dimmed transition-colors hover:text-muted">
      <UIcon name="i-lucide-arrow-left" class="size-3.5" />
      Broadcasts
    </RouterLink>

    <header class="space-y-1.5">
      <h1 class="text-2xl font-semibold text-highlighted">{{ broadcast?.name ?? 'Broadcast' }}</h1>
      <p class="text-sm text-muted">Plugin-provided Broadcast details.</p>
    </header>

    <div v-if="loading" class="flex items-center gap-2 text-sm text-muted">
      <UIcon name="i-lucide-loader-circle" class="size-4 animate-spin" />
      Loading Broadcast details…
    </div>

    <template v-else>
      <UAlert v-if="error" color="error" variant="subtle" icon="i-lucide-circle-alert" :title="errorTitle" :description="error" />

      <template v-if="broadcast">
        <UAlert
          v-if="details.diagnostics.length"
          color="warning"
          variant="subtle"
          icon="i-lucide-triangle-alert"
          title="Some plugin details are unsupported"
          :description="details.diagnostics.map(diagnostic => diagnostic.message).join(' ')"
        />

        <section class="space-y-3">
          <h2 class="text-base font-medium text-highlighted">Details</h2>
          <UCard :ui="{ body: 'p-4 sm:p-6' }">
            <PluginDetailFields v-if="details.fields.length" :fields="details.fields" />
            <p v-else class="text-sm text-muted">This Broadcast has no plugin-provided details.</p>
          </UCard>
        </section>

        <section v-if="actions.actions.length || actions.diagnostics.length" class="space-y-3">
          <h2 class="text-base font-medium text-highlighted">Actions</h2>
          <UAlert
            v-if="actions.diagnostics.length"
            color="warning"
            variant="subtle"
            icon="i-lucide-triangle-alert"
            title="Some plugin actions are unsupported"
            :description="actions.diagnostics.map(diagnostic => diagnostic.message).join(' ')"
          />
          <PluginActions :actions="actions.actions" :pending-action-id="pendingActionId" :disabled="pendingActionId !== undefined" @run="requestAction" />
          <p v-if="completedActionLabel" class="text-sm text-success">{{ completedActionLabel }} completed.</p>
        </section>
      </template>
    </template>

    <UModal v-model:open="confirmationOpen" title="Confirm action" :description="confirmingAction ? `Run “${confirmingAction.label}”?` : undefined" :ui="{ content: 'max-w-md' }">
      <template #body>
        <div class="flex justify-end gap-2">
          <UButton label="Cancel" variant="ghost" color="neutral" :disabled="pendingActionId !== undefined" @click="confirmationOpen = false" />
          <UButton :label="confirmingAction?.label ?? 'Run action'" :loading="pendingActionId === confirmingAction?.id" @click="confirmingAction && runAction(confirmingAction)" />
        </div>
      </template>
    </UModal>
  </main>
</template>
