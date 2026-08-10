import type { ItemFixture } from '../types/item'

/**
 * UI-only fixture data. See fixtures/stashes.ts for the rules.
 * `art` is only set on 'ready' items — thumbnail art is only generated once
 * an item finishes processing, so processing/queued/needs-attention items
 * exercise the fallback tile.
 */
export const itemFixtures: ItemFixture[] = [
  {
    id: 'item-1',
    stashId: 'stash-1',
    title: 'S3E12 · The Sunken Archive',
    publishedAt: '2026-08-06T18:00:00',
    duration: '48:32',
    sizeLabel: '1.4 GB',
    status: 'ready',
    art: 'from-amber-900/40 to-neutral-950'
  },
  {
    id: 'item-2',
    stashId: 'stash-1',
    title: 'S3E11 · Signal From the Ridge',
    publishedAt: '2026-08-04T18:00:00',
    duration: '52:04',
    sizeLabel: '1.6 GB',
    status: 'ready',
    art: 'from-sky-900/30 to-neutral-950'
  },
  {
    id: 'item-3',
    stashId: 'stash-1',
    title: 'S3E10 · Field Notes: The Long Dark',
    publishedAt: '2026-08-01T18:00:00',
    duration: '39:18',
    sizeLabel: '1.1 GB',
    status: 'ready',
    art: 'from-emerald-900/30 to-neutral-950'
  },
  {
    id: 'item-4',
    stashId: 'stash-1',
    title: 'S3E13 · The Cartographer’s Error',
    publishedAt: '2026-08-07T13:40:00',
    duration: '44:57',
    sizeLabel: '860 MB / 1.3 GB',
    status: 'processing',
    progressPercent: 58,
    progressStage: 'Transcoding · 720p'
  },
  {
    id: 'item-5',
    stashId: 'stash-1',
    title: 'S3E09 · What the Wreck Remembers',
    publishedAt: '2026-07-29T18:00:00',
    duration: '55:41',
    sizeLabel: '1.7 GB',
    status: 'ready',
    art: 'from-violet-900/30 to-neutral-950'
  },
  {
    id: 'item-6',
    stashId: 'stash-1',
    title: 'S3E08 · A Quiet Frequency',
    publishedAt: '2026-07-25T18:00:00',
    duration: '41:09',
    sizeLabel: '—',
    status: 'needs-attention'
  },
  {
    id: 'item-7',
    stashId: 'stash-1',
    title: 'S3E14 · Return to the Ridge',
    publishedAt: '2026-08-07T15:50:00',
    duration: '—',
    sizeLabel: '—',
    status: 'queued'
  },
  {
    id: 'item-8',
    stashId: 'stash-1',
    title: 'S3E07 · The Last Broadcast Tower',
    publishedAt: '2026-07-21T18:00:00',
    duration: '46:23',
    sizeLabel: '1.3 GB',
    status: 'ready',
    art: 'from-rose-900/25 to-neutral-950'
  },
  {
    id: 'item-9',
    stashId: 'stash-1',
    title: 'S3E06 · Static and Salt',
    publishedAt: '2026-07-18T18:00:00',
    duration: '37:52',
    sizeLabel: '990 MB',
    status: 'ready',
    art: 'from-cyan-900/25 to-neutral-950'
  },
  {
    id: 'item-10',
    stashId: 'stash-1',
    title: 'S3E05 · The Watchers of Oculus',
    publishedAt: '2026-07-14T18:00:00',
    duration: '61:15',
    sizeLabel: '1.9 GB',
    status: 'ready',
    art: 'from-amber-900/30 to-neutral-950'
  },
  // Backfill so the collection spans several pages worth of results without
  // hand-authoring dozens of one-off rows — same shape, generated variety.
  ...backfillItems()
]

function backfillItems(): ItemFixture[] {
  const arts = [
    'from-amber-900/30 to-neutral-950',
    'from-sky-900/30 to-neutral-950',
    'from-emerald-900/30 to-neutral-950',
    'from-violet-900/30 to-neutral-950',
    'from-rose-900/25 to-neutral-950',
    'from-cyan-900/25 to-neutral-950'
  ]
  return Array.from({ length: 42 }, (_, i) => {
    const n = i + 11
    const season = 2
    const episode = 42 - i
    const publishedAt = new Date(Date.UTC(2026, 5, 1) - i * 2 * 86_400_000).toISOString().slice(0, 19)
    return {
      id: `item-${n}`,
      stashId: 'stash-1',
      title: `S${season}E${String(episode).padStart(2, '0')} · Archive Recording ${episode}`,
      publishedAt,
      duration: `${30 + (i % 25)}:${String((i * 7) % 60).padStart(2, '0')}`,
      sizeLabel: `${(0.8 + (i % 12) * 0.1).toFixed(1)} GB`,
      status: 'ready',
      art: arts[i % arts.length]
    }
  })
}
