<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { normalizeBroadcastActions, type PluginAction } from '../adapters/normalizeBroadcastActions'
import { normalizeBroadcastDetailFields } from '../adapters/normalizeBroadcastDetailFields'
import { normalizeBroadcastSourceOptions } from '../adapters/normalizeBroadcastSourceOptions'
import { deleteBroadcast, fetchBroadcast, invokeBroadcastAction, updateBroadcastDestination } from '../api/broadcasts'
import { fetchStashInputs } from '../api/inputs'
import PluginActions from '../components/plugin/PluginActions.vue'
import PluginDetailFields from '../components/plugin/PluginDetailFields.vue'
import type { BroadcastApiResource } from '../types/broadcast-plugin'
import type { StashInputApiResource } from '../types/input'
import { subscribeLiveUpdates, type LiveEvent } from '../live/mercure'

const route = useRoute()
const router = useRouter()
const broadcastId = String(route.params.broadcastId)
const broadcast = ref<BroadcastApiResource>()
const sourceInputs = ref<StashInputApiResource[]>([])
const loading = ref(true)
const error = ref<string>()
const errorTitle = ref('Could not load Broadcast details')
const pendingActionId = ref<string>()
const confirmingAction = ref<PluginAction>()
const confirmationOpen = ref(false)
const completedActionLabel = ref<string>()
const destinationOpen = ref(false)
const destinationValue = ref('')
const destinationSaving = ref(false)
const destinationError = ref<string>()
const deleteOpen = ref(false)
const deleteSubmitting = ref(false)
const deleteCommandId = ref<string>()
const deleteError = ref<string>()
let refreshTimer: ReturnType<typeof setTimeout> | undefined
let unsubscribe: (() => void) | undefined

const details = computed(() => normalizeBroadcastDetailFields(broadcast.value?.plugin_detail_fields ?? []))
const actions = computed(() => normalizeBroadcastActions(broadcast.value?.plugin_actions ?? []))
const sourceOptions = computed(() => normalizeBroadcastSourceOptions(broadcast.value?.plugin_source_options ?? []))
const sourceSettingsAvailable = computed(() => sourceOptions.value.fields.length > 0 && sourceInputs.value.length > 0)

async function load() {
  loading.value = true
  error.value = undefined
  errorTitle.value = 'Could not load Broadcast details'

  try {
    broadcast.value = await fetchBroadcast(broadcastId)
    try {
      sourceInputs.value = await fetchStashInputs(broadcast.value.stash_id)
    } catch {
      sourceInputs.value = []
    }
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

function eventEntityId(event: LiveEvent): string | null {
  if (!event.event.startsWith('job.')) return null
  return event.payload.entityId ?? event.payload.entity_id ?? null
}

function eventCommandId(event: LiveEvent): string | null {
  if (!event.event.startsWith('job.')) return null
  return event.payload.commandId ?? event.payload.command_id ?? null
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

  const commandId = eventCommandId(event)
  const matches = eventEntityId(event) === id
  const deleting = deleteCommandId.value !== undefined && commandId === deleteCommandId.value
  if (!matches && !deleting) return

  if (event.event === 'job.completed' && deleting) {
    void router.push(`/stashes/${broadcast.value?.stash_id ?? ''}`)
    return
  }

  if (event.event === 'job.failed' && deleting) {
    deleteError.value = event.payload.lastError ?? event.payload.last_error ?? 'Broadcast deletion failed.'
    deleteSubmitting.value = false
    deleteCommandId.value = undefined
    return
  }

  if (event.event === 'job.completed' || event.event === 'job.failed') scheduleLiveRefresh()
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

function openDestination() {
  destinationValue.value = broadcast.value?.settings?.destination_path ?? ''
  destinationError.value = undefined
  destinationOpen.value = true
}

async function saveDestination() {
  if (!broadcast.value || destinationSaving.value) return
  destinationSaving.value = true
  destinationError.value = undefined

  try {
    broadcast.value = await updateBroadcastDestination(broadcast.value.id, destinationValue.value)
    destinationOpen.value = false
  } catch (exception) {
    destinationError.value = exception instanceof Error ? exception.message : 'Could not update the destination path.'
  } finally {
    destinationSaving.value = false
  }
}

function openDelete() {
  deleteError.value = undefined
  deleteCommandId.value = undefined
  deleteOpen.value = true
}

async function confirmDelete() {
  if (!broadcast.value || deleteSubmitting.value) return
  deleteSubmitting.value = true
  deleteError.value = undefined

  try {
    deleteCommandId.value = await deleteBroadcast(broadcast.value.id)
  } catch (exception) {
    deleteSubmitting.value = false
    deleteError.value = exception instanceof Error ? exception.message : 'Could not delete this Broadcast.'
  }
}
</script>

<template>
  <main class="mx-auto max-w-2xl space-y-8 px-4 py-8 sm:px-8">
    <RouterLink :to="broadcast ? `/stashes/${broadcast.stash_id}` : '/stashes'" class="inline-flex items-center gap-1 font-mono text-xs text-dimmed transition-colors hover:text-muted">
      <UIcon name="i-lucide-arrow-left" class="size-3.5" />
      Back to Stash
    </RouterLink>

    <header class="flex items-start justify-between gap-4">
      <div class="space-y-1.5">
        <h1 class="text-2xl font-semibold text-highlighted">{{ broadcast?.name ?? 'Broadcast' }}</h1>
        <p class="text-sm text-muted">Plugin-provided Broadcast details.</p>
      </div>
      <UDropdownMenu
        v-if="broadcast"
        :items="[
          { label: 'Edit destination path', icon: 'i-lucide-folder-pen', onSelect: openDestination },
          { type: 'separator' },
          { label: 'Delete Broadcast', icon: 'i-lucide-trash-2', onSelect: openDelete }
        ]"
        :content="{ align: 'end' }"
      >
        <UButton icon="i-lucide-ellipsis" aria-label="Broadcast actions" title="Broadcast actions" variant="ghost" color="neutral" />
      </UDropdownMenu>
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

        <section v-if="sourceSettingsAvailable" class="space-y-3">
          <h2 class="text-base font-medium text-highlighted">Source settings</h2>
          <UCard :ui="{ body: 'divide-y divide-default p-0' }">
            <div v-for="source in sourceInputs" :key="source.id" class="flex items-center justify-between gap-3 p-4">
              <div class="min-w-0">
                <p class="truncate text-sm font-medium text-highlighted">{{ source.title ?? source.provider_input_id }}</p>
                <p class="truncate font-mono text-xs text-muted">{{ source.source_uri }}</p>
              </div>
              <UButton :to="`/broadcasts/${broadcast.id}/sources/${source.id}/configure`" label="Configure" variant="outline" color="neutral" size="sm" />
            </div>
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

    <UModal v-model:open="destinationOpen" title="Edit destination path" description="Leave blank to use the default destination." :ui="{ content: 'max-w-lg' }">
      <template #body>
        <div class="space-y-4">
          <UFormField label="Destination path" name="destination_path" :error="destinationError">
            <UInput v-model="destinationValue" placeholder="/path/to/broadcasts" class="w-full" />
          </UFormField>
          <div class="flex justify-end gap-2">
            <UButton label="Cancel" variant="ghost" color="neutral" :disabled="destinationSaving" @click="destinationOpen = false" />
            <UButton label="Save destination" :loading="destinationSaving" @click="saveDestination" />
          </div>
        </div>
      </template>
    </UModal>

    <UModal v-model:open="deleteOpen" title="Delete Broadcast" :description="broadcast ? `Remove “${broadcast.name}” and its generated output? The owning Stash and preserved Vault data are retained.` : undefined" :ui="{ content: 'max-w-lg' }">
      <template #body>
        <UAlert v-if="deleteError" class="mb-4" color="error" variant="subtle" icon="i-lucide-circle-alert" title="Could not delete Broadcast" :description="deleteError" />
        <p v-if="deleteCommandId" class="text-sm text-muted">Deletion queued. Waiting for completion…</p>
        <div v-else class="flex justify-end gap-2">
          <UButton label="Cancel" variant="ghost" color="neutral" :disabled="deleteSubmitting" @click="deleteOpen = false" />
          <UButton label="Delete Broadcast" color="error" :loading="deleteSubmitting" @click="confirmDelete" />
        </div>
      </template>
    </UModal>
  </main>
</template>
