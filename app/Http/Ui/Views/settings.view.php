<x-stashd-layout title="Settings · stashd_">
	<div x-data="settings">
		<div class="mb-6 flex items-center justify-between">
			<h1 class="text-base font-semibold text-cream">Settings</h1>
			<p class="text-[13px] text-error" x-show="error" x-text="error"></p>
		</div>

		<template x-if="loading">
			<p class="flex items-center gap-2 text-[13px] text-muted">
				<span class="h-1.5 w-1.5 rounded-full bg-amber pulse-dot"></span>
				Loading…
			</p>
		</template>

		<template x-if="!loading">
			<div class="space-y-6">
				<section class="rounded-lg border border-line bg-panel/60 p-4">
					<h2 class="text-sm font-semibold text-cream">Account</h2>
					<p class="mt-1 text-[13px] text-muted">
						<span x-text="me?.username"></span> · <span x-text="me?.role"></span>
					</p>
				</section>

				<section class="rounded-lg border border-line bg-panel/60">
					<h2 class="border-b border-line px-4 py-3 text-[13px] font-semibold text-cream">API tokens</h2>

					<div class="border-b border-line p-4" x-show="justCreatedToken">
						<p class="text-[11px] uppercase tracking-wide text-muted">New token — copy it now</p>
						<div class="mt-1 flex items-center gap-2">
							<code class="flex-1 overflow-x-auto text-[12px] text-cream" x-text="justCreatedToken"></code>
							<button type="button"
								class="shrink-0 rounded border border-line px-2 py-1 text-[12px] text-muted transition-colors hover:text-cream"
								x-on:click="copyToken(justCreatedToken)">copy</button>
						</div>
						<p class="mt-1 text-[12px] text-warn">This token will not be shown again.</p>
					</div>

					<table class="w-full text-left text-[13px]" x-show="tokens.length > 0">
						<thead>
							<tr class="text-[11px] uppercase tracking-wide text-muted">
								<th class="px-4 py-2 font-normal">Name</th>
								<th class="px-4 py-2 font-normal">Preview</th>
								<th class="px-4 py-2 font-normal">Last used</th>
								<th class="px-4 py-2 font-normal"></th>
							</tr>
						</thead>
						<tbody>
							<template x-for="token in tokens" x-bind:key="token.id">
								<tr class="border-t border-line/60">
									<td class="px-4 py-2 text-cream" x-text="token.name"></td>
									<td class="px-4 py-2 font-mono text-muted" x-text="token.token_preview"></td>
									<td class="px-4 py-2 text-muted" x-text="formatRelativeTime(token.last_used_at)"></td>
									<td class="px-4 py-2 text-right">
										<button type="button" x-on:click="revokeToken(token.id)"
											class="rounded border border-line px-2 py-1 text-[12px] text-muted transition-colors hover:text-error">revoke</button>
									</td>
								</tr>
							</template>
						</tbody>
					</table>
					<p class="px-4 py-3 text-[13px] text-muted" x-show="tokens.length === 0">No tokens yet.</p>

					<div class="flex items-center gap-2 border-t border-line p-4">
						<input type="text" x-model="newTokenName" placeholder="Token name"
							class="flex-1 rounded border border-line bg-espresso px-3 py-2 text-cream outline-none focus:border-amber"/>
						<button type="button" x-on:click="createToken()"
							x-bind:disabled="creatingToken || newTokenName.trim() === ''"
							class="rounded bg-amber px-3 py-2 text-[13px] font-semibold text-espresso transition-colors hover:bg-amber-dim disabled:opacity-60">
							Create
						</button>
					</div>
				</section>

				<section class="rounded-lg border border-line bg-panel/60">
					<h2 class="border-b border-line px-4 py-3 text-[13px] font-semibold text-cream">Media servers</h2>

					<ul class="divide-y divide-line/60" x-show="mediaServers.length > 0">
						<template x-for="server in mediaServers" x-bind:key="server.id">
							<li class="px-4 py-3">
								<div class="flex items-center justify-between gap-3">
									<div>
										<p class="text-[13px] font-semibold text-cream" x-text="server.name"></p>
										<p class="text-[12px] text-muted" x-text="server.type + ' · ' + server.base_uri"></p>
									</div>
									<span class="inline-flex items-center gap-1.5" x-bind:class="statusBadge(server.state).text">
										<span class="h-1.5 w-1.5 rounded-full" x-bind:class="[statusBadge(server.state).dot, statusBadge(server.state).pulse ? 'pulse-dot' : '']"></span>
										<span x-text="server.state"></span>
									</span>
								</div>

								<p class="mt-1 text-[12px] text-error" x-show="server.last_error" x-text="server.last_error"></p>
								<p class="mt-1 text-[12px]" x-show="testResults[server.id]"
									x-bind:class="testResults[server.id]?.ok ? 'text-success' : 'text-error'"
									x-text="testResults[server.id]?.message"></p>

								<div class="mt-2 flex flex-wrap gap-2">
									<button type="button"
										class="rounded border border-line px-2 py-1 text-[12px] text-muted transition-colors hover:text-cream disabled:opacity-50"
										x-bind:disabled="testingId === server.id"
										x-on:click="testMediaServer(server.id)">test</button>
									<button type="button"
										class="rounded border border-line px-2 py-1 text-[12px] text-muted transition-colors hover:text-error"
										x-on:click="deleteMediaServer(server.id)">delete</button>
								</div>
							</li>
						</template>
					</ul>
					<p class="px-4 py-3 text-[13px] text-muted" x-show="mediaServers.length === 0">No media servers yet.</p>

					<div class="space-y-2 border-t border-line p-4">
						<div class="flex gap-2">
							<select x-model="newMediaServerType"
								class="rounded border border-line bg-espresso px-3 py-2 text-cream outline-none focus:border-amber">
								<option value="jellyfin">Jellyfin</option>
								<option value="plex">Plex</option>
							</select>
							<input type="text" x-model="newMediaServerName" placeholder="Name"
								class="flex-1 rounded border border-line bg-espresso px-3 py-2 text-cream outline-none focus:border-amber"/>
						</div>
						<input type="text" x-model="newMediaServerBaseUri" placeholder="http://jellyfin.local:8096"
							class="w-full rounded border border-line bg-espresso px-3 py-2 text-cream outline-none focus:border-amber"/>
						<input type="password" x-model="newMediaServerToken" placeholder="API token (optional)"
							class="w-full rounded border border-line bg-espresso px-3 py-2 text-cream outline-none focus:border-amber"/>
						<button type="button" x-on:click="createMediaServer()"
							x-bind:disabled="creatingMediaServer || newMediaServerName.trim() === '' || newMediaServerBaseUri.trim() === ''"
							class="w-full rounded bg-amber px-3 py-2 text-[13px] font-semibold text-espresso transition-colors hover:bg-amber-dim disabled:opacity-60">
							Add media server
						</button>
					</div>
				</section>

				<section class="rounded-lg border border-line bg-panel/60 p-4">
					<h2 class="text-sm font-semibold text-cream">System</h2>
					<p class="mt-1 text-[13px] text-muted">
						Version <span class="text-cream" x-text="health?.version"></span> ·
						Database
						<span x-bind:class="health?.database?.writable ? 'text-success' : 'text-error'">
							<span x-text="health?.database?.writable ? 'writable' : 'not writable'"></span>
						</span>
						· Hardlinks
						<span x-bind:class="health?.storage?.vault_broadcast_hardlink ? 'text-success' : 'text-warn'">
							<span x-text="health?.storage?.vault_broadcast_hardlink ? 'supported' : 'unsupported'"></span>
						</span>
					</p>

					<table class="mt-3 w-full text-left text-[13px]">
						<thead>
							<tr class="text-[11px] uppercase tracking-wide text-muted">
								<th class="py-1 font-normal">Location</th>
								<th class="py-1 font-normal">Path</th>
								<th class="py-1 font-normal">State</th>
							</tr>
						</thead>
						<tbody>
							<template x-for="location in (health?.storage?.locations ?? [])" x-bind:key="location.key">
								<tr class="border-t border-line/60">
									<td class="py-1.5 text-cream" x-text="location.key"></td>
									<td class="py-1.5 font-mono text-muted" x-text="location.path"></td>
									<td class="py-1.5">
										<span class="inline-flex items-center gap-1.5" x-bind:class="statusBadge(location.state).text">
											<span class="h-1.5 w-1.5 rounded-full" x-bind:class="[statusBadge(location.state).dot, statusBadge(location.state).pulse ? 'pulse-dot' : '']"></span>
											<span x-text="location.state"></span>
										</span>
									</td>
								</tr>
							</template>
						</tbody>
					</table>
				</section>
			</div>
		</template>
	</div>
</x-stashd-layout>
