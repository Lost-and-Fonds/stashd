import type { VaultItemRecord } from '../types/preservationRecord'

/**
 * UI-only fixture data. See fixtures/stashes.ts for the rules.
 * Only 4 of the 9 Vault overview Items have a full preservation record here
 * — enough to prove the real route stays general across document/physical-
 * capture/internet-video without hand-authoring a record for every fixture
 * row. `id` matches fixtures/vaultItems.ts so the overview row and record
 * are the same canonical Item; note the canonical title here can differ
 * from the overview row's fuller label (e.g. "The Sunken Archive" vs.
 * "Oculus Imperia · S3E12 — The Sunken Archive") — the overview row adds
 * Stash-ish scanning context, this page is the canonical identity.
 */
export const vaultItemRecords: VaultItemRecord[] = [
  {
    id: 'vault-4',
    title: 'Exhibit 103',
    typeLabel: 'Document',
    sourceLabel: 'File import · Discovery production 003',
    preservedLabel: 'Preserved 20 Jul 2026',
    preservation: {
      state: 'verified',
      label: 'Verified',
      summary: 'All durable assets match their recorded integrity data.',
      assetCount: 2,
      lastVerifiedLabel: '6 Aug 2026'
    },
    assets: [
      { role: 'Original', filename: 'EXHIBIT-103.pdf', detail: 'PDF · 4.2 MB', action: { label: 'Open', icon: 'i-lucide-external-link' } },
      { role: 'Extracted text', filename: 'EXHIBIT-103.txt', detail: '178 KB', derivedFrom: 'Original', action: { label: 'View', icon: 'i-lucide-eye' } }
    ],
    provenanceIntro: 'File import · Discovery production 003',
    provenanceFacts: [{ label: 'Original path', value: '/documents/EXHIBIT-103.pdf' }],
    organisedIn: ['Smith v. Widgets — Discovery', 'Deposition prep'],
    usedBy: [{ name: 'Case listening feed', kind: 'Text-to-speech podcast' }],
    history: [
      { dateLabel: '6 Aug 2026', label: 'Verified' },
      { dateLabel: '21 Jul 2026', label: 'Text extracted' },
      { dateLabel: '20 Jul 2026', label: 'Ingested' }
    ]
  },
  {
    id: 'vault-5',
    title: 'Blade Runner — Disc 1, Side A',
    typeLabel: 'Disc capture',
    sourceLabel: 'LaserDisc capture station',
    preservedLabel: 'Preserved 30 Jun 2026',
    preservation: {
      state: 'verified',
      label: 'Verified',
      summary: 'All durable assets match their recorded integrity data.',
      assetCount: 2,
      lastVerifiedLabel: '4 Aug 2026'
    },
    assets: [
      { role: 'Preservation master', filename: 'blade-runner-disc1-side-a.mkv', detail: '18.6 GB', action: { label: 'Open', icon: 'i-lucide-external-link' } },
      { role: 'Extracted audio', filename: 'blade-runner-disc1-side-a.flac', detail: '640 MB', derivedFrom: 'Preservation master', action: { label: 'Open', icon: 'i-lucide-external-link' } }
    ],
    provenanceIntro: 'LaserDisc capture station',
    provenanceFacts: [
      { label: 'Source artifact', value: 'Blade Runner — Criterion Collection' },
      { label: 'Disc', value: 'Disc 1 · Side A' }
    ],
    organisedIn: ['Film Preservation', 'Criterion Collection Captures'],
    usedBy: [],
    history: [
      { dateLabel: '4 Aug 2026', label: 'Verified' },
      { dateLabel: '30 Jun 2026', label: 'Ingested' }
    ]
  },
  {
    id: 'vault-1',
    title: 'The Sunken Archive',
    typeLabel: 'Video',
    sourceLabel: 'YouTube · @oculusimperia',
    preservedLabel: 'Preserved 6 Aug 2026',
    publishedLabel: 'Published 3 Aug 2026',
    preservation: {
      state: 'verified',
      label: 'Verified',
      summary: 'Your preserved copy is intact.',
      assetCount: 2,
      lastVerifiedLabel: '7 Aug 2026'
    },
    sourceAvailability: { label: 'No longer available from YouTube', note: 'The original video has been removed or made private upstream.' },
    assets: [
      { role: 'Preserved video', filename: 'oculus-imperia-s3e12.mkv', detail: '1.4 GB', action: { label: 'Open', icon: 'i-lucide-external-link' } },
      { role: 'Thumbnail', filename: 'oculus-imperia-s3e12-thumb.jpg', detail: '220 KB', derivedFrom: 'Source metadata', action: { label: 'View', icon: 'i-lucide-eye' } }
    ],
    provenanceIntro: 'YouTube · @oculusimperia',
    provenanceFacts: [{ label: 'Canonical source URL', value: 'youtube.com/watch?v=9f2a1c…' }],
    organisedIn: ['Oculus Imperia', 'Favourite 40k lore'],
    usedBy: [{ name: 'Oculus Imperia', kind: 'Podcast' }, { name: 'Home Jellyfin', kind: 'Jellyfin' }],
    history: [
      { dateLabel: '7 Aug 2026', label: 'Verified' },
      { dateLabel: '5 Aug 2026', label: 'Source became unavailable' },
      { dateLabel: '6 Aug 2026', label: 'Ingested' }
    ]
  },
  {
    id: 'vault-7',
    title: 'Field Log 14',
    typeLabel: 'Document',
    sourceLabel: 'File import · Antarctica archive',
    preservedLabel: 'Preserved 25 Jul 2026',
    preservation: {
      state: 'needs-attention',
      label: 'Needs attention',
      summary: 'One durable asset no longer matches its recorded integrity data.',
      assetCount: 1,
      lastVerifiedLabel: '2 Aug 2026',
      lastMismatchLabel: '7 Aug 2026'
    },
    assets: [
      { role: 'Original', filename: 'FIELD-LOG-14.pdf', detail: 'PDF · 1.1 MB', action: { label: 'Open', icon: 'i-lucide-external-link' } }
    ],
    provenanceIntro: 'File import · Antarctica archive',
    provenanceFacts: [{ label: 'Original path', value: '/documents/field-log-14.pdf' }],
    organisedIn: ['Antarctica: Ice Core Archive'],
    usedBy: [],
    history: [
      { dateLabel: '7 Aug 2026', label: 'Fixity mismatch detected' },
      { dateLabel: '2 Aug 2026', label: 'Verified' },
      { dateLabel: '25 Jul 2026', label: 'Ingested' }
    ]
  }
]
