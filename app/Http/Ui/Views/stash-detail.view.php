<?php
/** @var string $id */
?>
<x-stashd-layout title="Stash · stashd_">
	<div x-data="stashDetail('<?= htmlspecialchars($id) ?>')">
		<div class="mb-6 flex items-center justify-between">
			<h1 class="text-base font-semibold text-cream">Stash</h1>
			<div class="text-right text-[13px]">
				<p class="text-error" x-show="error" x-text="error"></p>
				<p class="text-success" x-show="actionFeedback" x-text="actionFeedback"></p>
			</div>
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
					<div class="flex items-center justify-between gap-3">
						<div class="flex items-center gap-2">
							<img x-show="stash?.icon_uri" x-bind:src="stash?.icon_uri" class="h-8 w-8 rounded-full object-cover" alt=""/>
							<span x-show="!stash?.icon_uri" class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-espresso text-[13px] text-muted" x-text="stash?.name?.charAt(0).toUpperCase()"></span>
							<h2 class="text-sm font-semibold text-cream" x-text="stash?.name"></h2>
						</div>
						<div class="flex items-center gap-2">
							<span class="inline-flex items-center gap-1.5" x-bind:class="statusBadge(stash?.state).text">
								<span class="h-1.5 w-1.5 rounded-full" x-bind:class="[statusBadge(stash?.state).dot, statusBadge(stash?.state).pulse ? 'pulse-dot' : '']"></span>
								<span x-text="stash?.state"></span>
							</span>
							<button type="button" x-on:click="startEdit()"
								class="rounded border border-line px-2 py-1 text-[12px] text-muted transition-colors hover:text-cream">Edit</button>
							<button type="button" x-on:click="startDelete()"
								class="rounded border border-line px-2 py-1 text-[12px] text-error transition-colors hover:bg-error/10">Delete</button>
						</div>
					</div>
					<p class="mt-2 text-[13px] text-muted">
						<span x-text="stash?.sync_mode"></span>
						·
						<span x-text="stash?.download_policy.replace(/_/g, ' ')"></span>
						·
						<span x-text="stash?.organization_mode"></span>
					</p>
				</section>

				<section class="rounded-lg border border-line bg-panel/60">
					<div class="flex items-center justify-between border-b border-line px-4 py-3">
						<h2 class="text-[13px] font-semibold text-cream">Inputs</h2>
						<button type="button" x-on:click="openAddInput()"
							class="rounded border border-line px-2 py-1 text-[12px] text-muted transition-colors hover:text-cream">+ Add input</button>
					</div>
					<table class="w-full text-left text-[13px]" x-show="inputs.length > 0">
						<thead>
							<tr class="text-[11px] uppercase tracking-wide text-muted">
								<th class="px-4 py-2 font-normal">Title</th>
								<th class="px-4 py-2 font-normal">Source</th>
								<th class="px-4 py-2 font-normal">State</th>
								<th class="px-4 py-2 font-normal">Sync</th>
								<th class="px-4 py-2 font-normal">Filters</th>
							</tr>
						</thead>
						<tbody>
							<template x-for="input in inputs" x-bind:key="input.id">
								<tr class="border-t border-line/60">
									<td class="px-4 py-2 text-cream" x-text="input.title ?? input.provider_input_id"></td>
									<td class="px-4 py-2 font-mono text-muted" x-text="input.source_uri"></td>
									<td class="px-4 py-2">
										<span class="inline-flex items-center gap-1.5" x-bind:class="statusBadge(input.state).text">
											<span class="h-1.5 w-1.5 rounded-full" x-bind:class="[statusBadge(input.state).dot, statusBadge(input.state).pulse ? 'pulse-dot' : '']"></span>
											<span x-text="input.state"></span>
										</span>
									</td>
									<td class="px-4 py-2 text-muted" x-text="input.sync_mode ?? '—'"></td>
									<td class="px-4 py-2">
										<template x-if="editingInputFiltersId !== input.id">
											<button type="button" class="text-muted transition-colors hover:text-cream"
												x-on:click="startEditInputFilters(input)">
												<span x-show="input.options?.title_regex_include || input.options?.title_regex_exclude">edit filters</span>
												<span x-show="!(input.options?.title_regex_include || input.options?.title_regex_exclude)">+ add filter</span>
											</button>
										</template>
										<template x-if="editingInputFiltersId === input.id">
											<div class="w-56 space-y-1.5 rounded border border-line bg-espresso p-2">
												<input type="text" x-model="editInputTitleRegexInclude" placeholder="Include title regex"
													class="w-full rounded border border-line bg-panel px-2 py-1 text-[12px] text-cream outline-none focus:border-amber"/>
												<input type="text" x-model="editInputTitleRegexExclude" placeholder="Exclude title regex"
													class="w-full rounded border border-line bg-panel px-2 py-1 text-[12px] text-cream outline-none focus:border-amber"/>
												<template x-for="option in input.input_options" x-bind:key="option.key">
													<label class="flex items-center gap-2 text-[12px] text-muted" x-show="option.type === 'bool'">
														<input type="checkbox" x-model="editInputProviderOptions[option.key]" class="rounded border-line"/>
														<span x-text="option.label"></span>
													</label>
												</template>
												<p class="text-[11px] text-muted">Applies to existing and future items.</p>
												<div class="flex items-center gap-2">
													<button type="button" x-on:click="saveInputFilters(input.id)"
														x-bind:disabled="savingInputFilters === input.id"
														class="rounded border border-line px-2 py-1 text-[12px] text-muted transition-colors hover:text-cream disabled:opacity-60">
														Save
													</button>
													<button type="button" x-on:click="cancelEditInputFilters()"
														class="text-[12px] text-muted transition-colors hover:text-cream">
														Cancel
													</button>
												</div>
											</div>
										</template>
									</td>
								</tr>
							</template>
						</tbody>
					</table>
					<div class="px-4 py-3 text-[13px] text-muted" x-show="inputs.length === 0">
						No inputs configured.
						<button type="button" x-on:click="openAddInput()" class="text-amber transition-colors hover:text-amber-dim">+ Add input</button>
					</div>
				</section>

				<section class="rounded-lg border border-line bg-panel/60">
					<div class="border-b border-line px-4 py-3">
						<h2 class="text-[13px] font-semibold text-cream">Broadcasts</h2>
						<p class="mt-0.5 text-[12px] text-muted">Generated views of this stash, with live build status.</p>
					</div>
					<p class="px-4 py-3 text-[13px] text-muted" x-show="broadcasts.length === 0">No broadcasts yet.</p>
					<ul class="divide-y divide-line/60" x-show="broadcasts.length > 0">
						<template x-for="broadcast in broadcasts" x-bind:key="broadcast.id">
							<li class="px-4 py-3">
								<div class="flex items-center justify-between gap-3">
									<div>
										<p class="text-[13px] font-semibold text-cream" x-text="broadcast.name"></p>
										<p class="text-[12px] text-muted" x-text="broadcast.type.replace(/_/g, ' ')"></p>
									</div>
									<span class="inline-flex items-center gap-1.5" x-bind:class="statusBadge(broadcast.state).text">
										<span class="h-1.5 w-1.5 rounded-full" x-bind:class="[statusBadge(broadcast.state).dot, statusBadge(broadcast.state).pulse ? 'pulse-dot' : '']"></span>
										<span x-text="broadcast.state"></span>
									</span>
								</div>

								<p class="mt-1 text-[12px] text-error" x-show="broadcast.last_error" x-text="broadcast.last_error"></p>

								<div class="mt-3 rounded border border-line bg-espresso p-3">
									<p class="flex items-center gap-2 text-[13px] text-cream">
										<span class="h-1.5 w-1.5 shrink-0 rounded-full"
											x-bind:class="broadcastJobFor(broadcast.id) || broadcastTranscodeJobs(broadcast).length > 0 ? 'bg-amber pulse-dot' : statusBadge(broadcast.state).dot"></span>
										<span x-text="broadcastStatus(broadcast)"></span>
									</p>
									<p class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[12px] text-muted">
										<span><strong class="font-medium text-cream" x-text="broadcastReadyItemCount(broadcast)"></strong> published</span>
										<span><strong class="font-medium text-cream" x-text="broadcast.impact?.eligible_item_count ?? 0"></strong> ready in Vault</span>
										<span x-show="(broadcast.impact?.skipped_item_count ?? 0) > 0"><strong class="font-medium text-cream" x-text="broadcast.impact?.skipped_item_count"></strong> waiting</span>
										<span x-show="broadcastProblemItems(broadcast).length > 0"><strong class="font-medium text-warn" x-text="broadcastProblemItems(broadcast).length"></strong> need attention</span>
										<span x-text="formatBytes(broadcast.impact?.vault_size_bytes) + ' in Vault'"></span>
									</p>

									<template x-if="broadcastJobFor(broadcast.id)?.state === 'processing'">
									<div class="mt-2 flex items-center gap-2">
										<span class="h-1.5 w-1.5 shrink-0 rounded-full bg-amber pulse-dot"></span>
										<span class="shrink-0 text-[11px] text-muted" x-text="broadcastJobFor(broadcast.id)?.progress_label ?? 'Building'"></span>
										<template x-if="broadcastJobFor(broadcast.id)?.progress_percent === null">
											<div class="h-1.5 flex-1 overflow-hidden rounded-full bg-espresso">
												<div class="h-full w-1/3 rounded-full bg-amber animate-pulse"></div>
											</div>
										</template>
										<template x-if="broadcastJobFor(broadcast.id)?.progress_percent !== null">
											<div class="contents">
												<div class="h-1.5 flex-1 rounded-full bg-espresso">
													<div class="h-1.5 rounded-full bg-amber transition-[width] duration-200" x-bind:style="'width: ' + broadcastJobFor(broadcast.id).progress_percent + '%'"></div>
												</div>
												<span class="shrink-0 text-[11px] text-muted" x-text="Math.round(broadcastJobFor(broadcast.id).progress_percent) + '%'"></span>
											</div>
										</template>
									</div>
									</template>
								</div>

								<div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1" x-show="broadcast.items.length > 0">
									<template x-for="row in broadcastItemStateCounts(broadcast)" x-bind:key="row.state">
										<span class="inline-flex items-center gap-1 text-[11px]" x-bind:class="statusBadge(row.state).text">
											<span class="h-1.5 w-1.5 rounded-full" x-bind:class="[statusBadge(row.state).dot, statusBadge(row.state).pulse ? 'pulse-dot' : '']"></span>
											<span x-text="row.count + ' ' + row.state"></span>
										</span>
									</template>
								</div>

								<details class="mt-3" x-show="broadcastProblemItems(broadcast).length > 0">
									<summary class="cursor-pointer list-none text-[12px] text-warn transition-colors hover:text-cream [&::-webkit-details-marker]:hidden"
										x-text="broadcastProblemItems(broadcast).length + ' item' + (broadcastProblemItems(broadcast).length === 1 ? '' : 's') + ' need attention'"></summary>
								<ul class="mt-2 max-h-56 space-y-1 overflow-y-auto">
									<template x-for="item in broadcastProblemItems(broadcast)" x-bind:key="item.id">
										<li class="rounded border border-line bg-espresso px-2 py-1 text-[11px]">
											<div class="flex items-center gap-2">
												<span class="h-1.5 w-1.5 shrink-0 rounded-full" x-bind:class="[statusBadge(item.state).dot, statusBadge(item.state).pulse ? 'pulse-dot' : '']"></span>
												<a class="truncate text-cream transition-colors hover:text-amber" x-bind:href="'/vault/' + item.media_item_id" x-text="item.media_item?.title ?? item.media_item_id"></a>
												<span class="shrink-0" x-bind:class="statusBadge(item.state).text" x-text="item.state"></span>
											</div>
											<p class="mt-0.5 truncate text-muted">
												<span x-text="item.last_error ?? '—'"></span> — <span x-text="broadcastProblemItemHint(item)"></span>
											</p>
										</li>
									</template>
								</ul>
								</details>

								<details class="mt-3 text-[12px] text-muted" x-show="broadcast.impact">
									<summary class="cursor-pointer list-none transition-colors hover:text-cream [&::-webkit-details-marker]:hidden">Storage details</summary>
								<div class="mt-2 rounded border border-line bg-espresso p-2">
									<p class="text-[12px] text-muted">
										<span x-text="broadcast.impact?.eligible_item_count"></span> item<span x-show="broadcast.impact?.eligible_item_count !== 1">s</span> published
										(<span x-text="formatBytes(broadcast.impact?.vault_size_bytes)"></span> already in the Vault).
									</p>
									<p class="mt-1 text-[12px] text-muted" x-show="(broadcast.impact?.skipped_item_count ?? 0) > 0">
										<span x-text="broadcast.impact?.skipped_item_count"></span> item<span x-show="broadcast.impact?.skipped_item_count !== 1">s</span> skipped — not yet downloaded, removed, or missing from the Vault.
									</p>
									<p class="mt-1 text-[12px] text-muted" x-show="(broadcast.impact?.hardlinked_item_count ?? 0) > 0">
										<span x-text="broadcast.impact?.hardlinked_item_count"></span> published via hardlink — ~0 extra space.
									</p>
									<p class="mt-1 text-[12px] text-warn" x-show="(broadcast.impact?.transcode_item_count ?? 0) > 0">
										<span x-text="broadcast.impact?.transcode_item_count"></span> transcoded — extra space beyond the Vault original.
									</p>
								</div>
								</details>

								<div class="mt-2 flex flex-wrap gap-2">
									<button type="button"
									class="inline-flex items-center gap-1.5 rounded bg-amber px-3 py-1.5 text-[12px] font-semibold text-espresso transition-colors hover:bg-amber-dim disabled:opacity-50"
									x-bind:disabled="actionPending === broadcast.id + ':rebuild' || broadcastJobFor(broadcast.id)"
									x-on:click="runBroadcastAction(broadcast.id, 'rebuild')">
										<span x-show="actionPending === broadcast.id + ':rebuild'" class="h-1.5 w-1.5 rounded-full bg-amber pulse-dot"></span>
									<span x-text="broadcast.last_built_at ? 'Rebuild' : 'Build now'"></span>
								</button>
								<details class="relative text-[12px] text-muted">
									<summary class="cursor-pointer list-none rounded border border-line px-2 py-1.5 transition-colors hover:text-cream [&::-webkit-details-marker]:hidden">Maintenance</summary>
									<div class="mt-2 flex flex-wrap gap-2">
									<button type="button"
										class="inline-flex items-center gap-1.5 rounded border border-line px-2 py-1 text-[12px] text-muted transition-colors hover:text-cream disabled:opacity-50"
										x-bind:disabled="actionPending === broadcast.id + ':verify'"
										x-on:click="runBroadcastAction(broadcast.id, 'verify')">
										<span x-show="actionPending === broadcast.id + ':verify'" class="h-1.5 w-1.5 rounded-full bg-amber pulse-dot"></span>
										verify
									</button>
									<button type="button"
										class="inline-flex items-center gap-1.5 rounded border border-line px-2 py-1 text-[12px] text-muted transition-colors hover:text-cream disabled:opacity-50"
										x-bind:disabled="actionPending === broadcast.id + ':prune'"
										x-on:click="runBroadcastAction(broadcast.id, 'prune')">
										<span x-show="actionPending === broadcast.id + ':prune'" class="h-1.5 w-1.5 rounded-full bg-amber pulse-dot"></span>
										prune
									</button>
									<button type="button"
										class="inline-flex items-center gap-1.5 rounded border border-line px-2 py-1 text-[12px] text-muted transition-colors hover:text-cream disabled:opacity-50"
										x-show="broadcast.plugin_actions?.some(action => action.intent === 'rotate_token')"
										x-bind:disabled="actionPending === broadcast.id + ':rotate_token'"
										x-on:click="runBroadcastAction(broadcast.id, 'rotate_token')">
										<span x-show="actionPending === broadcast.id + ':rotate_token'" class="h-1.5 w-1.5 rounded-full bg-amber pulse-dot"></span>
										rotate token
									</button>
									<button type="button"
										class="inline-flex items-center gap-1.5 rounded border border-error/60 px-2 py-1 text-[12px] text-error transition-colors hover:border-error hover:text-cream disabled:opacity-50"
										x-bind:disabled="actionPending === broadcast.id + ':delete' || broadcastJobFor(broadcast.id)"
										x-on:click="deleteBroadcast(broadcast)">
										<span x-show="actionPending === broadcast.id + ':delete'" class="h-1.5 w-1.5 rounded-full bg-amber pulse-dot"></span>
										<span x-show="actionPending !== broadcast.id + ':delete'">delete</span>
										<span x-show="actionPending === broadcast.id + ':delete'">queueing…</span>
									</button>
									</div>
								</details>
								</div>

									<div class="mt-3 rounded border border-line bg-espresso p-2" x-show="broadcastDetailField(broadcast, 'url')">
									<p class="text-[11px] uppercase tracking-wide text-muted">Private feed URL</p>
									<div class="mt-1 flex items-center gap-2">
										<code class="flex-1 overflow-x-auto text-[12px] text-cream" x-text="broadcastDetailField(broadcast, 'url')"></code>
										<button type="button"
											class="shrink-0 rounded border border-line px-2 py-1 text-[12px] text-muted transition-colors hover:text-cream"
											x-on:click="copyFeedUrl(broadcastDetailField(broadcast, 'url'))">copy</button>
									</div>
									<p class="mt-1 text-[12px] text-warn">Anyone with this link can listen — treat it like a password.</p>
								</div>

								<details class="mt-3 text-[12px] text-muted">
									<summary class="cursor-pointer list-none transition-colors hover:text-cream [&::-webkit-details-marker]:hidden">Settings</summary>
								<div class="mt-2 rounded border border-line bg-espresso p-2"
									x-init="ensureDestinationPathDraft(broadcast)">
									<p class="text-[11px] uppercase tracking-wide text-muted">Destination path</p>
									<p class="mt-1 text-[12px] text-muted">Where this broadcast's files are written. Stashd creates and exclusively manages one folder named after the broadcast directly under this path — it never touches anything else already there. Leave blank for the default location inside the Stashd media volume.</p>
									<div class="mt-2 flex items-center gap-2">
										<input type="text" placeholder="/mnt/nas/media/TV"
											x-model="destinationPathDrafts[broadcast.id]"
											class="flex-1 rounded border border-line bg-panel px-2 py-1 text-[12px] text-cream outline-none focus:border-amber"/>
										<button type="button" x-on:click="saveDestinationPath(broadcast.id)"
											x-bind:disabled="savingDestinationPath === broadcast.id"
											class="shrink-0 rounded border border-line px-2 py-1 text-[12px] text-muted transition-colors hover:text-cream disabled:opacity-60">
											Save
										</button>
									</div>
								</div>

								<template x-if="isSeriesBroadcastType(broadcast.type) && inputs.length > 0">
									<div class="mt-3 rounded border border-line bg-espresso p-2"
										x-init="ensureSeasonMappingDraft(broadcast)">
										<p class="text-[11px] uppercase tracking-wide text-muted">Season mapping</p>
										<p class="mt-1 text-[12px] text-muted">Assign each input to a season. Leave blank for the default (Season 01).</p>
										<div class="mt-2 space-y-1">
											<template x-for="input in inputs" x-bind:key="input.id">
												<div class="flex items-center gap-2">
													<span class="flex-1 truncate text-[12px] text-cream" x-text="input.title ?? input.provider_input_id"></span>
													<input type="number" min="1" placeholder="default"
														x-model="seasonMappingDrafts[broadcast.id][input.id]"
														class="w-20 rounded border border-line bg-panel px-2 py-1 text-[12px] text-cream outline-none focus:border-amber"/>
												</div>
											</template>
										</div>
										<button type="button" x-on:click="saveSeasonMapping(broadcast.id)"
											x-bind:disabled="savingSeasonMapping === broadcast.id"
											class="mt-2 rounded border border-line px-2 py-1 text-[12px] text-muted transition-colors hover:text-cream disabled:opacity-60">
											Save season mapping
										</button>
									</div>
								</template>
								</details>
							</li>
						</template>
					</ul>

					<details class="border-t border-line p-4">
						<summary class="cursor-pointer list-none text-[13px] font-semibold text-amber transition-colors hover:text-amber-dim [&::-webkit-details-marker]:hidden">+ Add broadcast</summary>
					<div class="mt-3">
						<div class="flex items-center gap-2">
							<select x-model="newBroadcastType" x-on:change="onBroadcastTypeChanged()"
								class="rounded border border-line bg-espresso px-3 py-2 text-cream outline-none focus:border-amber">
								<template x-for="plugin in broadcastPlugins" x-bind:key="plugin.key">
									<option x-bind:value="plugin.key" x-text="plugin.label"></option>
								</template>
							</select>
							<input type="text" x-model="newBroadcastName" placeholder="Name (optional)"
								class="flex-1 rounded border border-line bg-espresso px-3 py-2 text-cream outline-none focus:border-amber"/>
							<button type="button" x-show="!broadcastPreview" x-on:click="previewBroadcastCreation()"
								x-bind:disabled="loadingBroadcastPreview"
								class="shrink-0 rounded bg-amber px-3 py-2 text-[13px] font-semibold text-espresso transition-colors hover:bg-amber-dim disabled:opacity-60">
								<span x-show="loadingBroadcastPreview">Loading…</span>
								<span x-show="!loadingBroadcastPreview">Preview</span>
							</button>
						</div>

						<input type="text" x-model="newBroadcastDestinationPath" placeholder="Destination path (optional) — e.g. /mnt/nas/media/TV"
							class="mt-2 w-full rounded border border-line bg-espresso px-3 py-2 text-cream outline-none focus:border-amber"/>

						<p class="mt-2 text-[12px] text-muted" x-show="isSeriesBroadcastType(newBroadcastType) && inputs.length > 1">
							Multiple inputs go into Season 01 by default — you can map each one to its own season from this broadcast's card once it's created.
						</p>

						<div class="mt-3 rounded border border-line bg-espresso p-3" x-show="broadcastPreview">
							<p class="text-[11px] uppercase tracking-wide text-muted">What this will do</p>
							<p class="mt-1 text-[12px] text-cream">
								<span x-text="broadcastPreview?.eligible_item_count"></span> item<span x-show="broadcastPreview?.eligible_item_count !== 1">s</span> will be published
								(<span x-text="formatBytes(broadcastPreview?.vault_size_bytes)"></span> already in the Vault).
							</p>
							<p class="mt-1 text-[12px] text-muted" x-show="(broadcastPreview?.skipped_item_count ?? 0) > 0">
								<span x-text="broadcastPreview?.skipped_item_count"></span> item<span x-show="broadcastPreview?.skipped_item_count !== 1">s</span> skipped — not yet downloaded, removed, or missing from the Vault.
							</p>
							<p class="mt-1 text-[12px] text-muted" x-show="(broadcastPreview?.hardlinked_item_count ?? 0) > 0">
								<span x-text="broadcastPreview?.hardlinked_item_count"></span> published via hardlink — ~0 extra space.
							</p>
							<p class="mt-1 text-[12px] text-warn" x-show="(broadcastPreview?.transcode_item_count ?? 0) > 0">
								<span x-text="broadcastPreview?.transcode_item_count"></span> will be transcoded — extra space, exact size unknown until it happens.
							</p>
							<div class="mt-2 flex items-center gap-2">
								<button type="button" x-on:click="cancelBroadcastPreview()"
									class="rounded border border-line px-3 py-2 text-[13px] text-muted transition-colors hover:text-cream">
									Cancel
								</button>
								<button type="button" x-on:click="createBroadcast()"
									x-bind:disabled="creatingBroadcast"
									class="rounded bg-amber px-3 py-2 text-[13px] font-semibold text-espresso transition-colors hover:bg-amber-dim disabled:opacity-60">
									Create broadcast
								</button>
							</div>
						</div>

						<div class="mt-3 rounded border border-warn/40 bg-warn/10 p-3" x-show="broadcastPolicyMismatchMessage()">
							<p class="text-[12px] text-warn" x-text="broadcastPolicyMismatchMessage()"></p>
							<p class="mt-1 text-[12px] text-muted">It'll still be created — there just won't be anything to publish yet.</p>
							<div class="mt-2 flex items-center gap-2">
								<select x-model="compatibleDownloadPolicyChoice"
									class="rounded border border-line bg-espresso px-2 py-1 text-[12px] text-cream outline-none focus:border-amber">
									<template x-for="policy in compatibleDownloadPolicies()" x-bind:key="policy">
										<option x-bind:value="policy" x-text="policy.replace(/_/g, ' ')"></option>
									</template>
								</select>
								<button type="button" x-on:click="updateStashDownloadPolicyTo(compatibleDownloadPolicyChoice)"
									x-bind:disabled="updatingDownloadPolicy"
									class="rounded border border-line px-2 py-1 text-[12px] text-muted transition-colors hover:text-cream disabled:opacity-60">
									Update download policy
								</button>
							</div>
						</div>
					</div>
					</details>
				</section>

				<section class="rounded-lg border border-line bg-panel/60">
					<div class="flex items-center justify-between border-b border-line px-4 py-3">
						<h2 class="text-[13px] font-semibold text-cream">Items</h2>
						<div class="flex flex-wrap items-center gap-x-1 text-[12px] text-muted" x-show="itemsTotal > 0">
							<button type="button" class="transition-colors hover:text-cream" x-bind:class="itemStatusFilter === 'all' ? 'text-cream' : ''" x-on:click="itemStatusFilter = 'all'">
								<span x-text="itemsTotal"></span> item<span x-show="itemsTotal !== 1">s</span>
							</button>
							<template x-for="chip in itemStatusSummary()" x-bind:key="chip.filter">
								<span>·
									<button type="button" class="transition-colors hover:text-cream" x-bind:class="itemStatusFilter === chip.filter ? 'text-cream' : ''" x-on:click="itemStatusFilter = chip.filter" x-text="chip.label"></button>
								</span>
							</template>
							<button type="button" class="ml-1 rounded border border-line px-2 py-0.5 text-muted transition-colors hover:text-cream disabled:opacity-50"
								x-bind:disabled="actionPending === 'check-new'"
								x-on:click="checkForNewItems()">
								<span x-show="actionPending !== 'check-new'">check for new items</span>
								<span x-show="actionPending === 'check-new'">checking…</span>
							</button>
							<button type="button" class="ml-1 rounded border border-line px-2 py-0.5 text-muted transition-colors hover:text-cream disabled:opacity-50"
								x-bind:disabled="actionPending === 'retry-all'"
								x-on:click="retryAllFailed()">
								<span x-show="actionPending !== 'retry-all'">retry all failed</span>
								<span x-show="actionPending === 'retry-all'">queueing…</span>
							</button>
							<button type="button" class="ml-1 rounded border border-line px-2 py-0.5 text-muted transition-colors hover:text-cream" x-show="ignoredItemCount() > 0"
								x-on:click="showIgnored = !showIgnored">
								<span x-show="!showIgnored"><span x-text="ignoredItemCount()"></span> ignored — show</span>
								<span x-show="showIgnored">hide ignored</span>
							</button>
						</div>
						<p class="text-[12px] text-muted" x-show="itemsTotal === 0">No items</p>
					</div>

					<div class="border-b border-line px-4 py-3" x-show="activeDownloadJobs().length > 0">
						<div class="flex items-center gap-2">
							<span class="h-1.5 w-1.5 shrink-0 rounded-full bg-amber pulse-dot"></span>
							<span class="shrink-0 text-[12px] text-cream" x-text="overallDownloadLabel()"></span>
							<div class="h-1.5 flex-1 rounded-full bg-espresso">
								<div class="h-1.5 rounded-full bg-amber transition-[width] duration-200" x-bind:style="'width: ' + overallDownloadProgress() + '%'"></div>
							</div>
							<span class="shrink-0 text-[11px] text-muted" x-text="Math.round(overallDownloadProgress()) + '%'"></span>
						</div>
					</div>

					<div class="flex flex-wrap items-center gap-2 border-b border-line px-4 py-2" x-show="itemsTotal > 0">
						<input type="text" x-model="itemSearch" placeholder="Search title…"
							class="w-40 rounded border border-line bg-espresso px-2 py-1 text-[12px] text-cream outline-none focus:border-amber"/>
						<select x-model="itemStatusFilter"
							class="rounded border border-line bg-espresso px-2 py-1 text-[12px] text-cream outline-none focus:border-amber">
							<option value="all">All statuses</option>
							<template x-for="status in itemStatusOptions()" x-bind:key="status">
								<option x-bind:value="status" x-text="statusBadge(status).label"></option>
							</template>
						</select>
					</div>

					<table class="w-full text-left text-[13px]" x-show="itemsTotal > 0">
						<thead>
							<tr class="text-[11px] uppercase tracking-wide text-muted">
								<th class="px-4 py-2 font-normal">
									<button type="button" class="transition-colors hover:text-cream" x-on:click="setItemSort('title')">Item <span x-text="itemSortIndicator('title')"></span></button>
								</th>
								<th class="px-4 py-2 font-normal">
									<button type="button" class="transition-colors hover:text-cream" x-on:click="setItemSort('published')">Published <span x-text="itemSortIndicator('published')"></span></button>
								</th>
								<th class="px-4 py-2 font-normal">
									<button type="button" class="transition-colors hover:text-cream" x-on:click="setItemSort('duration')">Duration <span x-text="itemSortIndicator('duration')"></span></button>
								</th>
								<th class="px-4 py-2 font-normal">
									<button type="button" class="transition-colors hover:text-cream" x-on:click="setItemSort('size')">Size <span x-text="itemSortIndicator('size')"></span></button>
								</th>
								<th class="px-4 py-2 font-normal">
									<button type="button" class="transition-colors hover:text-cream" x-on:click="setItemSort('status')">Status <span x-text="itemSortIndicator('status')"></span></button>
								</th>
							</tr>
						</thead>
						<tbody>
							<template x-for="row in itemRows()" x-bind:key="row.key">
								<tr class="border-t border-line/60" x-bind:class="row.item.state === 'ignored' ? 'opacity-50' : (isDownloading(row.item) ? 'bg-amber/5' : '')">
									<template x-if="row.type === 'item'">
										<td class="px-4 py-2">
											<a class="flex items-center gap-2 text-cream transition-colors hover:text-amber" x-bind:href="'/vault/' + row.item.media_item_id">
												<img x-show="row.item.media_item?.thumbnail_uri" x-bind:src="row.item.media_item?.thumbnail_uri" class="h-9 w-16 shrink-0 rounded object-cover" alt=""/>
												<span x-text="row.item.display_title ?? row.item.media_item?.title ?? row.item.media_item_id"></span>
											</a>
										</td>
									</template>
									<template x-if="row.type === 'item'">
										<td class="px-4 py-2 text-muted" x-text="formatRelativeTime(row.item.media_item?.published_at)"></td>
									</template>
									<template x-if="row.type === 'item'">
										<td class="px-4 py-2 text-muted" x-text="formatDuration(row.item.media_item?.duration_seconds)"></td>
									</template>
									<template x-if="row.type === 'item'">
										<td class="px-4 py-2 text-muted" x-text="formatBytes(row.item.total_asset_size_bytes)"></td>
									</template>
									<template x-if="row.type === 'item'">
										<td class="px-4 py-2">
											<span class="inline-flex items-center gap-1.5" x-bind:class="statusBadge(row.item.media_item?.state).text"
												x-bind:title="row.item.media_item?.state === 'failed' ? (row.item.media_item?.failure_reason ?? 'Unknown error') : null">
												<span class="h-1.5 w-1.5 rounded-full" x-bind:class="[statusBadge(row.item.media_item?.state).dot, statusBadge(row.item.media_item?.state).pulse ? 'pulse-dot' : '']"></span>
												<span x-text="statusBadge(row.item.media_item?.state).label"></span>
											</span>
											<button type="button" x-show="row.item.media_item?.state === 'failed'"
												class="ml-2 rounded border border-line px-1.5 py-0.5 text-[11px] text-muted transition-colors hover:text-cream disabled:opacity-50"
												x-bind:disabled="actionPending === row.item.id + ':retry'"
												x-on:click="retryDownload(row.item)">
												<span x-show="actionPending !== row.item.id + ':retry'">retry</span>
												<span x-show="actionPending === row.item.id + ':retry'">queueing…</span>
											</button>
											<p class="mt-1 text-[12px] text-muted" x-show="row.item.state === 'ignored'" x-text="'ignored: ' + (row.item.ignored_reason ?? 'unknown reason').replace(/_/g, ' ')"></p>
										</td>
									</template>
								</tr>
							</template>
						</tbody>
					</table>
					<p class="px-4 py-3 text-[13px] text-muted" x-show="stashItemCount === 0">No items yet.</p>
					<p class="px-4 py-3 text-[13px] text-muted" x-show="stashItemCount > 0 && itemsTotal === 0">No items match the current filters.</p>
					<div class="flex items-center justify-between border-t border-line px-4 py-2 text-[12px] text-muted" x-show="itemsTotal > itemsLimit">
						<span x-text="itemsRangeLabel()"></span>
						<div class="flex items-center gap-2">
							<button type="button" class="rounded border border-line px-2 py-1 text-muted transition-colors hover:text-cream disabled:opacity-40"
								x-bind:disabled="!hasPrevItemsPage()" x-on:click="prevItemsPage()">
								Prev
							</button>
							<button type="button" class="rounded border border-line px-2 py-1 text-muted transition-colors hover:text-cream disabled:opacity-40"
								x-bind:disabled="!hasNextItemsPage()" x-on:click="nextItemsPage()">
								Next
							</button>
						</div>
					</div>
				</section>
			</div>
		</template>

		<div class="fixed inset-0 z-40 flex items-center justify-center bg-black/60 p-4" x-show="editingOpen" x-cloak>
			<div class="w-full max-w-md rounded-lg border border-line bg-panel p-4">
				<h2 class="text-sm font-semibold text-cream">Edit stash</h2>

				<label class="mt-3 block">
					<span class="mb-1 block text-[12px] text-muted">Name</span>
					<input type="text" x-model="editForm.name"
						class="w-full rounded border border-line bg-espresso px-3 py-2 text-cream outline-none focus:border-amber"/>
				</label>
				<label class="mt-3 block">
					<span class="mb-1 block text-[12px] text-muted">Description</span>
					<input type="text" x-model="editForm.description"
						class="w-full rounded border border-line bg-espresso px-3 py-2 text-cream outline-none focus:border-amber"/>
				</label>
				<label class="mt-3 block">
					<span class="mb-1 block text-[12px] text-muted">Sync mode</span>
					<select x-model="editForm.syncMode"
						class="w-full rounded border border-line bg-espresso px-3 py-2 text-cream outline-none focus:border-amber">
						<option value="automatic">Automatic</option>
						<option value="manual">Manual</option>
					</select>
				</label>
				<label class="mt-3 block">
					<span class="mb-1 block text-[12px] text-muted">Download policy</span>
					<select x-model="editForm.downloadPolicy"
						class="w-full rounded border border-line bg-espresso px-3 py-2 text-cream outline-none focus:border-amber">
						<option value="video">Video</option>
						<option value="audio_only">Audio only</option>
						<option value="metadata_only">Metadata only</option>
						<option value="manual_download">Manual download</option>
					</select>
				</label>
				<label class="mt-3 block">
					<span class="mb-1 block text-[12px] text-muted">Organization mode</span>
					<select x-model="editForm.organizationMode"
						class="w-full rounded border border-line bg-espresso px-3 py-2 text-cream outline-none focus:border-amber">
						<option value="flat">Flat</option>
						<option value="chronological">Chronological</option>
						<option value="series">Series</option>
						<option value="seasoned_series">Seasoned series</option>
					</select>
				</label>

				<div class="mt-4 flex justify-end gap-2">
					<button type="button" x-on:click="cancelEdit()"
						class="rounded border border-line px-3 py-2 text-[13px] text-muted transition-colors hover:text-cream">Cancel</button>
					<button type="button" x-on:click="saveEdit()"
						x-bind:disabled="savingEdit || editForm.name.trim() === ''"
						class="rounded bg-amber px-3 py-2 text-[13px] font-semibold text-espresso transition-colors hover:bg-amber-dim disabled:opacity-60">Save</button>
				</div>
			</div>
		</div>

		<div class="fixed inset-0 z-40 flex items-center justify-center bg-black/60 p-4" x-show="deletingOpen" x-cloak>
			<div class="w-full max-w-md rounded-lg border border-line bg-panel p-4">
				<h2 class="text-sm font-semibold text-cream">Delete <span x-text="stash?.name"></span>?</h2>

				<template x-if="loadingDeleteImpact">
					<p class="mt-3 text-[13px] text-muted">Checking what this affects…</p>
				</template>

				<template x-if="!loadingDeleteImpact && deleteImpact">
					<div class="mt-3 space-y-2 text-[13px]">
						<p class="text-muted" x-show="deleteImpact.shared_items.length === 0 && deleteImpact.orphaned_items.length === 0">
							This stash has no items.
						</p>
						<div x-show="deleteImpact.shared_items.length > 0">
							<p class="text-cream"><span x-text="deleteImpact.shared_items.length"></span> item(s) are also used by other stashes and will be kept in the Vault.</p>
						</div>
						<div x-show="deleteImpact.orphaned_items.length > 0">
							<p class="text-warn"><span x-text="deleteImpact.orphaned_items.length"></span> item(s) will become orphaned in the Vault (no longer referenced by any stash).</p>
						</div>
					</div>
				</template>

				<p class="mt-3 text-[12px] text-muted">The stash, its inputs, and its item links will be removed. Vault originals are kept.</p>

				<div class="mt-4 flex justify-end gap-2">
					<button type="button" x-on:click="cancelDelete()"
						class="rounded border border-line px-3 py-2 text-[13px] text-muted transition-colors hover:text-cream">Cancel</button>
					<button type="button" x-on:click="confirmDelete()"
						x-bind:disabled="deletingBusy || loadingDeleteImpact"
						class="rounded bg-error px-3 py-2 text-[13px] font-semibold text-cream transition-colors hover:opacity-90 disabled:opacity-60">Delete stash</button>
				</div>
			</div>
		</div>

		<div class="fixed inset-0 z-40 flex items-center justify-center bg-black/60 p-4" x-show="addInputOpen" x-cloak>
			<div class="w-full max-w-md rounded-lg border border-line bg-panel p-4">
				<h2 class="text-sm font-semibold text-cream">Add input</h2>
				<p class="mt-1 text-[12px] text-error" x-show="addInputError" x-text="addInputError"></p>

				<template x-if="addInputStep === 'paste'">
					<div class="mt-3">
						<label class="block">
							<span class="mb-1 block text-[12px] text-muted">Channel, playlist, or video URL</span>
							<input type="text" x-model="addInputSourceUri" placeholder="source reference"
								class="w-full rounded border border-line bg-espresso px-3 py-2 text-cream outline-none focus:border-amber"/>
						</label>
						<div class="mt-4 flex justify-end gap-2">
							<button type="button" x-on:click="cancelAddInput()"
								class="rounded border border-line px-3 py-2 text-[13px] text-muted transition-colors hover:text-cream">Cancel</button>
							<button type="button" x-on:click="submitAddInputPreflight()"
								x-bind:disabled="addInputSubmitting || addInputSourceUri.trim() === ''"
								class="rounded bg-amber px-3 py-2 text-[13px] font-semibold text-espresso transition-colors hover:bg-amber-dim disabled:opacity-60">Continue</button>
						</div>
					</div>
				</template>

				<template x-if="addInputStep === 'reviewing'">
					<div class="mt-3">
						<p class="flex items-center gap-2 text-[13px] text-muted">
							<span class="h-1.5 w-1.5 rounded-full bg-amber pulse-dot"></span>
							Looking up that source…
						</p>
						<p class="mt-2 text-[12px] text-muted">This can take a while for a slow or rate-limited source. It'll keep running in the background even if you close this.</p>
						<div class="mt-4 flex justify-end gap-2">
							<button type="button" x-on:click="cancelAddInput()"
								class="rounded border border-line px-3 py-2 text-[13px] text-muted transition-colors hover:text-cream">Close</button>
						</div>
					</div>
				</template>

				<template x-if="addInputStep === 'review'">
					<div class="mt-3">
						<div class="flex items-center gap-2">
							<img x-show="addInputResolved?.source_avatar_uri" x-bind:src="addInputResolved?.source_avatar_uri"
								class="h-8 w-8 rounded-full" alt=""/>
							<div>
								<p class="text-[13px] font-semibold text-cream" x-text="addInputResolved?.source_title ?? addInputResolved?.title ?? 'Unknown source'"></p>
								<p class="text-[12px] text-muted">
									<span x-show="addInputEstimatedItemCount !== null">approx. <span x-text="addInputEstimatedItemCount"></span> items</span>
									<span x-show="addInputEstimatedTotalDurationSeconds"> · <span x-text="formatDuration(addInputEstimatedTotalDurationSeconds)"></span></span>
								</p>
							</div>
						</div>

						<ul class="mt-3 max-h-40 space-y-1 overflow-y-auto text-[12px] text-muted" x-show="addInputSampleItems.length > 0">
							<template x-for="item in addInputSampleItems" x-bind:key="item.provider_item_id">
								<li class="truncate" x-text="item.title"></li>
							</template>
						</ul>

						<div class="mt-3 space-y-2" x-show="addInputUniversalFilters.length > 0">
							<template x-for="filter in addInputUniversalFilters" x-bind:key="filter.key">
								<label class="block">
									<span class="mb-1 block text-[12px] text-muted" x-text="filter.label"></span>
									<input type="text" x-show="filter.key === 'title_regex_include'" x-model="addInputTitleRegexInclude"
										class="w-full rounded border border-line bg-espresso px-3 py-2 text-[12px] text-cream outline-none focus:border-amber"/>
									<input type="text" x-show="filter.key === 'title_regex_exclude'" x-model="addInputTitleRegexExclude"
										class="w-full rounded border border-line bg-espresso px-3 py-2 text-[12px] text-cream outline-none focus:border-amber"/>
								</label>
							</template>
						</div>

						<div class="mt-3 space-y-1" x-show="addInputInputOptions.length > 0">
							<template x-for="option in addInputInputOptions" x-bind:key="option.key">
								<label class="flex items-center gap-2 text-[12px] text-muted" x-show="option.type === 'bool'">
									<input type="checkbox" x-model="addInputProviderOptions[option.key]" class="rounded border-line"/>
									<span x-text="option.label"></span>
								</label>
							</template>
						</div>

						<p class="mt-3 text-[12px] text-muted">Will download: <span x-text="stash?.download_policy.replace(/_/g, ' ')"></span> (per this stash's download policy).</p>

						<div class="mt-4 flex justify-end gap-2">
							<button type="button" x-on:click="cancelAddInput()"
								class="rounded border border-line px-3 py-2 text-[13px] text-muted transition-colors hover:text-cream">Cancel</button>
							<button type="button" x-on:click="confirmAddInput()"
								x-bind:disabled="addInputSubmitting"
								class="rounded bg-amber px-3 py-2 text-[13px] font-semibold text-espresso transition-colors hover:bg-amber-dim disabled:opacity-60">Add input</button>
						</div>
					</div>
				</template>

				<template x-if="addInputStep === 'committing'">
					<div class="mt-3">
						<p class="flex items-center gap-2 text-[13px] text-muted">
							<span class="h-1.5 w-1.5 rounded-full bg-amber pulse-dot"></span>
							Adding input and discovering items…
						</p>
						<p class="mt-2 text-[12px] text-muted">This can take a while for a slow or rate-limited source. It'll keep running in the background even if you close this.</p>
						<div class="mt-4 flex justify-end gap-2">
							<button type="button" x-on:click="cancelAddInput()"
								class="rounded border border-line px-3 py-2 text-[13px] text-muted transition-colors hover:text-cream">Close</button>
						</div>
					</div>
				</template>

				<template x-if="addInputStep === 'failed'">
					<div class="mt-3">
						<template x-if="addInputUnsupported">
							<p class="text-[13px] text-warn">That source isn't supported yet.
								<a href="https://github.com/hipsterjazzbo/stashd/issues/new" target="_blank" rel="noopener"
									class="text-amber transition-colors hover:text-amber-dim">Request support</a>.</p>
						</template>
						<template x-if="!addInputUnsupported">
							<p class="text-[13px] text-error" x-text="addInputFailureMessage"></p>
						</template>
						<div class="mt-4 flex justify-end gap-2">
							<button type="button" x-on:click="cancelAddInput()"
								class="rounded border border-line px-3 py-2 text-[13px] text-muted transition-colors hover:text-cream">Close</button>
						</div>
					</div>
				</template>
			</div>
		</div>
	</div>
</x-stashd-layout>
