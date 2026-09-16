<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div class="gm-mm">
		<div class="gm-mm__header">
			<h3 class="gm-mm__title">
				{{ t('group_manager', 'Members') }}
				<span v-if="total !== null" class="gm-mm__total">({{ total }})</span>
			</h3>
			<NcTextField class="gm-mm__search"
				v-model="search"
				:label="t('group_manager', 'Search members')"
				:show-trailing-button="search.length > 0"
				@update:model-value="onSearchInput"
				@trailing-button-click="search = ''">
				<template #icon>
					<Magnify :size="20" />
				</template>
			</NcTextField>
		</div>

		<NcSelect class="gm-mm__add"
			:model-value="null"
			:options="candidateOptions"
			:loading="searchingCandidates"
			:filterable="false"
			label="displayName"
			:placeholder="t('group_manager', 'Add member…')"
			:aria-label-combobox="t('group_manager', 'Search users to add to this group')"
			@search="onCandidateSearch"
			@option:selected="onCandidateSelected">
			<template #option="option">
				<span>{{ option.displayName }}</span>
				<span class="gm-mm__add-uid">{{ option.uid }}</span>
			</template>
		</NcSelect>

		<ul v-if="pendingAdd.length > 0" class="gm-mm__pending">
			<li v-for="item in pendingAdd" :key="'add-' + item.uid" class="gm-mm__pending-row gm-mm__pending-row--add">
				<PlusCircleOutline :size="16" class="gm-mm__pending-icon" />
				<span class="gm-mm__pending-name">{{ item.displayName }}</span>
				<span v-if="item.status === 'error'" class="gm-mm__pending-error" :title="item.error">
					<AlertCircle :size="16" />
				</span>
				<NcButton variant="tertiary"
					:aria-label="t('group_manager', 'Cancel adding {name}', { name: item.displayName })"
					:disabled="applying"
					@click="cancelPendingAdd(item)">
					<template #icon>
						<Close :size="16" />
					</template>
				</NcButton>
			</li>
		</ul>

		<div v-if="loading && members.length === 0" class="gm-mm__loading">
			<NcLoadingIcon :size="24" />
		</div>

		<p v-else-if="members.length === 0" class="gm-mm__empty">
			{{ t('group_manager', 'No members found.') }}
		</p>

		<ul v-else class="gm-mm__grid">
			<li v-for="member in members"
				:key="member.uid"
				class="gm-mm__row"
				:class="{ 'gm-mm__row--removing': isPendingRemove(member.uid) }">
				<NcCheckboxRadioSwitch :model-value="isPendingRemove(member.uid)"
					:disabled="applying"
					:aria-label="t('group_manager', 'Remove {name}', { name: member.displayName })"
					@update:model-value="toggleRemove(member)" />
				<span class="gm-mm__row-clickable" @click="!applying && toggleRemove(member)">
					<AccountOutline :size="16" class="gm-mm__row-icon" />
					<span class="gm-mm__row-name">{{ member.displayName }}</span>
				</span>
				<span v-if="pendingRemoveError(member.uid)" class="gm-mm__pending-error" :title="pendingRemoveError(member.uid)">
					<AlertCircle :size="16" />
				</span>
			</li>
		</ul>

		<NcButton v-if="hasMore"
			class="gm-mm__more"
			:disabled="applying"
			@click="loadMore">
			{{ t('group_manager', 'Load more') }}
		</NcButton>

		<div v-if="hasPendingChanges" class="gm-mm__actionbar">
			<span class="gm-mm__actionbar-summary">{{ pendingSummary }}</span>
			<NcButton :disabled="applying" @click="discardChanges">
				{{ t('group_manager', 'Discard') }}
			</NcButton>
			<NcButton variant="primary" :disabled="applying" @click="applyChanges">
				<template v-if="applying" #icon>
					<NcLoadingIcon :size="18" />
				</template>
				{{ applying ? t('group_manager', 'Applying…') : t('group_manager', 'Apply changes') }}
			</NcButton>
		</div>

		<NcNoteCard v-if="lastResult" :type="lastResult.failed.length > 0 ? 'warning' : 'success'" class="gm-mm__result">
			<p>{{ resultSummaryText }}</p>
			<details v-if="lastResult.failed.length > 0">
				<summary>{{ t('group_manager', 'Show details') }}</summary>
				<ul class="gm-mm__result-list">
					<li v-for="f in lastResult.failed" :key="f.action + '-' + f.uid">
						{{ f.displayName }} — {{ f.error }}
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
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import AccountOutline from 'vue-material-design-icons/AccountOutline.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import Close from 'vue-material-design-icons/Close.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import PlusCircleOutline from 'vue-material-design-icons/PlusCircleOutline.vue'
import { fetchGroupMembers, searchGroupCandidates, addGroupMember, removeGroupMember } from '../services/api.js'
import { extractErrorMessage } from '../utils/errors.js'
import { runWithConcurrency } from '../utils/concurrency.js'

const PAGE_SIZE = 50
const SEARCH_DEBOUNCE_MS = 300
const APPLY_CONCURRENCY = 4

export default {
	name: 'GroupMembersManager',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
		AccountOutline,
		AlertCircle,
		Close,
		Magnify,
		PlusCircleOutline,
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
			members: [],
			total: null,
			search: '',
			offset: 0,
			loading: true,
			searchTimer: null,

			candidateOptions: [],
			searchingCandidates: false,
			candidateSearchTimer: null,

			// {uid, displayName, status: 'pending'|'applying'|'error', error}
			pendingAdd: [],
			pendingRemove: [],
			applying: false,
			lastResult: null,
		}
	},

	computed: {
		hasMore() {
			return this.total !== null && this.members.length < this.total
		},

		hasPendingChanges() {
			return this.pendingAdd.length > 0 || this.pendingRemove.length > 0
		},

		pendingSummary() {
			const parts = []
			if (this.pendingAdd.length > 0) {
				parts.push(t('group_manager', '{count} to add', { count: this.pendingAdd.length }))
			}
			if (this.pendingRemove.length > 0) {
				parts.push(t('group_manager', '{count} to remove', { count: this.pendingRemove.length }))
			}
			return parts.join(' · ')
		},

		resultSummaryText() {
			if (!this.lastResult) {
				return ''
			}
			if (this.lastResult.failed.length === 0) {
				return t('group_manager', 'Applied {count} change(s) successfully.', { count: this.lastResult.succeededCount })
			}
			return t('group_manager', '{succeeded} change(s) applied, {failed} failed.', {
				succeeded: this.lastResult.succeededCount,
				failed: this.lastResult.failed.length,
			})
		},
	},

	watch: {
		groupId() {
			this.resetState()
			this.reload()
		},

		hasPendingChanges(value) {
			this.$emit('pending-changed', value)
		},
	},

	mounted() {
		this.reload()
	},

	beforeUnmount() {
		clearTimeout(this.searchTimer)
		clearTimeout(this.candidateSearchTimer)
	},

	methods: {
		t,

		resetState() {
			this.search = ''
			this.candidateOptions = []
			this.pendingAdd = []
			this.pendingRemove = []
			this.lastResult = null
		},

		onSearchInput() {
			clearTimeout(this.searchTimer)
			this.searchTimer = setTimeout(() => this.reload(), SEARCH_DEBOUNCE_MS)
		},

		async reload() {
			this.loading = true
			this.offset = 0
			try {
				const data = await fetchGroupMembers(this.groupId, {
					search: this.search,
					limit: PAGE_SIZE,
					offset: 0,
				})
				this.members = data.members
				this.total = data.total
				this.offset = data.members.length
			} finally {
				this.loading = false
			}
		},

		async loadMore() {
			const data = await fetchGroupMembers(this.groupId, {
				search: this.search,
				limit: PAGE_SIZE,
				offset: this.offset,
			})
			this.members = this.members.concat(data.members)
			this.total = data.total
			this.offset += data.members.length
		},

		onCandidateSearch(search, loading) {
			clearTimeout(this.candidateSearchTimer)
			if (search.trim() === '') {
				this.candidateOptions = []
				return
			}
			loading(true)
			this.searchingCandidates = true
			this.candidateSearchTimer = setTimeout(async () => {
				try {
					this.candidateOptions = await searchGroupCandidates(this.groupId, search, 10)
				} finally {
					loading(false)
					this.searchingCandidates = false
				}
			}, SEARCH_DEBOUNCE_MS)
		},

		onCandidateSelected(option) {
			const uid = option.uid
			if (this.pendingAdd.some((i) => i.uid === uid) || this.members.some((m) => m.uid === uid)) {
				return
			}
			this.pendingAdd.push({ uid, displayName: option.displayName, status: 'pending', error: '' })
			this.candidateOptions = []
		},

		cancelPendingAdd(item) {
			this.pendingAdd = this.pendingAdd.filter((i) => i.uid !== item.uid)
		},

		isPendingRemove(uid) {
			return this.pendingRemove.some((i) => i.uid === uid)
		},

		pendingRemoveError(uid) {
			return this.pendingRemove.find((i) => i.uid === uid)?.error || ''
		},

		toggleRemove(member) {
			if (this.isPendingRemove(member.uid)) {
				this.pendingRemove = this.pendingRemove.filter((i) => i.uid !== member.uid)
			} else {
				this.pendingRemove.push({ uid: member.uid, displayName: member.displayName, status: 'pending', error: '' })
			}
		},

		discardChanges() {
			this.pendingAdd = []
			this.pendingRemove = []
			this.lastResult = null
		},

		async applyChanges() {
			this.applying = true
			this.lastResult = null

			const tasks = [
				...this.pendingAdd.map((item) => ({ kind: 'add', item })),
				...this.pendingRemove.map((item) => ({ kind: 'remove', item })),
			]
			tasks.forEach(({ item }) => {
				item.status = 'applying'
				item.error = ''
			})

			await runWithConcurrency(tasks, APPLY_CONCURRENCY, async ({ kind, item }) => {
				try {
					if (kind === 'add') {
						await addGroupMember(this.groupId, item.uid)
					} else {
						await removeGroupMember(this.groupId, item.uid)
					}
					item.status = 'done'
				} catch (err) {
					item.status = 'error'
					item.error = extractErrorMessage(err, t('group_manager', 'Failed'))
				}
			})

			const failedAdd = this.pendingAdd.filter((i) => i.status === 'error')
			const failedRemove = this.pendingRemove.filter((i) => i.status === 'error')

			this.lastResult = {
				succeededCount: tasks.length - failedAdd.length - failedRemove.length,
				failed: [
					...failedAdd.map((i) => ({ ...i, action: 'add' })),
					...failedRemove.map((i) => ({ ...i, action: 'remove' })),
				],
			}

			this.pendingAdd = failedAdd
			this.pendingRemove = failedRemove
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
.gm-mm__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16px;
	margin-bottom: 12px;
}

.gm-mm__title {
	margin: 0;
}

.gm-mm__total {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.gm-mm__search {
	max-width: 260px;
}

.gm-mm__add {
	margin-bottom: 12px;
	max-width: 400px;
}

.gm-mm__add-uid {
	margin-left: 6px;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.gm-mm__pending {
	list-style: none;
	margin: 0 0 12px;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 2px;
}

.gm-mm__pending-row {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 4px 8px;
	border-radius: var(--border-radius);
	background: var(--color-primary-element-light);
}

.gm-mm__pending-icon {
	color: var(--color-primary-element);
	flex-shrink: 0;
}

.gm-mm__pending-name {
	flex: 1;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.gm-mm__pending-error {
	color: var(--color-error);
	display: flex;
}

.gm-mm__loading {
	display: flex;
	justify-content: center;
	padding: 20px 0;
}

.gm-mm__empty {
	color: var(--color-text-maxcontrast);
}

.gm-mm__grid {
	display: grid;
	grid-template-columns: repeat(2, minmax(220px, 1fr));
	gap: 2px 16px;
	list-style: none;
	margin: 0;
	padding: 0;
}

.gm-mm__row {
	display: flex;
	align-items: center;
	gap: 4px;
	padding: 2px 4px;
	border-radius: var(--border-radius);
	min-width: 0;
}

.gm-mm__row--removing {
	opacity: 0.5;
}

.gm-mm__row--removing .gm-mm__row-name {
	text-decoration: line-through;
}

.gm-mm__row-clickable {
	display: flex;
	align-items: center;
	gap: 4px;
	flex: 1;
	min-width: 0;
	padding: 2px 0;
	cursor: pointer;
}

.gm-mm__row-icon {
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
}

.gm-mm__row-name {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.gm-mm__more {
	margin-top: 12px;
}

.gm-mm__actionbar {
	position: sticky;
	bottom: 0;
	display: flex;
	align-items: center;
	gap: 12px;
	margin-top: 16px;
	padding: 12px;
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
}

.gm-mm__actionbar-summary {
	flex: 1;
	color: var(--color-text-maxcontrast);
}

.gm-mm__result {
	margin-top: 16px;
}

.gm-mm__result-list {
	margin: 8px 0;
	padding-left: 20px;
}
</style>
