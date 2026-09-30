/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Second line under a member's name: username and email, each only when it
 * adds something. A username identical to the display name (ncadmin) would
 * just repeat the line above it, and a missing email is left out rather than
 * padded with a placeholder.
 *
 * @param {{uid: string, displayName: string, email?: string}} member
 * @return {string} empty when there is nothing to add
 */
export function memberDetailLine(member) {
	const parts = []
	if (member.uid && member.uid !== member.displayName) {
		parts.push(member.uid)
	}
	if (member.email) {
		parts.push(member.email)
	}
	return parts.join(' · ')
}
