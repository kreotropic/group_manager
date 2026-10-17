<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div class="gm-ug">
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

		<div class="gm-ug__scroll">
			<p v-if="rows.length === 0" class="gm-ug__empty">
				{{ t('group_manager', 'Not a member of any group.') }}
			</p>

			<div v-else class="gm-ug__table" role="table" :aria-label="t('group_manager', 'Groups of this user')">
				<div class="gm-ug__row gm-ug__row--head" role="row">
					<span role="columnheader">{{ t('group_manager', 'Group') }}</span>
					<span role="columnheader" class="gm-ug__col-admin">{{ t('group_manager', 'Group admin') }}</span>
					<span role="columnheader" class="gm-ug__col-action" />
				</div>
				<div v-for="row in rows"
					:key="row.id"
					class="gm-ug__row"
					:class="{
						'gm-ug__row--joining': row.joining,
						'gm-ug__row--leaving': row.leaving,
					}"
					role="row">
					<span class="gm-ug__col-group" role="cell">
						<GroupIcon :backend="row.backend" />
						<span class="gm-ug__group-text">
							<span class="gm-ug__group-name">{{ row.displayName }}</span>
							<span v-if="row.id !== row.displayName" class="gm-ug__group-id">{{ row.id }}</span>
						</span>
						<span v-if="row.joining" class="gm-ug__chip gm-ug__chip--joining">{{ t('group_manager', 'Joining') }}</span>
						<span v-else-if="row.leaving" class="gm-ug__chip gm-ug__chip--leaving">{{ t('group_manager', 'Leaving') }}</span>
						<span v-else-if="!row.member" class="gm-ug__chip">{{ t('group_manager', 'Admin only') }}</span>
					</span>
					<span class="gm-ug__col-admin" role="cell">
						<NcCheckboxRadioSwitch v-if="row.id !== 'admin'"
							:model-value="row.admin"
							:disabled="applying || row.leaving"
							:aria-label="t('group_manager', 'Group admin of {group}', { group: row.displayName })"
							@update:model-value="toggleAdmin(row, $event)" />
					</span>
					<span class="gm-ug__col-action" role="cell">
						<NcButton v-if="row.joining || row.leaving"
							variant="tertiary"
							:disabled="applying"
							:aria-label="t('group_manager', 'Undo')"
							:title="t('group_manager', 'Undo')"
							@click="undo(row)">
							<template #icon>
								<Undo :size="18" />
							</template>
						</NcButton>
						<NcButton v-else-if="row.member && row.removable"
							variant="tertiary"
							:disabled="applying"
							:aria-label="t('group_manager', 'Remove from {group}', { group: row.displayName })"
							:title="t('group_manager', 'Remove from group')"
							@click="leave(row)">
							<template #icon>
								<Close :size="18" />
							</template>
						</NcButton>
						<span v-else-if="row.member"
							class="gm-ug__locked"
							:title="t('group_manager', 'Membership of this group is managed by its backend (LDAP)')">
							<LockOutline :size="16" />
						</span>
					</span>
				</div>
			</div>
		</div>

		<NcNoteCard v-if="lastResult" :type="lastResult.failures.length ? 'error' : 'success'" class="gm-ug__result">
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
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import Close from 'vue-material-design-icons/Close.vue'
import LockOutline from 'vue-material-design-icons/LockOutline.vue'
import Undo from 'vue-material-design-icons/Undo.vue'
import GroupIcon from './GroupIcon.vue'
import { addGroupMember, addSubAdmin, removeGroupMember, removeSubAdmin } from '../services/api.js'
import { runWithConcurrency } from '../utils/concurrency.js'
import { extractErrorMessage } from '../utils/errors.js'

const EMPTY = () => ({ joining: [], leaving: [], adminOn: [], adminOff: [] })

export default {
	name: 'UserGroupsManager',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
		NcSelect,
		Close,
		LockOutline,
		Undo,
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

		rows() {
			const s = this.staged
			const ids = new Set([...this.memberOf, ...this.adminOf, ...s.joining])
			return [...ids].map((id) => {
				const group = this.groupsById.get(id)
				const member = this.memberOf.includes(id)
				const admin = (this.adminOf.includes(id) && !s.adminOff.includes(id)) || s.adminOn.includes(id)
				return {
					id,
					displayName: group?.displayName ?? id,
					backend: group?.backend ?? 'local',
					member,
					admin,
					joining: s.joining.includes(id),
					leaving: s.leaving.includes(id),
					// LDAP membership is the directory's; everything else here
					// can be changed, the same rule as the Members tab.
					removable: (group?.backend ?? 'local') !== 'ldap',
				}
			}).sort((a, b) => a.displayName.localeCompare(b.displayName, undefined, { sensitivity: 'base' }))
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

		without(list, id) {
			return list.filter((x) => x !== id)
		},

		onAdd(group) {
			if (!group) {
				return
			}
			this.lastResult = null
			if (this.staged.leaving.includes(group.id)) {
				this.staged.leaving = this.without(this.staged.leaving, group.id)
				return
			}
			this.staged.joining = [...this.staged.joining, group.id]
		},

		leave(row) {
			this.lastResult = null
			this.staged.leaving = [...this.staged.leaving, row.id]
			// Leaving a group drops a staged promotion in it; an existing
			// admin assignment is left alone (core keeps it apart from
			// membership).
			this.staged.adminOn = this.without(this.staged.adminOn, row.id)
		},

		undo(row) {
			this.staged.joining = this.without(this.staged.joining, row.id)
			this.staged.leaving = this.without(this.staged.leaving, row.id)
			this.staged.adminOn = this.without(this.staged.adminOn, row.id)
		},

		toggleAdmin(row, value) {
			this.lastResult = null
			const was = this.adminOf.includes(row.id)
			this.staged.adminOn = this.without(this.staged.adminOn, row.id)
			this.staged.adminOff = this.without(this.staged.adminOff, row.id)
			if (value && !was) {
				this.staged.adminOn = [...this.staged.adminOn, row.id]
			} else if (!value && was) {
				this.staged.adminOff = [...this.staged.adminOff, row.id]
			}
		},

		discardChanges() {
			this.staged = EMPTY()
			this.lastResult = null
		},

		/**
		 * Memberships first, then admin changes (a group admin is usually a
		 * member, so joining comes before promoting). A failure doesn't stop
		 * the rest; it stays staged with the reason. The caller has already
		 * confirmed the password.
		 */
		async applyChanges() {
			if (!this.hasPendingChanges) {
				return
			}
			this.applying = true
			const s = this.staged
			const name = (id) => this.groupsById.get(id)?.displayName ?? id
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
					...s.joining.map((id) => ({ kind: 'joining', id, call: () => addGroupMember(id, this.uid) })),
					...s.leaving.map((id) => ({ kind: 'leaving', id, call: () => removeGroupMember(id, this.uid) })),
				]),
				...await run([
					...s.adminOn.map((id) => ({ kind: 'adminOn', id, call: () => addSubAdmin(id, this.uid) })),
					...s.adminOff.map((id) => ({ kind: 'adminOff', id, call: () => removeSubAdmin(id, this.uid) })),
				]),
			]
			this.applying = false

			const next = EMPTY()
			const failures = []
			for (const result of results) {
				if (!result.ok) {
					next[result.kind].push(result.id)
					failures.push({ key: result.kind + ':' + result.id, group: name(result.id), message: result.message })
				}
			}
			this.staged = next
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

.gm-ug__add-select {
	width: 100%;
	max-width: 420px;
	margin: 0 !important;
}

.gm-ug__scroll {
	flex: 1;
	min-height: 0;
	overflow-y: auto;
	padding-right: var(--gm-scrollbar-w);
	margin-right: calc(-1 * var(--gm-scrollbar-w));
}

.gm-ug__empty {
	padding: 24px 0;
	color: var(--color-text-maxcontrast);
}

.gm-ug__row {
	display: grid;
	grid-template-columns: minmax(0, 1fr) 120px 48px;
	align-items: center;
	gap: 8px;
	min-height: 48px;
	padding: 0 8px;
	border-bottom: 1px solid var(--color-border);
}

.gm-ug__row--head {
	position: sticky;
	top: 0;
	z-index: 1;
	min-height: var(--gf-row-section);
	background: var(--color-main-background);
	font-size: 11px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: .09em;
	color: var(--color-text-maxcontrast);
}

.gm-ug__row--joining {
	background: color-mix(in srgb, var(--color-success) 8%, transparent);
}

.gm-ug__row--leaving {
	background: color-mix(in srgb, var(--color-error) 8%, transparent);
}

.gm-ug__row--leaving .gm-ug__group-name {
	text-decoration: line-through;
}

.gm-ug__col-group {
	display: flex;
	align-items: center;
	gap: 10px;
	min-width: 0;
}

.gm-ug__group-text {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

.gm-ug__group-name,
.gm-ug__group-id {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.gm-ug__group-id {
	font-family: monospace;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.gm-ug__col-admin {
	display: flex;
	justify-content: center;
}

.gm-ug__col-action {
	display: flex;
	justify-content: center;
}

.gm-ug__locked {
	display: flex;
	color: var(--color-text-maxcontrast);
}

.gm-ug__chip {
	flex-shrink: 0;
	padding: 1px 8px;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-pill);
	color: var(--color-text-maxcontrast);
	font-size: 11px;
	font-weight: 600;
}

.gm-ug__chip--joining {
	border-color: var(--color-success);
	color: var(--color-success-text, var(--color-success));
}

.gm-ug__chip--leaving {
	border-color: var(--color-error);
	color: var(--color-error-text, var(--color-error));
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
