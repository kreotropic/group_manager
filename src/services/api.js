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
 * @param {object} params { search, pageSize, offset } -- pageSize, not
 *   limit: GroupController::members() takes that name specifically so a
 *   value outside its own bounds gets that controller's own clean 400
 *   (GM-10) rather than Nextcloud's own dispatcher silently clamping any
 *   parameter literally named "limit" to [1, 500] and 500ing outside it
 *   (NC 34+; see that method's docblock).
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
 * Search users AND groups not already fully represented in the group, for
 * the single add field. Returns { users, groups, nextOffset, hasMore } — group
 * entries carry newMemberCount (how many new people picking them would add).
 */
export async function searchGroupCandidates(gid, search, limit = 10, offset = 0) {
	const { data } = await axios.get(base('/api/groups/' + encodeURIComponent(gid) + '/candidates'), {
		params: { search, limit, offset },
	})
	return data
}

/**
 * Expand a "whole group" candidate into the individual members it would add
 * (those not already in `gid`).
 */
export async function expandGroupForAdd(gid, sourceGid) {
	const { data } = await axios.get(base('/api/groups/' + encodeURIComponent(gid) + '/expand-group'), {
		params: { sourceGid },
	})
	return data.members
}

/**
 * Resolve a pasted list of tokens (uid or email, one per line) against real
 * accounts. Every token comes back once, matched or not.
 */
export async function resolvePastedList(gid, tokens) {
	const { data } = await axios.post(base('/api/groups/' + encodeURIComponent(gid) + '/resolve-list'), { tokens })
	return data.results
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

/**
 * A group's admins (Nextcloud subadmins), members or not. Returns
 * { subAdmins: [{ uid, displayName, email, enabled, isMember }], canGrant }.
 */
export async function fetchSubAdmins(gid) {
	const { data } = await axios.get(base('/api/groups/' + encodeURIComponent(gid) + '/subadmins'))
	return data
}

/**
 * Make a member one of the group's admins. Idempotent; works for LDAP groups.
 */
export async function addSubAdmin(gid, uid) {
	const { data } = await axios.post(
		base('/api/groups/' + encodeURIComponent(gid) + '/subadmins/' + encodeURIComponent(uid)),
	)
	return data
}

/**
 * End a group admin assignment, member or not. Idempotent.
 */
export async function removeSubAdmin(gid, uid) {
	const { data } = await axios.delete(
		base('/api/groups/' + encodeURIComponent(gid) + '/subadmins/' + encodeURIComponent(uid)),
	)
	return data
}

/**
 * Group folders already assigned to a group, with per-group permissions.
 * Works for LDAP groups too — folder assignment isn't gated by backend.
 */
export async function fetchGroupFolders(gid) {
	const { data } = await axios.get(base('/api/groups/' + encodeURIComponent(gid) + '/folders'))
	return data.folders
}

/**
 * Group folders NOT yet assigned to $gid, name-matching $search — the pool
 * for the folder assignment field's dropdown.
 */
export async function searchAssignableFolders(gid, search, limit = 10) {
	const { data } = await axios.get(base('/api/groups/' + encodeURIComponent(gid) + '/folders/search'), {
		params: { search, limit },
	})
	return data.folders
}

/**
 * Create a brand-new group folder and assign it to gid in the same step.
 * Requires a recent password confirmation (see src/utils/confirmPassword
 * usage in the calling dialog) — this creates real backing storage, unlike
 * everything else in this file which only touches metadata.
 */
export async function createGroupFolder(gid, mountPoint) {
	const { data } = await axios.post(base('/api/groups/' + encodeURIComponent(gid) + '/folders/create'), { mountPoint })
	return data
}

/**
 * Every group folder's id and mount point — the picker builds its tree of
 * levels from the `/`-separated parts of these.
 */
export async function fetchMountPoints() {
	const { data } = await axios.get(base('/api/folders/mount-points'))
	return data.folders
}

/**
 * Give a group access to a folder — defaults to full (write/share/delete)
 * permissions, same default the groupfolders admin UI itself uses.
 */
export async function assignGroupFolder(gid, folderId) {
	const { data } = await axios.post(base('/api/groups/' + encodeURIComponent(gid) + '/folders/' + folderId))
	return data
}

/**
 * Remove a group's access to a folder entirely.
 */
export async function unassignGroupFolder(gid, folderId) {
	const { data } = await axios.delete(base('/api/groups/' + encodeURIComponent(gid) + '/folders/' + folderId))
	return data
}

/**
 * Set a group's write/share/delete permissions on a folder it already has
 * access to. Read is always implied and isn't a toggle.
 */
export async function setGroupFolderPermissions(gid, folderId, { write, share, del }) {
	const { data } = await axios.put(base('/api/groups/' + encodeURIComponent(gid) + '/folders/' + folderId + '/permissions'), {
		write,
		share,
		delete: del,
	})
	return data
}

/**
 * Set a folder's quota in bytes. Quota belongs to the folder, not the group
 * — this edit affects every other group with access to it too.
 */
export async function setGroupFolderQuota(gid, folderId, quota) {
	const { data } = await axios.put(base('/api/groups/' + encodeURIComponent(gid) + '/folders/' + folderId + '/quota'), { quota })
	return data
}

/**
 * Whether this admin has the top-bar shortcut enabled.
 */
export async function fetchQuickAccess() {
	const { data } = await axios.get(base('/api/preferences'))
	return data.quickAccess
}

export async function saveQuickAccess(enabled) {
	const { data } = await axios.put(base('/api/preferences/quick-access'), { enabled })
	return data.quickAccess
}
