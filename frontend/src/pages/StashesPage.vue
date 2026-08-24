<script setup lang="ts">
import MetaLine from '../components/MetaLine.vue'
import OperationProgress from '../components/OperationProgress.vue'
import { stashFixtures } from '../fixtures/stashes'
import type { StashFixture, StashStatus } from '../types/stash'

// Status: quiet dot + label, matching the pattern established on /design —
// deliberately not a badge/pill, and not the same enum as OperationStatus
// (a Stash's health is independent of whether work is actively happening).
const statusMeta: Record<StashStatus, { label: string, dot: string }> = {
  active: { label: 'active', dot: 'bg-success' },
  paused: { label: 'paused', dot: 'bg-neutral-400' },
  'needs-attention': { label: 'needs attention', dot: 'bg-error' }
}

const stashActions = [
  [{ label: 'Open stash', icon: 'i-lucide-external-link' }, { label: 'Rebuild broadcasts', icon: 'i-lucide-refresh-cw' }],
  [{ label: 'Pause', icon: 'i-lucide-pause' }],
  [{ label: 'Delete', icon: 'i-lucide-trash-2', color: 'error' as const }]
]

function monogram(name: string) {
  return name.charAt(0).toUpperCase()
}

function pluralize(count: number, noun: string) {
  return `${count} ${noun}${count === 1 ? '' : 's'}`
}

// Naming the action beats a naked relative timestamp — see MetaLine.
function stashMetaItems(s: StashFixture) {
  const activityText = s.status === 'needs-attention' ? `Failed ${s.lastActivity}` : `Updated ${s.lastActivity}`
  return [
    { text: `${s.itemCount.toLocaleString()} items` },
    { text: s.sizeLabel },
    { text: activityText, datetime: s.lastActivityAt }
  ]
}
</script>

<template>
  <main class="mx-auto max-w-4xl space-y-6 px-4 py-8 sm:px-8">
    <header class="flex items-start justify-between gap-4">
      <div>
        <h1 class="text-2xl font-semibold text-highlighted">Stashes</h1>
        <p class="mt-1 text-sm text-muted">Everything you're preserving, at a glance.</p>
      </div>
      <UButton label="New stash" icon="i-lucide-plus" to="/stashes/new" />
    </header>

    <div class="divide-y divide-default rounded-md border border-default">
      <div v-for="stash in stashFixtures" :key="stash.id" class="p-4 transition-colors hover:bg-elevated/40 sm:p-5">
        <div class="flex items-start gap-3">
          <!-- Row navigates to the Stash; the action menu below is a separate
               sibling control, not nested inside this link. -->
          <RouterLink :to="`/stashes/${stash.id}`" class="flex min-w-0 flex-1 items-start gap-3">
            <div class="flex size-11 shrink-0 items-center justify-center rounded-md bg-elevated font-mono text-sm text-muted">
              {{ monogram(stash.name) }}
            </div>

            <div class="min-w-0 flex-1">
              <p class="truncate font-mono text-base leading-tight text-highlighted">{{ stash.name }}</p>

              <MetaLine class="mt-1" :status="statusMeta[stash.status]" :items="stashMetaItems(stash)">
                <UTooltip :text="pluralize(stash.inputCount, 'input')">
                  <span class="inline-flex items-center gap-1 font-mono text-xs text-dimmed">
                    <UIcon name="i-lucide-download" class="size-3.5" />
                    {{ stash.inputCount }}
                  </span>
                </UTooltip>
                <UTooltip :text="pluralize(stash.broadcastCount, 'broadcast')">
                  <span class="inline-flex items-center gap-1 font-mono text-xs text-dimmed">
                    <UIcon name="i-lucide-radio" class="size-3.5" />
                    {{ stash.broadcastCount }}
                  </span>
                </UTooltip>
              </MetaLine>

              <div v-if="stash.operation" class="mt-3 max-w-md">
                <OperationProgress
                  variant="compact"
                  :label="stash.operation.label"
                  :percent="stash.operation.percent"
                  :stage="stash.operation.stage"
                  status="active"
                />
              </div>
            </div>
          </RouterLink>

          <!-- A sibling of the name/status block, not nested inside it — otherwise
               the button's own min-height keeps that block from sitting tight
               against the avatar. -->
          <UDropdownMenu :items="stashActions">
            <UButton icon="i-lucide-ellipsis-vertical" aria-label="More actions" title="More actions" variant="ghost" color="neutral" size="md" class="shrink-0" />
          </UDropdownMenu>
        </div>
      </div>
    </div>
  </main>
</template>
