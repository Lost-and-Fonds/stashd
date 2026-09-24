# Stashd Browser Extension — v1 Specification

> **Status:** Proposed first slice. No extension exists yet.
> **Purpose:** Send the user and their explicitly selected page into Stashd's existing create-stash flow.

## Decision

v1 is a **browser-to-web-app handoff**, not an API client.

```text
current supported page
  → toolbar click
  → Stashd create-stash page, seeded with the URL
  → existing Stashd preflight and review flow
```

The extension never creates a stash, polls a command, or decides download policy. Stashd remains the only place that authenticates, preflights, reviews, and creates a stash.

This is deliberately smaller than the earlier draft. The current public preflight API is bearer-authenticated and returns an API review URL, not a web-app review handoff. Making an extension own an API token would add token storage, host permissions, and an API-to-web-session bridge without improving the v1 action.

## User story

While on a supported YouTube page, a signed-in Stashd user clicks `stashd_`. Stashd opens its New Stash flow with that URL ready to submit. The user reviews and chooses all settings there.

Supported v1 URLs:

- YouTube handles, channels, custom channels, and user channels.
- YouTube playlists.
- YouTube videos.

Unsupported pages show a short explanation and an **Open Stashd** action.

## Handoff contract

The extension opens:

```text
{configured-base-url}/stashes/new#stashd-source={encodeURIComponent(current-page-url)}
```

The URL is in the fragment, so it is not sent in the HTTP request, proxy logs, or referrer headers. On load, the Stashd UI reads `stashd-source`, validates it as a URL, pre-fills the New Stash link field, removes the fragment with `history.replaceState()`, and lets the user submit the normal create flow.

The Stashd UI must not auto-submit. It is the explicit confirmation point for preflight and policy choices.

If the user is not signed in, normal Stashd login applies; the source fragment must survive the login redirect or be retained client-side for that redirect only.

## Extension scope

### Required

- Chromium Manifest V3 extension.
- Toolbar popup.
- Options page with one required setting: Stashd base URL.
- URL-only YouTube detection.
- **Open Stashd to stash this page** action.
- Local extension storage for the base URL.
- No content script, background service worker, network client, telemetry, or submission history.

### Deferred

- Firefox packaging, after the Chromium build is useful.
- Context-menu and keyboard-shortcut entry points; both reuse the same handoff function when demand justifies them.
- Sending a page title.
- Generic yt-dlp URLs and other providers.
- Multiple Stashd instances.
- API-token mode, direct command submission, command polling, job progress, or a separate extension review UI.

## Permissions and privacy

The manifest should request only:

```json
{
  "permissions": ["activeTab", "storage"],
  "host_permissions": []
}
```

`activeTab` is used only after the user opens the popup to read the active tab URL. The extension does not inspect browsing history, inject scripts, make requests to Stashd, or retain submitted URLs.

The configured base URL accepts `https:` and local development `http://localhost` / `http://127.0.0.1`. Warn before saving any other `http:` URL: the eventual Stashd web session may be exposed on the network.

## Popup

### Unconfigured

```text
Connect Stashd

Base URL: [ http://localhost:8474 ]
[Save]
```

### Supported page

```text
stashd_
YouTube playlist detected

[Open Stashd to stash this page]
[Open Stashd]
```

### Unsupported page

```text
stashd_
This page is not supported yet.

[Open Stashd]
```

### Error handling

The extension only validates its configured URL and current-page URL. Once it opens Stashd, connectivity, login, provider setup, and preflight errors belong to the existing Stashd UI.

## Stashd change required before packaging

Teach the `/stashes/new` UI to consume the `#stashd-source=` fragment as described above. Reuse the existing URL validation and create-stash preflight path; do not add a new API endpoint or an extension-specific server session.

Minimum behavior:

- a valid fragment pre-fills the New Stash link;
- the fragment is removed after it is consumed;
- an invalid fragment is ignored safely;
- a normal `/stashes/new` visit is unchanged.

## Delivery order

1. Add the Stashd fragment handoff.
2. Build the minimal Chromium popup and options page.
3. Manually verify a channel, playlist, video, unsupported page, logged-out handoff, and local HTTP warning.
4. Package Firefox only if the Chromium extension proves useful.

## Product boundary

The extension reduces copy/paste. It is not another Stashd frontend.
