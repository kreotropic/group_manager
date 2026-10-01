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
							<span v-if="group.backend !== 'local'"
								class="gm-detail__badge"
								:title="t('group_manager', 'This group is managed by an external backend. Renaming and deleting are disabled here.')">
								{{ t('group_manager', 'LDAP · read-only') }}
							</span>
							<NcButton v-if="group.canRename"
								class="gm-detail__rename"
								variant="tertiary"
								:aria-label="t('group_manager', 'Rename group')"
								:title="t('group_manager', 'Rename group')"
								@click="showRenameDialog = true">
								<template #icon>
									<Pencil :size="16" />
								</template>
							</NcButton>
						</h2>
						<p v-if="subtitleText" class="gm-detail__subtitle">{{ subtitleText }}</p>
						<p v-if="group.dn" class="gm-detail__dn">
							<span class="gm-detail__dn-label">DN</span>
							<code class="gm-detail__dn-value">{{ group.dn }}</code>
							<NcButton variant="tertiary"
								:aria-label="t('group_manager', 'Copy DN')"
								:title="t('group_manager', 'Copy DN')"
								@click="copyDn">
								<template #icon>
									<ContentCopy :size="16" />
								</template>
							</NcButton>
						</p>
					</div>

					<NcActions v-if="group.canDelete"
						class="gm-detail__menu"
						:force-menu="true"
						:aria-label="t('group_manager', 'Group actions')">
						<NcActionButton @click="showDeleteDialog = true">
							<template #icon>
								<TrashCanOutline :size="20" />
							</template>
							{{ t('group_manager', 'Delete group') }}
						</NcActionButton>
					</NcActions>
				</header>

				<nav v-if="showTabs" class="gm-detail__tabs">
					<button type="button"
						class="gm-detail__tab"
						:class="{ 'gm-detail__tab--active': activeTab === 'members' }"
						@click="activeTab = 'members'">
						<span class="gm-detail__tab-label">{{ t('group_manager', 'Members') }}</span>
						<span v-if="showMembersDot" class="gm-detail__tab-dot" />
					</button>
					<button type="button"
						class="gm-detail__tab"
						:class="{ 'gm-detail__tab--active': activeTab === 'folders' }"
						@click="activeTab = 'folders'">
						<span class="gm-detail__tab-label">{{ t('group_manager', 'Folders') }}</span>
						<span v-if="showFoldersDot" class="gm-detail__tab-dot" />
					</button>
				</nav>
			</div>

			<div class="gm-detail-panel__body">
				<GroupMembersManager v-if="group.canAddUser || group.canRemoveUser"
					v-show="!showTabs || activeTab === 'members'"
					ref="membersManager"
					:group-id="group.id"
					:hide-title="true"
					:total-member-count="group.memberCount"
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

			<div v-if="showFooter" class="gm-detail-panel__footer">
				<span class="gm-detail__footer-summary">{{ footerSummaryText }}</span>
				<NcButton :disabled="applying" @click="discardAll">
					{{ t('group_manager', 'Discard') }}
				</NcButton>
				<NcButton class="gm-detail__apply" variant="primary" :disabled="applying" @click="applyAll">
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
import { showSuccess, showError } from '@nextcloud/dialogs'
import { confirmPassword } from '@nextcloud/password-confirmation'
import '@nextcloud/password-confirmation/style.css'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
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
		NcActionButton,
		NcActions,
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		ContentCopy,
		Pencil,
		TrashCanOutline,
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
			// The counts live here, once; the tabs carry none.
			const parts = []
			if (this.group.backend === 'local') {
				parts.push(t('group_manager', 'Local group'))
			}
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

		// Only while there is something to apply or an apply is running:
		// on a read-only group, or with nothing staged, the bar was just
		// "No pending changes" plus two dead buttons.
		showFooter() {
			return (this.showTabs || this.group?.canAddUser || this.group?.canRemoveUser)
				&& (this.hasPendingChanges || this.applying)
		},

		footerSummaryText() {
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

		applying() {
			this.emitPendingState()
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

		async copyDn() {
			try {
				await navigator.clipboard.writeText(this.group.dn)
				showSuccess(t('group_manager', 'DN copied'))
			} catch {
				showError(t('group_manager', 'Could not copy the DN.'))
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
			this.emitPendingState()
		},

		onFoldersPendingChanged(payload) {
			this.foldersPending = payload
			this.emitPendingState()
		},

		/**
		 * hasPendingChanges and applying (GM-08) are each aggregated across
		 * both tabs here (App.vue doesn't need to know which tab a change or
		 * an in-flight batch belongs to), and re-emitted together on every
		 * change to either — applying flips in applyAll() below, not through
		 * either *PendingChanged handler above, so it needs the same watch.
		 */
		emitPendingState() {
			this.$emit('pending-changed', { hasPendingChanges: this.hasPendingChanges, applying: this.applying })
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
				await confirmPassword()
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
	min-width: 0;
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
	display: flex;
	flex-direction: column;
	box-sizing: border-box;
	/* +1px: the list's rule is the top border of its scroll area, below its
	   top region; this one is the last pixel of this box. */
	height: calc(var(--gm-top-h) + 1px);
	padding-top: 26px;
	border-bottom: 1px solid var(--color-border);
}

.gm-detail-panel__body {
	flex: 1;
	min-height: 0;
	min-width: 0;
	display: flex;
	padding-top: 20px;
}

.gm-detail__header {
	display: flex;
	gap: 12px;
	align-items: flex-start;
}

.gm-detail__titles {
	flex: 1;
	min-width: 0;
}

.gm-detail__name {
	display: flex;
	align-items: center;
	min-height: 32px;
	gap: 4px;
	margin: 0;
	font-size: 24px;
	font-weight: 600;
	text-align: left;
	/* Nextcloud's own ".section h2" is inline-flex + justify-content:
	   center, which centred the title over the left-aligned rest. */
	justify-content: flex-start;
	max-width: none;
}

.gm-detail__rename {
	/* The default 44px tap target towered over the 24px title and looked
	   detached; revealed on hover/focus, always shown where there is no hover. */
	--default-clickable-area: 32px;
	opacity: 0;
}

.gm-detail__name:hover .gm-detail__rename,
.gm-detail__name:focus-within .gm-detail__rename {
	opacity: 1;
}

@media (hover: none) {
	.gm-detail__rename {
		opacity: 1;
	}
}

.gm-detail__badge {
	flex-shrink: 0;
	margin-left: 8px;
	padding: 2px 10px;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-pill);
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	font-weight: 600;
	white-space: nowrap;
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

.gm-detail__menu {
	flex-shrink: 0;
	/* The title row is 36px tall (24px type at 1.5), the button 32px: 2px
	   puts the button's centre on the title's. */
	margin-top: 2px;
	--default-clickable-area: 32px;
}

.gm-detail__dn {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 2px 0 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.gm-detail__dn-label {
	font-weight: 700;
	letter-spacing: .07em;
}

.gm-detail__dn-value {
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-family: monospace;
}

.gm-detail__tabs {
	display: flex;
	gap: 22px;
	margin-top: auto;
}

.gm-detail__tabs .gm-detail__tab {
	margin: 0;
	display: flex;
	align-items: baseline;
	gap: 6px;
	padding: 0 0 10px;
	border: none;
	border-bottom: 2px solid transparent;
	border-radius: 0;
	background: transparent;
	color: var(--color-text-maxcontrast);
	font-size: 14px;
	font-weight: 600;
	font-family: inherit;
	cursor: pointer;
}

.gm-detail__tabs .gm-detail__tab--active {
	margin-bottom: -1px;
	border-bottom-color: var(--color-primary-element);
	color: var(--color-main-text);
	font-weight: 600;
}

.gm-detail__tab-label {
	padding-bottom: 0;
	border-bottom: none;
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
