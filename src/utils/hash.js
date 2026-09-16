/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// No leading slash after '#' — a hash shaped like a path ("#/group/x") gets
// silently reverted a moment after being pushed, on this and presumably any
// Nextcloud page: some other app sharing the page (Talk's unified-search
// filters are loaded on every settings page) runs a hash-mode router that
// treats any "#/..." as one of its own routes, fails to match it, and
// normalizes the URL back — confirmed by watching a spurious popstate/
// hashchange fire immediately after our own pushState. A slash-free hash
// isn't claimed by that router and survives untouched.
const GROUP_HASH_PREFIX = '#group='

/**
 * Read the selected group id from the current URL hash, if any.
 */
export function readGroupIdFromHash() {
	const hash = window.location.hash
	if (!hash.startsWith(GROUP_HASH_PREFIX)) {
		return null
	}
	const encoded = hash.slice(GROUP_HASH_PREFIX.length)
	return encoded ? decodeURIComponent(encoded) : null
}

/**
 * Push a new history entry pointing at `gid` (or strip the hash entirely
 * when `gid` is null). Pushing (not replacing) lets the browser's back/
 * forward buttons step through previously selected groups.
 */
export function pushGroupHash(gid) {
	const base = window.location.pathname + window.location.search
	const url = gid ? `${base}${GROUP_HASH_PREFIX}${encodeURIComponent(gid)}` : base
	window.history.pushState({ groupId: gid }, '', url)
}
