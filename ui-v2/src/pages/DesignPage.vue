<script setup lang="ts">
import { h, ref, resolveComponent } from 'vue'
import type { TableColumn } from '@nuxt/ui'
import OperationProgress from '../components/OperationProgress.vue'

const surfaces = [
  { name: 'default', class: 'bg-default' },
  { name: 'muted', class: 'bg-muted' },
  { name: 'elevated', class: 'bg-elevated' },
  { name: 'accented', class: 'bg-accented' }
]

const cardUi = {
  header: 'bg-muted p-3 sm:px-4',
  body: 'p-3 sm:p-4',
  footer: 'bg-muted/60 p-3 sm:px-4'
}

// Status: a dot + label, deliberately NOT a badge/pill — statuses are
// read-only state, not an interactive element, and shouldn't compete
// visually with real buttons.
type VaultStatus = 'complete' | 'downloading' | 'queued' | 'failed'

const statusMeta: Record<VaultStatus, { label: string, dot: string, pulse?: boolean, color: 'primary' | 'success' | 'neutral' | 'error' }> = {
  complete: { label: 'complete', dot: 'bg-success', color: 'success' },
  downloading: { label: 'downloading', dot: 'bg-primary', pulse: true, color: 'primary' },
  queued: { label: 'queued', dot: 'bg-neutral-400', color: 'neutral' },
  failed: { label: 'failed', dot: 'bg-error', color: 'error' }
}
const statusList: VaultStatus[] = ['complete', 'downloading', 'queued', 'failed']

// Media/data table specimen — fixture rows only, not a real data model.
interface VaultRow {
  title: string
  source: string
  status: VaultStatus
  progress: number | null
  size: string
  updated: string
  art: string
}

const vaultRows: VaultRow[] = [
  { title: 'Oculus Imperia · S3E04', source: 'Field Records', status: 'complete', progress: 100, size: '1.2 GB', updated: '2h ago', art: 'from-amber-900/40 to-neutral-950' },
  { title: 'Lo-fi Study Beats vol. 12', source: 'Chillhop Radio', status: 'downloading', progress: 63, size: '740 MB / 1.1 GB', updated: 'just now', art: 'from-emerald-900/30 to-neutral-950' },
  { title: 'Antarctica: Ice Core Archive', source: 'Field Records', status: 'queued', progress: null, size: '—', updated: '10m ago', art: 'from-sky-900/30 to-neutral-950' },
  { title: 'Garden Birds — March', source: 'backyard.wildlife', status: 'failed', progress: 22, size: '180 MB', updated: '1h ago', art: 'from-rose-900/25 to-neutral-950' },
  { title: 'Critical Role · C3E58', source: 'Critical Role', status: 'complete', progress: 100, size: '4.6 GB', updated: '1d ago', art: 'from-violet-900/30 to-neutral-950' }
]

const rowActions = [
  [{ label: 'View', icon: 'i-lucide-eye' }, { label: 'Rebuild', icon: 'i-lucide-refresh-cw' }],
  [{ label: 'Remove', icon: 'i-lucide-trash-2', color: 'error' as const }]
]

function thumbClass(row: VaultRow) {
  return ['flex shrink-0 items-center justify-center rounded-sm bg-gradient-to-br', row.art]
}

const vaultColumns: TableColumn<VaultRow>[] = [
  {
    accessorKey: 'title',
    header: 'Title',
    cell: ({ row }) => h('div', { class: 'flex items-center gap-2.5' }, [
      h('div', { class: [...thumbClass(row.original), 'size-8'] }, [h(resolveComponent('UIcon'), { name: 'i-lucide-play', class: 'size-3 text-dimmed' })]),
      h('span', { class: 'block max-w-[260px] truncate font-mono text-highlighted' }, row.original.title)
    ])
  },
  {
    accessorKey: 'source',
    header: 'Source',
    cell: ({ row }) => h('span', { class: 'block max-w-[160px] truncate font-mono text-muted' }, row.original.source)
  },
  {
    accessorKey: 'status',
    header: 'Status',
    cell: ({ row }) => {
      const s = statusMeta[row.original.status]
      return h('span', { class: 'inline-flex items-center gap-1.5' }, [
        h('span', { class: ['size-1.5 rounded-full', s.dot, s.pulse && 'animate-pulse'] }),
        h('span', { class: 'font-mono text-xs uppercase tracking-wide text-muted' }, s.label)
      ])
    }
  },
  {
    accessorKey: 'progress',
    header: 'Progress',
    cell: ({ row }) => h(OperationProgress, { variant: 'compact', percent: row.original.progress, status: row.original.status === 'downloading' ? 'active' : row.original.status })
  },
  {
    accessorKey: 'size',
    header: 'Size',
    cell: ({ row }) => h('span', { class: 'font-mono text-xs text-muted' }, row.original.size)
  },
  {
    accessorKey: 'updated',
    header: 'Updated',
    cell: ({ row }) => h('span', { class: 'font-mono text-xs text-dimmed' }, row.original.updated)
  },
  {
    id: 'actions',
    cell: () => h(resolveComponent('UDropdownMenu'), { items: rowActions },
      () => h(resolveComponent('UButton'), { icon: 'i-lucide-ellipsis-vertical', variant: 'ghost', color: 'neutral', size: 'sm' }))
  }
]

const tableUi = {
  th: 'font-mono text-xs uppercase tracking-wider text-dimmed py-2 px-2',
  td: 'py-2 px-2'
}

// Form specimen — local-only demo state, no persistence/backend.
const urlValue = ref('')
const qualityValue = ref('1080p')
const qualityOptions = ['Source', '1080p', '720p', 'Audio only']
const autoBroadcast = ref(true)
const notifyOnFailure = ref(false)
</script>

<template>
  <main class="mx-auto max-w-6xl space-y-16 px-4 py-12 sm:px-8">
    <!-- Prose/specimen sections use a constrained reading measure (max-w-3xl).
         The media table below intentionally breaks out to the full application
         measure — operational data benefits from the extra width; prose doesn't. -->
    <!-- Identity -->
    <section class="max-w-3xl space-y-1.5">
      <p class="font-mono text-3xl tracking-tight text-highlighted">
        stashd<span class="text-primary">_</span>
      </p>
      <p class="text-muted">Because the internet forgets.</p>
      <p class="text-sm text-dimmed">
        Design playground — a working surface for deciding what Stashd should look like, not a finished page.
      </p>
    </section>

    <USeparator class="max-w-3xl" />

    <!-- Typography -->
    <section class="max-w-3xl space-y-4">
      <h2 class="font-mono text-xs uppercase tracking-wider text-dimmed">Typography</h2>
      <div class="space-y-2">
        <p class="text-2xl font-semibold text-highlighted">
          <span class="text-muted">Vault</span> <span class="font-mono">oculusimperia</span>
        </p>
        <p class="text-base text-default">
          Stashd turns fragile online media into a local archive you control, then rebroadcasts it into the apps you already use.
        </p>
        <p class="font-mono text-sm text-muted">Last synced 2 hours ago · 412 items · 118 GB</p>
        <p class="font-mono text-xs text-dimmed">stash_input_id · vault/oculusimperia/S3E04.mkv</p>
      </div>
    </section>

    <USeparator class="max-w-3xl" />

    <!-- Colour -->
    <section class="max-w-3xl space-y-4">
      <h2 class="font-mono text-xs uppercase tracking-wider text-dimmed">Surfaces &amp; colour</h2>
      <div class="grid grid-cols-4 gap-3">
        <div v-for="s in surfaces" :key="s.name" class="space-y-1.5">
          <div :class="[s.class, 'h-12 rounded-md border border-default']" />
          <p class="font-mono text-xs text-dimmed">{{ s.name }}</p>
        </div>
      </div>
      <div class="flex flex-wrap gap-2">
        <div class="size-8 rounded-md bg-primary" />
        <div class="size-8 rounded-md bg-success" />
        <div class="size-8 rounded-md bg-error" />
        <div class="size-8 rounded-md bg-inverted" />
      </div>
    </section>

    <USeparator class="max-w-3xl" />

    <!-- Buttons -->
    <section class="max-w-3xl space-y-4">
      <h2 class="font-mono text-xs uppercase tracking-wider text-dimmed">Buttons</h2>
      <div class="flex flex-wrap items-center gap-2">
        <UButton label="Start stash" />
        <UButton label="Rebuild broadcast" variant="soft" />
        <UButton label="Cancel" color="neutral" variant="outline" />
        <UButton label="Danger zone" color="error" variant="soft" />
        <UButton icon="i-lucide-refresh-cw" variant="ghost" color="neutral" />
      </div>
    </section>

    <USeparator class="max-w-3xl" />

    <!-- Status -->
    <section class="max-w-3xl space-y-4">
      <h2 class="font-mono text-xs uppercase tracking-wider text-dimmed">Status</h2>
      <p class="text-xs text-dimmed">Read-only state — a dot and a label, never a pill. Contrast this with the buttons above.</p>
      <div class="flex flex-wrap gap-4">
        <span v-for="key in statusList" :key="key" class="inline-flex items-center gap-1.5">
          <span class="size-1.5 rounded-full" :class="[statusMeta[key].dot, statusMeta[key].pulse && 'animate-pulse']" />
          <span class="font-mono text-xs uppercase tracking-wide text-muted">{{ statusMeta[key].label }}</span>
        </span>
      </div>
    </section>

    <USeparator class="max-w-3xl" />

    <!-- Media/data table -->
    <section class="space-y-4">
      <h2 class="font-mono text-xs uppercase tracking-wider text-dimmed">Media table</h2>

      <UTable :data="vaultRows" :columns="vaultColumns" :ui="tableUi" class="hidden text-sm md:block" />

      <!-- UTable is a literal <table>; it can't reflow into a card shape.
           Below md, render the same fixture as purpose-built rows instead
           of forcing horizontal scroll on a 7-column table. -->
      <div class="divide-y divide-default rounded-md border border-default md:hidden">
        <div v-for="row in vaultRows" :key="row.title" class="flex items-center gap-3 p-3">
          <div :class="[...thumbClass(row), 'size-10']">
            <UIcon name="i-lucide-play" class="size-3.5 text-dimmed" />
          </div>
          <div class="min-w-0 flex-1 space-y-1">
            <p class="truncate font-mono text-sm text-highlighted">{{ row.title }}</p>
            <p class="truncate font-mono text-xs text-muted">{{ row.source }}</p>
            <div class="flex items-center gap-3 pt-0.5">
              <span class="inline-flex shrink-0 items-center gap-1.5">
                <span class="size-1.5 rounded-full" :class="[statusMeta[row.status].dot, statusMeta[row.status].pulse && 'animate-pulse']" />
                <span class="font-mono text-xs uppercase tracking-wide text-muted">{{ statusMeta[row.status].label }}</span>
              </span>
              <OperationProgress
                variant="compact"
                :percent="row.progress"
                :status="row.status === 'downloading' ? 'active' : row.status"
              />
            </div>
            <p class="font-mono text-xs text-dimmed">{{ row.size }} · {{ row.updated }}</p>
          </div>
          <UDropdownMenu :items="rowActions">
            <UButton icon="i-lucide-ellipsis-vertical" variant="ghost" color="neutral" size="md" class="shrink-0" />
          </UDropdownMenu>
        </div>
      </div>
    </section>

    <USeparator class="max-w-3xl" />

    <!-- Composite progress -->
    <section class="max-w-3xl space-y-4">
      <h2 class="font-mono text-xs uppercase tracking-wider text-dimmed">Progress</h2>
      <p class="text-xs text-dimmed">
        The bar is the aggregate progress of the whole operation. The stage line names what's
        currently contributing to it — it is not a second measurement.
      </p>
      <div class="max-w-md space-y-6">
        <OperationProgress
          label="Rebuilding podcast broadcast"
          :percent="63"
          status="active"
          stage="Transcoding · Restoring a Commodore SX-64"
          count="14 of 22 items"
        />

        <div class="space-y-1.5">
          <p class="font-mono text-xs uppercase tracking-wider text-dimmed">Media row (compact)</p>
          <OperationProgress variant="compact" :percent="63" status="active" />
        </div>

        <OperationProgress label="Vault sync" :percent="100" status="complete" />

        <OperationProgress label="Ingesting new stash items" :percent="null" status="queued" />
      </div>
    </section>

    <USeparator class="max-w-3xl" />

    <!-- Cards -->
    <section class="max-w-3xl space-y-4">
      <h2 class="font-mono text-xs uppercase tracking-wider text-dimmed">Panels</h2>
      <UCard :ui="cardUi">
        <template #header>
          <p class="font-medium text-highlighted">Broadcast · Jellyfin</p>
        </template>
        <p class="text-sm text-muted">
          Hardlinked from Vault. Regenerates automatically when source items change.
        </p>
        <template #footer>
          <p class="font-mono text-xs text-dimmed">Rebuilt 14 minutes ago</p>
        </template>
      </UCard>
    </section>

    <USeparator class="max-w-3xl" />

    <!-- Form controls -->
    <section class="max-w-3xl space-y-4">
      <h2 class="font-mono text-xs uppercase tracking-wider text-dimmed">Form controls</h2>
      <div class="max-w-sm space-y-4">
        <UInput v-model="urlValue" class="font-mono" placeholder="https://youtube.com/watch?v=..." icon="i-lucide-link" size="lg" />

        <USelect v-model="qualityValue" :items="qualityOptions" size="lg" />

        <UTextarea placeholder="Notes for this stash — optional." :rows="2" size="lg" class="w-full" />

        <UCheckbox v-model="autoBroadcast" label="Auto-rebuild broadcasts on new items" />

        <div class="flex items-center justify-between">
          <span class="text-sm text-default">Notify on failure</span>
          <USwitch v-model="notifyOnFailure" />
        </div>
      </div>
    </section>

    <USeparator class="max-w-3xl" />

    <!-- Media thumbnail -->
    <section class="max-w-3xl space-y-4">
      <h2 class="font-mono text-xs uppercase tracking-wider text-dimmed">Thumbnail treatment</h2>
      <div class="w-48 overflow-hidden rounded-md border border-default bg-elevated">
        <div class="flex aspect-video items-center justify-center bg-gradient-to-br from-neutral-800 to-neutral-950">
          <UIcon name="i-lucide-play" class="size-6 text-dimmed" />
        </div>
        <div class="px-2.5 py-1.5">
          <p class="truncate font-mono text-sm text-default">S3E04 · Field notes</p>
          <p class="font-mono text-xs text-dimmed">42:11</p>
        </div>
      </div>
    </section>
  </main>
</template>
