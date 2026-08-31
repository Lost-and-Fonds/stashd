<script setup lang="ts">
/**
 * Reusable "What Stashd will do" review surface. Purely presentational —
 * callers own the fixture analysis/timing and pass in a PreflightState.
 * Renders whatever operations/notes it's given; carries no knowledge of
 * "hardlink"/"transcode"/"download" or of Input vs Broadcast.
 */
import { computed } from 'vue'
import type { PreflightState } from '../types/preflight'

const props = defineProps<{
  state: PreflightState
}>()

const storageText = computed(() => {
  const storage = props.state.plan.storage
  switch (storage.kind) {
    case 'none': return 'No additional storage'
    case 'estimate': return `${storage.estimated === false ? '' : '~'}${storage.label} additional`
    case 'range': return `~${storage.lowLabel}–${storage.highLabel} additional`
    case 'calculating': return 'Calculating estimate…'
    case 'unavailable': return 'Unable to estimate additional storage'
  }
})

const storageClass = computed(() => {
  switch (props.state.plan.storage.kind) {
    case 'none': return 'text-success'
    case 'calculating': return 'text-dimmed'
    case 'unavailable': return 'text-dimmed'
    default: return 'text-highlighted'
  }
})
</script>

<template>
  <div class="space-y-3 rounded-md bg-elevated p-3">
    <p class="text-sm font-medium text-highlighted">What Stashd will do</p>

    <p v-if="state.plan.itemCountLabel" class="font-mono text-xs text-dimmed">{{ state.plan.itemCountLabel }}</p>

    <div v-if="state.plan.operations.length > 0" class="space-y-1.5">
      <div v-for="op in state.plan.operations" :key="op.key" class="flex items-center justify-between gap-3">
        <span class="flex min-w-0 items-center gap-2">
          <UIcon v-if="op.icon" :name="op.icon" class="size-3.5 shrink-0 text-dimmed" />
          <span class="shrink-0 font-mono text-xs text-dimmed">{{ op.itemCount }}</span>
          <span class="truncate text-xs text-toned">{{ op.label }}</span>
        </span>
        <span class="shrink-0 font-mono text-xs text-dimmed">{{ op.storageLabel }}</span>
      </div>
    </div>

    <div class="border-t border-default/60 pt-2.5">
      <p class="font-mono text-[10px] uppercase tracking-wider text-dimmed">Additional storage</p>
      <p class="mt-0.5 flex items-center gap-1.5 text-sm" :class="storageClass">
        <UIcon v-if="state.plan.storage.kind === 'calculating'" name="i-lucide-loader-2" class="size-3.5 shrink-0 animate-spin" />
        {{ storageText }}
      </p>
    </div>

    <ul v-if="state.plan.notes && state.plan.notes.length > 0" class="space-y-1">
      <li v-for="note in state.plan.notes" :key="note" class="text-xs text-dimmed">{{ note }}</li>
    </ul>
  </div>
</template>
