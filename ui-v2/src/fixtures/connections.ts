export type MediaServerConnectionType = 'jellyfin' | 'plex'

export interface ConnectionFixture {
  id: string
  type: MediaServerConnectionType
  name: string
}

/**
 * UI-only fixture data. See fixtures/stashes.ts for the rules.
 * Minimal placeholder for the New Broadcast workflow's Connection picker —
 * not a stand-in for the real Connections page, which remains unbuilt
 * (planning/INTEGRATION-GAPS.md).
 */
export const connectionFixtures: ConnectionFixture[] = [
  { id: 'connection-1', type: 'jellyfin', name: 'Jellyfin — homelab' },
  { id: 'connection-2', type: 'plex', name: 'Plex — homelab' }
]
