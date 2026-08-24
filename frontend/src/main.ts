import './assets/css/main.css'

import { createApp } from 'vue'
import ui from '@nuxt/ui/vue-plugin'

import App from './App.vue'
import router from './router'
import { startLiveUpdates, stopLiveUpdates } from './live/mercure'

const app = createApp(App)

app
  .use(router)
  .use(ui)
  .mount('#app')

window.addEventListener('pagehide', stopLiveUpdates, { once: true })
window.addEventListener('stashd:auth-required', () => {
  if (router.currentRoute.value.name !== 'login') {
    void router.push({ name: 'login', query: { return_to: router.currentRoute.value.fullPath } })
  }
  stopLiveUpdates()
})

router.isReady().then(() => {
  if (router.currentRoute.value.name !== 'login') startLiveUpdates()
})
