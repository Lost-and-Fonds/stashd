<x-stashd-layout title="New stash · stashd_">
	<div class="mx-auto max-w-3xl" x-data="createStash">
		<div class="mb-6 flex items-center justify-between gap-4">
			<div>
				<a href="/stashes" class="text-[12px] text-muted transition-colors hover:text-cream">← Stashes</a>
				<h1 class="mt-1 text-base font-semibold text-cream">New stash</h1>
			</div>
			<p class="text-right text-[13px] text-error" x-show="error" x-text="error"></p>
		</div>

		<div class="space-y-4">
			<section class="rounded-lg border border-line bg-panel/60 p-4">
				<h2 class="text-[13px] font-semibold text-cream">Source</h2>
				<p class="mt-1 text-[12px] text-muted">Optional. Leave this empty to create a stash and add inputs later.</p>

				<label class="mt-3 block">
					<span class="mb-1 block text-[12px] text-muted">Channel, playlist, or video URL</span>
					<input type="text" x-model="sourceUri" placeholder="source reference"
						x-bind:disabled="busy || createdStashId !== null"
						class="w-full rounded border border-line bg-espresso px-3 py-2 text-cream outline-none focus:border-amber disabled:opacity-60"/>
				</label>

				<div class="mt-3 flex items-center gap-3">
					<button type="button" x-on:click="reviewSource()"
						x-bind:disabled="busy || sourceUri.trim() === '' || !sourceNeedsReview() || createdStashId !== null"
						class="rounded border border-line px-3 py-2 text-[13px] text-muted transition-colors hover:text-cream disabled:opacity-60">
						Review source
					</button>
					<p class="flex items-center gap-2 text-[13px] text-muted" x-show="step === 'reviewing'">
						<span class="h-1.5 w-1.5 rounded-full bg-amber pulse-dot"></span>
						Looking up that source…
					</p>
				</div>

				<div class="mt-4 border-t border-line pt-4" x-show="step === 'review' && !sourceNeedsReview()" x-cloak>
					<div class="flex items-center gap-3">
						<img x-show="resolved?.source_avatar_uri" x-bind:src="resolved?.source_avatar_uri"
							class="h-10 w-10 rounded-full object-cover" alt=""/>
						<div>
							<p class="text-[13px] font-semibold text-cream" x-text="resolved?.source_title ?? resolved?.title ?? 'Unknown source'"></p>
							<p class="text-[12px] text-muted">
								<span x-show="estimatedItemCount !== null">approx. <span x-text="estimatedItemCount"></span> items</span>
								<span x-show="estimatedTotalDurationSeconds"> · <span x-text="formatDuration(estimatedTotalDurationSeconds)"></span></span>
							</p>
						</div>
					</div>

					<ul class="mt-3 max-h-40 space-y-1 overflow-y-auto text-[12px] text-muted" x-show="sampleItems.length > 0">
						<template x-for="item in sampleItems" x-bind:key="item.provider_item_id">
							<li class="truncate" x-text="item.title"></li>
						</template>
					</ul>

					<details class="mt-4 rounded border border-line bg-espresso p-3" x-show="universalFilters.length > 0 || inputOptions.length > 0">
						<summary class="cursor-pointer text-[12px] text-muted">Input filters</summary>
						<div class="mt-3 space-y-3">
							<template x-for="filter in universalFilters" x-bind:key="filter.key">
								<label class="block">
									<span class="mb-1 block text-[12px] text-muted" x-text="filter.label"></span>
									<input type="text" x-show="filter.key === 'title_regex_include'" x-model="titleRegexInclude"
										class="w-full rounded border border-line bg-panel px-3 py-2 text-[12px] text-cream outline-none focus:border-amber"/>
									<input type="text" x-show="filter.key === 'title_regex_exclude'" x-model="titleRegexExclude"
										class="w-full rounded border border-line bg-panel px-3 py-2 text-[12px] text-cream outline-none focus:border-amber"/>
								</label>
							</template>
							<template x-for="option in inputOptions" x-bind:key="option.key">
								<label class="flex items-center gap-2 text-[12px] text-muted" x-show="option.type === 'bool'">
									<input type="checkbox" x-model="providerOptions[option.key]" class="rounded border-line"/>
									<span x-text="option.label"></span>
								</label>
							</template>
						</div>
					</details>
				</div>
			</section>

			<section class="rounded-lg border border-line bg-panel/60 p-4">
				<h2 class="text-[13px] font-semibold text-cream">Stash settings</h2>

				<div class="mt-3 grid gap-3 sm:grid-cols-2">
					<label class="block">
						<span class="mb-1 block text-[12px] text-muted">Name (optional)</span>
						<input type="text" x-model="form.name" placeholder="Defaults to the source name"
							x-bind:disabled="busy || createdStashId !== null"
							class="w-full rounded border border-line bg-espresso px-3 py-2 text-cream outline-none focus:border-amber disabled:opacity-60"/>
					</label>
					<label class="block">
						<span class="mb-1 block text-[12px] text-muted">Download policy</span>
						<select x-model="form.downloadPolicy" x-bind:disabled="busy || createdStashId !== null"
							class="w-full rounded border border-line bg-espresso px-3 py-2 text-cream outline-none focus:border-amber disabled:opacity-60">
							<option value="video">Video</option>
							<option value="audio_only">Audio only</option>
							<option value="metadata_only">Metadata only</option>
							<option value="manual_download">Manual download</option>
						</select>
					</label>
				</div>

				<details class="mt-4 rounded border border-line bg-espresso p-3">
					<summary class="cursor-pointer text-[12px] text-muted">Advanced options</summary>
					<div class="mt-3 grid gap-3 sm:grid-cols-2">
						<label class="block sm:col-span-2">
							<span class="mb-1 block text-[12px] text-muted">Description</span>
							<input type="text" x-model="form.description" x-bind:disabled="busy || createdStashId !== null"
								class="w-full rounded border border-line bg-panel px-3 py-2 text-cream outline-none focus:border-amber disabled:opacity-60"/>
						</label>
						<label class="block">
							<span class="mb-1 block text-[12px] text-muted">Sync mode</span>
							<select x-model="form.syncMode" x-bind:disabled="busy || createdStashId !== null"
								class="w-full rounded border border-line bg-panel px-3 py-2 text-cream outline-none focus:border-amber disabled:opacity-60">
								<option value="automatic">Automatic</option>
								<option value="manual">Manual</option>
							</select>
						</label>
						<label class="block">
							<span class="mb-1 block text-[12px] text-muted">Organization</span>
							<select x-model="form.organizationMode" x-bind:disabled="busy || createdStashId !== null"
								class="w-full rounded border border-line bg-panel px-3 py-2 text-cream outline-none focus:border-amber disabled:opacity-60">
								<option value="flat">Flat</option>
								<option value="chronological">Chronological</option>
								<option value="series">Series</option>
								<option value="seasoned_series">Seasoned series</option>
							</select>
						</label>
					</div>
				</details>
			</section>

			<section class="rounded-lg border border-line bg-panel/60 p-4">
				<div class="flex flex-wrap items-center justify-between gap-3">
					<div class="text-[12px] text-muted">
						<p x-show="step === 'creating'" class="flex items-center gap-2">
							<span class="h-1.5 w-1.5 rounded-full bg-amber pulse-dot"></span>
							Creating stash…
						</p>
						<p x-show="step === 'committing'" class="flex items-center gap-2">
							<span class="h-1.5 w-1.5 rounded-full bg-amber pulse-dot"></span>
							Adding the source and discovering items…
						</p>
						<p x-show="sourceNeedsReview() && sourceUri.trim() !== '' && step !== 'reviewing'">Review the current source before creating this stash.</p>
						<p x-show="createdStashId !== null && step === 'failed'">
							The stash was created, but its input was not added.
							<a x-bind:href="'/stashes/' + createdStashId" class="text-amber hover:text-amber-dim">Open the empty stash</a>.
						</p>
					</div>
					<div class="flex items-center gap-2">
						<a href="/stashes" class="rounded border border-line px-3 py-2 text-[13px] text-muted transition-colors hover:text-cream">Cancel</a>
						<button type="button" x-on:click="create()"
							x-bind:disabled="busy || (sourceUri.trim() !== '' && sourceNeedsReview())"
							class="rounded bg-amber px-3 py-2 text-[13px] font-semibold text-espresso transition-colors hover:bg-amber-dim disabled:opacity-60"
							x-text="createdStashId !== null ? 'Retry input' : (sourceUri.trim() === '' ? 'Create empty stash' : 'Create stash')">
						</button>
					</div>
				</div>
			</section>
		</div>
	</div>
</x-stashd-layout>
