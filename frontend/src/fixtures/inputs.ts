import { reactive } from 'vue'
import type { InputFixture } from '../types/input'

/**
 * UI-only fixture data. See fixtures/stashes.ts for the rules.
 * `reactive()` so the Add Input workflow can push a new fixture Input and
 * have it appear immediately on an already-mounted Stash detail page.
 */
export const inputFixtures: InputFixture[] = reactive([
  {
    id: 'input-1',
    stashId: 'stash-1',
    provider: 'youtube-channel',
    providerLabel: 'YouTube channel',
    identity: '@oculusimperia',
    url: 'https://youtube.com/@oculusimperia',
    status: 'active',
    syncMode: 'automatic',
    lastChecked: '2h ago',
    lastCheckedAt: '2026-08-07T14:00:00'
  },
  {
    id: 'input-2',
    stashId: 'stash-2',
    provider: 'youtube-playlist',
    providerLabel: 'YouTube playlist',
    identity: 'Field Records — Analog Archive',
    url: 'https://youtube.com/playlist?list=PLfieldrecordsanalog',
    status: 'active',
    syncMode: 'automatic',
    filterSummary: 'Only videos over 5 minutes',
    lastChecked: 'just now',
    lastCheckedAt: '2026-08-07T15:58:00'
  },
  {
    id: 'input-3',
    stashId: 'stash-2',
    provider: 'rss',
    providerLabel: 'RSS feed',
    identity: 'fieldrecords.example/feed.xml',
    url: 'https://fieldrecords.example/feed.xml',
    status: 'active',
    syncMode: 'manual',
    lastChecked: '38m ago',
    lastCheckedAt: '2026-08-07T15:22:00'
  },
  {
    id: 'input-4',
    stashId: 'stash-3',
    provider: 'youtube-channel',
    providerLabel: 'YouTube channel',
    identity: '@criticalrole',
    url: 'https://youtube.com/@criticalrole',
    status: 'active',
    syncMode: 'automatic',
    filterSummary: 'Excludes YouTube Shorts',
    lastChecked: '1d ago',
    lastCheckedAt: '2026-08-06T16:00:00'
  },
  {
    id: 'input-5',
    stashId: 'stash-4',
    provider: 'youtube-playlist',
    providerLabel: 'YouTube playlist',
    identity: 'Garden Birds — March',
    url: 'https://youtube.com/playlist?list=PLgardenbirdsmarch',
    status: 'paused',
    syncMode: 'manual',
    lastChecked: '3d ago',
    lastCheckedAt: '2026-08-04T16:00:00'
  },
  {
    id: 'input-6',
    stashId: 'stash-5',
    provider: 'youtube-channel',
    providerLabel: 'YouTube channel',
    identity: '@polararchive',
    url: 'https://youtube.com/@polararchive',
    status: 'needs-attention',
    syncMode: 'automatic',
    lastChecked: '1h ago',
    lastCheckedAt: '2026-08-07T15:00:00'
  }
])
