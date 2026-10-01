/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

const UUID_PATTERN = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i

/**
 * LDAP often keys users by a generated UUID, which says nothing to a person
 * reading the list; it is kept for the tooltip instead of the row.
 *
 * @param {string} uid
 * @return {boolean}
 */
export function isUuid(uid) {
	return UUID_PATTERN.test(uid ?? '')
}

/**
 * Tooltip for a member's name: the UUID, when that is what the username is.
 *
 * @param {{uid: string}} member
 * @return {string|undefined}
 */
export function memberNameTitle(member) {
	return isUuid(member.uid) ? member.uid : undefined
}

/**
 * Detail line under a member's name: username and email, each only when it
 * adds something. A username identical to the display name (ncadmin) would
 * just repeat the line above it, and a missing email is left out rather than
 * padded with a placeholder.
 *
 * @param {{uid: string, displayName: string, email?: string}} member
 * @return {string} empty when there is nothing to add
 */
export function memberDetailLine(member) {
	const parts = []
	if (member.uid && member.uid !== member.displayName && !isUuid(member.uid)) {
		parts.push(member.uid)
	}
	if (member.email) {
		parts.push(member.email)
	}
	return parts.join(' · ')
}
