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
const USER_HASH_PREFIX = '#user='
// The Users view with nobody selected.
const USERS_HASH = '#users'

/**
 * The view and selection the current URL hash points at:
 * { mode: 'groups' | 'users', id: string | null }.
 */
export function readSelectionFromHash() {
	const hash = window.location.hash
	const decode = (prefix) => {
		const encoded = hash.slice(prefix.length)
		return encoded ? decodeURIComponent(encoded) : null
	}
	if (hash.startsWith(USER_HASH_PREFIX)) {
		return { mode: 'users', id: decode(USER_HASH_PREFIX) }
	}
	if (hash === USERS_HASH) {
		return { mode: 'users', id: null }
	}
	if (hash.startsWith(GROUP_HASH_PREFIX)) {
		return { mode: 'groups', id: decode(GROUP_HASH_PREFIX) }
	}
	return { mode: 'groups', id: null }
}

/**
 * Push a new history entry for a view and selection (a bare page URL for
 * the Groups view with nothing selected). Pushing, not replacing, lets the
 * browser's back/forward buttons step through earlier selections.
 *
 * `index` (GM-08) is the caller's own monotonic navigation counter, carried
 * in `state`. It's what a popstate handler compares against its last-known
 * position to tell how far a Back/Forward jump moved (and in which
 * direction), which is what makes reverting a declined jump with
 * `history.go()` possible — comparing the selection alone can't do that (an
 * older AND a newer entry both plausibly point at some other group).
 */
export function pushSelectionHash(mode, id, index) {
	const base = window.location.pathname + window.location.search
	let hash = ''
	if (mode === 'users') {
		hash = id ? USER_HASH_PREFIX + encodeURIComponent(id) : USERS_HASH
	} else if (id) {
		hash = GROUP_HASH_PREFIX + encodeURIComponent(id)
	}
	window.history.pushState({ mode, id, index }, '', base + hash)
}
