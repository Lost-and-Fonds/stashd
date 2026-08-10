import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import ui from '@nuxt/ui/vite'

export default defineConfig({
  plugins: [
    vue(),
    ui({
      // ponytail: no light/dark toggle yet, so skip the color-mode runtime
      // and let the static `class="dark"` in index.html decide the theme.
      colorMode: false,
      ui: {
        colors: {
          primary: 'amber',
          neutral: 'stone'
        }
      }
    })
  ]
})
