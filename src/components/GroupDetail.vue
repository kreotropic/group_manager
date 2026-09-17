<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div class="gm-detail-panel">
		<div v-if="loading" class="gm-detail-panel__loading">
			<NcLoadingIcon :size="32" />
		</div>

		<NcNoteCard v-else-if="loadError" type="error">
			{{ loadError }}
		</NcNoteCard>

		<template v-else-if="group">
			<div class="gm-detail-panel__fixed">
				<header class="gm-detail__header">
					<div class="gm-detail__titles">
						<h2 class="gm-detail__name">
							<span class="gm-detail__name-text">{{ group.displayName }}</span>
							<NcButton v-if="group.canRename"
								variant="tertiary"
								:aria-label="t('group_manager', 'Rename group')"
								:title="t('group_manager', 'Rename group')"
								@click="showRenameDialog = true">
								<template #icon>
									<Pencil :size="16" />
								</template>
							</NcButton>
						</h2>
						<p class="gm-detail__subtitle">{{ subtitleText }}</p>
					</div>

					<button v-if="group.canDelete"
						type="button"
						class="gm-detail__delete"
						@click="showDeleteDialog = true">
						{{ t('group_manager', 'Delete group') }}
					</button>
				</header>

				<NcNoteCard v-if="group.backend !== 'local'" type="info">
					<p>{{ t('group_manager', 'This group is managed by an external backend. Renaming and deleting are disabled here.') }}</p>
					<p v-if="group.dn" class="gm-detail__dn">{{ group.dn }}</p>
				</NcNoteCard>

				<nav v-if="showTabs" class="gm-detail__tabs">
					<button type="button"
						class="gm-detail__tab"
						:class="{ 'gm-detail__tab--active': activeTab === 'members' }"
						@click="activeTab = 'members'">
						{{ t('group_manager', 'Members') }}
						<span class="gm-detail__tab-count">{{ group.memberCount }}</span>
						<span v-if="showMembersDot" class="gm-detail__tab-dot" />
					</button>
					<button type="button"
						class="gm-detail__tab"
						:class="{ 'gm-detail__tab--active': activeTab === 'folders' }"
						@click="activeTab = 'folders'">
						{{ t('group_manager', 'Folders') }}
						<span class="gm-detail__tab-count">{{ group.folderCount }}</span>
						<span v-if="showFoldersDot" class="gm-detail__tab-dot" />
					</button>
				</nav>
			</div>

			<div class="gm-detail-panel__body">
				<GroupMembersManager v-if="group.canAddUser || group.canRemoveUser"
					v-show="!showTabs || activeTab === 'members'"
					ref="membersManager"
					:group-id="group.id"
					class="gm-detail__tab-content"
					@changed="onMembersChanged"
					@pending-changed="onMembersPendingChanged" />
				<GroupMembersList v-else v-show="!showTabs || activeTab === 'members'" :group-id="group.id" class="gm-detail__tab-content" />

				<GroupFoldersManager v-if="showTabs"
					v-show="activeTab === 'folders'"
					ref="foldersManager"
					:group-id="group.id"
					class="gm-detail__tab-content"
					@changed="onFoldersChanged"
					@pending-changed="onFoldersPendingChanged" />
			</div>

			<div v-if="showFooter" class="gm-detail-panel__footer" :class="{ 'gm-detail-panel__footer--disabled': !hasPendingChanges }">
				<span class="gm-detail__footer-summary">{{ footerSummaryText }}</span>
				<NcButton :disabled="!hasPendingChanges || applying" @click="discardAll">
					{{ t('group_manager', 'Discard') }}
				</NcButton>
				<NcButton class="gm-detail__apply" variant="primary" :disabled="!hasPendingChanges || applying" @click="applyAll">
					<template v-if="applying" #icon>
						<NcLoadingIcon :size="18" />
					</template>
					{{ applying ? t('group_manager', 'Applying…') : t('group_manager', 'Apply') }}
				</NcButton>
			</div>

			<RenameGroupDialog :open="showRenameDialog"
				:group="group"
				@update:open="showRenameDialog = $event"
				@renamed="onRenamed" />

			<NcDialog :open="showDeleteDialog"
				:name="t('group_manager', 'Delete group?')"
				:buttons="deleteButtons"
				size="small"
				@update:open="onDeleteDialogUpdateOpen">
				<div class="gm-delete-dialog">
					<NcNoteCard type="warning">
						{{ deleteWarning }}
					</NcNoteCard>
					<NcNoteCard v-if="deleteError" type="error">
						{{ deleteError }}
					</NcNoteCard>
				</div>
			</NcDialog>
		</template>
	</div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import RenameGroupDialog from './RenameGroupDialog.vue'
import GroupMembersList from './GroupMembersList.vue'
import GroupMembersManager from './GroupMembersManager.vue'
import GroupFoldersManager from './GroupFoldersManager.vue'
import { fetchGroup, deleteGroup } from '../services/api.js'
import { extractErrorMessage } from '../utils/errors.js'

const EMPTY_PENDING = { hasPendingChanges: false, count: 0, joining: 0, leaving: 0 }

export default {
	name: 'GroupDetail',

	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		Pencil,
		RenameGroupDialog,
		GroupMembersList,
		GroupMembersManager,
		GroupFoldersManager,
	},

	props: {
		groupId: {
			type: String,
			required: true,
		},
	},

	emits: ['renamed', 'deleted', 'pending-changed'],

	data() {
		return {
			group: null,
			loading: true,
			loadError: '',
			showRenameDialog: false,
			showDeleteDialog: false,
			deleteError: '',
			activeTab: 'members',
			membersPending: { ...EMPTY_PENDING },
			foldersPending: { ...EMPTY_PENDING },
			applying: false,
		}
	},

	computed: {
		subtitleText() {
			if (!this.group) {
				return ''
			}
			const parts = [
				this.group.backend === 'ldap' ? t('group_manager', 'LDAP group') : t('group_manager', 'Local group'),
			]
			parts.push(this.group.memberCount
				? n('group_manager', '%n member', '%n members', this.group.memberCount)
				: t('group_manager', 'no members'))
			if (this.group.disabledCount) {
				parts.push(n('group_manager', '%n disabled', '%n disabled', this.group.disabledCount))
			}
			if (this.group.foldersEnabled) {
				parts.push(this.group.folderCount
					? n('group_manager', '%n folder', '%n folders', this.group.folderCount)
					: t('group_manager', 'no folders'))
			}
			return parts.join(' · ')
		},

		showTabs() {
			return !!this.group?.foldersEnabled
		},

		showMembersDot() {
			return this.showTabs && this.activeTab !== 'members' && this.membersPending.hasPendingChanges
		},

		showFoldersDot() {
			return this.showTabs && this.activeTab !== 'folders' && this.foldersPending.hasPendingChanges
		},

		hasPendingChanges() {
			return this.membersPending.hasPendingChanges || this.foldersPending.hasPendingChanges
		},

		showFooter() {
			return this.showTabs || this.group?.canAddUser || this.group?.canRemoveUser
		},

		footerSummaryText() {
			if (!this.hasPendingChanges) {
				return t('group_manager', 'No pending changes')
			}
			if (!this.showTabs) {
				const parts = []
				if (this.membersPending.joining > 0) {
					parts.push(t('group_manager', '{count} joining', { count: this.membersPending.joining }))
				}
				if (this.membersPending.leaving > 0) {
					parts.push(t('group_manager', '{count} leaving', { count: this.membersPending.leaving }))
				}
				return parts.join(' · ')
			}
			const membersText = this.membersPending.count > 0
				? n('group_manager', '%n change in Members', '%n changes in Members', this.membersPending.count)
				: t('group_manager', 'none in Members')
			const foldersText = this.foldersPending.count > 0
				? n('group_manager', '%n change in Folders', '%n changes in Folders', this.foldersPending.count)
				: t('group_manager', 'none in Folders')
			return membersText + ' · ' + foldersText
		},

		deleteWarning() {
			if (!this.group) {
				return ''
			}
			return this.group.memberCount
				? t('group_manager', 'This will permanently delete "{name}" and remove its {count} member(s). This cannot be undone.', {
					name: this.group.displayName,
					count: this.group.memberCount,
				})
				: t('group_manager', 'This will permanently delete "{name}". This cannot be undone.', {
					name: this.group.displayName,
				})
		},

		deleteButtons() {
			return [
				{ label: t('group_manager', 'Cancel'), type: 'reset', callback: () => true },
				{
					label: t('group_manager', 'Delete'),
					type: 'submit',
					variant: 'error',
					callback: this.confirmDelete,
				},
			]
		},
	},

	watch: {
		groupId: {
			immediate: true,
			handler() {
				this.loadGroup()
			},
		},
	},

	methods: {
		t,

		async loadGroup() {
			this.loading = true
			this.loadError = ''
			try {
				this.group = await fetchGroup(this.groupId)
			} catch (err) {
				this.loadError = extractErrorMessage(err, t('group_manager', 'Could not load this group.'))
			} finally {
				this.loading = false
			}
		},

		onRenamed(group) {
			this.group = group
			this.showRenameDialog = false
			this.$emit('renamed', group)
		},

		async onMembersChanged() {
			await this.refreshGroup()
		},

		async onFoldersChanged() {
			await this.refreshGroup()
		},

		/**
		 * Silent refresh (no `loading` flag) — that would unmount the
		 * v-else-if="group" branch, including both tab managers, wiping
		 * whichever one is showing its just-applied result summary.
		 */
		async refreshGroup() {
			try {
				this.group = await fetchGroup(this.groupId)
				this.$emit('renamed', this.group)
			} catch {
				// Keep the stale summary rather than surface an error here —
				// the change itself already succeeded/reported.
			}
		},

		onMembersPendingChanged(payload) {
			this.membersPending = payload
			this.$emit('pending-changed', this.hasPendingChanges)
		},

		onFoldersPendingChanged(payload) {
			this.foldersPending = payload
			this.$emit('pending-changed', this.hasPendingChanges)
		},

		discardAll() {
			this.$refs.membersManager?.discardChanges()
			this.$refs.foldersManager?.discardChanges()
		},

		async applyAll() {
			this.applying = true
			try {
				const tasks = []
				if (this.membersPending.hasPendingChanges && this.$refs.membersManager) {
					tasks.push(this.$refs.membersManager.applyChanges())
				}
				if (this.foldersPending.hasPendingChanges && this.$refs.foldersManager) {
					tasks.push(this.$refs.foldersManager.applyChanges())
				}
				await Promise.all(tasks)
			} finally {
				this.applying = false
			}
		},

		onDeleteDialogUpdateOpen(value) {
			this.showDeleteDialog = value
			if (!value) {
				this.deleteError = ''
			}
		},

		/**
		 * Returning `false` keeps the dialog open (NcDialogButton awaits this).
		 */
		async confirmDelete() {
			try {
				await deleteGroup(this.group.id)
				this.$emit('deleted', this.group.id)
				return true
			} catch (err) {
				this.deleteError = extractErrorMessage(err, t('group_manager', 'Could not delete the group.'))
				return false
			}
		},
	},
}
</script>

<style scoped>
.gm-detail-panel {
	width: 100%;
	height: 100%;
	overflow: hidden;
	display: flex;
	flex-direction: column;
	padding: 0 32px;
}

.gm-detail-panel__loading {
	display: flex;
	align-items: center;
	justify-content: center;
	height: 100%;
}

.gm-detail-panel__fixed {
	flex-shrink: 0;
	padding: 26px 0 20px;
}

.gm-detail-panel__body {
	flex: 1;
	min-height: 0;
	display: flex;
}

.gm-detail__header {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 8px;
}

.gm-detail__titles {
	flex: 1;
	min-width: 0;
}

.gm-detail__name {
	display: flex;
	align-items: center;
	gap: 4px;
	margin: 0;
	font-size: 24px;
	font-weight: 600;
}

.gm-detail__name-text {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.gm-detail__subtitle {
	margin: 2px 0 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.gm-detail__delete {
	flex-shrink: 0;
	padding: 4px 2px;
	border: none;
	background: transparent;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	cursor: pointer;
}

.gm-detail__delete:hover {
	color: var(--color-error-text);
	text-decoration: underline;
}

.gm-detail__dn {
	font-family: monospace;
	font-size: 13px;
	word-break: break-all;
}

.gm-detail__tabs {
	display: flex;
	gap: 22px;
	margin-top: 12px;
	border-bottom: 1px solid var(--color-border);
}

.gm-detail__tab {
	display: flex;
	align-items: baseline;
	gap: 6px;
	padding: 0 0 10px;
	border: none;
	border-bottom: 2px solid transparent;
	background: transparent;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
	font-family: inherit;
	cursor: pointer;
}

.gm-detail__tab--active {
	margin-bottom: -1px;
	border-bottom-color: var(--color-primary-element);
	color: var(--color-main-text);
	font-weight: 600;
}

.gm-detail__tab-count {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
}

.gm-detail__tab-dot {
	width: 6px;
	height: 6px;
	border-radius: 50%;
	background: var(--color-primary-element);
}

.gm-detail__tab-content {
	flex: 1;
	min-height: 0;
	width: 100%;
}

.gm-detail-panel__footer {
	flex-shrink: 0;
	display: flex;
	align-items: center;
	gap: 12px;
	margin: 0 -32px;
	padding: 10px 32px 14px;
	border-top: 1px solid var(--color-border);
	background: var(--color-background-hover);
}

.gm-detail-panel__footer--disabled {
	color: var(--color-text-maxcontrast);
}

.gm-detail__footer-summary {
	flex: 1;
	min-width: 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.gm-detail__apply {
	border-radius: var(--border-radius-pill);
}

.gm-delete-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 4px 12px;
}
</style>
