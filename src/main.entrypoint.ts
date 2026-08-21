import './main.entrypoint.css'
import Alpine from 'alpinejs'

declare global {
	interface Window {
		Alpine: typeof Alpine
	}
}

/**
 * Marks the current section in the top nav with the amber underscore.
 * Nav links carry `data-nav="/stashes"` etc.; the longest matching prefix wins
 * so `/stashes/{id}` still highlights Stashes.
 */
function markActiveNav(): void {
	const path = window.location.pathname
	const links = Array.from(
		document.querySelectorAll<HTMLElement>('[data-nav]'),
	)

	let best: HTMLElement | null = null
	for (const link of links) {
		const target = link.dataset.nav ?? ''
		const matches = target === '/' ? path === '/' : path.startsWith(target)
		if (matches && (!best || target.length > (best.dataset.nav?.length ?? 0))) {
			best = link
		}
	}

	for (const link of links) {
		link.classList.toggle('is-active', link === best)
	}
}

/**
 * Wires the header "log out" button: revokes the session server-side (which
 * clears the HttpOnly cookie) then returns to the login page.
 */
function wireLogout(): void {
	const button = document.querySelector<HTMLElement>('[data-logout]')
	if (!button) return

	button.addEventListener('click', async () => {
		try {
			await fetch('/api/v1/auth/logout', {
				method: 'POST',
				credentials: 'same-origin',
			})
		} finally {
			window.location.assign('/login')
		}
	})
}

/** Thrown by apiFetch on 401/403, after it has already redirected to /login. */
class UnauthenticatedError extends Error {}

const SLOW_API_REQUEST_MILLISECONDS = 500

function apiDiagnosticPath(path: string): string {
	try {
		return new URL(path, window.location.origin).pathname
	} catch {
		return '/api/v1/[invalid-path]'
	}
}

/**
 * Shared fetch wrapper for every /api/v1 call made from the dashboard shell.
 * Sends the session cookie, and treats 401/403 as "bounce to /login" rather
 * than something each call site needs to check for itself.
 */
async function apiFetch(path: string, init: RequestInit = {}): Promise<Response> {
	const startedAt = performance.now()
	const diagnosticPath = apiDiagnosticPath(path)
	let response: Response

	try {
		response = await fetch(path, { ...init, credentials: 'same-origin' })
	} catch (cause) {
		console.warn('Stashd API request failed before receiving a response.', {
			method: init.method ?? 'GET',
			path: diagnosticPath,
			duration_ms: Math.round(performance.now() - startedAt),
		})
		throw cause
	}

	const duration = performance.now() - startedAt
	if (duration >= SLOW_API_REQUEST_MILLISECONDS || response.status >= 500) {
		console.warn('Stashd API request diagnostic.', {
			method: init.method ?? 'GET',
			path: diagnosticPath,
			status: response.status,
			duration_ms: Math.round(duration),
			server_timing: response.headers.get('Server-Timing'),
			request_id: response.headers.get('X-Stashd-Request-Id'),
		})
	}

	if (response.status === 401 || response.status === 403) {
		window.location.assign('/login')
		throw new UnauthenticatedError(path)
	}

	return response
}

/**
 * Retries once, after a short delay, when `fetch()` itself rejects (a
 * network-level failure -- dropped connection, DNS blip -- as opposed to an
 * HTTP error status, which callers already handle separately). Only safe for
 * GETs: a lost response after a non-idempotent write may mean the request
 * still landed server-side, so this must not be used for mutating calls.
 */
async function getWithRetry(path: string): Promise<Response> {
	try {
		return await apiFetch(path)
	} catch (cause) {
		if (cause instanceof UnauthenticatedError) throw cause
		await new Promise((resolve) => setTimeout(resolve, 400))
		return await apiFetch(path)
	}
}

/**
 * Client auth gate for the dashboard shell. The HTML pages are public, so when
 * the session cookie is missing or invalid every /api/v1 call returns 401 — we
 * detect that up front and bounce to /login. Pages opt in via the layout's
 * `data-requires-auth` body attribute; the login page does not.
 */
async function enforceAuth(): Promise<void> {
	if (!document.body.hasAttribute('data-requires-auth')) return

	try {
		await apiFetch('/api/v1/auth/me')
	} catch {
		// apiFetch already redirected on 401/403; a network error here is left
		// in place rather than trapping a redirect loop.
	}
}

function formatBytes(bytes: number | null | undefined): string {
	if (bytes === null || bytes === undefined || Number.isNaN(bytes)) return '—'

	const units = ['B', 'KB', 'MB', 'GB', 'TB']
	let value = bytes
	let unit = 0
	while (value >= 1024 && unit < units.length - 1) {
		value /= 1024
		unit++
	}

	return `${unit === 0 ? value : value.toFixed(1)} ${units[unit]}`
}

function formatDuration(seconds: number | null | undefined): string {
	if (seconds === null || seconds === undefined || Number.isNaN(seconds)) return '—'

	const total = Math.max(0, Math.round(seconds))
	if (total < 60) return `${total}s`

	const minutes = Math.floor(total / 60)
	if (minutes < 60) return `${minutes}m ${total % 60}s`

	const hours = Math.floor(minutes / 60)
	return `${hours}h ${minutes % 60}m`
}

// navigator.clipboard requires a secure context (HTTPS/localhost) -- same
// constraint as crypto.randomUUID elsewhere in this file, and just as
// reachable over a plain-HTTP LAN IP. Falls back to the classic
// execCommand technique, which works anywhere as long as it runs
// synchronously from a real user gesture (true for both call sites, both
// directly inside a click handler).
async function copyToClipboard(text: string): Promise<void> {
	if (navigator.clipboard) {
		await navigator.clipboard.writeText(text)
		return
	}

	const textarea = document.createElement('textarea')
	textarea.value = text
	textarea.style.position = 'fixed'
	textarea.style.opacity = '0'
	document.body.appendChild(textarea)
	textarea.select()
	document.execCommand('copy')
	textarea.remove()
}

const ALL_DOWNLOAD_POLICIES = ['video', 'audio_only', 'metadata_only', 'manual_download']

/**
 * Mirrors App\Broadcasts\BroadcastType::isSatisfiedByDownloadPolicy() — kept
 * here only for instant reactive feedback as the user picks a broadcast type;
 * the server-side check in BroadcastController::create() is authoritative.
 */
function downloadPolicySatisfiesBroadcastType(policy: string, broadcastType: string, mediaKind?: string): boolean {
	if (policy === 'metadata_only') return false
	if (policy === 'audio_only') return !(broadcastType === 'podcast' && mediaKind === 'video')
	return true
}

function compatibleDownloadPolicies(broadcastType: string, mediaKind?: string): string[] {
	return ALL_DOWNLOAD_POLICIES.filter((policy) => downloadPolicySatisfiesBroadcastType(policy, broadcastType, mediaKind))
}

function formatRelativeTime(iso: string | null | undefined): string {
	if (!iso) return '—'

	const then = new Date(iso).getTime()
	if (Number.isNaN(then)) return '—'

	const diffSeconds = Math.round((then - Date.now()) / 1000)
	const abs = Math.abs(diffSeconds)
	const suffix = diffSeconds <= 0 ? 'ago' : 'from now'

	if (abs < 5) return 'just now'
	if (abs < 60) return `${abs}s ${suffix}`
	if (abs < 3600) return `${Math.round(abs / 60)}m ${suffix}`
	if (abs < 86400) return `${Math.round(abs / 3600)}h ${suffix}`
	if (abs < 86400 * 30) return `${Math.round(abs / 86400)}d ${suffix}`

	// Past a month, a relative count (e.g. "2856d ago") stops being useful --
	// an absolute date is.
	return new Date(then).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
}

interface Badge {
	label: string
	dot: string
	text: string
	/** Whether the dot should pulse (T14: only genuinely "happening right now" states). */
	pulse?: boolean
}

// Tailwind's scanner needs every class name to appear as a literal string
// somewhere in the source — it can't see through `bg-${color}`-style
// interpolation — so state -> badge is a static lookup table, not a template.
const STATE_BADGES: Record<string, Badge> = {
	pending: { label: 'pending', dot: 'bg-muted', text: 'text-muted' },
	processing: { label: 'processing', dot: 'bg-amber', text: 'text-amber' },
	ready: { label: 'ready', dot: 'bg-success', text: 'text-success' },
	completed: { label: 'completed', dot: 'bg-success', text: 'text-success' },
	stale: { label: 'stale', dot: 'bg-warn', text: 'text-warn' },
	failed: { label: 'failed', dot: 'bg-error', text: 'text-error' },
	missing: { label: 'missing', dot: 'bg-error', text: 'text-error' },
	disabled: { label: 'disabled', dot: 'bg-muted', text: 'text-muted' },
	ignored: { label: 'ignored', dot: 'bg-muted', text: 'text-muted' },
	cancelled: { label: 'cancelled', dot: 'bg-muted', text: 'text-muted' },
	active: { label: 'active', dot: 'bg-success', text: 'text-success' },
	removed: { label: 'removed', dot: 'bg-muted', text: 'text-muted' },
	hidden: { label: 'hidden', dot: 'bg-muted', text: 'text-muted' },
	discovered: { label: 'discovered', dot: 'bg-muted', text: 'text-muted' },
	metadata_ready: { label: 'metadata ready', dot: 'bg-amber', text: 'text-amber' },
	download_pending: { label: 'queued', dot: 'bg-amber', text: 'text-amber' },
	downloading: { label: 'downloading', dot: 'bg-amber', text: 'text-amber' },
}

// States that mean "actively in progress right now", as opposed to queued
// (pending, download_pending), settled (ready, failed, ignored…), or merely
// descriptive (active = not-removed, not a live process). Only these pulse.
const ACTIVELY_HAPPENING_STATES = new Set(['processing', 'downloading'])

// Mirrors App\Vault\MediaItemState -- the stash items table's status filter
// always offers every lifecycle state, not just ones currently present (see
// stashDetailComponent.itemStatusOptions).
const ITEM_STATUS_OPTIONS = ['discovered', 'metadata_ready', 'download_pending', 'downloading', 'ready', 'failed', 'missing', 'ignored']

function statusBadge(state: string | null | undefined): Badge {
	if (!state) return { label: '—', dot: 'bg-muted', text: 'text-muted' }
	const badge = STATE_BADGES[state] ?? { label: state, dot: 'bg-muted', text: 'text-muted' }
	return { ...badge, pulse: ACTIVELY_HAPPENING_STATES.has(state) }
}

const ACTIVITY_LEVEL_BADGES: Record<string, Badge> = {
	info: { label: 'info', dot: 'bg-muted', text: 'text-muted' },
	success: { label: 'success', dot: 'bg-success', text: 'text-success' },
	warning: { label: 'warning', dot: 'bg-warn', text: 'text-warn' },
	error: { label: 'error', dot: 'bg-error', text: 'text-error' },
}

const JOB_EVENT_BADGES: Record<string, Badge> = {
	'job.created': { label: 'job created', dot: 'bg-muted', text: 'text-muted' },
	'job.progress': { label: 'job progress', dot: 'bg-amber', text: 'text-amber' },
	'job.completed': { label: 'job completed', dot: 'bg-success', text: 'text-success' },
	'job.failed': { label: 'job failed', dot: 'bg-error', text: 'text-error' },
}

interface ActivityEvent {
	id: string
	event: string
	payload: Record<string, unknown>
	created_at: string
}

function eventBadge(event: ActivityEvent): Badge {
	if (event.event === 'activity.created') {
		const level = String(event.payload.level ?? 'info')
		return ACTIVITY_LEVEL_BADGES[level] ?? ACTIVITY_LEVEL_BADGES.info
	}

	return JOB_EVENT_BADGES[event.event] ?? { label: event.event, dot: 'bg-muted', text: 'text-muted' }
}

function summarizeEvent(event: ActivityEvent): string {
	const payload = event.payload

	switch (event.event) {
		case 'activity.created':
			return String(payload.message ?? '')
		case 'job.created':
			return `${String(payload.intent ?? 'job')} job created`
		case 'job.progress': {
			const label = String(payload.progress_label ?? payload.intent ?? 'job')
			return payload.progress_percent === null || payload.progress_percent === undefined
				? label
				: `${label} — ${payload.progress_percent}%`
		}
		case 'job.completed':
			return `${String(payload.intent ?? 'job')} completed`
		case 'job.failed':
			return payload.last_error
				? `${String(payload.intent ?? 'job')} failed: ${payload.last_error}`
				: `${String(payload.intent ?? 'job')} failed`
		default:
			return event.event
	}
}

interface ActivityLogEntry {
	id: string
	level: string
	type: string
	message: string
	entity_type: string | null
	entity_id: string | null
	stash_id: string | null
	media_item_id: string | null
	broadcast_id: string | null
	job_id: string | null
	command_id: string | null
	created_at: string
}

interface ActivitySummaryGroup {
	type: string
	level: string
	stash_id: string | null
	message: string
	count: number
	created_at: string
}

/**
 * Collapses consecutive (already-recency-ordered) log entries that share a
 * type and stash into one summary line with a count — spec §29's grouping
 * ("event type, stash, command/job, short time window"), approximated by
 * "adjacent in an already-time-ordered recent list" rather than parsing
 * timestamps. Shows the most recent message in the group; older ones in the
 * same group are summarized as "+N more" rather than re-stated.
 */
function summarizeRecentActivity(events: ActivityLogEntry[], limit = 8): ActivitySummaryGroup[] {
	const groups: ActivitySummaryGroup[] = []

	for (const event of events) {
		const last = groups[groups.length - 1]
		if (last && last.type === event.type && last.stash_id === event.stash_id) {
			last.count++
		} else {
			groups.push({
				type: event.type,
				level: event.level,
				stash_id: event.stash_id,
				message: event.message,
				count: 1,
				created_at: event.created_at,
			})
		}
	}

	return groups.slice(0, limit)
}

/**
 * The Activity page's live feed renders `ActivityEvent`s straight off Mercure
 * (event name + raw payload); its initial backfill instead comes from the
 * persisted `ActivityLogEntry` log, a differently-shaped resource. A
 * persisted entry is exactly what happens at rest when an `activity.created`
 * event fires, so it's adapted to that same shape here rather than teaching
 * every render helper (eventBadge, summarizeEvent) a second event shape.
 */
function activityLogEntryToEvent(entry: ActivityLogEntry): ActivityEvent {
	return {
		id: entry.id,
		event: 'activity.created',
		payload: {
			level: entry.level,
			message: entry.message,
			type: entry.type,
			entity_type: entry.entity_type,
			entity_id: entry.entity_id,
			stash_id: entry.stash_id,
			media_item_id: entry.media_item_id,
			broadcast_id: entry.broadcast_id,
			job_id: entry.job_id,
			command_id: entry.command_id,
		},
		created_at: entry.created_at,
	}
}

// The full, fixed set of event names MercurePublisher ever emits
// (app/System/Event/EventPublisher.php).
const EVENT_TYPES = ['job.created', 'job.progress', 'job.completed', 'job.failed', 'activity.created'] as const

type MercureListener = (event: ActivityEvent) => void
type ConnectionListener = (connected: boolean, reconnected: boolean) => void

// crypto.randomUUID() only exists in secure contexts (HTTPS or localhost) --
// unavailable over a plain-HTTP LAN IP, a real access pattern for this app.
// This id is a synthetic, client-only key for Alpine's event list (never
// sent to the server), so a cheap fallback is fine; prefer the real thing
// when it's there.
function randomEventId(): string {
	if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
		return crypto.randomUUID()
	}

	return `${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`
}

// One EventSource shared by every page component, opened against FrankenPHP's
// embedded Mercure hub instead of each component (and the old
// awaitSseTerminal one-shot helper) dialing its own /api/v1/events poll-loop
// connection -- consolidating avoids the browser's 6-connections-per-host
// HTTP/1.1 cap and the worker-starvation math the old poll loop required.
const mercureListeners = new Map<string, Set<MercureListener>>()
const mercureConnectionListeners = new Set<ConnectionListener>()
let mercureSource: EventSource | null = null
let mercureConnectedOnce = false

// The hub requires a subscriber JWT (no `anonymous`); this mints one and
// sets it as the `mercureAuthorization` cookie the browser then sends
// automatically to the same-origin /.well-known/mercure endpoint.
async function refreshMercureSubscription(): Promise<void> {
	await apiFetch('/api/v1/events/subscription')
}

function ensureMercureConnection(): void {
	if (mercureSource || !('EventSource' in window)) return

	mercureSource = new EventSource(`/.well-known/mercure?topic=${encodeURIComponent('stashd/events')}`)

	mercureSource.onopen = () => {
		const reconnected = mercureConnectedOnce
		mercureConnectedOnce = true
		for (const listener of mercureConnectionListeners) listener(true, reconnected)
	}

	mercureSource.onerror = () => {
		for (const listener of mercureConnectionListeners) listener(false, false)
		// The JWT is short-lived (~1h) -- remint on every drop so the
		// browser's automatic reconnect carries a fresh cookie.
		void refreshMercureSubscription().catch(() => {})
	}

	mercureSource.onmessage = (raw: MessageEvent<string>) => {
		const { event, ...payload } = JSON.parse(raw.data) as { event: string } & Record<string, unknown>
		const id = event === 'activity.created' && typeof payload.id === 'string' ? `activity:${payload.id}` : randomEventId()
		const wrapped: ActivityEvent = { id, event, payload, created_at: new Date().toISOString() }

		for (const listener of mercureListeners.get(event) ?? []) listener(wrapped)
	}
}

/** Subscribes to the given event names on the shared Mercure connection; returns an unsubscribe function. */
function subscribeToEvents(types: readonly string[], listener: MercureListener): () => void {
	void refreshMercureSubscription().then(ensureMercureConnection).catch(() => {})

	for (const type of types) {
		if (!mercureListeners.has(type)) mercureListeners.set(type, new Set())
		mercureListeners.get(type)?.add(listener)
	}

	return () => {
		for (const type of types) mercureListeners.get(type)?.delete(listener)
	}
}

function subscribeToConnectionState(listener: ConnectionListener): () => void {
	mercureConnectionListeners.add(listener)
	void refreshMercureSubscription().then(ensureMercureConnection).catch(() => {})

	return () => mercureConnectionListeners.delete(listener)
}

document.addEventListener('visibilitychange', () => {
	if (!('EventSource' in window) || document.visibilityState !== 'visible' || mercureSource?.readyState !== EventSource.OPEN) return

	for (const listener of mercureConnectionListeners) listener(true, true)
})

/**
 * Resolves once `checkTerminal` returns true, subscribing to the shared
 * Mercure connection rather than opening its own (as the old
 * per-EventSource-per-retry version did). Returns a cancel function the
 * caller can invoke to stop watching early (e.g. the user backed out of the
 * flow that started it).
 *
 * Only for short, one-shot waits (preflight/add-input steps) — never held
 * open for a page's whole lifetime like Dashboard/Activity/Stash detail.
 */
function awaitSseTerminal(checkTerminal: () => Promise<boolean>): () => void {
	let closed = false
	let fallback: ReturnType<typeof setInterval> | null = null
	let unsubscribe: (() => void) | null = null
	let unsubscribeConnection: (() => void) | null = null

	const finish = () => {
		if (closed) return
		closed = true
		unsubscribe?.()
		unsubscribeConnection?.()
		if (fallback !== null) clearInterval(fallback)
	}

	const tick = async () => {
		if (closed) return
		if (await checkTerminal()) finish()
	}

	unsubscribe = subscribeToEvents(EVENT_TYPES, () => void tick())
	unsubscribeConnection = subscribeToConnectionState((connected, reconnected) => {
		if (connected && reconnected) void tick()
	})

	// ponytail: unsupported browsers have no stream to trigger terminal
	// detection, so retain polling only there; reconnect performs the normal
	// resync in browsers with EventSource.
	if (!('EventSource' in window)) fallback = setInterval(() => void tick(), 3000)

	void tick()

	return finish
}

interface StorageLocation {
	key: string
	path: string
	state: string
	readable: boolean
	writable: boolean
	supports_hardlinks: boolean
	last_error: string | null
	free_bytes: number | null
	total_bytes: number | null
}

interface HealthReport {
	status: string
	version: string
	database: { writable: boolean }
	storage: {
		ready: boolean
		vault_broadcast_hardlink: boolean
		message: string | null
		locations: StorageLocation[]
	}
}

interface JobSummary {
	id: string
	command_id: string | null
	intent: string
	entity_type: string | null
	entity_id: string | null
	state: string
	progress_current: number | null
	progress_total: number | null
	progress_percent: number | null
	progress_label: string | null
	last_error: string | null
	started_at: string | null
	finished_at: string | null
	heartbeat_at: string | null
	created_at: string
	updated_at: string
	payload?: Record<string, unknown> | null
}

function realtimeJob(event: ActivityEvent): JobSummary | null {
	if (!event.event.startsWith('job.')) return null

	const payload = event.payload
	if (typeof payload.id !== 'string' || typeof payload.intent !== 'string' || typeof payload.state !== 'string') return null

	return {
		id: payload.id,
		command_id: typeof payload.command_id === 'string' ? payload.command_id : null,
		intent: payload.intent,
		entity_type: typeof payload.entity_type === 'string' ? payload.entity_type : null,
		entity_id: typeof payload.entity_id === 'string' ? payload.entity_id : null,
		state: payload.state,
		progress_current: typeof payload.progress_current === 'number' ? payload.progress_current : null,
		progress_total: typeof payload.progress_total === 'number' ? payload.progress_total : null,
		progress_percent: typeof payload.progress_percent === 'number' ? payload.progress_percent : null,
		progress_label: typeof payload.progress_label === 'string' ? payload.progress_label : null,
		last_error: typeof payload.last_error === 'string' ? payload.last_error : null,
		started_at: typeof payload.started_at === 'string' ? payload.started_at : null,
		finished_at: typeof payload.finished_at === 'string' ? payload.finished_at : null,
		heartbeat_at: typeof payload.heartbeat_at === 'string' ? payload.heartbeat_at : null,
		created_at: typeof payload.created_at === 'string' ? payload.created_at : event.created_at,
		updated_at: typeof payload.updated_at === 'string' ? payload.updated_at : event.created_at,
	}
}

function upsertJob(jobs: JobSummary[], job: JobSummary): JobSummary[] {
	const index = jobs.findIndex((candidate) => candidate.id === job.id)
	if (index === -1) return [job, ...jobs]

	return jobs.map((candidate) => candidate.id === job.id ? { ...candidate, ...job } : candidate)
}

function isTerminalJobEvent(event: ActivityEvent): boolean {
	return event.event === 'job.completed' || event.event === 'job.failed'
}

function realtimeActivity(event: ActivityEvent): ActivityLogEntry | null {
	if (event.event !== 'activity.created') return null

	const payload = event.payload
	if (typeof payload.id !== 'string' || typeof payload.type !== 'string' || typeof payload.message !== 'string') return null

	return {
		id: payload.id,
		level: typeof payload.level === 'string' ? payload.level : 'info',
		type: payload.type,
		message: payload.message,
		entity_type: typeof payload.entity_type === 'string' ? payload.entity_type : null,
		entity_id: typeof payload.entity_id === 'string' ? payload.entity_id : null,
		stash_id: typeof payload.stash_id === 'string' ? payload.stash_id : null,
		media_item_id: typeof payload.media_item_id === 'string' ? payload.media_item_id : null,
		broadcast_id: typeof payload.broadcast_id === 'string' ? payload.broadcast_id : null,
		job_id: typeof payload.job_id === 'string' ? payload.job_id : null,
		command_id: typeof payload.command_id === 'string' ? payload.command_id : null,
		created_at: typeof payload.created_at === 'string' ? payload.created_at : event.created_at,
	}
}

interface ResolvedInputSummary {
	provider_key: string
	input_type: string
	source_uri: string
	provider_input_id: string
	title: string | null
	source_title: string | null
	source_avatar_uri: string | null
	estimated_item_count: number | null
}

interface DiscoveredItemSummary {
	provider_item_id: string
	canonical_uri: string
	title: string
	description: string | null
	duration_seconds: number | null
	published_at: string | null
	thumbnail_uri: string | null
}

interface UniversalFilterDeclaration {
	key: string
	label: string
	type: string
}

interface InputOptionDeclaration {
	key: string
	label: string
	type: 'bool' | 'enum'
	default: boolean | string
	choices: string[] | null
	applicable_input_types: string[]
}

interface PreflightReview {
	command_id: string
	state: string
	review_url: string | null
	preflight: {
		source_uri: string
		source_title: string | null
		origin: string
		resolved_input: ResolvedInputSummary | null
		discovery: {
			strategy_key: string
			estimated_item_count: number
			estimated_total_duration_seconds: number
			discovered_items: DiscoveredItemSummary[]
			sample_items: DiscoveredItemSummary[]
		} | null
		universal_filters: UniversalFilterDeclaration[]
		input_options: InputOptionDeclaration[]
	} | null
	ui_note: string
}

interface CommandSummary {
	id: string
	type: string
	state: string
	target_type: string | null
	target_id: string | null
	options: Record<string, unknown> | null
	result: Record<string, unknown> | null
	created_by_user_id: string | null
	created_at: string
	updated_at: string
}

interface CommandShowResponse {
	command: CommandSummary
	jobs: JobSummary[]
}

interface StashSummary {
	id: string
	name: string
	description: string | null
	sync_mode: string
	download_policy: string
	organization_mode: string
	state: string
	icon_uri: string | null
	created_at: string
	updated_at: string
}

interface StashDeleteImpactItem {
	media_item_id: string
	title: string
}

interface StashDeleteImpactSharedItem extends StashDeleteImpactItem {
	shared_with_stashes: { id: string; name: string }[]
}

interface StashDeleteImpactSummary {
	shared_items: StashDeleteImpactSharedItem[]
	orphaned_items: StashDeleteImpactItem[]
}

interface StashEditForm {
	name: string
	description: string
	syncMode: string
	downloadPolicy: string
	organizationMode: string
}

type ItemSortKey = 'position' | 'title' | 'published' | 'duration' | 'size' | 'status'

interface StashItemSummary {
	id: string
	stash_id: string
	media_item_id: string
	stash_input_id: string | null
	state: string
	position: number | null
	season_number: number | null
	episode_number: number | null
	season_title: string | null
	display_title: string | null
	display_description: string | null
	first_seen_at: string | null
	last_seen_at: string | null
	removed_at: string | null
	removed_reason: string | null
	ignored_reason: string | null
	created_at: string
	updated_at: string
	media_item: {
		title: string
		state: string
		thumbnail_uri: string | null
		duration_seconds: number | null
		content_type: string | null
		published_at: string | null
		failure_reason: string | null
	} | null
	total_asset_size_bytes: number | null
}

interface StashInputSummary {
	id: string
	stash_id: string
	provider_key: string
	input_type: string
	source_uri: string
	provider_input_id: string
	state: string
	consecutive_failures: number
	title: string | null
	sync_mode: string | null
	options: {
		title_regex_include: string | null
		title_regex_exclude: string | null
		provider: Record<string, boolean | string>
	} | null
	input_options: InputOptionDeclaration[]
	last_checked_at: string | null
	next_check_at: string | null
	last_success_at: string | null
	last_failure_at: string | null
	created_at: string
	updated_at: string
}

interface BroadcastSummary {
	id: string
	stash_id: string
	type: string
	name: string
	slug: string
	state: string
	settings: Record<string, unknown> | null
	last_planned_at: string | null
	last_built_at: string | null
	last_verified_at: string | null
	last_error: string | null
	created_at: string
	updated_at: string
	feed_url?: string
	token_preview?: string
	plugin_detail_fields?: Array<{ id: string; label: string; value: unknown; kind?: string; link?: string }>
	plugin_actions?: Array<{ id: string; label: string; intent: string; confirmation?: boolean }>
	items: BroadcastItemSummary[]
	impact: BroadcastCreationPreviewSummary | null
}

interface BroadcastItemSummary {
	id: string
	broadcast_id: string
	stash_item_id: string
	media_item_id: string
	state: string
	last_error: string | null
	media_item: { title: string } | null
}

interface BroadcastCreationPreviewSummary {
	eligible_item_count: number
	skipped_item_count: number
	vault_size_bytes: number
	hardlinked_item_count: number
	transcode_item_count: number
}

interface BroadcastPluginUiControl {
	name: string
	label: string
	type: string
	default: unknown
	options: string[]
}

interface BroadcastPluginSummary {
	key: string
	label: string
	description: string
	supported_file_kinds: string[]
	ui_controls: BroadcastPluginUiControl[]
}

interface MediaItemSummary {
	id: string
	provider_key: string
	provider_item_id: string
	canonical_uri: string
	title: string
	description: string | null
	state: string
	upstream_state: string
	content_type: string | null
	creator_name: string | null
	duration_seconds: number | null
	published_at: string | null
	thumbnail_uri: string | null
	last_seen_upstream_at: string | null
	created_at: string
	updated_at: string
}

interface AssetSummary {
	id: string
	media_item_id: string
	broadcast_id: string | null
	role: string
	kind: string
	state: string
	derived_from_asset_id: string | null
	path: string | null
	relative_path: string | null
	mime_type: string | null
	container: string | null
	size_bytes: number | null
	checksum: string | null
	duration_seconds: number | null
	last_verified_at: string | null
	missing_at: string | null
	missing_reason: string | null
	created_at: string
	updated_at: string
	generated_by: string | null
	can_regenerate: boolean | null
	safe_to_delete: boolean | null
}

interface ApiTokenSummary {
	id: string
	name: string
	token_preview: string
	scopes: string[]
	last_used_at: string | null
	expires_at: string | null
	created_at: string
}

interface YoutubeApiKeyStatus {
	configured: boolean
}

interface MediaServerSummary {
	id: string
	type: string
	name: string
	base_uri: string
	state: string
	settings: Record<string, unknown> | null
	last_checked_at: string | null
	last_error: string | null
	created_at: string
	updated_at: string
}

interface LibrarySummary {
	id: string
	name: string
	type: string | null
}

async function describeFailedResponse(response: Response): Promise<string> {
	const requestId = response.headers.get('X-Stashd-Request-Id')
	const reference = requestId ? ` [request ${requestId}]` : ''

	try {
		const body = (await response.json()) as { error?: { message?: string } }
		return `${body.error?.message ?? `HTTP ${response.status}`}${reference}`
	} catch {
		return `HTTP ${response.status}${reference}`
	}
}

function describeFailureReason(cause: unknown): string {
	return cause instanceof Error ? cause.message : String(cause)
}

function dashboardComponent() {
	return {
		loading: true,
		error: null as string | null,
		health: null as HealthReport | null,
		stashCount: null as number | null,
		vaultCount: null as number | null,
		activitySummary: [] as ActivitySummaryGroup[],
		formatRelativeTime,
		formatBytes,
		statusBadge,

		totalFreeBytes(): number | null {
			const locations = this.health?.storage?.locations ?? []
			return locations.length === 0 ? null : locations.reduce((sum, location) => sum + (location.free_bytes ?? 0), 0)
		},

		totalDiskBytes(): number | null {
			const locations = this.health?.storage?.locations ?? []
			return locations.length === 0 ? null : locations.reduce((sum, location) => sum + (location.total_bytes ?? 0), 0)
		},

		async init() {
			await this.refresh()
			this.loading = false

			if ('EventSource' in window) {
				subscribeToEvents(EVENT_TYPES, (event) => {
				const activity = realtimeActivity(event)
				if (activity === null) return

				const current = this.activitySummary[0]
				const next = current?.type === activity.type && current.stash_id === activity.stash_id
					? { ...current, count: current.count + 1, level: activity.level, message: activity.message, created_at: activity.created_at }
					: { type: activity.type, level: activity.level, stash_id: activity.stash_id, message: activity.message, count: 1, created_at: activity.created_at }
				this.activitySummary = current?.type === activity.type && current.stash_id === activity.stash_id
					? [next, ...this.activitySummary.slice(1)].slice(0, 8)
					: [next, ...this.activitySummary].slice(0, 8)

				const job = realtimeJob(event)
				if (job?.intent === 'storage_check' && isTerminalJobEvent(event)) void this.refreshHealth()
			})
				subscribeToConnectionState((connected, reconnected) => {
					if (connected && reconnected) void this.refresh()
				})
			} else {
				setInterval(() => void this.refresh(), 5000)
			}
		},

		async refresh() {
			// Each endpoint runs independently (Promise.allSettled, not
			// Promise.all) so one bad endpoint doesn't hide which one it was
			// behind a single generic "could not reach the server" message.
			const endpoints: Array<{ label: string; run: () => Promise<void> }> = [
				{
					label: 'system health',
					run: async () => {
						const response = await getWithRetry('/api/v1/system/health')
						if (!response.ok) throw new Error(await describeFailedResponse(response))
						this.health = await response.json()
					},
				},
				{
					label: 'stashes',
					run: async () => {
						const response = await getWithRetry('/api/v1/stashes')
						if (!response.ok) throw new Error(await describeFailedResponse(response))
						this.stashCount = ((await response.json()).stashes as unknown[]).length
					},
				},
				{
					label: 'vault items',
					run: async () => {
						const response = await getWithRetry('/api/v1/items')
						if (!response.ok) throw new Error(await describeFailedResponse(response))
						this.vaultCount = ((await response.json()).items as unknown[]).length
					},
				},
				{
					label: 'activity',
					run: async () => {
						const response = await getWithRetry('/api/v1/activity')
						if (!response.ok) throw new Error(await describeFailedResponse(response))
						this.activitySummary = summarizeRecentActivity((await response.json()).events as ActivityLogEntry[])
					},
				},
			]

			const results = await Promise.allSettled(endpoints.map((endpoint) => endpoint.run()))

			if (results.some((result) => result.status === 'rejected' && result.reason instanceof UnauthenticatedError)) {
				return
			}

			const failures = results
				.map((result, index) => (result.status === 'rejected' ? `${endpoints[index].label} (${describeFailureReason(result.reason)})` : null))
				.filter((message): message is string => message !== null)

			this.error = failures.length > 0 ? `Could not reach: ${failures.join(', ')}.` : null
		},

		async refreshHealth() {
			try {
				const response = await getWithRetry('/api/v1/system/health')
				if (response.ok) this.health = await response.json()
			} catch {
				// The reconnect resync remains the recovery path for a transient miss.
			}
		},
	}
}

function activityComponent() {
	return {
		loading: true,
		events: [] as ActivityEvent[],
		connected: false,
		// SSE replaces `events` wholesale on every notification; tracking
		// open-state here (rather than relying on <details>'s own DOM state)
		// is what survives that, since x-bind:open re-derives it from this
		// set every render instead of trusting whatever the browser kept.
		expandedEventIds: new Set<string>(),
		formatRelativeTime,
		eventBadge,
		summarize: summarizeEvent,

		toggleEventDisclosure(id: string, open: boolean) {
			if (open) {
				this.expandedEventIds.add(id)
			} else {
				this.expandedEventIds.delete(id)
			}
		},

		async init() {
			try {
				const response = await apiFetch('/api/v1/activity')
				const entries = ((await response.json()).events as ActivityLogEntry[]) ?? []
				this.events = entries.map(activityLogEntryToEvent)
			} catch (cause) {
				if (cause instanceof UnauthenticatedError) return
				// Backfill failing shouldn't block the live feed below -- an
				// empty list here just means "No activity yet" until the
				// first live event arrives, same as before this existed.
			} finally {
				this.loading = false
			}

			if (!('EventSource' in window)) return

			subscribeToConnectionState((connected) => {
				this.connected = connected
			})
			subscribeToEvents(EVENT_TYPES, (parsed) => {
				this.events = [parsed, ...this.events.filter((event) => event.id !== parsed.id)].slice(0, 200)
			})
		},
	}
}

function stashesComponent() {
	return {
		loading: true,
		error: null as string | null,
		stashes: [] as StashSummary[],
		statusBadge,

		editingStash: null as StashSummary | null,
		editForm: { name: '', description: '', syncMode: 'automatic', downloadPolicy: 'video', organizationMode: 'flat' } as StashEditForm,
		savingEdit: false,

		deletingStash: null as StashSummary | null,
		deleteImpact: null as StashDeleteImpactSummary | null,
		loadingDeleteImpact: false,
		deletingBusy: false,

		async init() {
			await this.refresh()
			this.loading = false

			if ('EventSource' in window) {
				subscribeToEvents(['activity.created'], (event) => {
					if (realtimeActivity(event)?.entity_type === 'stash') void this.refresh()
				})
				subscribeToConnectionState((connected, reconnected) => {
					if (connected && reconnected) void this.refresh()
				})
			}
		},

		async refresh() {
			try {
				const response = await apiFetch('/api/v1/stashes')
				this.stashes = (await response.json()).stashes
				this.error = null
			} catch (cause) {
				if (cause instanceof UnauthenticatedError) return
				this.error = 'Could not reach the server.'
			}
		},

		startEdit(stash: StashSummary) {
			this.editingStash = stash
			this.editForm = {
				name: stash.name,
				description: stash.description ?? '',
				syncMode: stash.sync_mode,
				downloadPolicy: stash.download_policy,
				organizationMode: stash.organization_mode,
			}
		},

		cancelEdit() {
			this.editingStash = null
		},

		async saveEdit() {
			if (!this.editingStash || this.editForm.name.trim() === '') return
			this.savingEdit = true
			try {
				const response = await apiFetch(`/api/v1/stashes/${this.editingStash.id}`, {
					method: 'PATCH',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({
						name: this.editForm.name.trim(),
						description: this.editForm.description.trim(),
						sync_mode: this.editForm.syncMode,
						download_policy: this.editForm.downloadPolicy,
						organization_mode: this.editForm.organizationMode,
					}),
				})
				if (!response.ok) {
					const body = (await response.json()) as { error?: { message?: string } }
					this.error = body.error?.message ?? 'Could not update that stash.'
					return
				}
				this.editingStash = null
				this.error = null
				await this.refresh()
			} catch (cause) {
				if (cause instanceof UnauthenticatedError) return
				this.error = 'Could not reach the server.'
			} finally {
				this.savingEdit = false
			}
		},

		async startDelete(stash: StashSummary) {
			this.deletingStash = stash
			this.deleteImpact = null
			this.loadingDeleteImpact = true
			try {
				const response = await apiFetch(`/api/v1/stashes/${stash.id}/delete-impact`)
				this.deleteImpact = (await response.json()).delete_impact
			} catch (cause) {
				if (cause instanceof UnauthenticatedError) return
				this.error = 'Could not load delete impact.'
			} finally {
				this.loadingDeleteImpact = false
			}
		},

		cancelDelete() {
			this.deletingStash = null
			this.deleteImpact = null
		},

		async confirmDelete() {
			if (!this.deletingStash) return
			this.deletingBusy = true
			try {
				const response = await apiFetch(`/api/v1/stashes/${this.deletingStash.id}`, { method: 'DELETE' })
				if (!response.ok) {
					const body = (await response.json()) as { error?: { message?: string } }
					this.error = body.error?.message ?? 'Could not delete that stash.'
					return
				}
				this.deletingStash = null
				this.deleteImpact = null
				await this.refresh()
			} catch (cause) {
				if (cause instanceof UnauthenticatedError) return
				this.error = 'Could not reach the server.'
			} finally {
				this.deletingBusy = false
			}
		},
	}
}

function createStashComponent() {
	return {
		sourceUri: '',
		reviewedSourceUri: '',
		step: 'idle' as 'idle' | 'reviewing' | 'review' | 'creating' | 'committing' | 'failed',
		busy: false,
		error: null as string | null,
		preflightCommandId: null as string | null,
		commitCommandId: null as string | null,
		createdStashId: null as string | null,
		sseCancel: null as (() => void) | null,
		resolved: null as ResolvedInputSummary | null,
		estimatedItemCount: null as number | null,
		estimatedTotalDurationSeconds: null as number | null,
		sampleItems: [] as DiscoveredItemSummary[],
		universalFilters: [] as UniversalFilterDeclaration[],
		inputOptions: [] as InputOptionDeclaration[],
		titleRegexInclude: '',
		titleRegexExclude: '',
		providerOptions: {} as Record<string, boolean | string>,
		form: { name: '', description: '', syncMode: 'automatic', downloadPolicy: 'video', organizationMode: 'flat' } as StashEditForm,
		formatDuration,

		sourceNeedsReview() {
			return this.sourceUri.trim() !== this.reviewedSourceUri
		},

		async reviewSource() {
			const sourceUri = this.sourceUri.trim()
			if (sourceUri === '' || this.busy) return

			this.sseCancel?.()
			this.sseCancel = null
			this.busy = true
			this.error = null
			this.reviewedSourceUri = ''
			this.resolved = null
			this.estimatedItemCount = null
			this.estimatedTotalDurationSeconds = null
			this.sampleItems = []
			this.universalFilters = []
			this.inputOptions = []
			this.titleRegexInclude = ''
			this.titleRegexExclude = ''
			this.providerOptions = {}

			try {
				const response = await apiFetch('/api/v1/stashes/preflight', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({ source_uri: sourceUri, origin: 'web_ui' }),
				})
				if (!response.ok) {
					const body = (await response.json()) as { error?: { message?: string } }
					this.error = body.error?.message ?? 'Could not start discovery.'
					this.busy = false
					return
				}

				const body = (await response.json()) as { command_id: string }
				this.preflightCommandId = body.command_id
				this.step = 'reviewing'
				this.sseCancel = awaitSseTerminal(() => this.checkPreflightTerminal(sourceUri))
			} catch (cause) {
				if (cause instanceof UnauthenticatedError) return
				this.error = 'Could not reach the server.'
				this.busy = false
			}
		},

		async checkPreflightTerminal(sourceUri: string): Promise<boolean> {
			try {
				const response = await apiFetch(`/api/v1/commands/${this.preflightCommandId}`)
				const body = (await response.json()) as CommandShowResponse

				if (body.command.state === 'completed') {
					const review = await apiFetch(`/api/v1/stashes/preflight/${this.preflightCommandId}/review`)
					const reviewBody = (await review.json()) as PreflightReview
					const discovery = reviewBody.preflight?.discovery ?? null
					this.resolved = reviewBody.preflight?.resolved_input ?? null
					this.estimatedItemCount = discovery?.estimated_item_count ?? null
					this.estimatedTotalDurationSeconds = discovery?.estimated_total_duration_seconds ?? null
					this.sampleItems = discovery?.sample_items ?? []
					this.universalFilters = reviewBody.preflight?.universal_filters ?? []
					this.inputOptions = reviewBody.preflight?.input_options ?? []
					this.providerOptions = Object.fromEntries(this.inputOptions.map((option) => [option.key, option.default]))
					this.reviewedSourceUri = sourceUri
					this.step = 'review'
					this.busy = false
					return true
				}

				if (body.command.state === 'failed' || body.command.state === 'rejected') {
					const lastError = body.jobs[0]?.last_error ?? 'Discovery failed.'
					this.error = lastError.startsWith('unsupported_provider_url:') ? "That source isn't supported yet." : lastError
					this.step = 'failed'
					this.busy = false
					return true
				}

				return false
			} catch (cause) {
				if (cause instanceof UnauthenticatedError) return true
				return false
			}
		},

		async create() {
			if (this.busy || (this.sourceUri.trim() !== '' && this.sourceNeedsReview())) return
			this.busy = true
			this.error = null

			try {
				if (this.createdStashId === null) {
					this.step = 'creating'
					const response = await apiFetch('/api/v1/stashes', {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify({
							name: this.form.name.trim() || undefined,
							description: this.form.description.trim() || undefined,
							sync_mode: this.form.syncMode,
							download_policy: this.form.downloadPolicy,
							organization_mode: this.form.organizationMode,
						}),
					})
					if (!response.ok) {
						const body = (await response.json()) as { error?: { message?: string } }
						this.error = body.error?.message ?? 'Could not create the stash.'
						this.step = 'failed'
						this.busy = false
						return
					}

					const body = (await response.json()) as { stash: StashSummary }
					this.createdStashId = body.stash.id
				}

				if (this.sourceUri.trim() === '') {
					window.location.assign(`/stashes/${this.createdStashId}`)
					return
				}

				await this.commitInput()
			} catch (cause) {
				if (cause instanceof UnauthenticatedError) return
				this.error = 'Could not reach the server.'
				this.step = 'failed'
				this.busy = false
			}
		},

		async commitInput() {
			const response = await apiFetch(`/api/v1/stashes/${this.createdStashId}/inputs`, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify({
					preflight_command_id: this.preflightCommandId,
					options: {
						title_regex_include: this.titleRegexInclude.trim() || undefined,
						title_regex_exclude: this.titleRegexExclude.trim() || undefined,
						provider: this.providerOptions,
					},
				}),
			})
			if (!response.ok) {
				const body = (await response.json()) as { error?: { message?: string } }
				this.error = body.error?.message ?? 'Could not add that input.'
				this.step = 'failed'
				this.busy = false
				return
			}

			const body = (await response.json()) as { command_id: string }
			this.commitCommandId = body.command_id
			this.step = 'committing'
			this.sseCancel = awaitSseTerminal(() => this.checkCommitTerminal())
		},

		async checkCommitTerminal(): Promise<boolean> {
			try {
				const response = await apiFetch(`/api/v1/commands/${this.commitCommandId}`)
				const body = (await response.json()) as CommandShowResponse

				if (body.command.state === 'completed') {
					window.location.assign(`/stashes/${this.createdStashId}`)
					return true
				}

				if (body.command.state === 'failed' || body.command.state === 'rejected') {
					this.error = body.jobs[0]?.last_error ?? 'Adding the input failed.'
					this.step = 'failed'
					this.busy = false
					return true
				}

				return false
			} catch (cause) {
				if (cause instanceof UnauthenticatedError) return true
				return false
			}
		},
	}
}

function stashDetailComponent(stashId: string) {
	return {
		loading: true,
		error: null as string | null,
		stash: null as StashSummary | null,
		items: [] as StashItemSummary[],
		itemsTotal: 0,
		stashItemCount: 0,
		itemsLimit: 50,
		itemsOffset: 0,
		itemsRefreshing: false,
		itemStatusCounts: {} as Record<string, number>,
		itemIgnoredCount: 0,
		itemSearchDebounce: null as ReturnType<typeof setTimeout> | null,
		itemSortKey: 'published' as ItemSortKey,
		itemSortDir: 'desc' as 'asc' | 'desc',
		itemStatusFilter: 'all',
		itemSearch: '',
		showIgnored: false,
		jobs: [] as JobSummary[],
		inputs: [] as StashInputSummary[],
		editingInputFiltersId: null as string | null,
		editInputTitleRegexInclude: '',
		editInputTitleRegexExclude: '',
		editInputProviderOptions: {} as Record<string, boolean | string>,
		savingInputFilters: null as string | null,
		broadcasts: [] as BroadcastSummary[],
		broadcastPlugins: [] as BroadcastPluginSummary[],
		actionPending: null as string | null,
		actionFeedback: null as string | null,
		newBroadcastType: 'podcast',
		newBroadcastMediaKind: 'audio',
		newBroadcastName: '',
		newBroadcastDestinationPath: '',
		creatingBroadcast: false,
		broadcastPreview: null as BroadcastCreationPreviewSummary | null,
		loadingBroadcastPreview: false,
		compatibleDownloadPolicyChoice: 'video',
		updatingDownloadPolicy: false,
		seasonMappingDrafts: {} as Record<string, Record<string, string>>,
		savingSeasonMapping: null as string | null,
		destinationPathDrafts: {} as Record<string, string>,
		savingDestinationPath: null as string | null,
		statusBadge,
		formatRelativeTime,
		formatDuration,
		formatBytes,

		editingOpen: false,
		editForm: { name: '', description: '', syncMode: 'automatic', downloadPolicy: 'video', organizationMode: 'flat' } as StashEditForm,
		savingEdit: false,

		deletingOpen: false,
		deleteImpact: null as StashDeleteImpactSummary | null,
		loadingDeleteImpact: false,
		deletingBusy: false,

		addInputOpen: false,
		addInputStep: 'paste' as 'paste' | 'reviewing' | 'review' | 'committing' | 'failed',
		addInputSourceUri: '',
		addInputSubmitting: false,
		addInputError: null as string | null,
		addInputPreflightCommandId: null as string | null,
		addInputCommitCommandId: null as string | null,
		addInputSseCancel: null as (() => void) | null,
		addInputResolved: null as ResolvedInputSummary | null,
		addInputEstimatedItemCount: null as number | null,
		addInputEstimatedTotalDurationSeconds: null as number | null,
		addInputSampleItems: [] as DiscoveredItemSummary[],
		addInputFailureMessage: null as string | null,
		addInputUnsupported: false,
		addInputUniversalFilters: [] as UniversalFilterDeclaration[],
		addInputInputOptions: [] as InputOptionDeclaration[],
		addInputTitleRegexInclude: '',
		addInputTitleRegexExclude: '',
		addInputProviderOptions: {} as Record<string, boolean | string>,

		async init() {
			await Promise.all([this.refresh(), this.loadBroadcastPlugins()])
			this.loading = false

			this.onBroadcastTypeChanged()
			const watch = (this as unknown as { $watch(property: string, callback: () => void): void }).$watch.bind(this)

			// Filter/search/showIgnored changes are query params now (server
			// -side filtering, see refreshItems()), not a client-side recompute
			// -- each needs its own re-fetch, not just an offset reset. Search
			// is debounced so typing doesn't fire a request per keystroke.
			watch('itemStatusFilter', () => {
				this.itemsOffset = 0
				void this.refreshItems()
			})
			watch('showIgnored', () => {
				this.itemsOffset = 0
				void this.refreshItems()
			})
			watch('itemSearch', () => {
				this.itemsOffset = 0
				if (this.itemSearchDebounce) clearTimeout(this.itemSearchDebounce)
				this.itemSearchDebounce = setTimeout(() => void this.refreshItems(), 300)
			})

			const link = new URLSearchParams(window.location.search).get('link')
			if (link) {
				window.history.replaceState({}, '', window.location.pathname)
				this.openAddInput()
				this.addInputSourceUri = link
				void this.submitAddInputPreflight()
			}

			if ('EventSource' in window) {
				subscribeToEvents(EVENT_TYPES, (event) => void this.applyRealtime(event))
				subscribeToConnectionState((connected, reconnected) => {
					if (connected && reconnected) void this.refresh()
				})
			} else {
				setInterval(() => void this.refresh(), 5000)
			}
		},

		applyRealtime(event: ActivityEvent) {
			const job = realtimeJob(event)
			if (job !== null) {
				this.jobs = upsertJob(this.jobs, job)
				if (!isTerminalJobEvent(event)) return

				if (job.entity_type === 'media_item') void this.refreshItems()
				if (job.entity_type === 'broadcast') void this.refreshBroadcasts()
				return
			}

			const activity = realtimeActivity(event)
			if (activity?.stash_id !== stashId) return
			if (activity.media_item_id !== null) void this.refreshItems()
			if (activity.broadcast_id !== null) void this.refreshBroadcasts()
		},

		// Fetches just the current page, filtered/sorted server-side --
		// search/status/sort/showIgnored are query params now
		// (StashController::items()), not a client-side recompute over a
		// fully-materialized list. Catches its own errors (rather than
		// throwing) so a failure here doesn't abort the Promise.all in
		// refresh(), matching every other endpoint's fallback-on-failure
		// behavior there.
		async refreshItems() {
			const params = new URLSearchParams({
				limit: String(this.itemsLimit),
				offset: String(this.itemsOffset),
				sort: this.itemSortKey,
				dir: this.itemSortDir,
				include_ignored: this.showIgnored ? 'true' : 'false',
			})
			if (this.itemStatusFilter !== 'all') params.set('status', this.itemStatusFilter)
			if (this.itemSearch.trim() !== '') params.set('search', this.itemSearch.trim())

			try {
				const response = await apiFetch(`/api/v1/stashes/${stashId}/items?${params.toString()}`)
				const body = (await response.json()) as {
					items?: StashItemSummary[]
					total?: number
					status_counts?: Record<string, number>
					ignored_count?: number
					stash_item_count?: number
				}
				this.items = body.items ?? this.items
				this.itemsTotal = body.total ?? this.itemsTotal
				this.itemStatusCounts = body.status_counts ?? this.itemStatusCounts
				this.itemIgnoredCount = body.ignored_count ?? this.itemIgnoredCount
				this.stashItemCount = body.stash_item_count ?? this.stashItemCount
			} catch (cause) {
				if (cause instanceof UnauthenticatedError) return
				this.error = 'Could not reach the server.'
			}
		},

		async refresh() {
			// SSE events fire on every job update and can arrive faster than a
			// refresh cycle completes -- without this guard, overlapping
			// refreshes would pile up.
			if (this.itemsRefreshing) return
			this.itemsRefreshing = true

			try {
				const [stashResponse, , inputsResponse, broadcastsResponse, jobsResponse] = await Promise.all([
					apiFetch(`/api/v1/stashes/${stashId}`),
					this.refreshItems(),
					apiFetch(`/api/v1/stashes/${stashId}/inputs`),
					apiFetch(`/api/v1/stashes/${stashId}/broadcasts`),
					apiFetch('/api/v1/jobs'),
				])
				const [stashBody, inputsBody, broadcastsBody, jobsBody] = await Promise.all([
					stashResponse.json(),
					inputsResponse.json(),
					broadcastsResponse.json(),
					jobsResponse.json(),
				])
				// A transient error response is still valid JSON (an `{ error }`
				// envelope), just without the expected key -- fall back to the
				// current value instead of wiping it to undefined and breaking
				// every template expression that reads it until the next
				// successful refresh.
				this.stash = stashBody.stash ?? this.stash
				this.inputs = inputsBody.inputs ?? this.inputs
				this.broadcasts = broadcastsBody.broadcasts ?? this.broadcasts
				this.jobs = jobsBody.jobs ?? this.jobs
				this.error = null
			} catch (cause) {
				if (cause instanceof UnauthenticatedError) return
				this.error = 'Could not reach the server.'
			} finally {
				this.itemsRefreshing = false
			}
		},

		async refreshBroadcasts() {
			try {
				const response = await apiFetch(`/api/v1/stashes/${stashId}/broadcasts`)
				const body = await response.json()
				this.broadcasts = body.broadcasts ?? this.broadcasts
			} catch (cause) {
				if (!(cause instanceof UnauthenticatedError)) this.error = 'Could not reach the server.'
			}
		},

		// Static per-session: the plugin registry doesn't change while the page
		// is open, so this is fetched once in init() rather than on every
		// refresh() (unlike stash/items/inputs/broadcasts/jobs, which do).
		async loadBroadcastPlugins() {
			try {
				const response = await apiFetch('/api/v1/broadcast-plugins')
				const body = await response.json()
				const plugins = (body.plugins ?? this.broadcastPlugins) as BroadcastPluginSummary[]
				this.broadcastPlugins = plugins.sort((left, right) => {
					return 0
				})
				this.newBroadcastType = this.broadcastPlugins[0]?.key ?? this.newBroadcastType
			} catch (cause) {
				if (cause instanceof UnauthenticatedError) return
				this.error = 'Could not reach the server.'
			}
		},

		// Mirrors App\Broadcasts\Plugins\AbstractSeriesBroadcastPlugin --
		// series plugins support video only, unlike the podcast plugin (audio
		// and video). No dedicated "is series" flag on BroadcastPlugin itself
		// since it'd only ever have one caller.
		isSeriesBroadcastType(type: string): boolean {
			const plugin = this.broadcastPlugins.find((candidate) => candidate.key === type)
			return plugin !== undefined && plugin.supported_file_kinds.length === 1 && plugin.supported_file_kinds[0] === 'video'
		},

		// Download/metadata jobs record entity_type 'media_item' + entity_id on
		// creation (ItemDownloadCommandHandler) — matching on that, rather than
		// a per-item field, is what lets one items-list query (T11) show live
		// progress without the backend needing to know about jobs at all.
		activeJobFor(mediaItemId: string): JobSummary | null {
			return this.jobs.find((job) => job.entity_type === 'media_item' && job.entity_id === mediaItemId && job.state === 'processing') ?? null
		},

		broadcastJobFor(broadcastId: string): JobSummary | null {
			return this.jobs.find((job) => job.entity_type === 'broadcast' && job.entity_id === broadcastId && ['pending', 'processing'].includes(job.state)) ?? null
		},

		broadcastTranscodeJobs(broadcast: BroadcastSummary): JobSummary[] {
			const mediaItemIds = new Set(broadcast.items.map((item) => item.media_item_id))
			return this.jobs.filter((job) => job.intent === 'transcode_podcast_audio'
				&& job.entity_id !== null
				&& mediaItemIds.has(job.entity_id)
				&& ['pending', 'processing'].includes(job.state))
		},

		broadcastDetailField(broadcast: BroadcastSummary, kind: string): string | null {
			const field = broadcast.plugin_detail_fields?.find((candidate) => candidate.kind === kind)
			return field && typeof field.value === 'string' ? field.value : null
		},

		broadcastPluginHasControl(type: string, name: string): boolean {
			return this.broadcastPlugins.find((plugin) => plugin.key === type)?.ui_controls.some((control) => control.name === name) ?? false
		},

		broadcastReadyItemCount(broadcast: BroadcastSummary): number {
			return broadcast.items.filter((item) => item.state === 'ready').length
		},

		broadcastStatus(broadcast: BroadcastSummary): string {
			const job = this.broadcastJobFor(broadcast.id)
			if (job?.state === 'pending') return 'Queued — waiting for downloads and a worker'
			if (job?.state === 'processing') return 'Building broadcast'

			const transcodes = this.broadcastTranscodeJobs(broadcast)
			if (transcodes.length > 0) return `${transcodes.length} audio transcode${transcodes.length === 1 ? '' : 's'} in progress — this will update automatically`

			if (broadcast.last_built_at === null) return 'Not built yet — nothing is running'
			if ((broadcast.impact?.eligible_item_count ?? 0) === 0) {
				const waiting = broadcast.impact?.skipped_item_count ?? 0
				return waiting > 0
					? `Built; waiting for ${waiting} item${waiting === 1 ? '' : 's'} to finish downloading`
					: 'Built; waiting for items to be discovered'
			}
			if (broadcast.state === 'failed') return 'Build failed'
			if (broadcast.state === 'stale') return `${this.broadcastProblemItems(broadcast).length} item${this.broadcastProblemItems(broadcast).length === 1 ? '' : 's'} need attention`

			return `Up to date · built ${formatRelativeTime(broadcast.last_built_at)}`
		},

		// Compact "N ready · N processing · N stale" summary of a broadcast's
		// items, in a stable order so the row doesn't reshuffle as counts change.
		broadcastItemStateCounts(broadcast: BroadcastSummary): Array<{ state: string; count: number }> {
			const counts = new Map<string, number>()
			for (const item of broadcast.items) {
				counts.set(item.state, (counts.get(item.state) ?? 0) + 1)
			}
			const order = ['processing', 'stale', 'failed', 'pending', 'ready', 'disabled']
			return order.filter((state) => counts.has(state)).map((state) => ({ state, count: counts.get(state)! }))
		},

		// Items that aren't settled as ready, with the reason attached -- e.g.
		// a pending transcode reads as "stale — podcast_audio_transcode_pending"
		// rather than the whole broadcast reading as one opaque failure.
		broadcastProblemItems(broadcast: BroadcastSummary): BroadcastItemSummary[] {
			return broadcast.items.filter((item) => item.state !== 'ready')
		},

		// "rebuild" already reprocesses every non-ready item regardless of
		// cause, so it's the default action for any error code. The one
		// exception: an unavailable asset means there's nothing for rebuild to
		// publish yet -- the source needs to come back first.
		broadcastProblemItemHint(item: BroadcastItemSummary): string {
			if (item.last_error?.endsWith('_asset_unavailable')) {
				return 'redownload the source item in the Vault first, then rebuild.'
			}
			if (item.last_error?.endsWith('_transcode_failed')) {
				return 'rebuild retries this, but not more than once every few minutes.'
			}
			return 'rebuild retries this.'
		},

		// Static -- always shown in the status filter regardless of which
		// states are present, so the dropdown's option list never shifts out
		// from under the current selection (a <select> whose selected
		// <option> disappears silently resets to another one).
		itemStatusOptions(): string[] {
			return ITEM_STATUS_OPTIONS
		},

		setItemSort(key: ItemSortKey) {
			if (this.itemSortKey === key) {
				this.itemSortDir = this.itemSortDir === 'asc' ? 'desc' : 'asc'
			} else {
				this.itemSortKey = key
				this.itemSortDir = key === 'published' ? 'desc' : 'asc'
			}
			this.itemsOffset = 0
			void this.refreshItems()
		},

		itemSortIndicator(key: ItemSortKey): string {
			if (this.itemSortKey !== key) return ''
			return this.itemSortDir === 'asc' ? '↑' : '↓'
		},

		isDownloading(item: StashItemSummary): boolean {
			return this.activeJobFor(item.media_item_id) !== null
		},

		displayItems(): StashItemSummary[] {
			return this.items
		},

		activeDownloadJobs(): JobSummary[] {
			return this.jobs.filter((job) => job.intent === 'download'
				&& job.state === 'processing'
				&& job.payload?.stash_id === stashId)
		},

		overallDownloadProgress(): number {
			const jobs = this.activeDownloadJobs()
			return jobs.length === 0 ? 0 : jobs.reduce((total, job) => total + (job.progress_percent ?? 0), 0) / jobs.length
		},

		overallDownloadLabel(): string {
			const jobs = this.activeDownloadJobs()
			if (jobs.length === 1) return jobs[0].progress_label ?? 'Downloading item'
			return `Downloading ${jobs.length} items`
		},

		// Count of stash-item-level ignored items (StashItemState::Ignored) --
		// distinct from the media-item-level "ignored" status chip below, which
		// counts a different enum (MediaItemState). Fetched server-side
		// (StashController::items()'s ignored_count) since it must reflect the
		// whole stash regardless of the current page/filter.
		ignoredItemCount(): number {
			return this.itemIgnoredCount
		},

		// Header summary chips, e.g. "218 items" · "12 downloading" · "3
		// failed" -- clicking a chip sets itemStatusFilter to it, so the summary
		// doubles as a set of filter shortcuts. Counts come from the server
		// (status_counts, a real GROUP BY aggregate across the whole stash), not
		// a client-side tally over the current page.
		itemStatusSummary(): Array<{ label: string; filter: string }> {
			return ITEM_STATUS_OPTIONS.filter((status) => (this.itemStatusCounts[status] ?? 0) > 0).map((status) => ({
				label: `${this.itemStatusCounts[status]} ${statusBadge(status).label}`,
				filter: status,
			}))
		},

		itemRows(): Array<{ type: 'item' | 'progress'; key: string; item: StashItemSummary; job: JobSummary | null }> {
			return this.displayItems().map((item) => ({ type: 'item', key: item.id, item, job: null }))
		},

		hasPrevItemsPage(): boolean {
			return this.itemsOffset > 0
		},

		hasNextItemsPage(): boolean {
			return this.itemsOffset + this.itemsLimit < this.itemsTotal
		},

		prevItemsPage() {
			if (!this.hasPrevItemsPage()) return
			this.itemsOffset = Math.max(0, this.itemsOffset - this.itemsLimit)
			void this.refreshItems()
		},

		nextItemsPage() {
			if (!this.hasNextItemsPage()) return
			this.itemsOffset += this.itemsLimit
			void this.refreshItems()
		},

		itemsRangeLabel(): string {
			if (this.itemsTotal === 0) return ''
			const start = this.itemsOffset + 1
			const end = Math.min(this.itemsOffset + this.itemsLimit, this.itemsTotal)
			return `${start}–${end} of ${this.itemsTotal}`
		},

		// Re-dispatches item.download for a failed item -- MediaItemState
		// allows Failed -> DownloadPending -> Downloading, so this is exactly
		// the same command the initial download used, just issued again.
		async retryDownload(item: StashItemSummary) {
			this.actionPending = `${item.id}:retry`
			this.actionFeedback = null
			try {
				const response = await apiFetch('/api/v1/commands', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({
						type: 'item.download',
						options: { media_item_id: item.media_item_id, stash_id: item.stash_id },
					}),
				})
				if (!response.ok) {
					this.error = await describeFailedResponse(response)
					return
				}
				this.error = null
				this.actionFeedback = 'Retry queued.'
				await this.refresh()
			} catch (cause) {
				if (cause instanceof UnauthenticatedError) return
				this.error = 'Could not retry that download.'
			} finally {
				this.actionPending = null
			}
		},

		// Dispatches one server-side command that looks up every failed item
		// in this stash itself (not this.items -- which, once paginated,
		// would only ever reflect whatever page happens to be loaded) and
		// retries all of them.
		async retryAllFailed() {
			this.actionPending = 'retry-all'
			this.actionFeedback = null
			try {
				const response = await apiFetch('/api/v1/commands', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({
						type: 'stash.retry_failed',
						options: { stash_id: stashId },
					}),
				})
				if (!response.ok) {
					this.error = await describeFailedResponse(response)
					return
				}
				this.error = null
				this.actionFeedback = 'Retries queued.'
				await this.refresh()
			} catch (cause) {
				if (cause instanceof UnauthenticatedError) return
				this.error = 'Could not retry failed downloads.'
			} finally {
				this.actionPending = null
			}
		},

		async checkForNewItems() {
			this.actionPending = 'check-new'
			this.actionFeedback = null
			try {
				const response = await apiFetch(`/api/v1/stashes/${stashId}/sync`, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({}),
				})
				if (!response.ok) {
					this.error = await describeFailedResponse(response)
					return
				}
				this.error = null
				this.actionFeedback = 'Checking for new items…'
				await this.refresh()
			} catch (cause) {
				if (cause instanceof UnauthenticatedError) return
				this.error = 'Could not check for new items.'
			} finally {
				this.actionPending = null
			}
		},

		startEditInputFilters(input: StashInputSummary) {
			this.editingInputFiltersId = input.id
			this.editInputTitleRegexInclude = input.options?.title_regex_include ?? ''
			this.editInputTitleRegexExclude = input.options?.title_regex_exclude ?? ''
			this.editInputProviderOptions = Object.fromEntries(input.input_options.map((option) => [option.key, input.options?.provider?.[option.key] ?? option.default]))
		},

		cancelEditInputFilters() {
			this.editingInputFiltersId = null
		},

		async saveInputFilters(inputId: string) {
			this.savingInputFilters = inputId
			try {
				const response = await apiFetch(`/api/v1/stashes/${stashId}/inputs/${inputId}`, {
					method: 'PATCH',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({
						options: {
							title_regex_include: this.editInputTitleRegexInclude.trim() || undefined,
							title_regex_exclude: this.editInputTitleRegexExclude.trim() || undefined,
							provider: this.editInputProviderOptions,
						},
					}),
				})
				if (!response.ok) {
					const body = (await response.json()) as { error?: { message?: string } }
					this.error = body.error?.message ?? 'Could not update that input’s filters.'
					return
				}
				this.editingInputFiltersId = null
				this.error = null
				await this.refresh()
			} catch (cause) {
				if (cause instanceof UnauthenticatedError) return
				this.error = 'Could not reach the server.'
			} finally {
				this.savingInputFilters = null
			}
		},

		async runBroadcastAction(broadcastId: string, action: 'rebuild' | 'verify' | 'prune' | 'rotate_token') {
			this.actionPending = `${broadcastId}:${action}`
			try {
				await apiFetch('/api/v1/commands', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({
						type: `broadcast.${action}`,
						options: { broadcast_id: broadcastId },
					}),
				})
				await this.refresh()
			} catch (cause) {
				if (cause instanceof UnauthenticatedError) return
				this.error = 'Could not run that action.'
			} finally {
				this.actionPending = null
			}
		},

		async deleteBroadcast(broadcast: BroadcastSummary) {
			if (!window.confirm(`Delete “${broadcast.name}”? This removes its generated files but keeps Vault media.`)) return

			this.actionPending = `${broadcast.id}:delete`
			this.actionFeedback = null
			try {
				const response = await apiFetch(`/api/v1/broadcasts/${broadcast.id}`, { method: 'DELETE' })
				if (!response.ok) {
					this.error = await describeFailedResponse(response)
					return
				}

				this.error = null
				this.actionFeedback = 'Broadcast deletion queued.'
				await this.refresh()
			} catch (cause) {
				if (cause instanceof UnauthenticatedError) return
				this.error = 'Could not reach the server.'
			} finally {
				this.actionPending = null
			}
		},

		async copyFeedUrl(feedUrl: string) {
			await copyToClipboard(feedUrl)
		},

		startEdit() {
			if (!this.stash) return
			this.editForm = {
				name: this.stash.name,
				description: this.stash.description ?? '',
				syncMode: this.stash.sync_mode,
				downloadPolicy: this.stash.download_policy,
				organizationMode: this.stash.organization_mode,
			}
			this.editingOpen = true
		},

		cancelEdit() {
			this.editingOpen = false
		},

		async saveEdit() {
			if (this.editForm.name.trim() === '') return
			this.savingEdit = true
			try {
				const response = await apiFetch(`/api/v1/stashes/${stashId}`, {
					method: 'PATCH',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({
						name: this.editForm.name.trim(),
						description: this.editForm.description.trim(),
						sync_mode: this.editForm.syncMode,
						download_policy: this.editForm.downloadPolicy,
						organization_mode: this.editForm.organizationMode,
					}),
				})
				if (!response.ok) {
					const body = (await response.json()) as { error?: { message?: string } }
					this.error = body.error?.message ?? 'Could not update that stash.'
					return
				}
				this.editingOpen = false
				this.error = null
				await this.refresh()
			} catch (cause) {
				if (cause instanceof UnauthenticatedError) return
				this.error = 'Could not reach the server.'
			} finally {
				this.savingEdit = false
			}
		},

		async startDelete() {
			this.deletingOpen = true
			this.deleteImpact = null
			this.loadingDeleteImpact = true
			try {
				const response = await apiFetch(`/api/v1/stashes/${stashId}/delete-impact`)
				this.deleteImpact = (await response.json()).delete_impact
			} catch (cause) {
				if (cause instanceof UnauthenticatedError) return
				this.error = 'Could not load delete impact.'
			} finally {
				this.loadingDeleteImpact = false
			}
		},

		cancelDelete() {
			this.deletingOpen = false
			this.deleteImpact = null
		},

		async confirmDelete() {
			this.deletingBusy = true
			try {
				const response = await apiFetch(`/api/v1/stashes/${stashId}`, { method: 'DELETE' })
				if (!response.ok) {
					const body = (await response.json()) as { error?: { message?: string } }
					this.error = body.error?.message ?? 'Could not delete that stash.'
					this.deletingBusy = false
					return
				}
				window.location.assign('/stashes')
			} catch (cause) {
				if (cause instanceof UnauthenticatedError) return
				this.error = 'Could not reach the server.'
				this.deletingBusy = false
			}
		},

		broadcastPolicyMismatchMessage(): string | null {
			if (!this.stash || downloadPolicySatisfiesBroadcastType(this.stash.download_policy, this.newBroadcastType, this.newBroadcastMediaKind)) {
				return null
			}

			const policy = this.stash.download_policy.replace(/_/g, ' ')
			const type = this.newBroadcastType.replace(/_/g, ' ')
			return `This stash's "${policy}" download policy won't produce media for a "${type}" broadcast.`
		},

		compatibleDownloadPolicies(): string[] {
			return compatibleDownloadPolicies(this.newBroadcastType, this.newBroadcastMediaKind)
		},

		onBroadcastTypeChanged() {
			const compatible = compatibleDownloadPolicies(this.newBroadcastType, this.newBroadcastMediaKind)
			if (!compatible.includes(this.compatibleDownloadPolicyChoice)) {
				this.compatibleDownloadPolicyChoice = compatible[0] ?? 'video'
			}
			// Whatever was previewed no longer reflects the current choice.
			this.broadcastPreview = null
		},

		// Storage-impact preview before actually creating anything -- what
		// createBroadcast() used to do immediately. No side effects on the
		// server (see BroadcastLifecycleService::preview): nothing is
		// created or persisted just by looking.
		async previewBroadcastCreation() {
			this.loadingBroadcastPreview = true
			try {
				const response = await apiFetch(`/api/v1/stashes/${stashId}/broadcasts/preview`, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({
						type: this.newBroadcastType,
						...(this.newBroadcastType === 'podcast' ? { mediaKind: this.newBroadcastMediaKind } : {}),
					}),
				})
				if (!response.ok) {
					const body = (await response.json()) as { error?: { message?: string } }
					this.error = body.error?.message ?? 'Could not preview that broadcast.'
					return
				}
				const body = (await response.json()) as { preview: BroadcastCreationPreviewSummary }
				this.broadcastPreview = body.preview
				this.error = null
			} catch (cause) {
				if (cause instanceof UnauthenticatedError) return
				this.error = 'Could not reach the server.'
			} finally {
				this.loadingBroadcastPreview = false
			}
		},

		cancelBroadcastPreview() {
			this.broadcastPreview = null
		},

		async updateStashDownloadPolicyTo(policy: string) {
			this.updatingDownloadPolicy = true
			try {
				const response = await apiFetch(`/api/v1/stashes/${stashId}`, {
					method: 'PATCH',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({ download_policy: policy }),
				})
				if (!response.ok) {
					const body = (await response.json()) as { error?: { message?: string } }
					this.error = body.error?.message ?? 'Could not update the download policy.'
					return
				}
				this.error = null
				await this.refresh()
			} catch (cause) {
				if (cause instanceof UnauthenticatedError) return
				this.error = 'Could not reach the server.'
			} finally {
				this.updatingDownloadPolicy = false
			}
		},

		async createBroadcast() {
			this.creatingBroadcast = true
			const settings: Record<string, unknown> = this.newBroadcastType === 'podcast' ? { media_kind: this.newBroadcastMediaKind } : {}
			const destinationPath = this.newBroadcastDestinationPath.trim()
			if (destinationPath !== '') settings.destination_path = destinationPath
			const name = this.newBroadcastName.trim()
			try {
				const response = await apiFetch(`/api/v1/stashes/${stashId}/broadcasts`, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({
						type: this.newBroadcastType,
						...(name !== '' ? { name } : {}),
						...(Object.keys(settings).length > 0 ? { settings } : {}),
					}),
				})
				if (!response.ok) {
					const body = (await response.json()) as { error?: { message?: string } }
					this.error = body.error?.message ?? 'Could not create that broadcast.'
					return
				}
				this.newBroadcastName = ''
				this.newBroadcastDestinationPath = ''
				this.broadcastPreview = null
				this.error = null
				await this.refresh()
			} catch (cause) {
				if (cause instanceof UnauthenticatedError) return
				this.error = 'Could not reach the server.'
			} finally {
				this.creatingBroadcast = false
			}
		},

		// Initializes a broadcast's season-mapping draft from its current
		// settings the first time it's rendered — never on refresh, so an
		// in-progress edit isn't clobbered by a background SSE-triggered refresh.
		ensureSeasonMappingDraft(broadcast: BroadcastSummary) {
			if (!this.isSeriesBroadcastType(broadcast.type)) return

			const existing = (broadcast.settings?.season_mapping ?? {}) as Record<string, number>
			const draft = this.seasonMappingDrafts[broadcast.id] ?? {}

			for (const input of this.inputs) {
				if (!(input.id in draft)) {
					draft[input.id] = existing[input.id] !== undefined ? String(existing[input.id]) : ''
				}
			}

			this.seasonMappingDrafts[broadcast.id] = draft
		},

		async saveSeasonMapping(broadcastId: string) {
			this.savingSeasonMapping = broadcastId
			try {
				const draft = this.seasonMappingDrafts[broadcastId] ?? {}
				const mapping: Record<string, number> = {}

				for (const [inputId, value] of Object.entries(draft)) {
					const trimmed = value.trim()
					if (trimmed === '') continue
					const season = Number.parseInt(trimmed, 10)
					if (Number.isFinite(season) && season >= 1) {
						mapping[inputId] = season
					}
				}

				const response = await apiFetch(`/api/v1/broadcasts/${broadcastId}/season-mapping`, {
					method: 'PATCH',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({ mapping }),
				})
				if (!response.ok) {
					const body = (await response.json()) as { error?: { message?: string } }
					this.error = body.error?.message ?? 'Could not update the season mapping.'
					return
				}
				this.error = null
				await this.refresh()
			} catch (cause) {
				if (cause instanceof UnauthenticatedError) return
				this.error = 'Could not reach the server.'
			} finally {
				this.savingSeasonMapping = null
			}
		},

		// Same "only initialize once" rule as ensureSeasonMappingDraft — a
		// background SSE-triggered refresh must not clobber an in-progress edit.
		ensureDestinationPathDraft(broadcast: BroadcastSummary) {
			if (broadcast.id in this.destinationPathDrafts) return

			this.destinationPathDrafts[broadcast.id] = (broadcast.settings?.destination_path as string | undefined) ?? ''
		},

		async saveDestinationPath(broadcastId: string) {
			this.savingDestinationPath = broadcastId
			try {
				const draft = (this.destinationPathDrafts[broadcastId] ?? '').trim()
				const response = await apiFetch(`/api/v1/broadcasts/${broadcastId}/destination`, {
					method: 'PATCH',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({ destination_path: draft !== '' ? draft : null }),
				})
				if (!response.ok) {
					const body = (await response.json()) as { error?: { message?: string } }
					this.error = body.error?.message ?? 'Could not update the destination path.'
					return
				}
				this.error = null
				await this.refresh()
			} catch (cause) {
				if (cause instanceof UnauthenticatedError) return
				this.error = 'Could not reach the server.'
			} finally {
				this.savingDestinationPath = null
			}
		},

		openAddInput() {
			this.addInputOpen = true
			this.addInputStep = 'paste'
			this.addInputSourceUri = ''
			this.addInputError = null
			this.addInputResolved = null
			this.addInputEstimatedItemCount = null
			this.addInputEstimatedTotalDurationSeconds = null
			this.addInputSampleItems = []
			this.addInputFailureMessage = null
			this.addInputUnsupported = false
			this.addInputUniversalFilters = []
			this.addInputInputOptions = []
			this.addInputTitleRegexInclude = ''
			this.addInputTitleRegexExclude = ''
			this.addInputProviderOptions = {}
		},

		cancelAddInput() {
			this.addInputSseCancel?.()
			this.addInputSseCancel = null
			this.addInputOpen = false
			this.addInputStep = 'paste'
			this.addInputPreflightCommandId = null
			this.addInputCommitCommandId = null
		},

		async submitAddInputPreflight() {
			if (this.addInputSourceUri.trim() === '') return
			// A previous attempt against this same modal (retried after
			// backing out of a stuck one) may still be polling -- only one
			// wait should ever be live at a time.
			this.addInputSseCancel?.()
			this.addInputSubmitting = true
			try {
				const response = await apiFetch('/api/v1/stashes/preflight', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({
						source_uri: this.addInputSourceUri.trim(),
						origin: 'web_ui',
					}),
				})
				if (!response.ok) {
					const body = (await response.json()) as { error?: { message?: string } }
					this.addInputError = body.error?.message ?? 'Could not start discovery.'
					return
				}
				const body = (await response.json()) as { command_id: string }
				this.addInputPreflightCommandId = body.command_id
				this.addInputError = null
				this.addInputStep = 'reviewing'
				this.addInputSseCancel = awaitSseTerminal(() => this.checkAddInputPreflightTerminal())
			} catch (cause) {
				if (cause instanceof UnauthenticatedError) return
				this.addInputError = 'Could not reach the server.'
			} finally {
				this.addInputSubmitting = false
			}
		},

		async checkAddInputPreflightTerminal(): Promise<boolean> {
			try {
				const response = await apiFetch(`/api/v1/commands/${this.addInputPreflightCommandId}`)
				const body = (await response.json()) as CommandShowResponse

				if (body.command.state === 'completed') {
					const review = await apiFetch(`/api/v1/stashes/preflight/${this.addInputPreflightCommandId}/review`)
					const reviewBody = (await review.json()) as PreflightReview
					const discovery = reviewBody.preflight?.discovery ?? null
					this.addInputResolved = reviewBody.preflight?.resolved_input ?? null
					this.addInputEstimatedItemCount = discovery?.estimated_item_count ?? null
					this.addInputEstimatedTotalDurationSeconds = discovery?.estimated_total_duration_seconds ?? null
					this.addInputSampleItems = discovery?.sample_items ?? []
					this.addInputUniversalFilters = reviewBody.preflight?.universal_filters ?? []
					this.addInputInputOptions = reviewBody.preflight?.input_options ?? []
					this.addInputProviderOptions = Object.fromEntries(
						this.addInputInputOptions.map((option) => [option.key, option.default]),
					)
					this.addInputStep = 'review'
					return true
				}

				if (body.command.state === 'failed' || body.command.state === 'rejected') {
					const lastError = body.jobs[0]?.last_error ?? 'Discovery failed.'
					this.addInputUnsupported = lastError.startsWith('unsupported_provider_url:')
					this.addInputFailureMessage = lastError
					this.addInputStep = 'failed'
					return true
				}

				return false
			} catch (cause) {
				if (cause instanceof UnauthenticatedError) return true
				// A network-level fetch failure (dropped connection, DNS hiccup,
				// interface change) here is exactly what the 3s fallback poll
				// (see awaitSseTerminal) exists to be resilient against -- treating
				// it as terminal defeats that purpose. The command's own state is
				// unknown, not failed, so keep polling instead of giving up on one
				// bad tick; a genuine command failure is still reported via the
				// state === 'failed'/'rejected' branch above once a request
				// actually reaches the server.
				return false
			}
		},

		async confirmAddInput() {
			if (!this.addInputPreflightCommandId) return
			// The preflight wait should already be done by the time review is
			// shown, but cancel defensively so only the commit wait started
			// below is ever live.
			this.addInputSseCancel?.()
			this.addInputSubmitting = true
			try {
				const response = await apiFetch(`/api/v1/stashes/${stashId}/inputs`, {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({
						preflight_command_id: this.addInputPreflightCommandId,
						options: {
							title_regex_include: this.addInputTitleRegexInclude.trim() || undefined,
							title_regex_exclude: this.addInputTitleRegexExclude.trim() || undefined,
							provider: this.addInputProviderOptions,
						},
					}),
				})
				if (!response.ok) {
					const body = (await response.json()) as { error?: { message?: string } }
					this.addInputError = body.error?.message ?? 'Could not add that input.'
					return
				}
				const body = (await response.json()) as { command_id: string }
				this.addInputCommitCommandId = body.command_id
				this.addInputError = null
				this.addInputStep = 'committing'
				this.addInputSseCancel = awaitSseTerminal(() => this.checkAddInputCommitTerminal())
			} catch (cause) {
				if (cause instanceof UnauthenticatedError) return
				this.addInputError = 'Could not reach the server.'
			} finally {
				this.addInputSubmitting = false
			}
		},

		async checkAddInputCommitTerminal(): Promise<boolean> {
			try {
				const response = await apiFetch(`/api/v1/commands/${this.addInputCommitCommandId}`)
				const body = (await response.json()) as CommandShowResponse

				if (body.command.state === 'completed') {
					this.addInputOpen = false
					this.addInputStep = 'paste'
					this.addInputPreflightCommandId = null
					this.addInputCommitCommandId = null
					await this.refresh()
					return true
				}

				if (body.command.state === 'failed' || body.command.state === 'rejected') {
					this.addInputFailureMessage = body.jobs[0]?.last_error ?? 'Adding the input failed.'
					this.addInputStep = 'failed'
					return true
				}

				return false
			} catch (cause) {
				if (cause instanceof UnauthenticatedError) return true
				// A network-level fetch failure (dropped connection, DNS hiccup,
				// interface change) here is exactly what the 3s fallback poll
				// (see awaitSseTerminal) exists to be resilient against -- treating
				// it as terminal defeats that purpose. The command's own state is
				// unknown, not failed, so keep polling instead of giving up on one
				// bad tick; a genuine command failure is still reported via the
				// state === 'failed'/'rejected' branch above once a request
				// actually reaches the server.
				return false
			}
		},
	}
}

function vaultComponent() {
	return {
		loading: true,
		error: null as string | null,
		items: [] as MediaItemSummary[],
		total: 0,
		limit: 50,
		offset: 0,
		statusBadge,
		formatDuration,
		formatRelativeTime,

		async init() {
			await this.refresh()
			this.loading = false

			if (!('EventSource' in window)) return

			subscribeToEvents(EVENT_TYPES, (event) => {
				const job = realtimeJob(event)
				if (job?.entity_type === 'media_item' && isTerminalJobEvent(event)) void this.refresh()
			})
			subscribeToConnectionState((connected, reconnected) => {
				if (connected && reconnected) void this.refresh()
			})
		},

		async refresh() {
			try {
				const response = await apiFetch(`/api/v1/items?limit=${this.limit}&offset=${this.offset}`)
				const body = await response.json()
				this.items = body.items ?? this.items
				this.total = body.total ?? this.total
				this.error = null
			} catch (cause) {
				if (cause instanceof UnauthenticatedError) return
				this.error = 'Could not reach the server.'
			}
		},

		hasPrevPage(): boolean {
			return this.offset > 0
		},

		hasNextPage(): boolean {
			return this.offset + this.limit < this.total
		},

		async prevPage() {
			if (!this.hasPrevPage()) return
			this.offset = Math.max(0, this.offset - this.limit)
			await this.refresh()
		},

		async nextPage() {
			if (!this.hasNextPage()) return
			this.offset += this.limit
			await this.refresh()
		},

		rangeLabel(): string {
			if (this.total === 0) return ''
			const start = this.offset + 1
			const end = Math.min(this.offset + this.limit, this.total)
			return `${start}–${end} of ${this.total}`
		},
	}
}

function vaultDetailComponent(itemId: string) {
	return {
		loading: true,
		error: null as string | null,
		item: null as MediaItemSummary | null,
		assets: [] as AssetSummary[],
		stashes: [] as StashSummary[],
		broadcasts: [] as BroadcastSummary[],
		statusBadge,
		formatBytes,
		formatDuration,
		formatRelativeTime,

		async init() {
			await this.refresh()
			this.loading = false

			if ('EventSource' in window) {
				subscribeToEvents(EVENT_TYPES, (event) => {
				const job = realtimeJob(event)
				const activity = realtimeActivity(event)
				if ((job?.entity_type === 'media_item' && job.entity_id === itemId && isTerminalJobEvent(event)) || activity?.media_item_id === itemId) {
					void this.refresh()
				}
			})
				subscribeToConnectionState((connected, reconnected) => {
					if (connected && reconnected) void this.refresh()
				})
			}
		},

		async refresh() {
			try {
				const [itemResponse, assetsResponse, stashesResponse, broadcastsResponse] = await Promise.all([
					apiFetch(`/api/v1/items/${itemId}`),
					apiFetch(`/api/v1/items/${itemId}/assets`),
					apiFetch(`/api/v1/items/${itemId}/stashes`),
					apiFetch(`/api/v1/items/${itemId}/broadcasts`),
				])
				this.item = (await itemResponse.json()).item
				this.assets = (await assetsResponse.json()).assets
				this.stashes = (await stashesResponse.json()).stashes
				this.broadcasts = (await broadcastsResponse.json()).broadcasts
				this.error = null
			} catch (cause) {
				if (cause instanceof UnauthenticatedError) return
				this.error = 'Could not reach the server.'
			}
		},
	}
}

function settingsComponent() {
	return {
		loading: true,
		error: null as string | null,
		me: null as { id: string; username: string; role: string } | null,
		health: null as HealthReport | null,
		tokens: [] as ApiTokenSummary[],
		mediaServers: [] as MediaServerSummary[],

		newTokenName: '',
		creatingToken: false,
		justCreatedToken: null as string | null,

		newMediaServerType: 'jellyfin',
		newMediaServerName: '',
		newMediaServerBaseUri: '',
		newMediaServerToken: '',
		creatingMediaServer: false,

		youtubeApiKey: { configured: false } as YoutubeApiKeyStatus,
		editingYoutubeApiKey: false,
		newYoutubeApiKey: '',
		savingYoutubeApiKey: false,

		testingId: null as string | null,
		testResults: {} as Record<string, { ok: boolean; message: string; server_name?: string; version?: string }>,

		statusBadge,
		formatRelativeTime,

		async init() {
			await this.refresh()
			this.loading = false

			if (!('EventSource' in window)) return

			subscribeToEvents(['activity.created'], (event) => {
				if (realtimeActivity(event)?.entity_type === 'media_server') void this.refresh()
			})
			subscribeToConnectionState((connected, reconnected) => {
				if (connected && reconnected) void this.refresh()
			})
		},

		async refresh() {
			try {
				const [meResponse, healthResponse, tokensResponse, mediaServersResponse, youtubeApiKeyResponse] = await Promise.all([
					apiFetch('/api/v1/auth/me'),
					apiFetch('/api/v1/system/health'),
					apiFetch('/api/v1/auth/tokens'),
					apiFetch('/api/v1/media-servers'),
					apiFetch('/api/v1/providers/youtube/credentials'),
				])
				this.me = (await meResponse.json()).user
				this.health = await healthResponse.json()
				this.tokens = (await tokensResponse.json()).tokens
				this.mediaServers = (await mediaServersResponse.json()).media_servers
				this.youtubeApiKey = await youtubeApiKeyResponse.json()
				this.error = null
			} catch (cause) {
				if (cause instanceof UnauthenticatedError) return
				this.error = 'Could not reach the server.'
			}
		},

		async createToken() {
			if (this.newTokenName.trim() === '') return
			this.creatingToken = true
			try {
				const response = await apiFetch('/api/v1/auth/tokens', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({ name: this.newTokenName.trim() }),
				})
				if (!response.ok) {
					const body = (await response.json()) as { error?: { message?: string } }
					this.error = body.error?.message ?? 'Could not create that token.'
					return
				}
				const body = (await response.json()) as { token: string }
				this.justCreatedToken = body.token
				this.newTokenName = ''
				this.error = null
				await this.refresh()
			} catch (cause) {
				if (cause instanceof UnauthenticatedError) return
				this.error = 'Could not reach the server.'
			} finally {
				this.creatingToken = false
			}
		},

		async revokeToken(id: string) {
			if (!window.confirm('Revoke this token? Anything using it will stop working immediately.')) return
			try {
				await apiFetch(`/api/v1/auth/tokens/${id}`, { method: 'DELETE' })
				await this.refresh()
			} catch (cause) {
				if (cause instanceof UnauthenticatedError) return
				this.error = 'Could not revoke that token.'
			}
		},

		async copyToken(token: string) {
			await copyToClipboard(token)
		},

		async createMediaServer() {
			if (this.newMediaServerName.trim() === '' || this.newMediaServerBaseUri.trim() === '') return
			this.creatingMediaServer = true
			try {
				const response = await apiFetch('/api/v1/media-servers', {
					method: 'POST',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({
						type: this.newMediaServerType,
						name: this.newMediaServerName.trim(),
						base_uri: this.newMediaServerBaseUri.trim(),
						token: this.newMediaServerToken.trim() || undefined,
					}),
				})
				if (!response.ok) {
					const body = (await response.json()) as { error?: { message?: string } }
					this.error = body.error?.message ?? 'Could not create that media server connection.'
					return
				}
				this.newMediaServerName = ''
				this.newMediaServerBaseUri = ''
				this.newMediaServerToken = ''
				this.error = null
				await this.refresh()
			} catch (cause) {
				if (cause instanceof UnauthenticatedError) return
				this.error = 'Could not reach the server.'
			} finally {
				this.creatingMediaServer = false
			}
		},

		async testMediaServer(id: string) {
			this.testingId = id
			try {
				const response = await apiFetch(`/api/v1/media-servers/${id}/test`, { method: 'POST' })
				const body = (await response.json()) as { status: { ok: boolean; message: string; server_name?: string; version?: string } }
				this.testResults = { ...this.testResults, [id]: body.status }
			} catch (cause) {
				if (cause instanceof UnauthenticatedError) return
				this.error = 'Could not test that connection.'
			} finally {
				this.testingId = null
			}
		},

		async deleteMediaServer(id: string) {
			if (!window.confirm('Delete this media server connection?')) return
			try {
				await apiFetch(`/api/v1/media-servers/${id}`, { method: 'DELETE' })
				await this.refresh()
			} catch (cause) {
				if (cause instanceof UnauthenticatedError) return
				this.error = 'Could not delete that connection.'
			}
		},

		async saveYoutubeApiKey() {
			if (this.newYoutubeApiKey.trim() === '') return
			this.savingYoutubeApiKey = true
			try {
				const response = await apiFetch('/api/v1/providers/youtube/credentials', {
					method: 'PUT',
					headers: { 'Content-Type': 'application/json' },
					body: JSON.stringify({ api_key: this.newYoutubeApiKey.trim() }),
				})
				if (!response.ok) {
					const body = (await response.json()) as { error?: { message?: string } }
					this.error = body.error?.message ?? 'Could not save that key.'
					return
				}
				this.newYoutubeApiKey = ''
				this.editingYoutubeApiKey = false
				this.error = null
				await this.refresh()
			} catch (cause) {
				if (cause instanceof UnauthenticatedError) return
				this.error = 'Could not reach the server.'
			} finally {
				this.savingYoutubeApiKey = false
			}
		},
	}
}

Alpine.data('dashboard', dashboardComponent)
Alpine.data('activity', activityComponent)
Alpine.data('stashes', stashesComponent)
Alpine.data('createStash', createStashComponent)
Alpine.data('stashDetail', stashDetailComponent)
Alpine.data('vault', vaultComponent)
Alpine.data('vaultDetail', vaultDetailComponent)
Alpine.data('settings', settingsComponent)

window.Alpine = Alpine
Alpine.start()

document.addEventListener('DOMContentLoaded', () => {
	markActiveNav()
	wireLogout()
	void enforceAuth()
})
