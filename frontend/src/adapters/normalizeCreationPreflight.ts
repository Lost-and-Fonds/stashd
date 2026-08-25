import type { BroadcastPreview } from '../api/broadcasts'
import type { StashPreflightReview } from '../api/stashes'
import type { PreflightState } from '../types/preflight'

function bytesLabel(bytes: number): string {
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`
  if (bytes < 1024 * 1024 * 1024) return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
  return `${(bytes / (1024 * 1024 * 1024)).toFixed(1)} GB`
}

export function normalizeStashPreflight(review: StashPreflightReview): PreflightState {
  const discovery = review.preflight?.discovery
  const resolved = review.preflight?.resolved_input
  const count = discovery?.estimated_item_count ?? resolved?.estimated_item_count ?? 0

  return {
    status: 'ready',
    plan: {
      itemCountLabel: `${count.toLocaleString()} items found`,
      operations: count > 0 ? [{ key: 'discovery', label: 'Items discovered', itemCount: count, storageLabel: '—', icon: 'i-lucide-list-video' }] : [],
      storage: { kind: 'unavailable' },
      notes: [
        ...(resolved?.provider_key ? [`Provider: ${resolved.provider_key}`] : []),
        'Storage estimate is not available until preservation work begins.'
      ]
    }
  }
}

export function normalizeBroadcastPreview(preview: BroadcastPreview): PreflightState {
  const operations = [
    ...(preview.hardlinked_item_count > 0 ? [{ key: 'hardlinks', label: 'Vault items linked', itemCount: preview.hardlinked_item_count, storageLabel: 'hardlinks', icon: 'i-lucide-link' }] : []),
    ...(preview.transcode_item_count > 0 ? [{ key: 'transcode', label: 'Items derived', itemCount: preview.transcode_item_count, storageLabel: 'derived', icon: 'i-lucide-repeat-2' }] : []),
    ...(preview.skipped_item_count > 0 ? [{ key: 'skipped', label: 'Items skipped', itemCount: preview.skipped_item_count, storageLabel: 'not included', icon: 'i-lucide-minus' }] : [])
  ]

  return {
    status: 'ready',
    plan: {
      itemCountLabel: `${preview.eligible_item_count.toLocaleString()} eligible items`,
      operations,
      storage: preview.vault_size_bytes > 0 ? { kind: 'estimate', label: bytesLabel(preview.vault_size_bytes) } : { kind: 'none' },
      notes: preview.skipped_item_count > 0 ? [`${preview.skipped_item_count.toLocaleString()} items are not eligible for this Broadcast.`] : []
    }
  }
}
