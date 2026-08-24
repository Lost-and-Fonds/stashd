import type { InputOptionsApiResource } from '../types/input'

// Matches the `input_options` resource Core returns for the current YouTube
// plugin. The actual API only exposes it after resolving/creating an Input,
// so this isolated design surface keeps a stable response fixture for now.
export const youtubeInputOptions: InputOptionsApiResource = {
  input_options: [
    { key: 'include_shorts', label: 'Include Shorts', type: 'bool', default: false, choices: null, applicable_input_types: [], description: null, required: false },
    { key: 'include_live', label: 'Include live and premieres', type: 'bool', default: false, choices: null, applicable_input_types: [], description: null, required: false },
    { key: 'include_captions', label: 'Include captions', type: 'bool', default: false, choices: null, applicable_input_types: [], description: null, required: false },
    { key: 'caption_languages', label: 'Caption languages', type: 'text', default: 'en', choices: null, applicable_input_types: [], description: null, required: false }
  ],
  options: {
    provider: {
      include_shorts: true,
      include_captions: true
    }
  }
}
