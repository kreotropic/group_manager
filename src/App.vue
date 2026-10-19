<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div class="gm-app">
		<NcNoteCard v-if="loadError" type="error">
			{{ loadError }}
		</NcNoteCard>

		<div ref="layout"
			class="gm-layout"
			:class="{ 'gm-layout--resizing': resizing }"
			:style="{ '--gm-list-w': listWidth + 'px' }">
			<GroupList v-show="mode === 'groups'"
				:groups="groups"
				:loading="loading"
				:selected-id="selectedId"
				:quick-access="quickAccess"
				@toggle-quick-access="toggleQuickAccess"
				@select="selectGroup"
				@create="showCreateDialog = true"
				@switch-mode="switchMode" />
			<!-- Mounted on first use, then kept: switching back and forth
			     keeps the search, the filter and the loaded pages. -->
			<UserList v-if="usersVisited"
				v-show="mode === 'users'"
				ref="userList"
				:selected-uid="selectedUid"
				:has-ldap="!!userOptions?.hasLdap"
				:quick-access="quickAccess"
				@toggle-quick-access="toggleQuickAccess"
				@select="selectUser"
				@create="showCreateUserDialog = true"
				@switch-mode="switchMode" />

			<!-- Drag to share the width between the list and the panel; the
			     arrow keys move it too, a double click restores the default. -->
			<div class="gm-splitter"
				role="separator"
				tabindex="0"
				aria-orientation="vertical"
				:aria-label="t('group_manager', 'Resize the list')"
				:aria-valuenow="listWidth"
				:aria-valuemin="minListWidth"
				:aria-valuemax="maxListWidth()"
				:title="t('group_manager', 'Drag to resize, double-click to reset')"
				@pointerdown="startResize"
				@keydown="onSplitterKey"
				@dblclick="setListWidth(defaultListWidth, true)" />

			<div class="gm-detail">
				<template v-if="mode === 'groups'">
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
						@pending-changed="onDetailPendingChanged" />
				</template>
				<template v-else>
					<NcEmptyContent v-if="!selectedUid || !userOptions"
						class="gm-detail__empty"
						:name="t('group_manager', 'Select a user')"
						:description="t('group_manager', 'Choose a user on the left to edit their account, profile and groups.')">
						<template #icon>
							<AccountOutline :size="48" />
						</template>
					</NcEmptyContent>
					<UserDetail v-else
						:key="selectedUid"
						:uid="selectedUid"
						:options="userOptions"
						:groups="groups"
						@updated="onUserUpdated"
						@deleted="onUserDeleted"
						@groups-changed="refreshGroups"
						@pending-changed="onDetailPendingChanged" />
				</template>
			</div>
		</div>

		<CreateGroupDialog :open="showCreateDialog"
			@update:open="showCreateDialog = $event"
			@created="onGroupCreated" />

		<CreateUserDialog v-if="userOptions"
			:open="showCreateUserDialog"
			:options="userOptions"
			:groups="groups"
			@update:open="showCreateUserDialog = $event"
			@created="onUserCreated" />

		<div class="gm-sr-only" role="status" aria-live="polite">{{ announcement }}</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import { showSuccess, showError } from '@nextcloud/dialogs'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import AccountOutline from 'vue-material-design-icons/AccountOutline.vue'
import GroupList from './components/GroupList.vue'
import GroupDetail from './components/GroupDetail.vue'
import CreateGroupDialog from './components/CreateGroupDialog.vue'
import UserList from './components/UserList.vue'
import UserDetail from './components/UserDetail.vue'
import CreateUserDialog from './components/CreateUserDialog.vue'
import { fetchGroups, fetchQuickAccess, fetchUserOptions, saveQuickAccess } from './services/api.js'
import { extractErrorMessage } from './utils/errors.js'
import { readSelectionFromHash, pushSelectionHash } from './utils/hash.js'

const DEFAULT_LIST_WIDTH = 296
const MIN_LIST_WIDTH = 240
const MAX_LIST_WIDTH = 720
// The panel never gets narrower than this while dragging.
const MIN_DETAIL_WIDTH = 420
const LIST_WIDTH_KEY = 'group_manager:list-width'

/** Per-browser convenience; storage can be missing or blocked. */
function readStoredWidth() {
	try {
		const value = Number.parseInt(window.localStorage.getItem(LIST_WIDTH_KEY), 10)
		return Number.isFinite(value) ? value : null
	} catch {
		return null
	}
}

function storeWidth(value) {
	try {
		window.localStorage.setItem(LIST_WIDTH_KEY, String(value))
	} catch {
		// Not remembered, nothing else lost.
	}
}

export default {
	name: 'App',

	components: {
		NcNoteCard,
		NcEmptyContent,
		AccountMultiple,
		AccountOutline,
		GroupList,
		GroupDetail,
		CreateGroupDialog,
		UserList,
		UserDetail,
		CreateUserDialog,
	},

	data() {
		return {
			groups: [],
			loading: true,
			loadError: '',
			// 'groups' or 'users': which list and panel are showing.
			mode: 'groups',
			usersVisited: false,
			selectedId: null,
			selectedUid: null,
			userOptions: null,
			showCreateDialog: false,
			showCreateUserDialog: false,
			// Unapplied changes in whichever panel is open (group or user).
			hasPendingMemberChanges: false,
			// GM-08: a batch is being sent right now (GroupDetail's own
			// applying, covering both its members and folders managers) --
			// navigating away is refused outright, not just guarded by a
			// confirm(), since there's nothing sane to ask about a request
			// already in flight and discarding it client-side wouldn't undo
			// it server-side anyway.
			applyingChanges: false,
			// This app's own monotonic position in the navigation stack,
			// mirrored into history.state by pushHistory() -- see
			// onPopState() and utils/hash.js's pushSelectionHash().
			historyIndex: 0,
			// Set right before this app calls history.go() itself, so the
			// popstate that generates is recognized as our own reversal
			// instead of being re-processed as a new user navigation.
			suppressNextPopState: false,
			announcement: '',
			quickAccess: false,
			listWidth: readStoredWidth() ?? DEFAULT_LIST_WIDTH,
			defaultListWidth: DEFAULT_LIST_WIDTH,
			minListWidth: MIN_LIST_WIDTH,
			resizing: false,
		}
	},

	async mounted() {
		this.measureScrollbar()
		window.addEventListener('popstate', this.onPopState)
		window.addEventListener('beforeunload', this.onBeforeUnload)

		window.addEventListener('resize', this.onWindowResize)
		this.$nextTick(() => this.setListWidth(this.listWidth))

		fetchQuickAccess().then((v) => { this.quickAccess = v }).catch(() => {})
		fetchUserOptions().then((v) => { this.userOptions = v }).catch(() => {})
		await this.loadGroups()

		// Deep links: #group=<gid> (only if that group still exists),
		// #user=<uid> and #users.
		const { mode, id } = readSelectionFromHash()
		if (mode === 'users') {
			this.mode = 'users'
			this.usersVisited = true
			this.selectedUid = id
		} else if (id && this.groups.some((g) => g.id === id)) {
			this.selectedId = id
		}
	},

	beforeUnmount() {
		window.removeEventListener('popstate', this.onPopState)
		window.removeEventListener('beforeunload', this.onBeforeUnload)
		window.removeEventListener('resize', this.onWindowResize)
	},

	methods: {
		t,

		/**
		 * The member list reserves its scrollbar lane and pushes it out into
		 * the panel's right padding (see --gm-scrollbar-w in admin.css), so
		 * the rows line up with the fixed parts above. The lane is as wide as
		 * the platform's thin scrollbar (the list sets scrollbar-width: thin),
		 * which is only knowable by measuring one with the same style.
		 */
		measureScrollbar() {
			const probe = document.createElement('div')
			probe.style.cssText = 'position:absolute;visibility:hidden;width:100px;height:100px;overflow-y:auto;scrollbar-gutter:stable;scrollbar-width:thin'
			this.$el.appendChild(probe)
			const width = probe.offsetWidth - probe.clientWidth
			this.$el.removeChild(probe)
			document.getElementById('group-manager')?.style.setProperty('--gm-scrollbar-w', width + 'px')
		},

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

		async toggleQuickAccess() {
			const next = !this.quickAccess
			try {
				this.quickAccess = await saveQuickAccess(next)
				showSuccess(this.quickAccess
					? t('group_manager', 'Top bar shortcut enabled. Reload the page to see it.')
					: t('group_manager', 'Top bar shortcut disabled. Reload the page to update the bar.'))
			} catch (err) {
				showError(extractErrorMessage(err, t('group_manager', 'Could not save the preference.')))
			}
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
			if (!this.confirmNavigateAway()) {
				return
			}
			this.hasPendingMemberChanges = false
			this.selectedId = gid
			this.pushHistory()
		},

		selectUser(uid) {
			if (uid === this.selectedUid) {
				return
			}
			if (!this.confirmNavigateAway()) {
				return
			}
			this.hasPendingMemberChanges = false
			this.selectedUid = uid
			this.pushHistory()
		},

		/** Groups ⇄ Users: a navigation like any other, guarded the same way. */
		switchMode(mode) {
			if (mode === this.mode || !this.confirmNavigateAway()) {
				return
			}
			this.hasPendingMemberChanges = false
			this.mode = mode
			if (mode === 'users') {
				this.usersVisited = true
			}
			this.pushHistory()
		},

		/**
		 * True when it's safe to leave the current group right now. Shared by
		 * selectGroup() and onPopState() (GM-08) so Back/Forward is held to
		 * the exact same policy as clicking another group in the list,
		 * including the hard applyingChanges refusal — a popstate has
		 * already happened by the time onPopState() runs, but "already
		 * happened" in the URL/history sense only; it's still reversible
		 * with history.go(), which onPopState() does when this returns false.
		 */
		confirmNavigateAway() {
			if (this.applyingChanges) {
				return false
			}
			if (!this.hasPendingMemberChanges) {
				return true
			}
			return window.confirm(this.mode === 'users'
				? t('group_manager', 'You have unapplied changes for this user. Discard them?')
				: t('group_manager', 'You have unapplied member changes for this group. Discard them and switch groups?'))
		},

		/**
		 * pushSelectionHash() for the current view and selection, plus
		 * keeping historyIndex in step with it — every caller that pushes a
		 * new entry goes through here so the two never drift apart.
		 */
		pushHistory() {
			this.historyIndex++
			pushSelectionHash(this.mode, this.mode === 'users' ? this.selectedUid : this.selectedId, this.historyIndex)
		},

		/**
		 * Reacting to the browser's own Back/Forward buttons. Unlike before,
		 * this is no longer unconditionally honoured (GM-08): the jump the
		 * browser already made is reverted with history.go() when
		 * confirmNavigateAway() declines it, using the index carried in
		 * history.state to know how many steps that takes and in which
		 * direction — comparing groupId alone can't tell an older entry from
		 * a newer one that happens to name the same group.
		 */
		onPopState(event) {
			if (this.suppressNextPopState) {
				this.suppressNextPopState = false
				return
			}

			const targetIndex = event.state?.index ?? 0
			const delta = targetIndex - this.historyIndex
			const target = event.state?.mode ? event.state : readSelectionFromHash()

			if (!this.confirmNavigateAway()) {
				if (delta !== 0) {
					this.suppressNextPopState = true
					window.history.go(-delta)
				}
				return
			}

			this.hasPendingMemberChanges = false
			this.historyIndex = targetIndex
			this.mode = target.mode
			if (target.mode === 'users') {
				this.usersVisited = true
				this.selectedUid = target.id ?? null
			} else {
				this.selectedId = target.id && this.groups.some((g) => g.id === target.id) ? target.id : null
			}
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
				this.pushHistory()
			}
			showSuccess(t('group_manager', 'Group "{name}" deleted.', { name: group?.displayName ?? gid }))
		},

		/**
		 * After a user's memberships changed: the member counts in the group
		 * list, quietly (no skeleton, no announcement).
		 */
		async refreshGroups() {
			try {
				this.groups = this.sortGroups(await fetchGroups())
			} catch {
				// Stale counts until the next load.
			}
		},

		onUserCreated(uid) {
			this.showCreateUserDialog = false
			this.$refs.userList?.reload()
			this.selectUser(uid)
			this.refreshGroups()
			showSuccess(t('group_manager', 'User "{uid}" created.', { uid }))
		},

		onUserUpdated(summary) {
			this.$refs.userList?.updateUser(summary)
		},

		onUserDeleted(uid) {
			this.$refs.userList?.removeUser(uid)
			if (this.selectedUid === uid) {
				this.hasPendingMemberChanges = false
				this.selectedUid = null
				this.pushHistory()
			}
			this.refreshGroups()
			showSuccess(t('group_manager', 'User "{uid}" deleted.', { uid }))
		},

		maxListWidth() {
			const total = this.$refs.layout?.clientWidth ?? 0
			return Math.max(MIN_LIST_WIDTH, Math.min(MAX_LIST_WIDTH, total - MIN_DETAIL_WIDTH))
		},

		setListWidth(value, save = false) {
			this.listWidth = Math.round(Math.min(Math.max(value, MIN_LIST_WIDTH), this.maxListWidth()))
			if (save) {
				storeWidth(this.listWidth)
			}
		},

		/** A narrower window can leave a stored width too wide for it. */
		onWindowResize() {
			this.setListWidth(readStoredWidth() ?? this.listWidth)
		},

		startResize(event) {
			if (event.button !== 0) {
				return
			}
			event.preventDefault()
			const splitter = event.currentTarget
			const left = this.$refs.layout.getBoundingClientRect().left
			splitter.setPointerCapture(event.pointerId)
			this.resizing = true
			const move = (e) => this.setListWidth(e.clientX - left)
			const stop = () => {
				splitter.removeEventListener('pointermove', move)
				splitter.removeEventListener('pointerup', stop)
				splitter.removeEventListener('pointercancel', stop)
				this.resizing = false
				storeWidth(this.listWidth)
			}
			splitter.addEventListener('pointermove', move)
			splitter.addEventListener('pointerup', stop)
			splitter.addEventListener('pointercancel', stop)
		},

		onSplitterKey(event) {
			const step = event.shiftKey ? 64 : 16
			if (event.key === 'ArrowLeft') {
				this.setListWidth(this.listWidth - step, true)
			} else if (event.key === 'ArrowRight') {
				this.setListWidth(this.listWidth + step, true)
			} else if (event.key === 'Home') {
				this.setListWidth(MIN_LIST_WIDTH, true)
			} else if (event.key === 'End') {
				this.setListWidth(this.maxListWidth(), true)
			} else {
				return
			}
			event.preventDefault()
		},

		/**
		 * GroupDetail aggregates both its members and folders managers into
		 * one hasPendingChanges/applying pair (see its own pending-changed
		 * docblock) — this app only needs the result, not which of the two
		 * tabs it came from.
		 */
		onDetailPendingChanged({ hasPendingChanges, applying }) {
			this.hasPendingMemberChanges = hasPendingChanges
			this.applyingChanges = applying
		},
	},
}
</script>

<style scoped>
.gm-app {
	max-width: 100%;
}

.gm-layout {
	display: flex;
	height: calc(100vh - 130px);
	min-height: 480px;
	overflow: hidden;
	position: relative;
}

/* No text selection or hover effects in the panels while dragging. */
.gm-layout--resizing {
	cursor: col-resize;
	user-select: none;
}

.gm-layout--resizing > :not(.gm-splitter) {
	pointer-events: none;
}

/* The 12px gap between the two cards is the handle; a thin bar shows on
   hover, keyboard focus and while dragging. */
.gm-splitter {
	flex: none;
	position: relative;
	width: 12px;
	cursor: col-resize;
	touch-action: none;
	outline: none;
}

.gm-splitter::after {
	content: '';
	position: absolute;
	top: 50%;
	left: 4px;
	width: 4px;
	height: 48px;
	transform: translateY(-50%);
	border-radius: 2px;
	background: transparent;
	transition: background-color .15s, height .15s;
}

.gm-splitter:hover::after {
	background: var(--color-border-maxcontrast);
}

.gm-splitter:focus-visible::after,
.gm-layout--resizing .gm-splitter::after {
	height: 96px;
	background: var(--color-primary-element);
}

/* No card of its own: the panel sits straight on Nextcloud's content
   area, beside the list's grey column. */
.gm-detail {
	flex: 1;
	min-width: 0;
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
