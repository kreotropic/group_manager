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
			<header class="gm-detail__header">
				<Lan v-if="group.backend === 'ldap'" :size="28" class="gm-detail__icon" />
				<AccountMultiple v-else :size="28" class="gm-detail__icon" />

				<div class="gm-detail__titles">
					<h2 class="gm-detail__name">
						{{ group.displayName }}
						<NcButton v-if="group.canRename"
							variant="tertiary"
							:aria-label="t('group_manager', 'Rename group')"
							:title="t('group_manager', 'Rename group')"
							@click="showRenameDialog = true">
							<template #icon>
								<Pencil :size="18" />
							</template>
						</NcButton>
					</h2>
					<p class="gm-detail__meta">
						<span v-if="group.id !== group.displayName" class="gm-detail__gid">{{ group.id }}</span>
						<span class="gm-badge" :class="'gm-badge--' + group.backend">
							{{ group.backend === 'ldap' ? t('group_manager', 'LDAP') : t('group_manager', 'Local') }}
						</span>
					</p>
				</div>

				<NcButton v-if="group.canDelete"
					variant="error"
					@click="showDeleteDialog = true">
					<template #icon>
						<DeleteOutline :size="18" />
					</template>
					{{ t('group_manager', 'Delete') }}
				</NcButton>
			</header>

			<div class="gm-metrics">
				<div class="gm-metric-card">
					<span class="gm-metric-card__value">{{ group.memberCount ?? '—' }}</span>
					<span class="gm-metric-card__label">{{ t('group_manager', 'Members') }}</span>
				</div>
				<div class="gm-metric-card">
					<span class="gm-metric-card__value">{{ group.disabledCount ?? '—' }}</span>
					<span class="gm-metric-card__label">{{ t('group_manager', 'Disabled members') }}</span>
				</div>
				<div class="gm-metric-card">
					<span class="gm-metric-card__value">{{ group.subAdminCount ?? '—' }}</span>
					<span class="gm-metric-card__label">{{ t('group_manager', 'Sub-admins') }}</span>
				</div>
			</div>

			<NcNoteCard v-if="group.backend !== 'local'" type="info">
				<p>{{ t('group_manager', 'This group is managed by an external backend. Renaming and deleting are disabled here.') }}</p>
				<p v-if="group.dn" class="gm-detail__dn">{{ group.dn }}</p>
			</NcNoteCard>

			<GroupMembersManager v-if="group.canAddUser || group.canRemoveUser"
				:group-id="group.id"
				class="gm-detail__members"
				@changed="onMembersChanged" />
			<GroupMembersList v-else :group-id="group.id" class="gm-detail__members" />

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
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import DeleteOutline from 'vue-material-design-icons/DeleteOutline.vue'
import Lan from 'vue-material-design-icons/Lan.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import RenameGroupDialog from './RenameGroupDialog.vue'
import GroupMembersList from './GroupMembersList.vue'
import GroupMembersManager from './GroupMembersManager.vue'
import { fetchGroup, deleteGroup } from '../services/api.js'
import { extractErrorMessage } from '../utils/errors.js'

export default {
	name: 'GroupDetail',

	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		AccountMultiple,
		DeleteOutline,
		Lan,
		Pencil,
		RenameGroupDialog,
		GroupMembersList,
		GroupMembersManager,
	},

	props: {
		groupId: {
			type: String,
			required: true,
		},
	},

	emits: ['renamed', 'deleted'],

	data() {
		return {
			group: null,
			loading: true,
			loadError: '',
			showRenameDialog: false,
			showDeleteDialog: false,
			deleteError: '',
		}
	},

	computed: {
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
			// Silent refresh (no `loading` flag) — that would unmount the
			// v-else-if="group" branch, including GroupMembersManager itself,
			// wiping the just-applied result summary it's showing.
			try {
				this.group = await fetchGroup(this.groupId)
				this.$emit('renamed', this.group)
			} catch {
				// Keep the stale summary rather than surface an error here —
				// the member change itself already succeeded/reported.
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
	overflow-y: auto;
	padding: 24px;
}

.gm-detail-panel__loading {
	display: flex;
	align-items: center;
	justify-content: center;
	height: 100%;
}

.gm-detail__header {
	display: flex;
	align-items: flex-start;
	gap: 12px;
	margin-bottom: 20px;
}

.gm-detail__icon {
	flex-shrink: 0;
	margin-top: 4px;
	color: var(--color-text-maxcontrast);
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
}

.gm-detail__meta {
	display: flex;
	align-items: center;
	gap: 8px;
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
}

.gm-detail__gid {
	font-family: monospace;
}

.gm-badge {
	display: inline-block;
	padding: 2px 8px;
	border-radius: var(--border-radius-pill);
	font-size: 12px;
	font-weight: bold;
}

.gm-badge--local {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-text);
}

.gm-badge--ldap,
.gm-badge--other {
	background: var(--color-background-darker);
	color: var(--color-text-maxcontrast);
}

.gm-metrics {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
	gap: 12px;
	margin-bottom: 20px;
}

.gm-metric-card {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
}

.gm-metric-card__value {
	font-size: 24px;
	font-weight: bold;
}

.gm-metric-card__label {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.gm-detail__dn {
	font-family: monospace;
	font-size: 13px;
	word-break: break-all;
}

.gm-detail__members {
	margin-top: 20px;
}

.gm-delete-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 4px 12px;
}
</style>
