<script setup lang="ts">
import type { PluginAction } from '../../adapters/normalizeBroadcastActions'

defineProps<{
  actions: PluginAction[]
  pendingActionId?: string
  disabled?: boolean
}>()

const emit = defineEmits<{ run: [action: PluginAction] }>()
</script>

<template>
  <div class="flex flex-wrap gap-2">
    <UButton
      v-for="action in actions"
      :key="action.id"
      :label="action.label"
      color="neutral"
      variant="soft"
      :loading="pendingActionId === action.id"
      :disabled="disabled || (pendingActionId !== undefined && pendingActionId !== action.id)"
      @click="emit('run', action)"
    />
  </div>
</template>
