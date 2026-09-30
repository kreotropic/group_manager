<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div class="gm-app">
		<NcNoteCard v-if="loadError" type="error">
			{{ loadError }}
		</NcNoteCard>

		<div class="gm-layout">
			<GroupList :groups="groups"
				:loading="loading"
				:selected-id="selectedId"
				:quick-access="quickAccess"
				@toggle-quick-access="toggleQuickAccess"
				@select="selectGroup"
				@create="showCreateDialog = true" />

			<div class="gm-detail">
				<NcEmptyContent v-if="!selectedId"
					class="gm-detail__empty"
					:name="t('group_manager', 'Select a group')"
					:description="t('group_manager', 'Choose a group on the left to see its details and members.')">
					<template #icon>
						<AccountMultiple :size="48" />
					</template>
				</NcEmptyContent>
				<GroupDetail v-else
					:key="selectedId"
					:group-id="selectedId"
					@renamed="onGroupRenamed"
					@deleted="onGroupDeleted"
					@pending-changed="onDetailPendingChanged" />
			</div>
		</div>

		<CreateGroupDialog :open="showCreateDialog"
			@update:open="showCreateDialog = $event"
			@created="onGroupCreated" />

		<div class="gm-sr-only" role="status" aria-live="polite">{{ announcement }}</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { showSuccess, showError } from '@nextcloud/dialogs'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import GroupList from './components/GroupList.vue'
import GroupDetail from './components/GroupDetail.vue'
import CreateGroupDialog from './components/CreateGroupDialog.vue'
import { fetchGroups, fetchQuickAccess, saveQuickAccess } from './services/api.js'
import { extractErrorMessage } from './utils/errors.js'
import { readGroupIdFromHash, pushGroupHash } from './utils/hash.js'

export default {
	name: 'App',

	components: {
		NcNoteCard,
		NcEmptyContent,
		AccountMultiple,
		GroupList,
		GroupDetail,
		CreateGroupDialog,
	},

	data() {
		return {
			groups: [],
			loading: true,
			loadError: '',
			selectedId: null,
			showCreateDialog: false,
			hasPendingMemberChanges: false,
			// GM-08: a batch is being sent right now (GroupDetail's own
			// applying, covering both its members and folders managers) --
			// navigating away is refused outright, not just guarded by a
			// confirm(), since there's nothing sane to ask about a request
			// already in flight and discarding it client-side wouldn't undo
			// it server-side anyway.
			applyingChanges: false,
			// This app's own monotonic position in the navigation stack,
			// mirrored into history.state by pushHistory() -- see
			// onPopState() and utils/hash.js's pushGroupHash().
			historyIndex: 0,
			// Set right before this app calls history.go() itself, so the
			// popstate that generates is recognized as our own reversal
			// instead of being re-processed as a new user navigation.
			suppressNextPopState: false,
			announcement: '',
			quickAccess: false,
		}
	},

	async mounted() {
		window.addEventListener('popstate', this.onPopState)
		window.addEventListener('beforeunload', this.onBeforeUnload)

		fetchQuickAccess().then((v) => { this.quickAccess = v }).catch(() => {})
		await this.loadGroups()

		// Deep-link support: #group=<gid>, only honoured if that group still exists.
		const hashGid = readGroupIdFromHash()
		if (hashGid && this.groups.some((g) => g.id === hashGid)) {
			this.selectedId = hashGid
		}
	},

	beforeUnmount() {
		window.removeEventListener('popstate', this.onPopState)
		window.removeEventListener('beforeunload', this.onBeforeUnload)
	},

	methods: {
		t,

		announce(message) {
			// Reset first so a repeated message is still picked up as a change.
			this.announcement = ''
			this.$nextTick(() => {
				this.announcement = message
			})
		},

		/**
		 * Case- and accent-insensitive, applied consistently everywhere the
		 * list is touched so the order never flips depending on which action
		 * last ran.
		 */
		sortGroups(groups) {
			return [...groups].sort((a, b) => a.displayName.localeCompare(b.displayName, undefined, { sensitivity: 'base' }))
		},

		async toggleQuickAccess() {
			const next = !this.quickAccess
			try {
				this.quickAccess = await saveQuickAccess(next)
				showSuccess(this.quickAccess
					? t('group_manager', 'Top bar shortcut enabled. Reload the page to see it.')
					: t('group_manager', 'Top bar shortcut disabled. Reload the page to update the bar.'))
			} catch (err) {
				showError(extractErrorMessage(err, t('group_manager', 'Could not save the preference.')))
			}
		},

		async loadGroups() {
			this.loading = true
			this.loadError = ''
			try {
				this.groups = this.sortGroups(await fetchGroups())
				this.announce(t('group_manager', '{count} groups loaded.', { count: this.groups.length }))
			} catch (err) {
				this.loadError = extractErrorMessage(err, t('group_manager', 'Could not load groups.'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * The only entry point for changing the selected group — guards against
		 * losing unsaved member-list staging and keeps the URL hash in sync.
		 */
		selectGroup(gid) {
			if (gid === this.selectedId) {
				return
			}
			if (!this.confirmNavigateAway()) {
				return
			}
			this.hasPendingMemberChanges = false
			this.selectedId = gid
			this.pushHistory(gid)
		},

		/**
		 * True when it's safe to leave the current group right now. Shared by
		 * selectGroup() and onPopState() (GM-08) so Back/Forward is held to
		 * the exact same policy as clicking another group in the list,
		 * including the hard applyingChanges refusal — a popstate has
		 * already happened by the time onPopState() runs, but "already
		 * happened" in the URL/history sense only; it's still reversible
		 * with history.go(), which onPopState() does when this returns false.
		 */
		confirmNavigateAway() {
			if (this.applyingChanges) {
				return false
			}
			if (!this.hasPendingMemberChanges) {
				return true
			}
			return window.confirm(
				t('group_manager', 'You have unapplied member changes for this group. Discard them and switch groups?'),
			)
		},

		/**
		 * pushGroupHash() plus keeping historyIndex in step with it — every
		 * caller that pushes a new entry goes through here so the two never
		 * drift apart.
		 */
		pushHistory(gid) {
			this.historyIndex++
			pushGroupHash(gid, this.historyIndex)
		},

		/**
		 * Reacting to the browser's own Back/Forward buttons. Unlike before,
		 * this is no longer unconditionally honoured (GM-08): the jump the
		 * browser already made is reverted with history.go() when
		 * confirmNavigateAway() declines it, using the index carried in
		 * history.state to know how many steps that takes and in which
		 * direction — comparing groupId alone can't tell an older entry from
		 * a newer one that happens to name the same group.
		 */
		onPopState(event) {
			if (this.suppressNextPopState) {
				this.suppressNextPopState = false
				return
			}

			const targetIndex = event.state?.index ?? 0
			const delta = targetIndex - this.historyIndex
			const gid = event.state?.groupId ?? readGroupIdFromHash()

			if (!this.confirmNavigateAway()) {
				if (delta !== 0) {
					this.suppressNextPopState = true
					window.history.go(-delta)
				}
				return
			}

			this.hasPendingMemberChanges = false
			this.historyIndex = targetIndex
			this.selectedId = gid && this.groups.some((g) => g.id === gid) ? gid : null
		},

		onBeforeUnload(event) {
			if (this.hasPendingMemberChanges) {
				event.preventDefault()
				event.returnValue = ''
			}
		},

		onGroupCreated(group) {
			this.groups = this.sortGroups([...this.groups, group])
			this.selectGroup(group.id)
			this.showCreateDialog = false
			showSuccess(t('group_manager', 'Group "{name}" created.', { name: group.displayName }))
		},

		onGroupRenamed(group) {
			const previous = this.groups.find((g) => g.id === group.id)
			const wasRenamed = previous && previous.displayName !== group.displayName
			const index = this.groups.findIndex((g) => g.id === group.id)
			const next = index !== -1
				? [...this.groups.slice(0, index), group, ...this.groups.slice(index + 1)]
				: this.groups
			this.groups = this.sortGroups(next)
			if (wasRenamed) {
				showSuccess(t('group_manager', 'Group renamed to "{name}".', { name: group.displayName }))
			}
		},

		onGroupDeleted(gid) {
			const group = this.groups.find((g) => g.id === gid)
			this.groups = this.groups.filter((g) => g.id !== gid)
			if (this.selectedId === gid) {
				this.hasPendingMemberChanges = false
				this.selectedId = null
				this.pushHistory(null)
			}
			showSuccess(t('group_manager', 'Group "{name}" deleted.', { name: group?.displayName ?? gid }))
		},

		/**
		 * GroupDetail aggregates both its members and folders managers into
		 * one hasPendingChanges/applying pair (see its own pending-changed
		 * docblock) — this app only needs the result, not which of the two
		 * tabs it came from.
		 */
		onDetailPendingChanged({ hasPendingChanges, applying }) {
			this.hasPendingMemberChanges = hasPendingChanges
			this.applyingChanges = applying
		},
	},
}
</script>

<style scoped>
.gm-app {
	max-width: 100%;
}

.gm-layout {
	/* Height of the top region of BOTH columns (title, search/subtitle,
	   tabs). Sharing one value keeps the rule under the tabs a single
	   continuous line across the list and the detail panel. */
	--gm-top-h: 156px;
	display: flex;
	height: calc(100vh - 130px);
	min-height: 480px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	overflow: hidden;
	position: relative;
}

.gm-detail {
	flex: 1;
	min-width: 0;
	display: flex;
	overflow: hidden;
}

.gm-detail__empty {
	margin: auto;
}

.gm-sr-only {
	position: absolute;
	width: 1px;
	height: 1px;
	padding: 0;
	margin: -1px;
	overflow: hidden;
	clip: rect(0, 0, 0, 0);
	white-space: nowrap;
	border: 0;
}
</style>
