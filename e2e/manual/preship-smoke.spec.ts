import { expect, Page, test } from '@playwright/test';

// Manual pre-ship E2E smoke run against live YouTube sources. Excluded from
// the routine suite via playwright.config.ts's testIgnore -- run explicitly:
// npx playwright test e2e/manual/preship-smoke.spec.ts
// Real network + real ffmpeg -- expect long runtimes, especially for
// multi-hour Critical Role episodes.

const ADMIN = { username: 'e2e-admin', password: 'e2e-smoke-test-pass-1' };

// Every step logs through this so a live run is diagnosable from stdout
// alone (correlatable against DB timestamps, which are UTC) instead of
// requiring an external DB query to tell whether it's progressing or stuck.
function step(label: string, detail?: string) {
	const ts = new Date().toISOString().slice(11, 19);
	console.log(`[${ts}] ${label}${detail ? ' -- ' + detail : ''}`);
}

// How many items to actually download per added source/input. Each stash is
// switched to manual_download (see setManualDownloadPolicy) specifically so
// discovery never auto-queues everything it finds -- without that, "video"
// policy dispatches item.download for every discovered item the moment an
// input's discovery job commits, regardless of how few this test waits for.
const ITEMS_PER_INPUT = 3;

test.describe.configure({ mode: 'serial' });

// Captures what's actually happening client-side -- console errors, uncaught
// JS exceptions, and Mercure/SSE connection lifecycle -- since the add-input
// commit-detection hang (SSE listener + a 3s fallback poll both failing to
// notice a command that completed server-side in seconds) can't be diagnosed
// from server-side DB state alone. Also sets a page-wide default action
// timeout: an unbounded .click()/.fill()/.selectOption() on an element that
// never becomes actionable retries Playwright's own auto-wait for the
// *entire remaining test timeout*, silently -- this has now bitten three
// different locators (the review-step "Add input" button, the title-regex
// field) in three different ways, each only found by accident. One page
// -level default instead of chasing each call site individually; explicit
// per-call timeouts (e.g. the 180000/300000ms waits below for genuinely
// long server-side work) still override this. Call once per test, before
// login().
function attachBrowserDiagnostics(page: Page) {
	page.setDefaultTimeout(20000);
	page.on('console', (msg) => {
		if (msg.type() === 'error' || msg.type() === 'warning') {
			step(`browser console.${msg.type()}`, msg.text().slice(0, 300));
		}
	});
	page.on('pageerror', (error) => {
		step('browser uncaught exception', String(error).slice(0, 300));
	});
	page.on('requestfailed', (request) => {
		if (request.url().includes('mercure') || request.url().includes('/api/')) {
			step('browser requestfailed', `${request.method()} ${request.url()} -- ${request.failure()?.errorText ?? 'unknown'}`);
		}
	});
	page.on('response', (response) => {
		if (response.url().includes('mercure')) {
			step('browser mercure response', `${response.status()} ${response.url()}`);
		}
		// checkAddInputCommitTerminal()'s poll target -- direct visibility into
		// whether the 3s fallback interval (and/or the SSE-triggered check) is
		// actually firing and what it's seeing, since the add-input hang is
		// exactly this poll never detecting an already-completed command.
		if (/\/api\/v1\/commands\/[a-zA-Z0-9_]+$/.test(new URL(response.url()).pathname)) {
			step('browser command-poll response', `${response.status()} ${response.url()}`);
		}
	});
}

async function login(page: Page) {
	step('login: starting');
	await page.goto('/login');
	await page.locator('input[name="username"]').fill(ADMIN.username);
	await page.locator('input[name="password"]').fill(ADMIN.password);
	await page.locator('#auth-submit').click();
	await page.waitForFunction(() => !location.pathname.includes('/login'), { timeout: 15000 });
	step('login: done');
}

// Switches a stash to manual_download via the Edit modal (its downloadPolicy
// select) *before* any inputs are added, so discovery never auto-dispatches
// item.download for everything it finds. Must run before addFollowUpInput /
// completeAddInput for a given stash.
async function setManualDownloadPolicy(page: Page) {
	step('setManualDownloadPolicy: opening Edit modal');
	await page.getByRole('button', { name: 'Edit' }).click();
	await page.locator('select[x-model="editForm.downloadPolicy"]').selectOption('manual_download');
	await page.getByRole('button', { name: 'Save' }).click();
	await expect(page.locator('select[x-model="editForm.downloadPolicy"]')).toBeHidden({ timeout: 10000 });
	step('setManualDownloadPolicy: done');
}

// Throws with the actual status + a body snippet on a non-JSON/non-ok
// response, instead of letting response.json() fail with an opaque
// "Unexpected token '<'" syntax error that gives no clue what was returned
// (an HTML error/login page, a 404, etc.).
async function apiJson(response: Awaited<ReturnType<Page['request']['get']>>): Promise<unknown> {
	if (!response.ok()) {
		const text = await response.text().catch(() => '<unreadable body>');
		throw new Error(`API call to ${response.url()} failed: ${response.status()} ${response.statusText()} -- ${text.slice(0, 300)}`);
	}
	try {
		return await response.json();
	} catch {
		const text = await response.text().catch(() => '<unreadable body>');
		throw new Error(`API call to ${response.url()} returned non-JSON (status ${response.status()}): ${text.slice(0, 300)}`);
	}
}

// A session drop bounces the *page* to /login via client-side JS (apiFetch's
// 401 handler calling window.location.assign), but page.request shares the
// same browser-context cookies -- a dropped session surfaces here as a 401
// JSON response instead, not an HTML redirect. One re-login-and-retry covers
// it without failing the whole poll.
async function resilientApiGet(page: Page, url: string): Promise<Awaited<ReturnType<Page['request']['get']>>> {
	const response = await page.request.get(url);
	if (response.status() !== 401) return response;
	await login(page);
	return page.request.get(url);
}

async function getLatestInputId(page: Page, stashId: string): Promise<string> {
	const response = await resilientApiGet(page, `/api/v1/stashes/${stashId}/inputs`);
	const body = (await apiJson(response)) as { inputs: { id: string }[] };
	const latest = body.inputs.at(-1);
	if (!latest) throw new Error('No inputs found for stash ' + stashId);
	step('getLatestInputId', latest.id);
	return latest.id;
}

// Explicitly dispatches item.download for the first `count` stash items
// belonging to one specific input (position order), paging through the
// items endpoint as needed. This is the actual download cap -- with the
// stash on manual_download, nothing downloads unless dispatched here.
// include_ignored=false excludes anything the app already filtered out at
// commit time (title-regex mismatch, include_shorts/include_live content
// -type exclusion, etc. -- see CreateStashFromDiscovery::ignoredReason())
// -- ItemDownloadCommandHandler now rejects item.download for an ignored
// item outright (400, not a job that fails later), so picking one here by
// the arbitrary "first N" order would just fail the dispatch itself instead
// of testing anything real.
async function downloadFirstItemsForInput(page: Page, stashId: string, stashInputId: string, count: number): Promise<string[]> {
	step('downloadFirstItemsForInput: searching for items to download', `input=${stashInputId} count=${count}`);
	const matched: string[] = [];
	let offset = 0;
	const pageSize = 200;

	while (matched.length < count) {
		const response = await resilientApiGet(page, `/api/v1/stashes/${stashId}/items?limit=${pageSize}&offset=${offset}&include_ignored=false`);
		const body = (await apiJson(response)) as {
			items: { stash_input_id: string | null; item_id: string }[];
			total: number;
		};

		for (const item of body.items) {
			if (item.stash_input_id === stashInputId) {
				matched.push(item.item_id);
			}
			if (matched.length >= count) break;
		}

		offset += pageSize;
		if (offset >= body.total) break;
	}

	step('downloadFirstItemsForInput: dispatching item.download', `${matched.length} item(s): ${matched.join(', ')}`);

	for (const itemId of matched) {
		await page.request.post('/api/v1/commands', {
			data: { type: 'item.download', options: { item_id: itemId, stash_id: stashId } },
		});
	}
	step('downloadFirstItemsForInput: done');
	return matched;
}

async function getLatestBroadcastId(page: Page, stashId: string): Promise<string> {
	const response = await resilientApiGet(page, `/api/v1/stashes/${stashId}/broadcasts`);
	const body = (await apiJson(response)) as { broadcasts: { id: string }[] };
	const latest = body.broadcasts.at(-1);
	if (!latest) throw new Error('No broadcasts found for stash ' + stashId);
	step('getLatestBroadcastId', latest.id);
	return latest.id;
}

// Podcast broadcasts publish EVERY active/discovered stash item as an
// episode, not just the ones actually downloaded (unlike Jellyfin/Plex's
// AbstractSeriesBroadcastPlugin, which silently skips undownloaded items via
// publishableStashItems()). So with capped downloads, a podcast broadcast is
// *correctly* stuck at "stale" forever -- most items legitimately have no
// asset. Waiting for the whole broadcast to reach "ready" is therefore not a
// valid completion signal here; instead poll the specific broadcast_items
// for the items actually downloaded, and wait for just those to reach
// "ready" (proving the download -> transcode-fallback -> publish pipeline
// worked), ignoring the (expected) failures on everything else.
async function waitForBroadcastItemsReady(
	page: Page,
	broadcastId: string,
	itemIds: string[],
	timeoutMs: number,
): Promise<{ ready: boolean; items: { item_id: string; state: string; last_error: string | null }[] }> {
	const deadline = Date.now() + timeoutMs;
	let lastLoggedSummary = '';

	while (Date.now() < deadline) {
		const response = await resilientApiGet(page, `/api/v1/broadcasts/${broadcastId}/items`);
		const body = (await apiJson(response)) as {
			items: { item_id: string; state: string; last_error: string | null }[];
		};
		const tracked = body.items.filter((item) => itemIds.includes(item.item_id));

		const summary = tracked.map((item) => `${item.item_id.slice(-8)}=${item.state}`).join(' ');
		if (summary !== lastLoggedSummary) {
			step('waitForBroadcastItemsReady: polled', summary);
			lastLoggedSummary = summary;
		}

		if (tracked.length === itemIds.length && tracked.every((item) => item.state === 'ready')) {
			return { ready: true, items: tracked };
		}

		await page.waitForTimeout(15000);
	}

	const response = await page.request.get(`/api/v1/broadcasts/${broadcastId}/items`);
	const body = (await apiJson(response)) as {
		items: { item_id: string; state: string; last_error: string | null }[];
	};
	return { ready: false, items: body.items.filter((item) => itemIds.includes(item.item_id)) };
}

async function createStashWithLink(page: Page, title: string, link?: string): Promise<string> {
	step('createStashWithLink: starting', title);
	await page.goto('/stashes/new');
	await page.getByLabel('Name (optional)').fill(title);
	if (link) {
		await page.getByLabel('Channel, playlist, or video URL').fill(link);
		await page.getByRole('button', { name: 'Review source' }).click();
		await expect(page.getByRole('button', { name: 'Create stash', exact: true })).toBeEnabled({ timeout: 180000 });
	}
	await page.getByRole('button', { name: link ? 'Create stash' : 'Create empty stash', exact: true }).click();
	await page.waitForURL(/\/stashes\/[^/?]+/, { timeout: link ? 300000 : 15000 });
	const stashId = new URL(page.url()).pathname.split('/').pop()!;
	step('createStashWithLink: created', stashId);
	return stashId;
}

// Waits out the add-input modal's full lifecycle: preflight -> review
// (optionally filling a title regex) -> commit -> auto-close on completion.
// Assumes the modal was opened from an existing stash.
async function completeAddInput(page: Page, opts?: { titleRegexInclude?: string }) {
	step('completeAddInput: waiting for modal');
	await expect(page.getByRole('heading', { name: 'Add input' })).toBeVisible({ timeout: 10000 });

	// "reviewing" (preflight) step can take a while against a live, possibly
	// rate-limited source. Waiting for "Looking up that source…" to become
	// hidden is fragile: a brief re-render/DOM churn can make the assertion's
	// polling sample a momentary gap and resolve early, even though the app
	// is genuinely still in 'reviewing' seconds later (confirmed via a
	// server-side screenshot at the exact moment a subsequent step failed --
	// the modal was still showing "Looking up that source…"). Waiting for
	// the review step's own "Add input" button to actually appear is a
	// positive signal of the state we actually need, not an absence that can
	// false-positive on a flicker.
	step('completeAddInput: waiting for preflight to finish (review step to appear)');
	const addInputButton = page.getByRole('button', { name: 'Add input', exact: true });
	await expect(addInputButton).toBeVisible({ timeout: 180000 });
	await expect(page.getByText(/Could not reach the server\.|isn't supported yet/)).toBeHidden({ timeout: 1000 }).catch(() => {});
	step('completeAddInput: preflight finished, now on review step');

	if (opts?.titleRegexInclude) {
		await page.getByLabel('Only include titles matching (regex)').fill(opts.titleRegexInclude);
	}

	step('completeAddInput: clicking Add input');

	// This exact click has been observed to sometimes not register at all
	// (no network request ever sent, no error shown, modal just sits there)
	// with no clear pattern to when. An unbounded .click() call retries
	// Playwright's own actionability wait for the *entire remaining test
	// timeout* if the element is never actionable -- silently, with no signal
	// at all. Giving it an explicit bounded timeout turns a stuck element
	// into a fast, specific Playwright error (not visible/enabled/stable,
	// covered by another element, etc.) instead of an unexplained multi
	// -minute hang. The response race below then separately covers the case
	// where the click itself succeeds but nothing was actually dispatched.
	const commitRequestSeen = page
		.waitForResponse(
			(response) => response.url().includes('/inputs') && response.request().method() === 'POST',
			{ timeout: 20000 },
		)
		.then(() => true)
		.catch(() => false);

	try {
		await addInputButton.click({ timeout: 20000 });
	} catch (error) {
		step('completeAddInput: click() itself failed or timed out', String(error).slice(0, 300));
		throw error;
	}

	const requestFired = await commitRequestSeen;

	if (!requestFired) {
		step('completeAddInput: click succeeded but no request seen within 20s, retrying click');
		await addInputButton.click({ timeout: 20000 });
	}

	// "committing" (discovery) step, then modal auto-closes on completion.
	step('completeAddInput: waiting for commit/discovery to finish', requestFired ? 'request confirmed sent' : 'retried click');
	await expect(page.getByRole('heading', { name: 'Add input' })).toBeHidden({ timeout: 300000 });
	step('completeAddInput: done, modal closed');
}

async function addFollowUpInput(page: Page, url: string, opts?: { titleRegexInclude?: string }) {
	step('addFollowUpInput: starting', url);
	// A short settle after the previous add-input modal's auto-close (Alpine's
	// x-if/x-show teardown) before firing the next one back-to-back -- rapid
	// repeated adds (e.g. AntsCanada's 7 playlists in a row) have been
	// observed to silently swallow a click during that transition, with no
	// error and no dispatched command, leaving the flow stuck indefinitely.
	// A real user wouldn't chain these anywhere near this fast.
	await page.waitForTimeout(1500);
	// Two "+ Add input" buttons exist when the input list is empty (header +
	// the empty-state fallback link) -- first() disambiguates either way.
	await page.getByRole('button', { name: '+ Add input' }).first().click();
	await page.getByLabel('Channel, playlist, or video URL').fill(url);
	await page.getByRole('button', { name: 'Continue' }).click();
	await completeAddInput(page, opts);
	step('addFollowUpInput: done', url);
}

// Polls until at least `count` items reach a terminal "ready" state, or
// times out. Real downloads run via the already-running background worker --
// this just waits and observes.
//
// Polls the items API directly (like downloadFirstItemsForInput /
// waitForBroadcastItemsReady) rather than a full page.goto() + DOM count.
// For a large stash (criticalrole: ~1830 discovered items), the app's own
// refresh() rebuilds its entire in-memory item list via several sequential
// paginated requests (main.entrypoint.ts's fetchAllStashItems) -- a fresh
// page.goto() every poll cycle cancels that fetch mid-flight (visible as a
// storm of net::ERR_ABORTED) and restarts it from zero, so the DOM's "ready"
// count could go 50+ minutes without ever reflecting state that had already
// been true server-side for most of that time. Polling the API directly
// avoids the page navigation entirely, so nothing gets cancelled.
async function waitForDownloads(page: Page, stashId: string, count: number, timeoutMs: number) {
	step('waitForDownloads: starting', `want ${count} ready`);
	const deadline = Date.now() + timeoutMs;
	let lastLoggedCount = -1;
	while (Date.now() < deadline) {
		let readyCount = 0;
		let offset = 0;
		const pageSize = 200;
		for (;;) {
			const response = await resilientApiGet(page, `/api/v1/stashes/${stashId}/items?limit=${pageSize}&offset=${offset}`);
			const body = (await apiJson(response)) as { items: { item?: { state: string | null } }[]; total: number };
			readyCount += body.items.filter((item) => item.item?.state === 'ready').length;
			offset += pageSize;
			if (offset >= body.total) break;
		}
		if (readyCount !== lastLoggedCount) {
			step('waitForDownloads: polled', `${readyCount}/${count} ready`);
			lastLoggedCount = readyCount;
		}
		if (readyCount >= count) return readyCount;
		await page.waitForTimeout(15000);
	}
	throw new Error(`Timed out waiting for ${count} ready downloads`);
}

async function createBroadcast(
	page: Page,
	opts: { typeLabel: string; mediaKind?: 'audio' | 'video'; name?: string },
) {
	step('createBroadcast: starting', opts.name ?? opts.typeLabel);
	await page.locator('select').filter({ hasText: opts.typeLabel }).first();
	const typeSelect = page.locator('div.border-t.border-line select').first();
	await typeSelect.selectOption({ label: opts.typeLabel });
	if (opts.mediaKind) {
		const kindSelect = page.locator('select[x-model="newBroadcastMediaKind"]');
		await kindSelect.selectOption(opts.mediaKind);
	}
	if (opts.name) {
		await page.getByPlaceholder('Name (optional)').fill(opts.name);
	}
	await page.getByRole('button', { name: 'Preview' }).click();
	await expect(page.getByText('What this will do')).toBeVisible({ timeout: 30000 });
	await page.getByRole('button', { name: 'Create broadcast' }).click();
	step('createBroadcast: created, triggering rebuild');

	// Creating a broadcast only inserts the record in "pending" -- it does not
	// auto-dispatch a build. The card's own "rebuild" button must be clicked.
	const card = opts.name ? page.locator('li', { hasText: opts.name }) : page.locator('li').last();
	await card.getByRole('button', { name: 'rebuild', exact: true }).click();
	step('createBroadcast: rebuild triggered');
}

test('oculusimperia: video download + audio podcast broadcast (transcode fix acceptance test)', async ({ page }) => {
	// Real observed runtime is ~7m (job completion itself takes seconds --
	// see waitForDownloads' comment on why the old page-reload polling could
	// make already-finished work look stuck for much longer than that). This
	// budget is ~2x that, not the old multi-hour guess.
	test.setTimeout(15 * 60 * 1000);
	attachBrowserDiagnostics(page);
	await login(page);

	// Create the empty stash first so its download policy can be switched to
	// manual before any input is added.
	const stashId = await createStashWithLink(page, 'oculusimperia');
	await setManualDownloadPolicy(page);
	await addFollowUpInput(page, 'https://www.youtube.com/@oculusimperia');

	const inputId = await getLatestInputId(page, stashId);
	const downloadedItemIds = await downloadFirstItemsForInput(page, stashId, inputId, ITEMS_PER_INPUT);

	await waitForDownloads(page, stashId, ITEMS_PER_INPUT, 10 * 60 * 1000);

	await createBroadcast(page, { typeLabel: 'Podcast', mediaKind: 'audio', name: 'oculusimperia audio podcast' });

	// A podcast broadcast publishes EVERY discovered/active stash item as an
	// episode, not just the ones actually downloaded -- with capped downloads
	// most of oculusimperia's ~200+ discovered items legitimately have no
	// asset, so the broadcast as a whole is correctly "stale" forever. That's
	// not a bug and not what this test is checking. What matters is that the
	// download -> audio-transcode-fallback -> publish pipeline actually works
	// for the items that WERE downloaded, so check those specific
	// broadcast_items rather than waiting for the whole broadcast to go
	// "ready" (which, under this test's own capped-download design, it can't).
	const broadcastId = await getLatestBroadcastId(page, stashId);
	const result = await waitForBroadcastItemsReady(page, broadcastId, downloadedItemIds, 10 * 60 * 1000);

	console.log('OCULUSIMPERIA DOWNLOADED-ITEM FINAL STATES:', JSON.stringify(result.items, null, 2));
	expect(result.items.every((item) => item.last_error !== 'podcast_audio_transcode_pending')).toBe(true);
	expect(result.ready).toBe(true);
});

// Resolved by hand from https://www.youtube.com/@AntsCanada/playlists --
// the channel's playlists page is JS-rendered, not a supported input URL
// itself, and yt-dlp/the provider needs a real playlist URL per season.
const ANTSCANADA_SEASON_PLAYLISTS = [
	'https://www.youtube.com/playlist?list=PL5vZEY2A9f_gkMEo_Mvo7A852ojXq0xTL', // Season 1
	'https://www.youtube.com/playlist?list=PL5vZEY2A9f_h7gyY4JBP1kwQCkcO_C5mr', // Season 2
	'https://www.youtube.com/playlist?list=PL5vZEY2A9f_jLknNwn8ClXNOd3patIPtZ', // Season 3
	'https://www.youtube.com/playlist?list=PL5vZEY2A9f_gwGKKN6tq2UULYZi7ZKxdk', // Season 4
	'https://www.youtube.com/playlist?list=PL5vZEY2A9f_jy8dK18L8U7Fg_3slkT3mU', // Season 5
	'https://www.youtube.com/playlist?list=PL5vZEY2A9f_hxHfbIrCZ_0r78K7dekkTl', // Season 6
	'https://www.youtube.com/playlist?list=PL5vZEY2A9f_ha7rQ8Bwxn_r6nzwlFFKbL', // Season 7
];

test('AntsCanada: 7 season playlists + Jellyfin broadcast with season mapping', async ({ page }) => {
	// Real observed runtime is ~2.5m -- see oculusimperia's test.setTimeout
	// comment. ~6x margin, not the old multi-hour guess.
	test.setTimeout(15 * 60 * 1000);
	attachBrowserDiagnostics(page);
	await login(page);

	const stashId = await createStashWithLink(page, 'Vivariums by AntsCanada');
	await setManualDownloadPolicy(page);

	for (const [index, url] of ANTSCANADA_SEASON_PLAYLISTS.entries()) {
		step('AntsCanada: playlist', `${index + 1}/${ANTSCANADA_SEASON_PLAYLISTS.length}`);
		await addFollowUpInput(page, url);
		const inputId = await getLatestInputId(page, stashId);
		await downloadFirstItemsForInput(page, stashId, inputId, ITEMS_PER_INPUT);
	}

	await waitForDownloads(page, stashId, ITEMS_PER_INPUT, 10 * 60 * 1000);

	await createBroadcast(page, { typeLabel: 'Jellyfin Series', name: 'AntsCanada Jellyfin' });

	// Season mapping: one number input per input, in the order inputs were
	// added (Season 1..7) -- fill 1..7 and save.
	const card = page.locator('li', { hasText: 'AntsCanada Jellyfin' });
	const seasonInputs = card.locator('input[type="number"]');
	const count = await seasonInputs.count();
	for (let i = 0; i < count; i++) {
		await seasonInputs.nth(i).fill(String(i + 1));
	}
	if (count > 0) {
		await card.getByRole('button', { name: 'Save season mapping' }).click();
	}

	await page.waitForTimeout(5000);
	const finalText = await card.innerText().catch(() => '');
	console.log('ANTSCANADA BROADCAST FINAL STATE:\n', finalText);
});

test('criticalrole: regex-filtered video download + Jellyfin broadcast', async ({ page }) => {
	// Unlike oculusimperia/AntsCanada, this one doesn't have a real completion
	// number yet -- every run so far picked an unaired 'premiere' item and
	// failed before a real Campaign 4 episode download ever ran (now fixed in
	// downloadFirstItemsForInput). Left generous on purpose per the top-of
	// -file note (real multi-hour episodes, real transfer time) until a run
	// actually completes and gives us a number to calibrate against.
	test.setTimeout(4 * 60 * 60 * 1000);
	attachBrowserDiagnostics(page);
	await login(page);

	// Create the empty stash first so its download policy can be switched to
	// manual before any input is added.
	const stashId = await createStashWithLink(page, 'criticalrole');
	await setManualDownloadPolicy(page);
	await addFollowUpInput(page, 'https://www.youtube.com/@criticalrole', { titleRegexInclude: 'Campaign 4, Episode \\d+' });

	const inputId = await getLatestInputId(page, stashId);
	await downloadFirstItemsForInput(page, stashId, inputId, ITEMS_PER_INPUT);

	await waitForDownloads(page, stashId, ITEMS_PER_INPUT, 3.5 * 60 * 60 * 1000);

	await createBroadcast(page, { typeLabel: 'Jellyfin Series', name: 'Critical Role Jellyfin' });

	await page.waitForTimeout(5000);
	const finalText = await page.locator('li', { hasText: 'Critical Role Jellyfin' }).innerText().catch(() => '');
	console.log('CRITICALROLE BROADCAST FINAL STATE:\n', finalText);
});
