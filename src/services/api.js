/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = (path) => generateUrl('/apps/group_manager' + path)

/**
 * Fetch the list of groups (local + LDAP), with backend/member-count summary.
 */
export async function fetchGroups() {
	const { data } = await axios.get(base('/api/groups'))
	return data.groups
}

/**
 * Fetch full detail for one group (subadmin count, canAddUser/canRemoveUser, ...).
 */
export async function fetchGroup(gid) {
	const { data } = await axios.get(base('/api/groups/' + encodeURIComponent(gid)))
	return data
}

/**
 * Fetch a page of a group's members.
 *
 * @param {string} gid
 * @param {object} params { search, limit, offset }
 */
export async function fetchGroupMembers(gid, params = {}) {
	const { data } = await axios.get(base('/api/groups/' + encodeURIComponent(gid) + '/members'), { params })
	return data
}

/**
 * Create a local group. Throws the axios error on failure; error.response.data
 * carries { error, code } from GroupServiceException (e.g. GROUP_ALREADY_EXISTS).
 */
export async function createGroup(gid, displayName = '') {
	const { data } = await axios.post(base('/api/groups'), { gid, displayName })
	return data
}

/**
 * Rename (change displayName of) a local group.
 */
export async function renameGroup(gid, displayName) {
	const { data } = await axios.put(base('/api/groups/' + encodeURIComponent(gid)), { displayName })
	return data
}

/**
 * Delete a local group.
 */
export async function deleteGroup(gid) {
	const { data } = await axios.delete(base('/api/groups/' + encodeURIComponent(gid)))
	return data
}

/**
 * Search users not already in the group, for the "add member" remote select.
 */
export async function searchGroupCandidates(gid, search, limit = 10) {
	const { data } = await axios.get(base('/api/groups/' + encodeURIComponent(gid) + '/candidates'), {
		params: { search, limit },
	})
	return data.candidates
}

/**
 * Add one user to a local group. Idempotent if already a member.
 */
export async function addGroupMember(gid, uid) {
	const { data } = await axios.post(base('/api/groups/' + encodeURIComponent(gid) + '/members'), { uid })
	return data
}

/**
 * Remove one user from a local group. Idempotent if not a member.
 */
export async function removeGroupMember(gid, uid) {
	const { data } = await axios.delete(
		base('/api/groups/' + encodeURIComponent(gid) + '/members/' + encodeURIComponent(uid)),
	)
	return data
}
