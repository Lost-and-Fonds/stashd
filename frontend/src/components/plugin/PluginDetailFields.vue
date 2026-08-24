<script setup lang="ts">
import type { PluginDetailField } from '../../adapters/normalizeBroadcastDetailFields'

defineProps<{ fields: PluginDetailField[] }>()
</script>

<template>
  <dl class="space-y-4">
    <div v-for="field in fields" :key="field.id" class="space-y-1">
      <dt class="text-sm font-medium text-highlighted">{{ field.label }}</dt>
      <dd>
        <a v-if="field.value !== ''" :href="field.href" target="_blank" rel="noreferrer" class="break-all font-mono text-sm text-primary hover:underline">{{ field.value }}</a>
        <span v-else class="text-sm text-dimmed">Unavailable</span>
      </dd>
    </div>
  </dl>
</template>
