import type { PluginField, PluginFieldValue } from '../types/plugin-ui'

export interface PluginFieldFixture {
  section: 'General' | 'Publishing' | 'Media'
  field: PluginField
  value: PluginFieldValue
  disabled?: boolean
  error?: string
  url?: boolean
}

export const pluginFieldFixtures: PluginFieldFixture[] = [
  { section: 'General', field: { key: 'podcast_title', label: 'Podcast title', type: 'text', required: true }, value: 'Field Notes from the Archive' },
  { section: 'General', field: { key: 'language', label: 'Language', type: 'select', choices: ['en', 'de', 'fr'], required: true }, value: 'en' },
  { section: 'General', field: { key: 'plex_connection', label: 'Plex connection', type: 'text' }, value: 'Home media server', disabled: true },
  { section: 'Publishing', field: { key: 'maximum_episodes', label: 'Maximum episodes', type: 'number', description: 'Choose how many of the newest episodes should appear in the generated feed. Older episodes remain preserved in the Vault and can be included again by increasing this value.' }, value: 50 },
  { section: 'Publishing', field: { key: 'feed_url', label: 'Feed URL', type: 'text', description: 'The public address listeners use to subscribe.', required: true }, value: 'https://archive.example.test/feed.xml', url: true },
  { section: 'Media', field: { key: 'download_subtitles', label: 'Download subtitles', type: 'boolean', description: 'Preserve available caption tracks with each item.' }, value: true },
  { section: 'Media', field: { key: 'caption_languages', label: 'Caption languages', type: 'text', required: true }, value: '', error: 'Enter at least one language code.' }
]
