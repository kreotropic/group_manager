<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div class="gm-ug">
		<div class="gm-ug__scroll">
			<div class="gm-ug__add">
				<NcSelect :model-value="null"
					:options="addableGroups"
					:disabled="applying"
					:placeholder="t('group_manager', 'Add to a group…')"
					:aria-label-combobox="t('group_manager', 'Add to a group')"
					label="displayName"
					:label-outside="true"
					:clearable="false"
					class="gm-ug__add-select"
					@update:model-value="onAdd" />
			</div>

			<!-- The same queue as a group's Members tab: what Apply will do. -->
			<div v-if="hasPendingChanges" class="gm-ug__queue" aria-live="polite">
				<span v-for="chip in queueChips"
					:key="chip.key"
					class="gm-ug__chip"
					:class="'gm-ug__chip--' + chip.kind"
					:title="chip.error || undefined">
					<ShieldAccount v-if="chip.kind === 'grant' || chip.kind === 'revoke'"
						:size="14"
						class="gm-ug__chip-prefix"
						:title="chip.kind === 'grant' ? t('group_manager', 'Becomes a group admin') : t('group_manager', 'Stops being a group admin')" />
					<span v-else class="gm-ug__chip-prefix" aria-hidden="true">{{ chip.kind === 'leave' ? '−' : '+' }}</span>
					<span class="gm-ug__chip-label">{{ chip.displayName }}</span>
					<AlertCircle v-if="chip.error" :size="14" class="gm-ug__chip-error" />
					<button type="button"
						class="gm-ug__chip-close"
						:aria-label="t('group_manager', 'Cancel: {name}', { name: chip.displayName })"
						:disabled="applying"
						@click="cancelChip(chip)"><svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><path fill="currentColor" d="M19,6.41L17.59,5L12,10.59L6.41,5L5,6.41L10.59,12L5,17.59L6.41,19L12,13.41L17.59,19L19,17.59L13.41,12L19,6.41Z" /></svg></button>
				</span>
			</div>

			<p v-if="adminOnlyGroups.length > 0" class="gm-ug__outside">
				<span class="gm-ug__outside-label">{{ t('group_manager', 'Also group admin of, without being a member:') }}</span>
				<span v-for="group in adminOnlyGroups"
					:key="group.id"
					class="gm-ug__outside-group"
					:class="{ 'gm-ug__outside-group--revoking': isStaged('adminOff', group.id) }">
					<ShieldAccount :size="14" class="gm-ug__outside-icon" />
					{{ group.displayName }}
					<button v-if="!isStaged('adminOff', group.id)"
						type="button"
						class="gm-ug__outside-revoke"
						:aria-label="t('group_manager', 'Stop being group admin of {group}', { group: group.displayName })"
						:title="t('group_manager', 'Remove as group admin')"
						:disabled="applying"
						@click="stage('adminOff', group.id)"><svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><path fill="currentColor" d="M19,6.41L17.59,5L12,10.59L6.41,5L5,6.41L10.59,12L5,17.59L6.41,19L12,13.41L17.59,19L19,17.59L13.41,12L19,6.41Z" /></svg></button>
				</span>
			</p>

			<p v-if="rows.length === 0" class="gm-ug__empty">
				{{ t('group_manager', 'Not a member of any group.') }}
			</p>

			<div v-else class="gm-ug__table" role="table" :aria-label="t('group_manager', 'Groups')">
				<div class="gm-ug__section" aria-hidden="true">
					{{ t('group_manager', 'Groups') }} · {{ rows.length }}
				</div>
				<div v-for="row in rows"
					:key="row.id"
					class="gm-ug__row"
					:class="{ 'gm-ug__row--leaving': row.leaving }"
					role="row">
					<span class="gm-ug__col-group" role="cell">
						<GroupIcon :backend="row.backend" />
						<span class="gm-ug__row-text">
							<span class="gm-ug__row-name">{{ row.displayName }}</span>
							<span v-if="row.id !== row.displayName" class="gm-ug__row-id">{{ row.id }}</span>
						</span>
					</span>
					<!-- Hover buttons over the badges' place, as on a group's
					     member rows. -->
					<span class="gm-ug__col-actions" role="cell">
						<span class="gm-ug__row-buttons">
							<button v-if="canToggleAdmin(row)"
								type="button"
								class="gm-ug__row-action gm-ug__row-admin-toggle"
								:aria-label="row.willBeAdmin
									? t('group_manager', 'Stop being group admin of {group}', { group: row.displayName })
									: t('group_manager', 'Make group admin of {group}', { group: row.displayName })"
								:title="row.willBeAdmin
									? t('group_manager', 'Remove as group admin')
									: t('group_manager', 'Make group admin')"
								:aria-pressed="row.willBeAdmin ? 'true' : 'false'"
								:disabled="applying"
								@click="toggleAdmin(row)">
								<ShieldAccount v-if="row.willBeAdmin" :size="16" />
								<ShieldAccountOutline v-else :size="16" />
							</button>
							<button v-if="row.removable && !row.leaving"
								type="button"
								class="gm-ug__row-action gm-ug__row-remove"
								:aria-label="t('group_manager', 'Remove from {group}', { group: row.displayName })"
								:title="t('group_manager', 'Remove from group')"
								:disabled="applying"
								@click="leave(row)"><svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="currentColor" d="M19,6.41L17.59,5L12,10.59L6.41,5L5,6.41L10.59,12L5,17.59L6.41,19L12,13.41L17.59,19L19,17.59L13.41,12L19,6.41Z" /></svg></button>
						</span>
						<span class="gm-ug__row-badges">
							<span v-if="row.adminState !== 'none' && !row.leaving"
								class="gm-ug__row-admin"
								:class="'gm-ug__row-admin--' + row.adminState"
								:title="adminStateTitle(row.adminState)">
								<ShieldAccount :size="14" />
								{{ t('group_manager', 'group admin') }}
							</span>
							<span v-if="row.leaving" class="gm-ug__row-leaving-label">
								{{ t('group_manager', 'leaving') }}
							</span>
							<span v-else-if="!row.removable"
								class="gm-ug__row-locked"
								:title="t('group_manager', 'Membership of this group is managed by its backend (LDAP)')">
								<LockOutline :size="14" />
							</span>
						</span>
					</span>
				</div>
			</div>
		</div>

		<NcNoteCard v-if="lastResult" :type="lastResult.failures.length ? 'warning' : 'success'" class="gm-ug__result">
			<p v-if="!lastResult.failures.length">
				{{ n('group_manager', '%n change applied.', '%n changes applied.', lastResult.done) }}
			</p>
			<template v-else>
				<p>{{ t('group_manager', 'Some changes were not applied and are still staged:') }}</p>
				<ul class="gm-ug__failures">
					<li v-for="failure in lastResult.failures" :key="failure.key">
						<strong>{{ failure.group }}</strong>: {{ failure.message }}
					</li>
				</ul>
			</template>
		</NcNoteCard>
	</div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import LockOutline from 'vue-material-design-icons/LockOutline.vue'
import ShieldAccount from 'vue-material-design-icons/ShieldAccount.vue'
import ShieldAccountOutline from 'vue-material-design-icons/ShieldAccountOutline.vue'
import GroupIcon from './GroupIcon.vue'
import { addGroupMember, addSubAdmin, removeGroupMember, removeSubAdmin } from '../services/api.js'
import { runWithConcurrency } from '../utils/concurrency.js'
import { extractErrorMessage } from '../utils/errors.js'

const EMPTY = () => ({ joining: [], leaving: [], adminOn: [], adminOff: [] })

// Chip kind per staged list, the same words as a group's Members queue.
const CHIP_KIND = { joining: 'join', leaving: 'leave', adminOn: 'grant', adminOff: 'revoke' }

export default {
	name: 'UserGroupsManager',

	components: {
		NcNoteCard,
		NcSelect,
		AlertCircle,
		LockOutline,
		ShieldAccount,
		ShieldAccountOutline,
		GroupIcon,
	},

	props: {
		uid: {
			type: String,
			required: true,
		},
		/** Ids of the groups the user belongs to (core's details.groups). */
		memberOf: {
			type: Array,
			default: () => [],
		},
		/** Ids of the groups the user administers (core's details.subadmin). */
		adminOf: {
			type: Array,
			default: () => [],
		},
		/** Every group, from App.vue's list (id, displayName, backend). */
		groups: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['changed', 'pending-changed'],

	data() {
		return {
			staged: EMPTY(),
			// Why a staged change failed on the last Apply, by "<list>:<gid>".
			errors: {},
			applying: false,
			lastResult: null,
		}
	},

	computed: {
		groupsById() {
			return new Map(this.groups.map((g) => [g.id, g]))
		},

		pendingCount() {
			const s = this.staged
			return s.joining.length + s.leaving.length + s.adminOn.length + s.adminOff.length
		},

		hasPendingChanges() {
			return this.pendingCount > 0
		},

		queueChips() {
			const chips = []
			for (const list of ['joining', 'leaving', 'adminOn', 'adminOff']) {
				for (const id of this.staged[list]) {
					chips.push({
						key: list + ':' + id,
						list,
						id,
						kind: CHIP_KIND[list],
						displayName: this.nameOf(id),
						error: this.errors[list + ':' + id] || '',
					})
				}
			}
			return chips
		},

		/** The groups the user is in, joining ones aside (those are chips). */
		rows() {
			return this.memberOf.map((id) => {
				const group = this.groupsById.get(id)
				const backend = group?.backend ?? 'local'
				const adminState = this.adminState(id)
				return {
					id,
					displayName: group?.displayName ?? id,
					backend,
					leaving: this.isStaged('leaving', id),
					adminState,
					willBeAdmin: adminState === 'admin' || adminState === 'granting',
					// LDAP membership is the directory's, the same rule as
					// a group's Members tab.
					removable: backend !== 'ldap',
				}
			}).sort((a, b) => a.displayName.localeCompare(b.displayName, undefined, { sensitivity: 'base' }))
		},

		/** Nextcloud allows a group admin who isn't a member (core Users page). */
		adminOnlyGroups() {
			return this.adminOf
				.filter((id) => !this.memberOf.includes(id))
				.map((id) => ({ id, displayName: this.nameOf(id) }))
		},

		addableGroups() {
			const taken = new Set([...this.memberOf, ...this.staged.joining])
			return this.groups.filter((g) => !taken.has(g.id) && g.backend !== 'ldap')
		},
	},

	watch: {
		pendingCount() {
			this.$emit('pending-changed', { hasPendingChanges: this.hasPendingChanges, count: this.pendingCount })
		},
	},

	methods: {
		t,
		n,

		nameOf(id) {
			return this.groupsById.get(id)?.displayName ?? id
		},

		isStaged(list, id) {
			return this.staged[list].includes(id)
		},

		stage(list, id) {
			this.lastResult = null
			if (!this.isStaged(list, id)) {
				this.staged[list] = [...this.staged[list], id]
			}
		},

		unstage(list, id) {
			this.staged[list] = this.staged[list].filter((x) => x !== id)
			const { [list + ':' + id]: dropped, ...rest } = this.errors
			this.errors = rest
		},

		adminState(id) {
			if (this.isStaged('adminOn', id)) {
				return 'granting'
			}
			if (this.adminOf.includes(id)) {
				return this.isStaged('adminOff', id) ? 'revoking' : 'admin'
			}
			return 'none'
		},

		adminStateTitle(state) {
			switch (state) {
			case 'granting':
				return t('group_manager', 'Becomes a group admin when the changes are applied')
			case 'revoking':
				return t('group_manager', 'Stops being a group admin when the changes are applied')
			default:
				return t('group_manager', 'Can manage this group\'s members')
			}
		},

		// Nextcloud never makes anyone an admin of "admin"; an existing
		// assignment elsewhere can always be ended.
		canToggleAdmin(row) {
			return !row.leaving && (row.id !== 'admin' || this.adminOf.includes(row.id))
		},

		onAdd(group) {
			if (group) {
				this.stage('joining', group.id)
			}
		},

		leave(row) {
			this.stage('leaving', row.id)
			// Leaving also ends an admin role there (GroupService::
			// removeMember()), so an admin change staged in it goes too.
			this.unstage('adminOn', row.id)
			this.unstage('adminOff', row.id)
		},

		toggleAdmin(row) {
			if (this.isStaged('adminOn', row.id)) {
				this.unstage('adminOn', row.id)
			} else if (this.isStaged('adminOff', row.id)) {
				this.unstage('adminOff', row.id)
			} else if (this.adminOf.includes(row.id)) {
				this.stage('adminOff', row.id)
			} else {
				this.stage('adminOn', row.id)
			}
		},

		cancelChip(chip) {
			this.unstage(chip.list, chip.id)
		},

		discardChanges() {
			this.staged = EMPTY()
			this.errors = {}
			this.lastResult = null
		},

		/**
		 * Memberships first, then admin changes. A failure doesn't stop the
		 * rest; it stays staged, its chip marked with the reason. The caller
		 * has already confirmed the password.
		 */
		async applyChanges() {
			if (!this.hasPendingChanges) {
				return
			}
			this.applying = true
			const s = this.staged
			const run = async (ops) => runWithConcurrency(ops, 3, async (op) => {
				try {
					await op.call()
					return { ...op, ok: true }
				} catch (err) {
					return { ...op, ok: false, message: extractErrorMessage(err, t('group_manager', 'Not accepted by the server.')) }
				}
			})

			const results = [
				...await run([
					...s.joining.map((id) => ({ list: 'joining', id, call: () => addGroupMember(id, this.uid) })),
					...s.leaving.map((id) => ({ list: 'leaving', id, call: () => removeGroupMember(id, this.uid) })),
				]),
				...await run([
					...s.adminOn.map((id) => ({ list: 'adminOn', id, call: () => addSubAdmin(id, this.uid) })),
					...s.adminOff.map((id) => ({ list: 'adminOff', id, call: () => removeSubAdmin(id, this.uid) })),
				]),
			]
			this.applying = false

			const next = EMPTY()
			const errors = {}
			const failures = []
			for (const result of results) {
				if (!result.ok) {
					next[result.list].push(result.id)
					errors[result.list + ':' + result.id] = result.message
					failures.push({ key: result.list + ':' + result.id, group: this.nameOf(result.id), message: result.message })
				}
			}
			this.staged = next
			this.errors = errors
			const done = results.filter((r) => r.ok).length
			this.lastResult = { done, failures }
			if (done > 0) {
				this.$emit('changed')
			}
		},
	},
}
</script>

<style scoped>
.gm-ug {
	display: flex;
	flex-direction: column;
	gap: 12px;
	min-height: 0;
}

.gm-ug__scroll {
	flex: 1;
	min-height: 0;
	overflow-y: auto;
	padding-right: var(--gm-scrollbar-w);
	margin-right: calc(-1 * var(--gm-scrollbar-w));
}

.gm-ug__add {
	margin-bottom: 12px;
}

.gm-ug__add-select {
	width: 100%;
	max-width: 420px;
	margin: 0 !important;
}

/* Queue of chips, as in GroupMembersManager. */
.gm-ug__queue {
	display: flex;
	flex-wrap: wrap;
	gap: 7px;
	margin-bottom: 12px;
}

.gm-ug__chip {
	display: inline-flex;
	align-items: center;
	gap: 5px;
	padding: 3px 6px 3px 9px;
	border-radius: var(--border-radius-pill);
	font-size: 13px;
	border: 1px solid transparent;
}

.gm-ug__chip--join {
	background: var(--color-success);
	border-color: var(--color-success-hover);
	color: var(--color-success-text);
}

.gm-ug__chip--leave {
	background: var(--color-error);
	border-color: var(--color-error-hover);
	color: var(--color-error-text);
}

.gm-ug__chip--grant,
.gm-ug__chip--revoke {
	background: var(--color-primary-element-light);
	border-color: var(--color-primary-element-light-hover);
	color: var(--color-primary-element-light-text);
}

.gm-ug__chip--revoke .gm-ug__chip-label {
	text-decoration: line-through;
}

.gm-ug__chip-prefix {
	display: inline-flex;
	font-weight: bold;
}

.gm-ug__chip-error {
	color: var(--color-error);
}

.gm-ug__chip-close {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 18px;
	height: 18px;
	min-width: 0;
	min-height: 0;
	margin: 0;
	padding: 0;
	border: none;
	border-radius: 50%;
	background: transparent;
	color: inherit;
	cursor: pointer;
	opacity: .75;
}

.gm-ug__chip-close svg {
	display: block;
}

.gm-ug__chip-close:hover {
	opacity: 1;
	background: color-mix(in srgb, currentColor 15%, transparent);
}

/* Admin of a group without being in it: no row of its own. */
.gm-ug__outside {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 6px 10px;
	margin: 0 0 12px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.gm-ug__outside-group {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 1px 4px 1px 8px;
	border-radius: var(--border-radius-pill);
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}

.gm-ug__outside-group--revoking {
	background: transparent;
	border: 1px dashed var(--color-border-maxcontrast);
	text-decoration: line-through;
	color: var(--color-text-maxcontrast);
}

.gm-ug__outside-revoke {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 18px;
	height: 18px;
	min-width: 0;
	min-height: 0;
	margin: 0;
	padding: 0;
	border: none;
	border-radius: 50%;
	background: transparent;
	color: inherit;
	cursor: pointer;
}

.gm-ug__outside-revoke:hover,
.gm-ug__outside-revoke:focus-visible {
	color: var(--color-error-text);
	background: color-mix(in srgb, currentColor 18%, transparent);
}

.gm-ug__empty {
	padding: 24px 0;
	color: var(--color-text-maxcontrast);
}

.gm-ug__table {
	display: flex;
	flex-direction: column;
}

/* Matches the group list's section header (GroupList.vue). */
.gm-ug__section {
	position: sticky;
	top: 0;
	z-index: 1;
	display: flex;
	align-items: center;
	box-sizing: border-box;
	height: var(--gf-row-section);
	background: var(--color-main-background);
	font-size: 11px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: .09em;
	color: var(--color-text-maxcontrast);
}

.gm-ug__row {
	display: flex;
	align-items: center;
	padding: 8px 0;
	border-top: 1px solid var(--color-border-dark);
}

.gm-ug__section + .gm-ug__row {
	border-top: none;
}

.gm-ug__row:hover {
	background: var(--color-background-hover);
}

.gm-ug__col-group {
	display: flex;
	align-items: center;
	gap: 12px;
	flex: 1 1 auto;
	min-width: 0;
}

.gm-ug__row-text {
	flex: 1;
	min-width: 0;
	display: flex;
	flex-direction: column;
	justify-content: center;
	min-height: 34px;
	line-height: 1.35;
}

.gm-ug__row-name,
.gm-ug__row-id {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.gm-ug__row-name {
	font-size: 14px;
	font-weight: 500;
}

.gm-ug__row-id {
	font-family: monospace;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.gm-ug__row--leaving .gm-ug__row-name {
	text-decoration: line-through;
	color: var(--color-text-maxcontrast);
}

/* One fixed zone at the right edge for the badges; the hover buttons take
   their place while the row is hovered or focused. */
.gm-ug__col-actions {
	position: relative;
	flex: none;
	width: 120px;
	display: flex;
	justify-content: flex-end;
	align-items: center;
}

.gm-ug__row-buttons {
	position: absolute;
	inset-inline-end: 0;
	top: 50%;
	transform: translateY(-50%);
	display: flex;
	gap: 2px;
}

.gm-ug__row-badges {
	display: flex;
	justify-content: flex-end;
	align-items: center;
	gap: 6px;
	min-width: 0;
}

.gm-ug__row:hover:has(.gm-ug__row-action) .gm-ug__row-badges,
.gm-ug__row:focus-within:has(.gm-ug__row-action) .gm-ug__row-badges {
	visibility: hidden;
}

.gm-ug__row-action {
	width: 22px;
	height: 22px;
	min-width: 0;
	min-height: 0;
	margin: 0;
	padding: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	border: none;
	border-radius: 50%;
	background: transparent;
	color: var(--color-text-maxcontrast);
	cursor: pointer;
	opacity: 0;
}

.gm-ug__row-action :deep(svg) {
	display: block;
}

.gm-ug__row:hover .gm-ug__row-action,
.gm-ug__row:focus-within .gm-ug__row-action {
	opacity: 1;
}

@media (hover: none) {
	.gm-ug__col-actions {
		width: auto;
		min-width: 120px;
		gap: 4px;
	}

	.gm-ug__row-buttons {
		position: static;
		transform: none;
	}

	.gm-ug__row-action {
		opacity: 1;
	}

	.gm-ug__row:hover:has(.gm-ug__row-action) .gm-ug__row-badges,
	.gm-ug__row:focus-within:has(.gm-ug__row-action) .gm-ug__row-badges {
		visibility: visible;
	}
}

.gm-ug__row-admin-toggle:hover,
.gm-ug__row-admin-toggle:focus-visible {
	color: var(--color-primary-element);
	background: color-mix(in srgb, currentColor 18%, transparent);
}

.gm-ug__row-remove:hover,
.gm-ug__row-remove:focus-visible {
	color: var(--color-error-text);
	background: color-mix(in srgb, currentColor 18%, transparent);
}

.gm-ug__row-action:disabled {
	opacity: .35;
	cursor: default;
}

/* "group admin" badge: staged states read as provisional until applied. */
.gm-ug__row-admin {
	flex-shrink: 0;
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 1px 8px 1px 6px;
	border: 1px solid transparent;
	border-radius: var(--border-radius-pill);
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
	font-size: 12px;
	white-space: nowrap;
}

.gm-ug__row-admin--granting {
	background: transparent;
	border-color: var(--color-primary-element);
	border-style: dashed;
	color: var(--color-primary-element);
}

.gm-ug__row-admin--revoking {
	background: transparent;
	border-color: var(--color-border-maxcontrast);
	border-style: dashed;
	color: var(--color-text-maxcontrast);
	text-decoration: line-through;
}

.gm-ug__row-leaving-label {
	flex-shrink: 0;
	font-size: 12px;
	color: var(--color-error-text);
}

.gm-ug__row-locked {
	display: flex;
	color: var(--color-text-maxcontrast);
}

.gm-ug__failures {
	margin: 6px 0 0 18px;
	list-style: disc;
}

.gm-ug__result {
	flex-shrink: 0;
	margin: 0 !important;
}
</style>
