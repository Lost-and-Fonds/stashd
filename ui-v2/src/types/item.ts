export type ItemStatus = 'ready' | 'processing' | 'queued' | 'needs-attention'

export interface ItemFixture {
  id: string
  stashId: string
  title: string
  publishedAt: string
  duration: string
  sizeLabel: string
  status: ItemStatus
  /** Tailwind gradient classes for the thumbnail; omitted falls back to a plain tile. */
  art?: string
  /** Only meaningful while status is 'processing'. */
  progressPercent?: number | null
  progressStage?: string
}
