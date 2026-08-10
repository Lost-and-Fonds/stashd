<script setup lang="ts">
/**
 * Status — "is Stashd okay, what is it doing, and what is it consuming."
 * An operational briefing, not a host-monitoring dashboard. See
 * planning/DECISIONS.md, "Status purpose".
 *
 * Fixture-backed for now. No visible scenario switcher — this is a
 * production-style page. Dev/QA can still reach the other two fixture
 * scenarios via ?scenario=busy or ?scenario=needs-attention; anything else
 * (or no param) falls back to the default quiet/healthy state.
 */
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import OperationProgress from '../components/OperationProgress.vue'

interface StatusIssue {
  id: string
  label: string
  context: string
  timeLabel: string
}

interface StatusOperation {
  id: string
  objectLabel: string
  actionLabel: string
  percent: number
  stage: string
  count: string
}

interface StorageBreakdownItem {
  label: string
  sizeLabel: string
  note: string
}

interface StatusScenario {
  key: string
  label: string
  issues: StatusIssue[]
  operations: StatusOperation[]
  storage: {
    usedLabel: string
    availableLabel: string
    percentUsed: number
    breakdown: StorageBreakdownItem[]
    pressureNote?: string
  }
  cpu: { percent: number, context: string }
  memory: { usedLabel: string, totalLabel: string, context: string }
  runtimeSummary: string
  recent: { timeLabel: string, label: string, context: string }[]
}

const scenarios: StatusScenario[] = [
  {
    key: 'quiet',
    label: 'Quiet',
    issues: [],
    operations: [],
    storage: {
      usedLabel: '1.84 TB used',
      availableLabel: '1.16 TB available',
      percentUsed: 61,
      breakdown: [
        { label: 'Vault', sizeLabel: '1.52 TB', note: 'preserved' },
        { label: 'Broadcasts', sizeLabel: '287 GB', note: 'rebuildable' },
        { label: 'Cache & temporary', sizeLabel: '33 GB', note: 'reclaimable' }
      ]
    },
    cpu: { percent: 8, context: 'Idle' },
    memory: { usedLabel: '1.1 GB', totalLabel: '8 GB', context: 'Idle' },
    runtimeSummary: 'Stashd 0.9 · 4 workers · scheduler healthy',
    recent: [
      { timeLabel: '12:41', label: '14 items preserved', context: 'Oculus Imperia' },
      { timeLabel: '12:37', label: 'Podcast rebuilt', context: 'Critical Role' },
      { timeLabel: '11:58', label: 'Input checked', context: 'Garden Birds — March' }
    ]
  },
  {
    key: 'busy',
    label: 'Busy',
    issues: [],
    operations: [
      { id: 'op-1', objectLabel: 'Oculus Imperia', actionLabel: 'Preserving new items', percent: 35, stage: 'Downloading S3E12 · The Sunken Archive', count: '34 / 96' },
      { id: 'op-2', objectLabel: 'Critical Role · Podcast', actionLabel: 'Rebuilding broadcast', percent: 82, stage: 'Transcoding C3E58', count: '18 / 22' }
    ],
    storage: {
      usedLabel: '1.86 TB used',
      availableLabel: '1.14 TB available',
      percentUsed: 62,
      breakdown: [
        { label: 'Vault', sizeLabel: '1.53 TB', note: 'preserved' },
        { label: 'Broadcasts', sizeLabel: '294 GB', note: 'rebuildable' },
        { label: 'Cache & temporary', sizeLabel: '41 GB', note: 'reclaimable' }
      ]
    },
    cpu: { percent: 78, context: 'Transcoding 4 items' },
    memory: { usedLabel: '6.8 GB', totalLabel: '8 GB', context: '3 active transcodes' },
    runtimeSummary: 'Stashd 0.9 · 4 workers · scheduler healthy',
    recent: [
      { timeLabel: '13:14', label: 'Download completed', context: 'Oculus Imperia' },
      { timeLabel: '13:02', label: '8 items preserved', context: 'Critical Role' },
      { timeLabel: '12:55', label: 'Input checked', context: 'Field Records Archive' }
    ]
  },
  {
    key: 'needs-attention',
    label: 'Needs attention',
    issues: [
      { id: 'issue-1', label: 'Oculus Imperia podcast rebuild failed', context: 'Stash · Broadcast', timeLabel: '12 min ago' },
      { id: 'issue-2', label: 'Home Jellyfin connection credentials expired', context: 'Configure · Connections', timeLabel: '2h ago' },
      { id: 'issue-3', label: 'Storage running low', context: '92% used · 184 GB available', timeLabel: 'Ongoing' }
    ],
    operations: [],
    storage: {
      usedLabel: '92% used',
      availableLabel: '184 GB available',
      percentUsed: 92,
      pressureNote: 'Storage running low',
      breakdown: [
        { label: 'Vault', sizeLabel: '1.79 TB', note: 'preserved' },
        { label: 'Broadcasts', sizeLabel: '312 GB', note: 'rebuildable' },
        { label: 'Cache & temporary', sizeLabel: '58 GB', note: 'reclaimable' }
      ]
    },
    cpu: { percent: 22, context: 'Idle' },
    memory: { usedLabel: '2.1 GB', totalLabel: '8 GB', context: 'Idle' },
    runtimeSummary: 'Stashd 0.9 · 4 workers · scheduler healthy',
    recent: [
      { timeLabel: '12:49', label: 'Rebuild failed', context: 'Oculus Imperia' },
      { timeLabel: '11:30', label: 'Connection check failed', context: 'Home Jellyfin' },
      { timeLabel: '10:12', label: 'Input checked', context: 'Antarctica: Ice Core Archive' }
    ]
  }
]

const route = useRoute()
const active = computed(() => scenarios.find(s => s.key === route.query.scenario) ?? scenarios[0])
</script>

<template>
  <main class="mx-auto max-w-2xl space-y-8 px-4 py-8 sm:px-8">
    <header class="space-y-1">
      <h1 class="text-2xl font-semibold text-highlighted">Status</h1>
      <p class="text-sm text-muted">Is Stashd okay, what it's doing, and what it's using.</p>
      <p class="font-mono text-xs text-dimmed">{{ active.runtimeSummary }}</p>
    </header>

    <!-- Needs attention: the most urgent section, so it gets the page's one
         bordered/elevated surface when issues exist. The healthy case is a
         single quiet line, never a success banner. -->
    <section class="space-y-3">
      <h2 class="text-base font-medium text-highlighted">Needs attention</h2>
      <div v-if="active.issues.length > 0" class="divide-y divide-default/60 overflow-hidden rounded-md border border-default bg-muted">
        <div v-for="issue in active.issues" :key="issue.id" class="space-y-1 p-3">
          <div class="flex items-center gap-2">
            <span class="size-1.5 rounded-full bg-error" />
            <span class="text-sm text-highlighted">{{ issue.label }}</span>
          </div>
          <p class="pl-3.5 text-xs text-dimmed">{{ issue.context }} · {{ issue.timeLabel }}</p>
        </div>
      </div>
      <p v-else class="flex items-center gap-2 text-sm text-muted">
        <span class="size-1.5 rounded-full bg-success" />
        No issues need your attention
      </p>
    </section>

    <!-- In progress: omitted entirely when quiet — reuses OperationProgress
         as-is (bar = aggregate progress, stage = current item), one plain
         bg-muted row per operation, never a bordered card. -->
    <section v-if="active.operations.length > 0" class="space-y-3">
      <h2 class="text-base font-medium text-highlighted">In progress</h2>
      <div v-for="op in active.operations" :key="op.id" class="rounded-md bg-muted p-3">
        <OperationProgress
          :label="`${op.objectLabel} · ${op.actionLabel}`"
          :percent="op.percent"
          :stage="op.stage"
          :count="op.count"
          status="active"
        />
      </div>
    </section>

    <!-- Resources: one coherent instrument-like surface — Storage dominant on
         top (capacity + semantic breakdown), a single internal divider, then
         compact CPU/Memory. Not three separate dashboard widgets. -->
    <section class="space-y-3">
      <h2 class="text-base font-medium text-highlighted">Resources</h2>
      <div class="space-y-4 rounded-md border border-default bg-muted p-4">
        <div>
          <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
            <p class="text-sm font-medium text-highlighted">Storage</p>
            <p class="font-mono text-xs text-dimmed">{{ active.storage.usedLabel }} · {{ active.storage.availableLabel }}</p>
          </div>
          <div class="mt-2.5 h-1.5 overflow-hidden rounded-full bg-elevated">
            <div class="h-full rounded-full bg-neutral-400" :style="{ width: `${active.storage.percentUsed}%` }" />
          </div>
          <p v-if="active.storage.pressureNote" class="mt-1.5 text-xs text-warning">{{ active.storage.pressureNote }}</p>

          <div class="mt-3 space-y-1.5">
            <div v-for="item in active.storage.breakdown" :key="item.label" class="flex items-center justify-between gap-3">
              <span class="text-xs text-toned">{{ item.label }}</span>
              <span class="font-mono text-xs text-dimmed">{{ item.sizeLabel }} · {{ item.note }}</span>
            </div>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-4 border-t border-default/60 pt-4">
          <div>
            <p class="text-xs text-dimmed">CPU</p>
            <p class="mt-0.5 font-mono text-base text-highlighted">{{ active.cpu.percent }}%</p>
            <p class="mt-0.5 text-xs text-dimmed">{{ active.cpu.context }}</p>
          </div>
          <div>
            <p class="text-xs text-dimmed">Memory</p>
            <p class="mt-0.5 font-mono text-base text-highlighted">{{ active.memory.usedLabel }} / {{ active.memory.totalLabel }}</p>
            <p class="mt-0.5 text-xs text-dimmed">{{ active.memory.context }}</p>
          </div>
        </div>
      </div>
    </section>

    <!-- Recent: a short operational tail, not the preservation audit log. -->
    <section class="space-y-2">
      <h2 class="text-base font-medium text-highlighted">Recent</h2>
      <div class="divide-y divide-default/60 border-t border-default/60">
        <div v-for="event in active.recent" :key="`${event.timeLabel}-${event.label}`" class="flex items-baseline gap-3 py-1.5">
          <span class="shrink-0 font-mono text-xs text-dimmed">{{ event.timeLabel }}</span>
          <span class="text-xs text-toned">{{ event.label }}</span>
          <span class="text-xs text-dimmed">· {{ event.context }}</span>
        </div>
      </div>
    </section>
  </main>
</template>
