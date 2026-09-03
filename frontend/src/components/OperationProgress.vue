<script setup lang="ts">
/**
 * Composite operation progress: the bar is the AGGREGATE progress of the whole
 * operation (e.g. rebuilding a broadcast across many transcodes). `stage` names
 * the specific item/subtask currently contributing to that aggregate — it is not
 * a second, independent progress measurement.
 */
type OperationStatus = 'active' | 'complete' | 'queued' | 'failed'

const props = withDefaults(defineProps<{
  label?: string
  stage?: string
  count?: string
  percent: number | null
  status?: OperationStatus
  variant?: 'default' | 'compact'
}>(), {
  status: 'active',
  variant: 'default'
})

const color = {
  active: 'primary',
  complete: 'success',
  queued: 'neutral',
  failed: 'error'
} as const satisfies Record<OperationStatus, 'primary' | 'success' | 'neutral' | 'error'>
</script>

<template>
  <!-- Compact + label: a dense version of the full treatment for overview/list
       contexts (e.g. the Stashes list) — still thin (size sm), just able to
       name the operation and stage, subordinate to whatever it's attached to. -->
  <div v-if="variant === 'compact' && label" class="space-y-1">
    <div class="flex items-center justify-between gap-2">
      <p class="truncate text-xs text-default">{{ label }}</p>
      <p class="shrink-0 font-mono text-xs text-dimmed">
        {{ status === 'complete' ? 'complete' : (percent === null ? 'queued' : `${percent}%`) }}
      </p>
    </div>
    <UProgress :model-value="percent" :color="color[status]" size="sm" />
    <p v-if="stage" class="truncate font-mono text-xs text-muted">{{ stage }}</p>
  </div>

  <!-- Compact, no label: bare bar + percent, for a table/row cell where the
       row itself already identifies what the progress belongs to. -->
  <div v-else-if="variant === 'compact'" class="flex w-20 items-center gap-1.5">
    <UProgress :model-value="percent" :color="color[status]" size="sm" class="flex-1" />
    <UIcon v-if="status === 'complete'" name="i-lucide-check" class="size-3.5 shrink-0 text-success" />
    <span v-else class="w-8 shrink-0 text-right font-mono text-xs text-dimmed">{{ percent === null ? '—' : `${percent}%` }}</span>
  </div>

  <div v-else class="space-y-1.5">
    <div v-if="label" class="flex items-center justify-between gap-3">
      <p class="truncate text-sm text-default">{{ label }}</p>
      <p v-if="status === 'complete'" class="flex shrink-0 items-center gap-1 font-mono text-xs text-success">
        <UIcon name="i-lucide-check" class="size-3.5" />
        complete
      </p>
      <p v-else class="shrink-0 font-mono text-xs text-dimmed">{{ percent === null ? 'queued' : `${percent}%` }}</p>
    </div>

    <UProgress :model-value="percent" :color="color[status]" size="lg" />

    <div v-if="stage || count" class="flex items-center justify-between gap-3">
      <p v-if="stage" class="truncate font-mono text-xs text-muted">{{ stage }}</p>
      <p v-if="count" class="ml-auto shrink-0 font-mono text-xs text-dimmed">{{ count }}</p>
    </div>
  </div>
</template>
