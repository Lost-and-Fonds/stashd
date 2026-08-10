import type { StashFixture } from '../types/stash'

/**
 * UI-only fixture data.
 *
 * Pages may import fixtures directly while we are designing. Do not build a
 * fake API, repository layer, or mock server around this unless explicitly asked.
 * During integration this import can be replaced by the real data source while
 * presentational components stay unchanged.
 */
export const stashFixtures: StashFixture[] = [
  {
    id: 'stash-1',
    name: 'Oculus Imperia',
    itemCount: 412,
    sizeLabel: '118 GB',
    lastActivity: '2h ago',
    lastActivityAt: '2026-08-07T14:00:00',
    status: 'active',
    inputCount: 1,
    broadcastCount: 2
  },
  {
    id: 'stash-2',
    name: 'Field Records Archive',
    itemCount: 96,
    sizeLabel: '22 GB',
    lastActivity: 'just now',
    lastActivityAt: '2026-08-07T15:58:00',
    status: 'active',
    inputCount: 1,
    broadcastCount: 1,
    operation: {
      label: 'Rebuilding podcast broadcast',
      percent: 63,
      stage: 'Transcoding · Restoring a Commodore SX-64',
      count: '14 of 22 items'
    }
  },
  {
    id: 'stash-3',
    name: 'Critical Role',
    itemCount: 1204,
    sizeLabel: '640 GB',
    lastActivity: '1d ago',
    lastActivityAt: '2026-08-06T16:00:00',
    status: 'active',
    inputCount: 1,
    broadcastCount: 3
  },
  {
    id: 'stash-4',
    name: 'Garden Birds — March',
    itemCount: 58,
    sizeLabel: '6.1 GB',
    lastActivity: '3d ago',
    lastActivityAt: '2026-08-04T16:00:00',
    status: 'paused',
    inputCount: 1,
    broadcastCount: 0
  },
  {
    id: 'stash-5',
    name: 'Antarctica: Ice Core Archive',
    itemCount: 210,
    sizeLabel: '40 GB',
    lastActivity: '1h ago',
    lastActivityAt: '2026-08-07T15:00:00',
    status: 'needs-attention',
    inputCount: 1,
    broadcastCount: 1
  }
]
