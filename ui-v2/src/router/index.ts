import { createRouter, createWebHistory } from 'vue-router'

import BroadcastCreatePage from '../pages/BroadcastCreatePage.vue'
import BroadcastsPage from '../pages/BroadcastsPage.vue'
import ConnectionsPage from '../pages/ConnectionsPage.vue'
import DesignPage from '../pages/DesignPage.vue'
import InputsPage from '../pages/InputsPage.vue'
import NotFoundPage from '../pages/NotFoundPage.vue'
import PluginFieldGalleryPage from '../pages/PluginFieldGalleryPage.vue'
import SecretsPage from '../pages/SecretsPage.vue'
import SettingsPage from '../pages/SettingsPage.vue'
import StashCreatePage from '../pages/StashCreatePage.vue'
import StashDetailConceptsPage from '../pages/StashDetailConceptsPage.vue'
import StashDetailPage from '../pages/StashDetailPage.vue'
import StashesPage from '../pages/StashesPage.vue'
import StatusConceptPage from '../pages/StatusConceptPage.vue'
import StatusPage from '../pages/StatusPage.vue'
import VaultItemConceptPage from '../pages/VaultItemConceptPage.vue'
import VaultItemPage from '../pages/VaultItemPage.vue'
import VaultPage from '../pages/VaultPage.vue'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/', redirect: '/stashes' },
    { path: '/status', name: 'status', component: StatusPage },
    { path: '/stashes', name: 'stashes', component: StashesPage },
    { path: '/stashes/new', name: 'stash-create', component: StashCreatePage },
    { path: '/stashes/:id', name: 'stash-detail', component: StashDetailPage },
    { path: '/stashes/:stashId/broadcasts/new', name: 'broadcast-create', component: BroadcastCreatePage },
    { path: '/inputs', name: 'inputs', component: InputsPage },
    { path: '/vault', name: 'vault', component: VaultPage },
    { path: '/vault/:itemId', name: 'vault-item', component: VaultItemPage },
    { path: '/connections', name: 'connections', component: ConnectionsPage },
    { path: '/secrets', name: 'secrets', component: SecretsPage },
    { path: '/broadcasts', name: 'broadcasts', component: BroadcastsPage },
    { path: '/settings', name: 'settings', component: SettingsPage },
    { path: '/design', name: 'design', component: DesignPage },
    { path: '/design/plugin-fields', name: 'design-plugin-fields', component: PluginFieldGalleryPage },
    { path: '/design/stash-detail-concepts', name: 'design-stash-detail-concepts', component: StashDetailConceptsPage },
    { path: '/design/vault-item-concept', name: 'design-vault-item-concept', component: VaultItemConceptPage },
    { path: '/design/status-concept', name: 'design-status-concept', component: StatusConceptPage },
    { path: '/:pathMatch(.*)*', name: 'not-found', component: NotFoundPage }
  ]
})

export default router
