<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { normalizeBroadcastDetailFields } from '../adapters/normalizeBroadcastDetailFields'
import { fetchBroadcast } from '../api/broadcasts'
import PluginDetailFields from '../components/plugin/PluginDetailFields.vue'
import type { BroadcastApiResource } from '../types/broadcast-plugin'

const route = useRoute()
const broadcastId = String(route.params.broadcastId)
const broadcast = ref<BroadcastApiResource>()
const loading = ref(true)
const error = ref<string>()

const details = computed(() => normalizeBroadcastDetailFields(broadcast.value?.plugin_detail_fields ?? []))

async function load() {
  loading.value = true
  error.value = undefined

  try {
    broadcast.value = await fetchBroadcast(broadcastId)
  } catch (exception) {
    error.value = exception instanceof Error ? exception.message : 'Could not load Broadcast details.'
  } finally {
    loading.value = false
  }
}

onMounted(load)
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
      <UAlert v-if="error" color="error" variant="subtle" icon="i-lucide-circle-alert" title="Could not load Broadcast details" :description="error" />

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
      </template>
    </template>
  </main>
</template>
