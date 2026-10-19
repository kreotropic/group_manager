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
					<GroupIcon :backend="group.backend" :size="44" />
					<div class="gm-detail__titles">
						<h2 class="gm-detail__name">
							<span class="gm-detail__name-text">{{ group.displayName }}</span>
							<span v-if="group.backend !== 'local'"
								class="gm-detail__badge"
								:title="t('group_manager', 'This group is managed by an external backend. Renaming and deleting are disabled here.')">
								{{ t('group_manager', 'LDAP · read-only') }}
							</span>
							<!-- A button, not a line under the summary: the header row
							     has the same fixed height as the list's title row. -->
							<NcButton v-if="group.dn"
								class="gm-detail__dn"
								variant="tertiary"
								:aria-label="t('group_manager', 'Copy DN')"
								:title="group.dn"
								@click="copyDn">
								<template #icon>
									<ContentCopy :size="16" />
								</template>
							</NcButton>
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
						<p v-if="subtitleText" class="gm-detail__subtitle" :title="subtitleText">{{ subtitleText }}</p>
					</div>

					<NcActions v-if="group.canDelete"
						class="gm-detail__menu"
						:force-menu="true"
						:aria-label="t('group_manager', 'Group actions')">
						<NcActionButton close-after-click @click="showDeleteDialog = true">
							<template #icon>
								<TrashCanOutline :size="20" />
							</template>
							{{ t('group_manager', 'Delete group') }}
						</NcActionButton>
					</NcActions>
				</header>

				<!-- Always there, even with Members alone: the row lines up with
				     the list's origin pills. -->
				<nav class="gm-detail__tabs">
					<div class="gm-detail__tabs-track">
						<button type="button"
							class="gm-detail__tab"
							:class="{ 'gm-detail__tab--active': activeTab === 'members' }"
							@click="activeTab = 'members'">
							<span class="gm-detail__tab-label">{{ t('group_manager', 'Members') }}</span>
							<span v-if="showMembersDot" class="gm-detail__tab-dot" />
						</button>
						<button v-if="showFoldersTab"
							type="button"
							class="gm-detail__tab"
							:class="{ 'gm-detail__tab--active': activeTab === 'folders' }"
							@click="activeTab = 'folders'">
							<span class="gm-detail__tab-label">{{ t('group_manager', 'Folders') }}</span>
							<span v-if="showFoldersDot" class="gm-detail__tab-dot" />
						</button>
						<button v-if="showQuotaTab"
							type="button"
							class="gm-detail__tab"
							:class="{ 'gm-detail__tab--active': activeTab === 'quota' }"
							@click="activeTab = 'quota'">
							<span class="gm-detail__tab-label">{{ t('group_manager', 'Quota') }}</span>
							<span v-if="showQuotaDot" class="gm-detail__tab-dot" />
						</button>
					</div>
				</nav>
			</div>

			<div class="gm-detail-panel__body">
				<!-- Every group, LDAP included: membership may be read-only, but
				     group admins are managed here regardless of backend. -->
				<GroupMembersManager v-show="!showTabs || activeTab === 'members'"
					ref="membersManager"
					:group-id="group.id"
					:hide-title="true"
					:total-member-count="group.memberCount"
					:can-add="group.canAddUser"
					:can-remove="group.canRemoveUser"
					class="gm-detail__tab-content"
					@changed="onMembersChanged"
					@pending-changed="onMembersPendingChanged" />

				<GroupFoldersManager v-if="showFoldersTab"
					v-show="activeTab === 'folders'"
					ref="foldersManager"
					:group-id="group.id"
					class="gm-detail__tab-content"
					@changed="onFoldersChanged"
					@pending-changed="onFoldersPendingChanged" />

				<GroupQuotaManager v-if="showQuotaTab"
					v-show="activeTab === 'quota'"
					ref="quotaManager"
					:group-id="group.id"
					class="gm-detail__tab-content"
					@changed="onQuotaChanged"
					@pending-changed="onQuotaPendingChanged" />
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
				dialog-classes="gm-dialog"
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
import GroupIcon from './GroupIcon.vue'
import RenameGroupDialog from './RenameGroupDialog.vue'
import GroupMembersManager from './GroupMembersManager.vue'
import GroupFoldersManager from './GroupFoldersManager.vue'
import GroupQuotaManager from './GroupQuotaManager.vue'
import { fetchGroup, deleteGroup } from '../services/api.js'
import { extractErrorMessage } from '../utils/errors.js'
import { humanSize } from '../utils/format.js'

const EMPTY_PENDING = { hasPendingChanges: false, count: 0, joining: 0, leaving: 0, admins: 0, current: null, after: null }

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
		GroupIcon,
		RenameGroupDialog,
		GroupMembersManager,
		GroupFoldersManager,
		GroupQuotaManager,
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
			quotaPending: { ...EMPTY_PENDING },
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
			// Only membership changes move the count; queued group admin
			// changes alone would just repeat it as "N after applying".
			const membershipChanges = this.membersPending.joining + this.membersPending.leaving
			if (membershipChanges > 0 && this.membersPending.after !== null) {
				parts.push(t('group_manager', '{current} members · {after} after applying', {
					current: this.membersPending.current,
					after: this.membersPending.after,
				}))
			} else {
				parts.push(this.group.memberCount
					? n('group_manager', '%n member', '%n members', this.group.memberCount)
					: t('group_manager', 'no members'))
			}
			if (this.group.subAdminCount) {
				parts.push(n('group_manager', '%n group admin', '%n group admins', this.group.subAdminCount))
			}
			if (this.group.disabledCount) {
				parts.push(n('group_manager', '%n disabled', '%n disabled', this.group.disabledCount))
			}
			if (this.group.foldersEnabled) {
				parts.push(this.group.folderCount
					? n('group_manager', '%n folder', '%n folders', this.group.folderCount)
					: t('group_manager', 'no folders'))
			}
			if (this.group.quotaEnabled) {
				parts.push(this.group.quota === null || this.group.quota === undefined
					? t('group_manager', 'no quota')
					: t('group_manager', 'quota {size}', { size: humanSize(this.group.quota) }))
			}
			return parts.join(' · ')
		},

		showFoldersTab() {
			return !!this.group?.foldersEnabled
		},

		showQuotaTab() {
			return !!this.group?.quotaEnabled
		},

		// The tab bar only exists when there is more than Members to show.
		showTabs() {
			return this.showFoldersTab || this.showQuotaTab
		},

		showMembersDot() {
			return this.showTabs && this.activeTab !== 'members' && this.membersPending.hasPendingChanges
		},

		showFoldersDot() {
			return this.showFoldersTab && this.activeTab !== 'folders' && this.foldersPending.hasPendingChanges
		},

		showQuotaDot() {
			return this.showQuotaTab && this.activeTab !== 'quota' && this.quotaPending.hasPendingChanges
		},

		hasPendingChanges() {
			return this.membersPending.hasPendingChanges
				|| this.foldersPending.hasPendingChanges
				|| this.quotaPending.hasPendingChanges
		},

		// Only while there is something to apply or an apply is running:
		// with nothing staged, the bar was just "No pending changes" plus two
		// dead buttons. Every group can stage something now (group admins,
		// even on a read-only LDAP group), so that is the only condition.
		showFooter() {
			return this.hasPendingChanges || this.applying
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
				if (this.membersPending.admins > 0) {
					parts.push(n('group_manager', '%n group admin change', '%n group admin changes', this.membersPending.admins))
				}
				return parts.join(' · ')
			}
			const membersText = this.membersPending.count > 0
				? n('group_manager', '%n change in Members', '%n changes in Members', this.membersPending.count)
				: t('group_manager', 'none in Members')
			const parts = [membersText]
			if (this.showFoldersTab) {
				parts.push(this.foldersPending.count > 0
					? n('group_manager', '%n change in Folders', '%n changes in Folders', this.foldersPending.count)
					: t('group_manager', 'none in Folders'))
			}
			if (this.showQuotaTab) {
				parts.push(this.quotaPending.count > 0
					? t('group_manager', 'quota change')
					: t('group_manager', 'none in Quota'))
			}
			return parts.join(' · ')
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

		async onQuotaChanged() {
			await this.refreshGroup()
		},

		onQuotaPendingChanged(payload) {
			this.quotaPending = payload
			this.emitPendingState()
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
			this.$refs.quotaManager?.discardChanges()
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
				if (this.quotaPending.hasPendingChanges && this.$refs.quotaManager) {
					tasks.push(this.$refs.quotaManager.applyChanges())
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

<style scoped src="../styles/detail.css"></style>
