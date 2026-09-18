<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div class="gm-app">
		<div class="gm-header">
			<h2>{{ t('group_manager', 'Group Manager') }}</h2>
		</div>

		<NcNoteCard v-if="loadError" type="error">
			{{ loadError }}
		</NcNoteCard>

		<div class="gm-layout">
			<GroupList :groups="groups"
				:loading="loading"
				:selected-id="selectedId"
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
					@pending-changed="hasPendingMemberChanges = $event" />
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
import { showSuccess } from '@nextcloud/dialogs'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import GroupList from './components/GroupList.vue'
import GroupDetail from './components/GroupDetail.vue'
import CreateGroupDialog from './components/CreateGroupDialog.vue'
import { fetchGroups } from './services/api.js'
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
			announcement: '',
		}
	},

	async mounted() {
		window.addEventListener('popstate', this.onPopState)
		window.addEventListener('beforeunload', this.onBeforeUnload)

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
			if (this.hasPendingMemberChanges && !window.confirm(
				t('group_manager', 'You have unapplied member changes for this group. Discard them and switch groups?'),
			)) {
				return
			}
			this.hasPendingMemberChanges = false
			this.selectedId = gid
			pushGroupHash(gid)
		},

		/**
		 * Reacting to the browser's own Back/Forward buttons — always honoured,
		 * the pending-changes guard only applies to in-app navigation (a
		 * popstate has already happened by the time this fires, so there's
		 * nothing sane left to block).
		 */
		onPopState(event) {
			const gid = event.state?.groupId ?? readGroupIdFromHash()
			this.hasPendingMemberChanges = false
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
				pushGroupHash(null)
			}
			showSuccess(t('group_manager', 'Group "{name}" deleted.', { name: group?.displayName ?? gid }))
		},
	},
}
</script>

<style scoped>
.gm-app {
	max-width: 100%;
}

.gm-header {
	margin-bottom: 16px;
}

.gm-layout {
	display: flex;
	height: calc(100vh - 170px);
	min-height: 480px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	overflow: hidden;
	position: relative;
}

.gm-detail {
	flex: 1;
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
