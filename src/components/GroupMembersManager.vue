<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div class="gm-mm">
		<div class="gm-mm__scroll">
			<div class="gm-mm__header">
				<h3 v-if="!hideTitle" class="gm-mm__title">
					{{ t('group_manager', 'Members') }}
					<span v-if="total !== null" class="gm-mm__count">{{ headerCountText }}</span>
				</h3>
				<span v-else-if="hasPendingChanges" class="gm-mm__count">{{ afterApplyingText }}</span>

				<div ref="addWrapper" class="gm-mm__add" :class="{ 'gm-mm__add--open': showDropdown }">
					<Plus :size="18" class="gm-mm__add-icon" />
					<input ref="addInput"
						v-model="addQuery"
						type="text"
						class="gm-mm__add-input"
						:placeholder="t('group_manager', 'Add a person, a whole group, or paste a list…')"
						:aria-label="t('group_manager', 'Add a person, a whole group, or paste a list')"
						@input="onAddInput"
						@paste="onAddPaste"
						@focus="onAddFocus"
						@keydown.down.prevent="moveActive(1)"
						@keydown.up.prevent="moveActive(-1)"
						@keydown.enter.prevent="onAddEnter"
						@keydown.esc="closeDropdown">

					<ul v-if="showDropdown" class="gm-mm__add-dropdown" role="listbox">
						<li v-if="addSearching" class="gm-mm__add-status">
							<NcLoadingIcon :size="16" />
							{{ t('group_manager', 'Searching…') }}
						</li>
						<template v-else-if="flatOptions.length === 0">
							<li class="gm-mm__add-status">
								{{ addQuery.trim() === ''
									? t('group_manager', 'No addable users or groups')
									: t('group_manager', 'No results for "{term}"', { term: addQuery.trim() }) }}
							</li>
						</template>
						<template v-else>
							<li v-for="(option, index) in flatOptions"
								:key="option.key"
								class="gm-mm__add-option"
								:class="{ 'gm-mm__add-option--active': index === activeIndex }"
								role="option"
								:aria-selected="option.kind === 'user' && isQueuedUser(option.uid)"
								@mouseenter="activeIndex = index"
								@mousedown.prevent
								@click="pickOption(option)">
								<AccountGroup v-if="option.kind === 'group'" :size="18" class="gm-mm__add-option-icon" />
								<AccountOutline v-else :size="18" class="gm-mm__add-option-icon" />
								<span class="gm-mm__add-option-name">{{ option.displayName }}</span>
								<span v-if="option.kind === 'group'" class="gm-mm__add-option-count">{{ option.newMemberCount }}</span>
								<input v-else
									type="checkbox"
									class="gm-mm__add-option-check"
									tabindex="-1"
									aria-hidden="true"
									:checked="isQueuedUser(option.uid)">
							</li>
							<li v-if="flatOptions.some((o) => o.kind === 'user')" class="gm-mm__add-hint">
								{{ t('group_manager', 'Pick as many as you need, then press Esc') }}
							</li>
						</template>
					</ul>
				</div>

				<NcTextField class="gm-mm__search"
					v-model="memberSearch"
					:label="t('group_manager', 'Filter members')"
					:show-trailing-button="memberSearch.length > 0"
					@update:model-value="onMemberSearchInput"
					@trailing-button-click="memberSearch = ''">
					<template #icon>
						<Magnify :size="18" />
					</template>
				</NcTextField>
			</div>

			<div v-if="hasPendingChanges" class="gm-mm__queue" aria-live="polite">
				<span v-for="chip in queueChips"
					:key="chip.key"
					class="gm-mm__chip"
					:class="'gm-mm__chip--' + chip.kind"
					:title="chip.error || undefined">
					<span class="gm-mm__chip-prefix" aria-hidden="true">{{ chip.kind === 'remove' ? '−' : '+' }}</span>
					<span class="gm-mm__chip-label">
						{{ chip.displayName }}<span v-if="chip.count > 1" class="gm-mm__chip-count">{{ chip.count }}</span>
					</span>
					<AlertCircle v-if="chip.hasError" :size="14" class="gm-mm__chip-error" />
					<button type="button"
						class="gm-mm__chip-close"
						:aria-label="t('group_manager', 'Cancel: {name}', { name: chip.displayName })"
						:disabled="applying"
						@click="cancelChip(chip)">
						×
					</button>
				</span>
			</div>

			<div v-if="pasteReview" class="gm-mm__paste-review">
				<p class="gm-mm__paste-review-summary">
					{{ pasteReviewSummary }}
				</p>
				<ul v-if="pasteReview.matched.length > 0" class="gm-mm__paste-review-list">
					<li v-for="m in pasteReview.matched" :key="'m-' + m.uid" class="gm-mm__paste-review-item gm-mm__paste-review-item--matched">
						<CheckCircle :size="14" /> {{ m.displayName }}
					</li>
				</ul>
				<ul v-if="pasteReview.alreadyMember.length > 0" class="gm-mm__paste-review-list">
					<li v-for="m in pasteReview.alreadyMember" :key="'a-' + m.uid" class="gm-mm__paste-review-item gm-mm__paste-review-item--already">
						<CheckCircle :size="14" /> {{ t('group_manager', '{name} (already in the group)', { name: m.displayName }) }}
					</li>
				</ul>
				<ul v-if="pasteReview.unmatched.length > 0" class="gm-mm__paste-review-list">
					<li v-for="(u, i) in pasteReview.unmatched" :key="'u-' + i" class="gm-mm__paste-review-item gm-mm__paste-review-item--unmatched">
						<AlertCircle :size="14" /> {{ u.token }}
					</li>
				</ul>
				<div class="gm-mm__paste-review-actions">
					<NcButton :disabled="pasteReview.matched.length === 0" variant="primary" @click="confirmPasteReview">
						{{ t('group_manager', 'Add {count} matched', { count: pasteReview.matched.length }) }}
					</NcButton>
					<NcButton @click="pasteReview = null">
						{{ t('group_manager', 'Cancel') }}
					</NcButton>
				</div>
			</div>

			<div v-if="loading && members.length === 0" class="gm-mm__loading">
				<NcLoadingIcon :size="24" />
			</div>

			<p v-else-if="members.length === 0" class="gm-mm__empty">
				{{ t('group_manager', 'This group has no members yet.') }}
			</p>

			<ul v-else class="gm-mm__grid">
				<li v-for="member in members"
					:key="member.uid"
					class="gm-mm__row"
					:class="{ 'gm-mm__row--leaving': isPendingRemove(member.uid) }">
					<NcAvatar :user="member.uid"
						:display-name="member.displayName"
						:size="26"
						:disable-menu="true"
						:disable-tooltip="true"
						class="gm-mm__row-avatar" />
					<span class="gm-mm__row-name">{{ member.displayName }}</span>
					<span class="gm-mm__row-meta">
						<span v-if="isPendingRemove(member.uid)" class="gm-mm__row-leaving-label">
							{{ t('group_manager', 'leaving') }}
						</span>
						<span v-else-if="!member.enabled" class="gm-mm__row-disabled-label">
							{{ t('group_manager', 'disabled') }}
						</span>
						<span v-else-if="member.email" class="gm-mm__row-email">{{ member.email }}</span>
						<span v-else class="gm-mm__row-email">{{ member.uid }}</span>
					</span>
					<button v-if="!isPendingRemove(member.uid)"
						type="button"
						class="gm-mm__row-remove"
						:aria-label="t('group_manager', 'Remove {name}', { name: member.displayName })"
						:disabled="applying"
						@click="markForRemoval(member)">
						×
					</button>
				</li>
			</ul>

			<NcButton v-if="hasMore"
				class="gm-mm__more"
				:disabled="applying || loadingMore"
				@click="loadMore">
				<template v-if="loadingMore" #icon>
					<NcLoadingIcon :size="18" />
				</template>
				{{ t('group_manager', 'Load more') }}
			</NcButton>
		</div>

		<NcNoteCard v-if="lastResult" :type="lastResult.failed.length > 0 ? 'warning' : 'success'" class="gm-mm__result">
			<p>{{ resultSummaryText }}</p>
			<details v-if="lastResult.failed.length > 0" open>
				<summary>{{ t('group_manager', 'Show failure details') }}</summary>
				<ul class="gm-mm__result-list">
					<li v-for="f in lastResult.failed" :key="f.action + '-' + f.uid">
						{{ f.displayName }}: {{ f.error }}
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
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import AccountOutline from 'vue-material-design-icons/AccountOutline.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import CheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import {
	fetchGroupMembers,
	searchGroupCandidates,
	expandGroupForAdd,
	resolvePastedList,
	addGroupMember,
	removeGroupMember,
} from '../services/api.js'
import { extractErrorMessage } from '../utils/errors.js'
import { runWithConcurrency } from '../utils/concurrency.js'

const PAGE_SIZE = 50
const SEARCH_DEBOUNCE_MS = 300
const APPLY_CONCURRENCY = 4

export default {
	name: 'GroupMembersManager',

	components: {
		NcAvatar,
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
		AccountGroup,
		AccountOutline,
		AlertCircle,
		CheckCircle,
		Magnify,
		Plus,
	},

	props: {
		groupId: {
			type: String,
			required: true,
		},
		// True when a tab above already shows "Members {count}" — the
		// in-panel title would just repeat it.
		hideTitle: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['changed', 'pending-changed'],

	data() {
		return {
			members: [],
			total: null,
			memberSearch: '',
			offset: 0,
			loading: true,
			loadingMore: false,
			memberSearchTimer: null,

			addQuery: '',
			addSearching: false,
			addResults: { users: [], groups: [] },
			addSearchTimer: null,
			showDropdown: false,
			activeIndex: -1,

			// {uid, displayName, status: 'pending'|'applying'|'error', error, fromGroup?: {id, displayName}}
			pendingAdd: [],
			// {uid, displayName, status, error}
			pendingRemove: [],

			pasteReview: null,

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

		pendingCount() {
			return this.pendingAdd.length + this.pendingRemove.length
		},

		flatOptions() {
			const groups = this.addResults.groups.map((g) => ({
				key: 'group-' + g.id,
				kind: 'group',
				id: g.id,
				displayName: g.displayName,
				newMemberCount: g.newMemberCount,
			}))
			const users = this.addResults.users.map((u) => ({
				key: 'user-' + u.uid,
				kind: 'user',
				uid: u.uid,
				displayName: u.displayName,
			}))
			return [...groups, ...users]
		},

		queueChips() {
			const individual = this.pendingAdd.filter((i) => !i.fromGroup)
			const groupedMap = new Map()
			for (const item of this.pendingAdd) {
				if (!item.fromGroup) {
					continue
				}
				const key = item.fromGroup.id
				if (!groupedMap.has(key)) {
					groupedMap.set(key, {
						key: 'add-group-' + key,
						kind: 'add-group',
						groupId: key,
						displayName: item.fromGroup.displayName,
						items: [],
					})
				}
				groupedMap.get(key).items.push(item)
			}
			const groupChips = Array.from(groupedMap.values()).map((g) => ({
				...g,
				count: g.items.length,
				hasError: g.items.some((i) => i.status === 'error'),
			}))
			const addChips = individual.map((i) => ({
				key: 'add-' + i.uid,
				kind: 'add',
				uid: i.uid,
				displayName: i.displayName,
				hasError: i.status === 'error',
				error: i.error,
			}))
			const removeChips = this.pendingRemove.map((i) => ({
				key: 'remove-' + i.uid,
				kind: 'remove',
				uid: i.uid,
				displayName: i.displayName,
				hasError: i.status === 'error',
				error: i.error,
			}))
			return [...addChips, ...groupChips, ...removeChips]
		},

		headerCountText() {
			const current = this.total ?? this.members.length
			if (!this.hasPendingChanges) {
				return t('group_manager', '{count} members', { count: current })
			}
			return t('group_manager', '{current} members · {after} after applying', { current, after: this.afterApplyingCount })
		},

		afterApplyingCount() {
			const current = this.total ?? this.members.length
			return current - this.pendingRemove.length + this.pendingAdd.length
		},

		afterApplyingText() {
			return t('group_manager', '{after} after applying', { after: this.afterApplyingCount })
		},

		pasteReviewSummary() {
			if (!this.pasteReview) {
				return ''
			}
			return t('group_manager', 'Matched {matched} of {total}.', {
				matched: this.pasteReview.matched.length + this.pasteReview.alreadyMember.length,
				total: this.pasteReview.matched.length + this.pasteReview.alreadyMember.length + this.pasteReview.unmatched.length,
			})
		},

		resultSummaryText() {
			if (!this.lastResult) {
				return ''
			}
			const parts = []
			if (this.lastResult.addedCount > 0) {
				parts.push(t('group_manager', '{count} added', { count: this.lastResult.addedCount }))
			}
			if (this.lastResult.removedCount > 0) {
				parts.push(t('group_manager', '{count} removed', { count: this.lastResult.removedCount }))
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
			this.emitPendingChanged(value)
		},

		pendingCount() {
			if (this.hasPendingChanges) {
				this.emitPendingChanged(true)
			}
		},
	},

	mounted() {
		this.reload()
		document.addEventListener('click', this.onDocumentClick)
	},

	beforeUnmount() {
		clearTimeout(this.memberSearchTimer)
		clearTimeout(this.addSearchTimer)
		document.removeEventListener('click', this.onDocumentClick)
	},

	methods: {
		t,

		emitPendingChanged(hasPendingChanges) {
			this.$emit('pending-changed', {
				hasPendingChanges,
				count: this.pendingCount,
				joining: this.pendingAdd.length,
				leaving: this.pendingRemove.length,
			})
		},

		resetState() {
			this.memberSearch = ''
			this.addQuery = ''
			this.addResults = { users: [], groups: [] }
			this.showDropdown = false
			this.pendingAdd = []
			this.pendingRemove = []
			this.pasteReview = null
			this.lastResult = null
		},

		onMemberSearchInput() {
			clearTimeout(this.memberSearchTimer)
			this.memberSearchTimer = setTimeout(() => this.reload(), SEARCH_DEBOUNCE_MS)
		},

		async reload() {
			this.loading = true
			this.offset = 0
			try {
				const data = await fetchGroupMembers(this.groupId, {
					search: this.memberSearch,
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
			if (this.loadingMore) {
				return
			}
			this.loadingMore = true
			try {
				const data = await fetchGroupMembers(this.groupId, {
					search: this.memberSearch,
					limit: PAGE_SIZE,
					offset: this.offset,
				})
				this.members = this.members.concat(data.members)
				this.total = data.total
				this.offset += data.members.length
			} finally {
				this.loadingMore = false
			}
		},

		onDocumentClick(event) {
			// Scoped to the add field itself, not the whole panel — the
			// dropdown now opens on a bare focus, so a broader root check
			// would leave it open while clicking anywhere else in the member
			// list/queue, which reads as "doesn't close".
			if (this.showDropdown && this.$refs.addWrapper && !this.$refs.addWrapper.contains(event.target)) {
				this.closeDropdown()
			}
		},

		onAddFocus() {
			this.showDropdown = true
			if (this.addResults.users.length === 0 && this.addResults.groups.length === 0) {
				this.runAddSearch()
			}
		},

		onAddInput() {
			this.activeIndex = -1
			this.showDropdown = true
			this.runAddSearch()
		},

		/**
		 * Searches on every keystroke AND on an empty query — an empty term
		 * browses all addable users alphabetically (server-sorted) instead of
		 * showing nothing until the admin starts typing.
		 */
		runAddSearch() {
			clearTimeout(this.addSearchTimer)
			const term = this.addQuery.trim()
			this.addSearching = true
			this.addSearchTimer = setTimeout(async () => {
				try {
					this.addResults = await searchGroupCandidates(this.groupId, term, 10)
				} finally {
					this.addSearching = false
				}
			}, SEARCH_DEBOUNCE_MS)
		},

		/**
		 * Multi-line paste is treated as a list to resolve against the server;
		 * a single-line paste behaves like normal typing (left to the browser).
		 */
		onAddPaste(event) {
			const text = event.clipboardData?.getData('text') ?? ''
			const lines = text.split(/\r?\n/).map((line) => line.trim()).filter(Boolean)
			if (lines.length <= 1) {
				return
			}
			event.preventDefault()
			this.addQuery = ''
			this.closeDropdown()
			this.reviewPastedList(lines)
		},

		async reviewPastedList(lines) {
			try {
				const results = await resolvePastedList(this.groupId, lines)
				const alreadyQueuedOrMember = new Set([
					...this.members.map((m) => m.uid),
					...this.pendingAdd.map((i) => i.uid),
				])
				this.pasteReview = {
					matched: results.filter((r) => r.matched && !alreadyQueuedOrMember.has(r.uid)),
					alreadyMember: results.filter((r) => r.matched && alreadyQueuedOrMember.has(r.uid)),
					unmatched: results.filter((r) => !r.matched),
				}
			} catch (err) {
				this.pasteReview = { matched: [], alreadyMember: [], unmatched: lines.map((token) => ({ token })) }
			}
		},

		confirmPasteReview() {
			for (const match of this.pasteReview.matched) {
				this.addUserToQueue({ uid: match.uid, displayName: match.displayName })
			}
			this.pasteReview = null
		},

		closeDropdown() {
			this.showDropdown = false
			this.activeIndex = -1
		},

		moveActive(delta) {
			if (!this.showDropdown || this.flatOptions.length === 0) {
				return
			}
			const count = this.flatOptions.length
			this.activeIndex = (this.activeIndex + delta + count) % count
		},

		onAddEnter() {
			if (this.activeIndex >= 0 && this.flatOptions[this.activeIndex]) {
				this.pickOption(this.flatOptions[this.activeIndex])
			}
		},

		pickOption(option) {
			if (option.kind === 'group') {
				this.pickGroup(option)
			} else {
				this.toggleUserOption(option)
			}
		},

		isQueuedUser(uid) {
			return this.pendingAdd.some((i) => i.uid === uid && !i.fromGroup)
		},

		/**
		 * Toggling (instead of add-and-close) lets several matches from the
		 * same search — e.g. picking multiple "Ana"s — be queued without
		 * re-opening and re-typing the search for each one.
		 */
		toggleUserOption(user) {
			if (this.isQueuedUser(user.uid)) {
				this.pendingAdd = this.pendingAdd.filter((i) => !(i.uid === user.uid && !i.fromGroup))
			} else {
				this.addUserToQueue({ uid: user.uid, displayName: user.displayName })
			}
		},

		async pickGroup(group) {
			this.addQuery = ''
			this.closeDropdown()
			try {
				const members = await expandGroupForAdd(this.groupId, group.id)
				for (const member of members) {
					this.addUserToQueue({ ...member, fromGroup: { id: group.id, displayName: group.displayName } })
				}
			} catch {
				// Silently no-op — the picker already only offered groups with
				// newMemberCount > 0, so a failure here is rare (race with a
				// concurrent change); nothing was queued, nothing to undo.
			}
		},

		addUserToQueue(user) {
			if (this.pendingAdd.some((i) => i.uid === user.uid) || this.members.some((m) => m.uid === user.uid)) {
				return
			}
			this.pendingAdd.push({
				uid: user.uid,
				displayName: user.displayName,
				status: 'pending',
				error: '',
				fromGroup: user.fromGroup || null,
			})
		},

		cancelChip(chip) {
			if (chip.kind === 'add') {
				this.pendingAdd = this.pendingAdd.filter((i) => i.uid !== chip.uid)
			} else if (chip.kind === 'add-group') {
				this.pendingAdd = this.pendingAdd.filter((i) => !(i.fromGroup && i.fromGroup.id === chip.groupId))
			} else if (chip.kind === 'remove') {
				this.pendingRemove = this.pendingRemove.filter((i) => i.uid !== chip.uid)
			}
		},

		isPendingRemove(uid) {
			return this.pendingRemove.some((i) => i.uid === uid)
		},

		markForRemoval(member) {
			if (this.isPendingRemove(member.uid)) {
				return
			}
			this.pendingRemove.push({ uid: member.uid, displayName: member.displayName, status: 'pending', error: '' })
		},

		discardChanges() {
			this.pendingAdd = []
			this.pendingRemove = []
			this.pasteReview = null
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
				addedCount: this.pendingAdd.length - failedAdd.length,
				removedCount: this.pendingRemove.length - failedRemove.length,
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
.gm-mm {
	display: flex;
	flex-direction: column;
	height: 100%;
	min-height: 0;
}

.gm-mm__scroll {
	flex: 1;
	min-height: 0;
	overflow-y: auto;
	padding-bottom: 8px;
}

.gm-mm__header {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 12px;
}

.gm-mm__title {
	flex-shrink: 0;
	margin: 0;
	font-size: 16px;
}

.gm-mm__count {
	flex-shrink: 0;
	margin-left: 8px;
	font-size: 13px;
	font-weight: normal;
	color: var(--color-text-maxcontrast);
	white-space: nowrap;
}

.gm-mm__search {
	/* NcTextField sizes its wrapper, icon and label off this one variable —
	   redefining it (rather than forcing height on each part separately)
	   keeps them all correctly centered together at the taller size. */
	--default-clickable-area: 42px;
	flex: 1 1 0;
	min-width: 0;
	/* Its root also carries a 6px top margin (meant for stacking under a
	   label in a form) — asymmetric margin skews flex centering. */
	margin: 0 !important;
}

/* Single add field: user, whole group, or a pasted list. */
.gm-mm__add {
	position: relative;
	display: flex;
	align-items: center;
	gap: 8px;
	flex: 1 1 0;
	min-width: 0;
	height: 42px;
	padding: 0 12px;
	border: 1px solid var(--color-border-dark);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
}

.gm-mm__add--open {
	border-color: var(--color-primary-element);
}

.gm-mm__add-icon {
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
}

.gm-mm__add-input {
	flex: 1;
	min-width: 0;
	height: 100%;
	border: none;
	outline: none;
	background: transparent;
	font-family: inherit;
	font-size: 14px;
	color: var(--color-main-text);
}

.gm-mm__add-dropdown {
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

.gm-mm__add-status {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 10px;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.gm-mm__add-option {
	display: flex;
	align-items: center;
	gap: 8px;
	padding: 8px 10px;
	border-radius: var(--border-radius);
	cursor: pointer;
}

.gm-mm__add-option--active,
.gm-mm__add-option:hover {
	background: var(--color-background-hover);
}

.gm-mm__add-option-icon {
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
}

.gm-mm__add-option-name {
	flex: 1;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-size: 14px;
}

.gm-mm__add-option-count {
	flex-shrink: 0;
	padding: 1px 7px;
	border-radius: var(--border-radius-pill);
	background: var(--color-background-dark);
	color: var(--color-text-maxcontrast);
	font-size: 11px;
	font-weight: bold;
}

.gm-mm__add-option-check {
	flex-shrink: 0;
	pointer-events: none;
}

.gm-mm__add-hint {
	padding: 6px 10px 2px;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
	font-style: italic;
}

/* Queue of chips — the single source of truth for what will happen on Apply. */
.gm-mm__queue {
	display: flex;
	flex-wrap: wrap;
	gap: 7px;
	margin-bottom: 12px;
}

.gm-mm__chip {
	display: inline-flex;
	align-items: center;
	gap: 5px;
	padding: 3px 6px 3px 9px;
	border-radius: var(--border-radius-pill);
	font-size: 13px;
	border: 1px solid transparent;
}

.gm-mm__chip--add,
.gm-mm__chip--add-group {
	background: var(--color-success);
	border-color: var(--color-success-hover);
	color: var(--color-success-text);
}

.gm-mm__chip--remove {
	background: var(--color-error);
	border-color: var(--color-error-hover);
	color: var(--color-error-text);
}

.gm-mm__chip-prefix {
	font-weight: bold;
}

.gm-mm__chip-count {
	margin-left: 4px;
}

.gm-mm__chip-error {
	color: var(--color-error);
}

.gm-mm__chip-close {
	display: flex;
	align-items: center;
	justify-content: center;
	width: 18px;
	height: 18px;
	min-width: 0;
	min-height: 0;
	padding: 0;
	border: none;
	border-radius: 50%;
	background: transparent;
	color: inherit;
	font-size: 15px;
	line-height: 1;
	cursor: pointer;
	opacity: .75;
}

.gm-mm__chip-close:hover {
	opacity: 1;
	background: color-mix(in srgb, currentColor 15%, transparent);
}

.gm-mm__paste-review {
	margin-bottom: 12px;
	padding: 10px 12px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
}

.gm-mm__paste-review-summary {
	margin: 0 0 6px;
	font-size: 13px;
	font-weight: 600;
}

.gm-mm__paste-review-list {
	list-style: none;
	margin: 0 0 6px;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 2px;
	max-height: 120px;
	overflow-y: auto;
}

.gm-mm__paste-review-item {
	display: flex;
	align-items: center;
	gap: 6px;
	font-size: 13px;
}

.gm-mm__paste-review-item--matched {
	color: var(--color-success-text);
}

.gm-mm__paste-review-item--already {
	color: var(--color-text-maxcontrast);
}

.gm-mm__paste-review-item--unmatched {
	color: var(--color-error-text);
}

.gm-mm__paste-review-actions {
	display: flex;
	gap: 8px;
	margin-top: 8px;
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
	display: flex;
	flex-direction: column;
	max-width: 560px;
	list-style: none;
	margin: 0;
	padding: 0;
}

.gm-mm__row {
	position: relative;
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 6px 26px 6px 2px;
	border-radius: var(--border-radius);
	min-width: 0;
}

.gm-mm__row:hover {
	background: var(--color-background-hover);
}

.gm-mm__row-avatar {
	flex-shrink: 0;
}

.gm-mm__row-name {
	flex-shrink: 0;
	max-width: 45%;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-size: 14px;
}

.gm-mm__row--leaving .gm-mm__row-name {
	text-decoration: line-through;
	color: var(--color-text-maxcontrast);
}

.gm-mm__row-meta {
	flex: 1;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	text-align: right;
	font-size: 12px;
}

.gm-mm__row-email {
	color: var(--color-text-maxcontrast);
}

.gm-mm__row-disabled-label {
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.gm-mm__row-leaving-label {
	color: var(--color-error-text);
}

/* Reserved space so the × appearing on hover never shifts the layout. */
.gm-mm__row-remove {
	position: absolute;
	right: 4px;
	top: 50%;
	transform: translateY(-50%);
	width: 22px;
	height: 22px;
	min-width: 0;
	min-height: 0;
	padding: 0;
	display: flex;
	align-items: center;
	justify-content: center;
	border: none;
	border-radius: 50%;
	background: transparent;
	color: var(--color-error-text);
	font-size: 16px;
	line-height: 1;
	cursor: pointer;
	opacity: 0;
}

.gm-mm__row:hover .gm-mm__row-remove,
.gm-mm__row-remove:focus-visible {
	opacity: 1;
}

.gm-mm__row-remove:hover {
	background: color-mix(in srgb, currentColor 18%, transparent);
}

.gm-mm__more {
	margin-top: 12px;
}

.gm-mm__result {
	flex-shrink: 0;
	margin-top: 12px;
}

.gm-mm__result-list {
	margin: 8px 0;
	padding-left: 20px;
}
</style>
