<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div class="gm-fm">
		<div class="gm-fm__scroll">
			<div class="gm-fm__add" :class="{ 'gm-fm__add--open': showDropdown }">
				<Plus :size="18" class="gm-fm__add-icon" />
				<input ref="addInput"
					v-model="addQuery"
					type="text"
					class="gm-fm__add-input"
					:placeholder="t('group_manager', 'Assign a group folder…')"
					:aria-label="t('group_manager', 'Assign a group folder')"
					@input="onAddInput"
					@focus="onAddFocus"
					@keydown.down.prevent="moveActive(1)"
					@keydown.up.prevent="moveActive(-1)"
					@keydown.enter.prevent="onAddEnter"
					@keydown.esc="closeDropdown">

				<ul v-if="showDropdown" class="gm-fm__add-dropdown" role="listbox">
					<li v-if="addSearching" class="gm-fm__add-status">
						<NcLoadingIcon :size="16" />
						{{ t('group_manager', 'Searching…') }}
					</li>
					<template v-else-if="addResults.length === 0">
						<li class="gm-fm__add-status">
							{{ addQuery.trim() === ''
								? t('group_manager', 'Type to search group folders')
								: t('group_manager', 'No folders for "{term}"', { term: addQuery.trim() }) }}
						</li>
						<li v-if="addQuery.trim() !== ''" class="gm-fm__add-hint">
							{{ t('group_manager', 'Folders already assigned to this group don\'t show up here.') }}
						</li>
					</template>
					<template v-else>
						<li v-for="(option, index) in addResults"
							:key="option.id"
							class="gm-fm__add-option"
							:class="{ 'gm-fm__add-option--active': index === activeIndex }"
							role="option"
							@mouseenter="activeIndex = index"
							@mousedown.prevent
							@click="pickFolder(option)">
							<FolderOutline :size="17" class="gm-fm__add-option-icon" />
							<span class="gm-fm__add-option-name">{{ option.mountPoint }}</span>
						</li>
					</template>
				</ul>
			</div>

			<div v-if="hasPendingChanges" class="gm-fm__queue" aria-live="polite">
				<span v-for="chip in queueChips"
					:key="chip.key"
					class="gm-fm__chip"
					:class="'gm-fm__chip--' + chip.kind"
					:title="chip.error || undefined">
					<span class="gm-fm__chip-prefix" aria-hidden="true">{{ chip.kind === 'remove' ? '−' : '+' }}</span>
					{{ chip.mountPoint }}
					<AlertCircle v-if="chip.hasError" :size="14" class="gm-fm__chip-error" />
					<button type="button"
						class="gm-fm__chip-close"
						:aria-label="t('group_manager', 'Cancel: {name}', { name: chip.mountPoint })"
						:disabled="applying"
						@click="cancelChip(chip)">
						×
					</button>
				</span>
			</div>

			<div v-if="loading" class="gm-fm__loading">
				<NcLoadingIcon :size="24" />
			</div>

			<p v-else-if="displayRows.length === 0" class="gm-fm__empty">
				{{ t('group_manager', "This group has no folders assigned.") }}
				<br>
				{{ t('group_manager', 'Use the field above to give it access to an existing folder.') }}
			</p>

			<table v-else class="gm-fm__table">
				<thead>
					<tr>
						<th class="gm-fm__col-name">{{ t('group_manager', 'Folder') }}</th>
						<th class="gm-fm__col-quota">{{ t('group_manager', 'Quota') }}</th>
						<th class="gm-fm__col-perm">{{ t('group_manager', 'Write') }}</th>
						<th class="gm-fm__col-perm">{{ t('group_manager', 'Share') }}</th>
						<th class="gm-fm__col-perm">{{ t('group_manager', 'Delete') }}</th>
						<th class="gm-fm__col-spacer" />
					</tr>
				</thead>
				<tbody>
					<tr v-for="row in displayRows"
						:key="row.id"
						class="gm-fm__row"
						:class="{ 'gm-fm__row--leaving': row.pendingKind === 'unassign', 'gm-fm__row--changed': row.changed }">
						<td class="gm-fm__col-name">
							<FolderOutline :size="17" class="gm-fm__row-icon" :class="{ 'gm-fm__row-icon--new': row.pendingKind === 'assign' }" />
							<span class="gm-fm__row-name" :class="{ 'gm-fm__row-name--new': row.pendingKind === 'assign' }">{{ row.mountPoint }}</span>
							<span v-if="row.pendingKind === 'assign'" class="gm-fm__row-badge gm-fm__row-badge--new">{{ t('group_manager', 'new access') }}</span>
							<span v-if="row.acl" class="gm-fm__row-badge gm-fm__row-badge--acl" :title="aclTooltip">{{ t('group_manager', 'ACL') }}</span>
						</td>
						<td class="gm-fm__col-quota">
							<span v-if="row.pendingKind === 'unassign'" class="gm-fm__row-leaving-label">{{ t('group_manager', 'access will end') }}</span>
							<span v-else class="gm-fm__row-quota">{{ formatQuota(row) }}</span>
						</td>
						<template v-if="row.pendingKind !== 'unassign'">
							<td class="gm-fm__col-perm">
								<div class="gm-fm__switch"
									:class="{ 'gm-fm__switch--on': row.permissions.write, 'gm-fm__switch--disabled': row.pendingKind === 'assign' || applying }"
									role="switch"
									:tabindex="row.pendingKind === 'assign' || applying ? -1 : 0"
									:aria-checked="row.permissions.write"
									:aria-disabled="row.pendingKind === 'assign' || applying"
									:aria-label="t('group_manager', 'Write access to {name}', { name: row.mountPoint })"
									@click="row.pendingKind !== 'assign' && !applying && togglePermission(row, 'write')"
									@keydown.enter.prevent="row.pendingKind !== 'assign' && !applying && togglePermission(row, 'write')"
									@keydown.space.prevent="row.pendingKind !== 'assign' && !applying && togglePermission(row, 'write')">
									<span class="gm-fm__switch-knob" />
								</div>
							</td>
							<td class="gm-fm__col-perm">
								<div class="gm-fm__switch"
									:class="{ 'gm-fm__switch--on': row.permissions.share, 'gm-fm__switch--disabled': row.pendingKind === 'assign' || applying }"
									role="switch"
									:tabindex="row.pendingKind === 'assign' || applying ? -1 : 0"
									:aria-checked="row.permissions.share"
									:aria-disabled="row.pendingKind === 'assign' || applying"
									:aria-label="t('group_manager', 'Share access to {name}', { name: row.mountPoint })"
									@click="row.pendingKind !== 'assign' && !applying && togglePermission(row, 'share')"
									@keydown.enter.prevent="row.pendingKind !== 'assign' && !applying && togglePermission(row, 'share')"
									@keydown.space.prevent="row.pendingKind !== 'assign' && !applying && togglePermission(row, 'share')">
									<span class="gm-fm__switch-knob" />
								</div>
							</td>
							<td class="gm-fm__col-perm">
								<div class="gm-fm__switch"
									:class="{ 'gm-fm__switch--on': row.permissions.delete, 'gm-fm__switch--disabled': row.pendingKind === 'assign' || applying }"
									role="switch"
									:tabindex="row.pendingKind === 'assign' || applying ? -1 : 0"
									:aria-checked="row.permissions.delete"
									:aria-disabled="row.pendingKind === 'assign' || applying"
									:aria-label="t('group_manager', 'Delete access to {name}', { name: row.mountPoint })"
									@click="row.pendingKind !== 'assign' && !applying && togglePermission(row, 'delete')"
									@keydown.enter.prevent="row.pendingKind !== 'assign' && !applying && togglePermission(row, 'delete')"
									@keydown.space.prevent="row.pendingKind !== 'assign' && !applying && togglePermission(row, 'delete')">
									<span class="gm-fm__switch-knob" />
								</div>
							</td>
						</template>
						<td v-else class="gm-fm__col-perm" colspan="3" />
						<td class="gm-fm__col-spacer">
							<button v-if="row.pendingKind !== 'unassign'"
								type="button"
								class="gm-fm__row-remove"
								:aria-label="t('group_manager', 'Remove access to {name}', { name: row.mountPoint })"
								:disabled="applying"
								@click="removeRow(row)">
								×
							</button>
						</td>
					</tr>
				</tbody>
			</table>

			<p class="gm-fm__note">
				{{ t('group_manager', 'Read access is always on.') }}
				{{ t('group_manager', "Quota belongs to the folder and is shared by every group with access to it — it isn't editable here.") }}
			</p>
		</div>

		<NcNoteCard v-if="lastResult" :type="lastResult.failed.length > 0 ? 'warning' : 'success'" class="gm-fm__result">
			<p>{{ resultSummaryText }}</p>
			<details v-if="lastResult.failed.length > 0" open>
				<summary>{{ t('group_manager', 'Show failure details') }}</summary>
				<ul class="gm-fm__result-list">
					<li v-for="f in lastResult.failed" :key="f.action + '-' + f.id">
						{{ f.mountPoint }} — {{ f.error }}
					</li>
				</ul>
			</details>
			<NcButton v-if="lastResult.failed.length > 0" @click="retryFailed">
				{{ t('group_manager', 'Try again') }}
			</NcButton>
		</NcNoteCard>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import {
	fetchGroupFolders,
	searchAssignableFolders,
	assignGroupFolder,
	unassignGroupFolder,
	setGroupFolderPermissions,
} from '../services/api.js'
import { extractErrorMessage } from '../utils/errors.js'
import { runWithConcurrency } from '../utils/concurrency.js'
import { humanSize } from '../utils/format.js'

const SEARCH_DEBOUNCE_MS = 300
const APPLY_CONCURRENCY = 4
const UNLIMITED_QUOTA = -3

export default {
	name: 'GroupFoldersManager',

	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		AlertCircle,
		FolderOutline,
		Plus,
	},

	props: {
		groupId: {
			type: String,
			required: true,
		},
	},

	emits: ['changed', 'pending-changed'],

	data() {
		return {
			folders: [],
			loading: true,

			addQuery: '',
			addSearching: false,
			addResults: [],
			addSearchTimer: null,
			showDropdown: false,
			activeIndex: -1,

			// {id, mountPoint, quota, size, acl, status, error}
			pendingAssign: [],
			// {id, mountPoint, status, error}
			pendingUnassign: [],
			// folderId -> {write, share, delete, status, error}
			permissionOverrides: {},

			applying: false,
			lastResult: null,
		}
	},

	computed: {
		hasPendingChanges() {
			return this.pendingAssign.length > 0
				|| this.pendingUnassign.length > 0
				|| Object.keys(this.permissionOverrides).length > 0
		},

		pendingCount() {
			return this.pendingAssign.length + this.pendingUnassign.length + Object.keys(this.permissionOverrides).length
		},

		aclTooltip() {
			return t('group_manager', 'Advanced permissions are on for this folder — effective access can be more restrictive than these switches show.')
		},

		queueChips() {
			const assignChips = this.pendingAssign.map((i) => ({
				key: 'assign-' + i.id,
				kind: 'add',
				id: i.id,
				mountPoint: i.mountPoint,
				hasError: i.status === 'error',
				error: i.error,
			}))
			const unassignChips = this.pendingUnassign.map((i) => ({
				key: 'unassign-' + i.id,
				kind: 'remove',
				id: i.id,
				mountPoint: i.mountPoint,
				hasError: i.status === 'error',
				error: i.error,
			}))
			return [...assignChips, ...unassignChips]
		},

		/**
		 * The single row list the table renders: real assigned folders (with
		 * unassign/permission-override state layered on top) plus not-yet-applied
		 * pending-assign rows appended at the end.
		 */
		displayRows() {
			const unassignIds = new Set(this.pendingUnassign.map((i) => i.id))
			const assigned = this.folders.map((folder) => {
				const override = this.permissionOverrides[folder.id]
				return {
					...folder,
					permissions: override ? { write: override.write, share: override.share, delete: override.delete } : folder.permissions,
					pendingKind: unassignIds.has(folder.id) ? 'unassign' : null,
					changed: !!override,
				}
			})
			const pending = this.pendingAssign.map((i) => ({
				id: i.id,
				mountPoint: i.mountPoint,
				quota: i.quota,
				size: i.size,
				acl: i.acl,
				permissions: { write: true, share: true, delete: true },
				pendingKind: 'assign',
				changed: false,
			}))
			return [...assigned, ...pending]
		},

		resultSummaryText() {
			if (!this.lastResult) {
				return ''
			}
			const parts = []
			if (this.lastResult.assignedCount > 0) {
				parts.push(t('group_manager', '{count} assigned', { count: this.lastResult.assignedCount }))
			}
			if (this.lastResult.unassignedCount > 0) {
				parts.push(t('group_manager', '{count} removed', { count: this.lastResult.unassignedCount }))
			}
			if (this.lastResult.permissionCount > 0) {
				parts.push(t('group_manager', '{count} permission change(s)', { count: this.lastResult.permissionCount }))
			}
			if (this.lastResult.failed.length > 0) {
				parts.push(t('group_manager', '{count} failed', { count: this.lastResult.failed.length }))
			}
			return parts.length > 0 ? parts.join(', ') : t('group_manager', 'Nothing to apply.')
		},
	},

	watch: {
		groupId() {
			this.resetState()
			this.reload()
		},

		hasPendingChanges(value) {
			this.$emit('pending-changed', { hasPendingChanges: value, count: this.pendingCount })
		},

		pendingCount(value) {
			if (this.hasPendingChanges) {
				this.$emit('pending-changed', { hasPendingChanges: true, count: value })
			}
		},
	},

	mounted() {
		this.reload()
		document.addEventListener('click', this.onDocumentClick)
	},

	beforeUnmount() {
		clearTimeout(this.addSearchTimer)
		document.removeEventListener('click', this.onDocumentClick)
	},

	methods: {
		t,
		humanSize,

		resetState() {
			this.addQuery = ''
			this.addResults = []
			this.showDropdown = false
			this.pendingAssign = []
			this.pendingUnassign = []
			this.permissionOverrides = {}
			this.lastResult = null
		},

		async reload() {
			this.loading = true
			try {
				this.folders = await fetchGroupFolders(this.groupId)
			} finally {
				this.loading = false
			}
		},

		formatQuota(row) {
			const used = humanSize(row.size)
			if (row.quota < 0 || row.quota === UNLIMITED_QUOTA) {
				return used
			}
			return t('group_manager', '{used} of {total}', { used, total: humanSize(row.quota) })
		},

		onDocumentClick(event) {
			if (this.showDropdown && this.$el && !this.$el.contains(event.target)) {
				this.closeDropdown()
			}
		},

		onAddFocus() {
			if (this.addQuery.trim() !== '') {
				this.showDropdown = true
			}
		},

		onAddInput() {
			clearTimeout(this.addSearchTimer)
			this.activeIndex = -1
			this.showDropdown = true
			this.addSearching = true
			this.addSearchTimer = setTimeout(async () => {
				try {
					this.addResults = await searchAssignableFolders(this.groupId, this.addQuery.trim(), 10)
				} finally {
					this.addSearching = false
				}
			}, SEARCH_DEBOUNCE_MS)
		},

		closeDropdown() {
			this.showDropdown = false
			this.activeIndex = -1
		},

		moveActive(delta) {
			if (!this.showDropdown || this.addResults.length === 0) {
				return
			}
			const count = this.addResults.length
			this.activeIndex = (this.activeIndex + delta + count) % count
		},

		onAddEnter() {
			if (this.activeIndex >= 0 && this.addResults[this.activeIndex]) {
				this.pickFolder(this.addResults[this.activeIndex])
			}
		},

		pickFolder(folder) {
			if (this.pendingAssign.some((i) => i.id === folder.id)) {
				return
			}
			this.pendingAssign.push({
				id: folder.id,
				mountPoint: folder.mountPoint,
				quota: folder.quota,
				size: folder.size,
				acl: folder.acl,
				status: 'pending',
				error: '',
			})
			this.addQuery = ''
			this.addResults = []
			this.closeDropdown()
		},

		cancelChip(chip) {
			if (chip.kind === 'add') {
				this.pendingAssign = this.pendingAssign.filter((i) => i.id !== chip.id)
			} else {
				this.pendingUnassign = this.pendingUnassign.filter((i) => i.id !== chip.id)
			}
		},

		togglePermission(row, key) {
			const current = row.permissions
			const next = { write: current.write, share: current.share, delete: current.delete, [key]: !current[key] }
			const original = this.folders.find((f) => f.id === row.id)?.permissions
			if (original && original.write === next.write && original.share === next.share && original.delete === next.delete) {
				const { [row.id]: _drop, ...rest } = this.permissionOverrides
				this.permissionOverrides = rest
			} else {
				this.permissionOverrides = { ...this.permissionOverrides, [row.id]: { ...next, status: 'pending', error: '' } }
			}
		},

		removeRow(row) {
			if (row.pendingKind === 'assign') {
				this.pendingAssign = this.pendingAssign.filter((i) => i.id !== row.id)
				return
			}
			if (this.permissionOverrides[row.id]) {
				const { [row.id]: _drop, ...rest } = this.permissionOverrides
				this.permissionOverrides = rest
			}
			this.pendingUnassign.push({ id: row.id, mountPoint: row.mountPoint, status: 'pending', error: '' })
		},

		discardChanges() {
			this.pendingAssign = []
			this.pendingUnassign = []
			this.permissionOverrides = {}
			this.lastResult = null
		},

		async applyChanges() {
			this.applying = true
			this.lastResult = null

			const assignTasks = this.pendingAssign.map((item) => ({ kind: 'assign', item }))
			const unassignTasks = this.pendingUnassign.map((item) => ({ kind: 'unassign', item }))
			const permissionTasks = Object.entries(this.permissionOverrides).map(([id, override]) => ({
				kind: 'permissions',
				item: {
					id: Number(id),
					mountPoint: this.folders.find((f) => f.id === Number(id))?.mountPoint ?? '',
					write: override.write,
					share: override.share,
					delete: override.delete,
					status: 'pending',
					error: '',
				},
			}))
			const tasks = [...assignTasks, ...unassignTasks, ...permissionTasks]

			await runWithConcurrency(tasks, APPLY_CONCURRENCY, async ({ kind, item }) => {
				try {
					if (kind === 'assign') {
						await assignGroupFolder(this.groupId, item.id)
					} else if (kind === 'unassign') {
						await unassignGroupFolder(this.groupId, item.id)
					} else {
						await setGroupFolderPermissions(this.groupId, item.id, { write: item.write, share: item.share, del: item.delete })
					}
					item.status = 'done'
				} catch (err) {
					item.status = 'error'
					item.error = extractErrorMessage(err, t('group_manager', 'Failed'))
				}
			})

			const failedAssign = assignTasks.map((task) => task.item).filter((i) => i.status === 'error')
			const failedUnassign = unassignTasks.map((task) => task.item).filter((i) => i.status === 'error')
			const failedPermissions = permissionTasks.map((task) => task.item).filter((i) => i.status === 'error')

			this.lastResult = {
				assignedCount: assignTasks.length - failedAssign.length,
				unassignedCount: unassignTasks.length - failedUnassign.length,
				permissionCount: permissionTasks.length - failedPermissions.length,
				failed: [
					...failedAssign.map((i) => ({ ...i, action: 'assign' })),
					...failedUnassign.map((i) => ({ ...i, action: 'unassign' })),
					...failedPermissions.map((i) => ({ ...i, action: 'permissions' })),
				],
			}

			this.pendingAssign = failedAssign
			this.pendingUnassign = failedUnassign
			this.permissionOverrides = Object.fromEntries(
				failedPermissions.map((i) => [i.id, { write: i.write, share: i.share, delete: i.delete, status: 'error', error: i.error }]),
			)
			this.applying = false

			await this.reload()
			this.$emit('changed')
		},

		retryFailed() {
			this.applyChanges()
		},
	},
}
</script>

<style scoped>
.gm-fm {
	display: flex;
	flex-direction: column;
	height: 100%;
	min-height: 0;
}

.gm-fm__scroll {
	flex: 1;
	min-height: 0;
	overflow-y: auto;
	padding-bottom: 8px;
}

.gm-fm__add {
	position: relative;
	display: flex;
	align-items: center;
	gap: 8px;
	height: 40px;
	padding: 0 12px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
	margin-bottom: 12px;
}

.gm-fm__add--open {
	border-color: var(--color-primary-element);
}

.gm-fm__add-icon {
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
}

.gm-fm__add-input {
	flex: 1;
	height: 100%;
	border: none;
	outline: none;
	background: transparent;
	font-family: inherit;
	font-size: 14px;
	color: var(--color-main-text);
}

.gm-fm__add-dropdown {
	position: absolute;
	z-index: 20;
	top: calc(100% + 4px);
	left: 0;
	right: 0;
	max-height: 280px;
	overflow-y: auto;
	margin: 0;
	padding: 4px;
	list-style: none;
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	box-shadow: 0 2px 12px var(--color-box-shadow);
}

.gm-fm__add-status {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 10px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.gm-fm__add-hint {
	padding: 0 10px 6px;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	font-style: italic;
}

.gm-fm__add-option {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 10px;
	border-radius: var(--border-radius);
	cursor: pointer;
}

.gm-fm__add-option--active,
.gm-fm__add-option:hover {
	background: var(--color-background-hover);
}

.gm-fm__add-option-icon {
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
}

.gm-fm__add-option-name {
	flex: 1;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-size: 14px;
}

.gm-fm__queue {
	display: flex;
	flex-wrap: wrap;
	gap: 7px;
	margin-bottom: 12px;
}

.gm-fm__chip {
	display: inline-flex;
	align-items: center;
	gap: 5px;
	padding: 3px 6px 3px 9px;
	border-radius: var(--border-radius-pill);
	font-size: 13px;
	border: 1px solid transparent;
}

.gm-fm__chip--add {
	background: var(--color-success);
	border-color: var(--color-success-hover);
	color: var(--color-success-text);
}

.gm-fm__chip--remove {
	background: var(--color-error);
	border-color: var(--color-error-hover);
	color: var(--color-error-text);
}

.gm-fm__chip-prefix {
	font-weight: bold;
}

.gm-fm__chip-error {
	color: var(--color-error);
}

.gm-fm__chip-close {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 18px;
	height: 18px;
	border: none;
	border-radius: 50%;
	background: transparent;
	color: inherit;
	font-size: 15px;
	line-height: 1;
	cursor: pointer;
	opacity: .75;
}

.gm-fm__chip-close:hover {
	opacity: 1;
	background: color-mix(in srgb, currentColor 15%, transparent);
}

.gm-fm__loading {
	display: flex;
	justify-content: center;
	padding: 20px 0;
}

.gm-fm__empty {
	color: var(--color-text-maxcontrast);
}

.gm-fm__table {
	width: 100%;
	border-collapse: collapse;
}

.gm-fm__table thead th {
	padding: 0 0 6px;
	text-align: left;
	font-size: 11px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: .07em;
	color: var(--color-text-maxcontrast);
}

.gm-fm__col-name {
	width: auto;
}

.gm-fm__col-quota {
	width: 112px;
	flex: none;
}

.gm-fm__col-perm {
	width: 62px;
	text-align: center;
}

.gm-fm__col-spacer {
	width: 22px;
}

.gm-fm__row {
	border-top: 1px solid var(--color-border);
}

.gm-fm__row td {
	padding: 9px 0;
	height: 1px;
	vertical-align: middle;
}

.gm-fm__row--changed {
	background: var(--color-background-hover);
}

.gm-fm__col-name {
	display: flex;
	align-items: center;
	gap: 8px;
	min-width: 0;
}

.gm-fm__row-icon {
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
}

.gm-fm__row-icon--new {
	color: var(--color-success-text);
}

.gm-fm__row-name {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-size: 14px;
	flex: 1;
	min-width: 0;
}

.gm-fm__row--leaving .gm-fm__row-name {
	text-decoration: line-through;
	color: var(--color-text-maxcontrast);
	flex: initial;
}

.gm-fm__row-name--new {
	color: var(--color-success-text);
}

.gm-fm__row-badge {
	flex: none;
	padding: 1px 7px;
	border-radius: 9px;
	border: 1px solid;
	font-size: 11px;
	font-weight: 600;
	white-space: nowrap;
}

.gm-fm__row-badge--new {
	color: var(--color-success-text);
	border-color: var(--color-success-hover);
	background: var(--color-success);
}

.gm-fm__row-badge--acl {
	color: var(--color-warning-text);
	border-color: var(--color-warning-hover);
	background: var(--color-warning);
	cursor: help;
}

.gm-fm__row-quota {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
	white-space: nowrap;
}

.gm-fm__row-leaving-label {
	font-size: 12px;
	color: var(--color-error-text);
	white-space: nowrap;
}

.gm-fm__switch {
	position: relative;
	display: inline-flex;
	align-items: center;
	width: 30px;
	height: 17px;
	padding: 2px;
	box-sizing: border-box;
	border-radius: 9px;
	background: var(--color-background-dark);
	cursor: pointer;
	transition: background-color .1s ease-in-out;
}

.gm-fm__switch--disabled {
	cursor: default;
	opacity: .6;
}

.gm-fm__switch--on {
	background: var(--color-primary-element);
}

.gm-fm__switch-knob {
	width: 13px;
	height: 13px;
	border-radius: 50%;
	background: #fff;
	transition: transform .1s ease-in-out;
}

.gm-fm__switch--on .gm-fm__switch-knob {
	transform: translateX(13px);
}

.gm-fm__row-remove {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 20px;
	height: 20px;
	border: none;
	border-radius: 50%;
	background: transparent;
	color: var(--color-text-maxcontrast);
	font-size: 16px;
	line-height: 1;
	cursor: pointer;
	opacity: 0;
}

.gm-fm__row:hover .gm-fm__row-remove,
.gm-fm__row-remove:focus-visible {
	opacity: 1;
}

.gm-fm__row-remove:hover {
	background: var(--color-background-dark);
	color: var(--color-main-text);
}

.gm-fm__note {
	margin: 12px 0 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.gm-fm__result {
	flex-shrink: 0;
	margin-top: 12px;
}

.gm-fm__result-list {
	margin: 8px 0;
	padding-left: 20px;
}
</style>
