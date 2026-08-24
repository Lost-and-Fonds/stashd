import type { VaultItemFixture } from '../types/vaultItem'

/**
 * UI-only fixture data. See fixtures/stashes.ts for the rules.
 * Deliberately mixed Item types (video/audio/document/photo/disc-capture) to
 * prove the Vault overview doesn't assume every preserved thing is playable
 * media. `vault-4`/`vault-5` demonstrate multi-Stash membership rendering as
 * ONE row; `vault-1`/`vault-2` demonstrate Broadcast usage. `stashIds` reuse
 * the real ids from fixtures/stashes.ts; `sourceId` keys into
 * fixtures/vaultSources.ts's Source picker hierarchy.
 */
export const vaultItemFixtures: VaultItemFixture[] = [
  {
    id: 'vault-1',
    title: 'Oculus Imperia · S3E12 — The Sunken Archive',
    type: 'video',
    sourceFamily: 'youtube',
    sourceLabel: 'YouTube · @oculusimperia',
    sourceId: 'oculusimperia',
    art: 'from-amber-900/40 to-neutral-950',
    preservedAt: '2026-08-06T18:00:00',
    sizeLabel: '1.4 GB',
    stashIds: ['stash-1'],
    broadcastCount: 2
  },
  {
    id: 'vault-2',
    title: 'Critical Role · C3E58',
    type: 'video',
    sourceFamily: 'youtube',
    sourceLabel: 'YouTube · @criticalrole',
    sourceId: 'criticalrole',
    art: 'from-violet-900/30 to-neutral-950',
    preservedAt: '2026-08-05T16:00:00',
    sizeLabel: '4.6 GB',
    stashIds: ['stash-3'],
    broadcastCount: 2
  },
  {
    id: 'vault-3',
    title: "The Internet's Own Boy",
    type: 'audio',
    sourceFamily: 'podcast',
    sourceLabel: 'Podcast · Field Records Archive',
    sourceId: 'field-records-archive',
    preservedAt: '2026-07-29T12:00:00',
    sizeLabel: '68 MB',
    stashIds: ['stash-2'],
    broadcastCount: 1
  },
  {
    id: 'vault-4',
    title: 'Exhibit 103',
    type: 'document',
    sourceFamily: 'file-import',
    sourceLabel: 'File import · Discovery production 003',
    sourceId: 'discovery-production-003',
    preservedAt: '2026-07-20T09:00:00',
    sizeLabel: '4.2 MB',
    stashIds: ['stash-2', 'stash-5'],
    broadcastCount: 0
  },
  {
    id: 'vault-5',
    title: 'Blade Runner — Disc 1, Side A',
    type: 'disc-capture',
    sourceFamily: 'physical-capture',
    sourceLabel: 'LaserDisc capture station',
    sourceId: 'laserdisc-capture-station',
    art: 'from-sky-900/30 to-neutral-950',
    preservedAt: '2026-06-30T14:00:00',
    sizeLabel: '18.6 GB',
    stashIds: ['stash-1', 'stash-3'],
    broadcastCount: 0
  },
  {
    id: 'vault-6',
    title: 'Garden Birds — March, Contact Sheet 04',
    type: 'photo',
    sourceFamily: 'scan',
    sourceLabel: 'Scan · Contact sheet scans',
    sourceId: 'contact-sheet-scans',
    art: 'from-emerald-900/30 to-neutral-950',
    preservedAt: '2026-08-04T10:00:00',
    sizeLabel: '22 MB',
    stashIds: ['stash-4'],
    broadcastCount: 0
  },
  {
    id: 'vault-7',
    title: 'Antarctica: Ice Core Archive · Field Log 14',
    type: 'document',
    sourceFamily: 'file-import',
    sourceLabel: 'File import · Antarctica archive',
    sourceId: 'antarctica-archive',
    preservedAt: '2026-07-25T09:00:00',
    sizeLabel: '1.1 MB',
    stashIds: ['stash-5'],
    broadcastCount: 0
  },
  {
    id: 'vault-8',
    title: 'Field Records — Analog Archive, Reel 7',
    type: 'audio',
    sourceFamily: 'file-import',
    sourceLabel: 'File import · Field Records — Analog Archive',
    sourceId: 'field-records-analog-archive',
    preservedAt: '2026-08-02T11:00:00',
    sizeLabel: '310 MB',
    stashIds: ['stash-2'],
    broadcastCount: 1
  },
  {
    id: 'vault-9',
    title: 'Garden Birds — March · Nest Box Cam, April 2',
    type: 'video',
    sourceFamily: 'youtube',
    sourceLabel: 'YouTube playlist · Garden Birds — March',
    sourceId: 'gardenbirds-march',
    art: 'from-rose-900/25 to-neutral-950',
    preservedAt: '2026-08-01T08:00:00',
    sizeLabel: '210 MB',
    stashIds: ['stash-4'],
    broadcastCount: 1
  }
]
