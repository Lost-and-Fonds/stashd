import type { VaultSourceFamily } from '../types/vaultItem'

/**
 * The Source picker's hierarchy: a family (broad acquisition/source type)
 * containing specific sources/Inputs. UI-only fixture data — see
 * fixtures/stashes.ts for the rules.
 */
export interface VaultSourceDescriptor {
  id: string
  label: string
  familyKey: VaultSourceFamily
}

export const sourceFamilyLabel: Record<VaultSourceFamily, string> = {
  youtube: 'YouTube',
  podcast: 'Podcast',
  'file-import': 'File import',
  'physical-capture': 'Capture',
  scan: 'Scan'
}

export const sourceFamilyIcon: Record<VaultSourceFamily, string> = {
  youtube: 'i-lucide-youtube',
  podcast: 'i-lucide-rss',
  'file-import': 'i-lucide-folder',
  'physical-capture': 'i-lucide-disc',
  scan: 'i-lucide-image'
}

export const vaultSourceCatalog: VaultSourceDescriptor[] = [
  { id: 'oculusimperia', label: '@oculusimperia', familyKey: 'youtube' },
  { id: 'criticalrole', label: '@criticalrole', familyKey: 'youtube' },
  { id: 'gardenbirds-march', label: 'Garden Birds — March', familyKey: 'youtube' },
  { id: 'field-records-archive', label: 'Field Records Archive', familyKey: 'podcast' },
  { id: 'antarctica-archive', label: 'Antarctica archive', familyKey: 'file-import' },
  { id: 'discovery-production-003', label: 'Discovery production 003', familyKey: 'file-import' },
  { id: 'field-records-analog-archive', label: 'Field Records — Analog Archive', familyKey: 'file-import' },
  { id: 'laserdisc-capture-station', label: 'LaserDisc capture station', familyKey: 'physical-capture' },
  { id: 'contact-sheet-scans', label: 'Contact sheet scans', familyKey: 'scan' }
]
