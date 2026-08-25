import { createRouter, createWebHistory } from 'vue-router'

import BroadcastCreatePage from '../pages/BroadcastCreatePage.vue'
import BroadcastDetailPage from '../pages/BroadcastDetailPage.vue'
import BroadcastSourceConfigurationPage from '../pages/BroadcastSourceConfigurationPage.vue'
import BroadcastsPage from '../pages/BroadcastsPage.vue'
import AddInputPage from '../pages/AddInputPage.vue'
import ConnectionsPage from '../pages/ConnectionsPage.vue'
import InputsPage from '../pages/InputsPage.vue'
import LoginPage from '../pages/LoginPage.vue'
import InputConfigurationPage from '../pages/InputConfigurationPage.vue'
import NotFoundPage from '../pages/NotFoundPage.vue'
import SettingsPage from '../pages/SettingsPage.vue'
import StashCreatePage from '../pages/StashCreatePage.vue'
import StashDetailPage from '../pages/StashDetailPage.vue'
import StashesPage from '../pages/StashesPage.vue'
import StatusPage from '../pages/StatusPage.vue'
import VaultItemPage from '../pages/VaultItemPage.vue'
import VaultPage from '../pages/VaultPage.vue'
import { currentUser } from '../api/auth'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/', redirect: '/stashes' },
    { path: '/login', name: 'login', component: LoginPage },
    { path: '/status', name: 'status', component: StatusPage },
    { path: '/stashes', name: 'stashes', component: StashesPage },
    { path: '/stashes/new', name: 'stash-create', component: StashCreatePage },
    { path: '/stashes/:id', name: 'stash-detail', component: StashDetailPage },
    { path: '/stashes/:stashId/inputs/new', name: 'input-add', component: AddInputPage },
    { path: '/stashes/:stashId/broadcasts/new', name: 'broadcast-create', component: BroadcastCreatePage },
    { path: '/broadcasts/:broadcastId', name: 'broadcast-detail', component: BroadcastDetailPage },
    { path: '/broadcasts/:broadcastId/sources/:sourceId/configure', name: 'broadcast-source-configure', component: BroadcastSourceConfigurationPage },
    { path: '/inputs', name: 'inputs', component: InputsPage },
    { path: '/stashes/:stashId/inputs/:inputId/configure', name: 'input-configure', component: InputConfigurationPage },
    { path: '/vault', name: 'vault', component: VaultPage },
    { path: '/vault/:itemId', name: 'vault-item', component: VaultItemPage },
    { path: '/settings/connections', name: 'connections', component: ConnectionsPage },
    { path: '/connections', redirect: { name: 'connections' } },
    { path: '/secrets', redirect: { name: 'settings' } },
    { path: '/broadcasts', name: 'broadcasts', component: BroadcastsPage },
    { path: '/settings', name: 'settings', component: SettingsPage },
    { path: '/:pathMatch(.*)*', name: 'not-found', component: NotFoundPage }
  ]
})

router.beforeEach(async (to) => {
  if (to.name === 'login') {
    try {
      if (await currentUser()) return { name: 'stashes' }
    } catch {
      // The login screen remains available when the auth check itself fails.
    }
    return true
  }

  try {
    if (await currentUser()) return true
  } catch {
    return true
  }

  return {
    name: 'login',
    query: { return_to: to.fullPath }
  }
})

export default router
