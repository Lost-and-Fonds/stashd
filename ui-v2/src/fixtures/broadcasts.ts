import type { BroadcastFixture } from '../types/broadcast'

/**
 * UI-only fixture data. See fixtures/stashes.ts for the rules.
 */
export const broadcastFixtures: BroadcastFixture[] = [
  {
    id: 'broadcast-1',
    stashId: 'stash-1',
    kind: 'podcast',
    name: 'Oculus Imperia · Private feed',
    formLabel: 'Podcast · Audio',
    status: 'active',
    buildState: 'current',
    lastRebuild: '2h ago',
    lastRebuildAt: '2026-08-07T14:00:00',
    itemsPublished: 412,
    itemsTotal: 412,
    sizeLabel: '64 GB',
    feedUrl: 'https://stashd.example/feeds/oc-imp-9f2a1c/rss.xml'
  },
  {
    id: 'broadcast-2',
    stashId: 'stash-1',
    kind: 'jellyfin',
    name: 'Oculus Imperia · Jellyfin',
    formLabel: 'Jellyfin · Media library',
    status: 'active',
    buildState: 'current',
    lastRebuild: '2h ago',
    lastRebuildAt: '2026-08-07T14:00:00',
    itemsPublished: 412,
    itemsTotal: 412,
    sizeLabel: '118 GB'
  },
  {
    id: 'broadcast-3',
    stashId: 'stash-2',
    kind: 'podcast',
    name: 'Field Records Archive · Private feed',
    formLabel: 'Podcast · Audio',
    status: 'active',
    buildState: 'rebuilding',
    buildPercent: 63,
    buildStage: 'Transcoding · Restoring a Commodore SX-64',
    lastRebuild: '2h ago',
    lastRebuildAt: '2026-08-07T14:00:00',
    itemsPublished: 82,
    itemsTotal: 96,
    sizeLabel: '16 GB',
    feedUrl: 'https://stashd.example/feeds/field-rec-6b40de/rss.xml'
  },
  {
    id: 'broadcast-4',
    stashId: 'stash-3',
    kind: 'podcast',
    name: 'Critical Role · Private feed',
    formLabel: 'Podcast · Audio',
    status: 'active',
    buildState: 'current',
    lastRebuild: '1d ago',
    lastRebuildAt: '2026-08-06T16:00:00',
    itemsPublished: 1204,
    itemsTotal: 1204,
    sizeLabel: '310 GB',
    feedUrl: 'https://stashd.example/feeds/crit-role-15dd3a/rss.xml'
  },
  {
    id: 'broadcast-5',
    stashId: 'stash-3',
    kind: 'plex',
    name: 'Critical Role · Plex',
    formLabel: 'Plex · Media library',
    status: 'active',
    buildState: 'current',
    lastRebuild: '1d ago',
    lastRebuildAt: '2026-08-06T16:00:00',
    itemsPublished: 1204,
    itemsTotal: 1204,
    sizeLabel: '640 GB'
  },
  {
    id: 'broadcast-6',
    stashId: 'stash-4',
    kind: 'jellyfin',
    name: 'Garden Birds — March · Jellyfin',
    formLabel: 'Jellyfin · Media library',
    status: 'paused',
    buildState: 'stale',
    lastRebuild: '3d ago',
    lastRebuildAt: '2026-08-04T16:00:00',
    itemsPublished: 40,
    itemsTotal: 58,
    sizeLabel: '4.8 GB'
  },
  {
    id: 'broadcast-7',
    stashId: 'stash-5',
    kind: 'podcast',
    name: 'Antarctica: Ice Core Archive · Private feed',
    formLabel: 'Podcast · Audio',
    status: 'needs-attention',
    buildState: 'stale',
    lastRebuild: '1h ago',
    lastRebuildAt: '2026-08-07T15:00:00',
    itemsPublished: 180,
    itemsTotal: 210,
    sizeLabel: '32 GB',
    feedUrl: 'https://stashd.example/feeds/ice-core-7ae120/rss.xml'
  }
]
