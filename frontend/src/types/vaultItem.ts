/**
 * The Vault's canonical Item — global archive inventory, independent of
 * which Stash(es) discovered it or which Broadcast(s) use it. A given
 * preserved thing appears exactly once here even if several Stashes
 * reference it. See planning/DECISIONS.md, "Vault overview".
 *
 * Deliberately not audiovisual-only: `VaultItemType` and `VaultSourceFamily`
 * are open unions expected to grow (documents, physical-media captures,
 * scans, …), not a fixed media taxonomy.
 */
export type VaultItemType = 'video' | 'audio' | 'document' | 'photo' | 'disc-capture'
export type VaultSourceFamily = 'youtube' | 'podcast' | 'file-import' | 'physical-capture' | 'scan'

export interface VaultItemFixture {
  id: string
  title: string
  type: VaultItemType
  sourceFamily: VaultSourceFamily
  sourceLabel: string
  /** A specific source/Input within `sourceFamily` — key into the Source picker hierarchy (fixtures/vaultSources.ts). */
  sourceId: string
  /** Tailwind gradient classes; omitted falls back to a type icon — not every Item has recognizable artwork. */
  art?: string
  preservedAt: string
  sizeLabel: string
  /** Which Stashes reference this canonical Item — real membership, not just a display count, so it can be filtered. */
  stashIds: string[]
  /** How many Broadcasts currently use this canonical Item — quiet context. */
  broadcastCount: number
}
