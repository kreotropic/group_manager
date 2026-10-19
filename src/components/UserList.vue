<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<nav class="gm-list" :aria-label="t('group_manager', 'Users')">
		<div class="gm-list__top">
			<div class="gm-list__header">
				<div class="gm-list__modes" role="group" :aria-label="t('group_manager', 'Show')">
					<button type="button"
						class="gm-list__mode"
						aria-pressed="false"
						@click="$emit('switch-mode', 'groups')">
						{{ t('group_manager', 'Groups') }}
					</button>
					<button type="button"
						class="gm-list__mode gm-list__mode--active"
						aria-pressed="true">
						{{ t('group_manager', 'Users') }}
					</button>
				</div>
				<NcActions class="gm-list__options" :aria-label="t('group_manager', 'Options')">
					<NcActionCheckbox :model-value="quickAccess"
						@update:model-value="$emit('toggle-quick-access')">
						{{ t('group_manager', 'Show shortcut in the top bar') }}
					</NcActionCheckbox>
				</NcActions>
			</div>

			<div class="gm-list__switch">
				<div class="gm-list__filters" role="group" :aria-label="t('group_manager', 'Filter users')">
					<button v-for="opt in filterOptions"
						:key="opt.value"
						type="button"
						class="gm-list__filter"
						:class="{ 'gm-list__filter--active': filter === opt.value }"
						:aria-pressed="filter === opt.value"
						@click="setFilter(opt.value)">
						{{ opt.label }}
					</button>
				</div>
			</div>

			<NcTextField class="gm-list__search"
				:model-value="searchQuery"
				:label="t('group_manager', 'Search users')"
				:label-outside="true"
				:placeholder="t('group_manager', 'Search users')"
				@update:model-value="onSearchInput">
				<template #icon>
					<Magnify :size="16" />
				</template>
			</NcTextField>
		</div>

		<div ref="scroll" class="gm-list__scroll" @scroll="onScroll">
			<template v-if="loading && users.length === 0">
				<div v-for="n in 6" :key="n" class="gm-skeleton-row gm-skeleton-row--round">
					<span class="gm-skeleton-row__text" />
				</div>
			</template>

			<NcNoteCard v-else-if="loadError" type="error" class="gm-list__error">
				{{ loadError }}
			</NcNoteCard>

			<NcEmptyContent v-else-if="users.length === 0"
				:name="t('group_manager', 'No users found')"
				:description="searchQuery || filter !== 'all'
					? t('group_manager', 'Try a different search term or clear the filter.')
					: ''">
				<template #icon>
					<AccountSearchOutline :size="48" />
				</template>
				<template v-if="searchQuery || filter !== 'all'" #action>
					<NcButton @click="clearFilters">
						{{ t('group_manager', 'Clear filters') }}
					</NcButton>
				</template>
			</NcEmptyContent>

			<template v-else>
				<button v-for="user in users"
					:key="user.uid"
					type="button"
					class="gm-list__row"
					:class="{
						'gm-list__row--active': user.uid === selectedUid,
						'gm-list__row--disabled': !user.enabled,
					}"
					:aria-current="user.uid === selectedUid ? 'true' : undefined"
					:title="user.uid"
					@click="$emit('select', user.uid)">
					<NcAvatar :user="user.uid"
						:display-name="user.displayName"
						:size="28"
						:hide-status="true"
						:disable-menu="true"
						:disable-tooltip="true"
						class="gm-list__row-avatar" />
					<span class="gm-list__row-main">
						<span class="gm-list__row-name">{{ user.displayName }}</span>
						<span v-if="secondLine(user)" class="gm-list__row-gid">{{ secondLine(user) }}</span>
					</span>
					<span v-if="!user.enabled" class="gm-list__row-tag">{{ t('group_manager', 'Disabled') }}</span>
					<ShieldAccountOutline v-else-if="user.isAdmin"
						:size="16"
						class="gm-list__row-admin"
						:title="t('group_manager', 'Administrator')" />
				</button>
				<div v-if="loading" class="gm-list__more">
					<NcLoadingIcon :size="20" />
				</div>
			</template>
		</div>

		<div class="gm-list__footer">
			<NcButton variant="primary" wide class="gm-list__create" @click="$emit('create')">
				<template #icon>
					<Plus :size="17" />
				</template>
				{{ t('group_manager', 'Create user') }}
			</NcButton>
		</div>
	</nav>
</template>

<script>
import NcActionCheckbox from '@nextcloud/vue/components/NcActionCheckbox'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import AccountSearchOutline from 'vue-material-design-icons/AccountSearchOutline.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import ShieldAccountOutline from 'vue-material-design-icons/ShieldAccountOutline.vue'
import { translate as t } from '@nextcloud/l10n'
import { fetchUsers } from '../services/api.js'
import { extractErrorMessage } from '../utils/errors.js'

const PAGE_SIZE = 50
const SEARCH_DEBOUNCE_MS = 300
// How close to the bottom (px) the next page starts loading.
const LOAD_AHEAD_PX = 200

export default {
	name: 'UserList',

	components: {
		NcActionCheckbox,
		NcActions,
		NcAvatar,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
		AccountSearchOutline,
		Magnify,
		Plus,
		ShieldAccountOutline,
	},

	props: {
		selectedUid: {
			type: String,
			default: null,
		},
		quickAccess: {
			type: Boolean,
			default: false,
		},
		// Local and LDAP are only offered when there is an LDAP backend, as
		// in the group list.
		hasLdap: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['select', 'create', 'toggle-quick-access', 'switch-mode'],

	data() {
		return {
			users: [],
			loading: false,
			loadError: '',
			hasMore: true,
			searchQuery: '',
			filter: 'all',
			// Bumped on every reset, so a page that arrives after the search
			// or filter changed is dropped instead of mixed into the new list.
			generation: 0,
		}
	},

	computed: {
		/**
		 * One control for origin and status: they are filtered server-side
		 * from different sources (one backend's own listing, the disabled
		 * accounts query), so they don't combine.
		 */
		filterOptions() {
			return [
				{ value: 'all', label: t('group_manager', 'All') },
				...(this.hasLdap
					? [
						{ value: 'local', label: t('group_manager', 'Local') },
						{ value: 'ldap', label: t('group_manager', 'LDAP') },
					]
					: []),
				{ value: 'disabled', label: t('group_manager', 'Disabled') },
			]
		},
	},

	watch: {
		// The options arrive after mount; an origin filter left on when LDAP
		// turns out to be gone would list nothing with no way to clear it.
		hasLdap(now) {
			if (!now && (this.filter === 'local' || this.filter === 'ldap')) {
				this.setFilter('all')
			}
		},
	},

	mounted() {
		this.reload()
	},

	beforeUnmount() {
		clearTimeout(this.searchTimer)
	},

	methods: {
		t,

		/**
		 * The id, when it says something the name doesn't (generated ids
		 * are long and opaque, but still the only way to tell two "Ana
		 * Silva" apart), then the email.
		 */
		secondLine(user) {
			if (user.uid !== user.displayName) {
				return user.uid
			}
			return user.email || ''
		},

		reload() {
			this.generation++
			this.users = []
			this.hasMore = true
			this.loadError = ''
			this.loading = false
			this.loadMore()
		},

		async loadMore() {
			if (this.loading || !this.hasMore) {
				return
			}
			const generation = this.generation
			this.loading = true
			try {
				const page = await fetchUsers({
					search: this.searchQuery.trim(),
					pageSize: PAGE_SIZE,
					offset: this.users.length,
					filter: this.filter,
				})
				if (generation !== this.generation) {
					return
				}
				const known = new Set(this.users.map((u) => u.uid))
				this.users = [...this.users, ...page.users.filter((u) => !known.has(u.uid))]
				this.hasMore = page.hasMore
			} catch (err) {
				if (generation === this.generation) {
					this.loadError = extractErrorMessage(err, t('group_manager', 'Could not load users.'))
					this.hasMore = false
				}
			} finally {
				if (generation === this.generation) {
					this.loading = false
					// A first page shorter than the column leaves no scrollbar
					// to trigger the next one.
					this.$nextTick(this.onScroll)
				}
			}
		},

		onScroll() {
			const el = this.$refs.scroll
			if (el && el.scrollTop + el.clientHeight >= el.scrollHeight - LOAD_AHEAD_PX) {
				this.loadMore()
			}
		},

		onSearchInput(value) {
			this.searchQuery = value
			clearTimeout(this.searchTimer)
			this.searchTimer = setTimeout(this.reload, SEARCH_DEBOUNCE_MS)
		},

		setFilter(value) {
			if (this.filter !== value) {
				this.filter = value
				this.reload()
			}
		},

		clearFilters() {
			this.searchQuery = ''
			this.filter = 'all'
			this.reload()
		},

		/** After an edit in the detail panel: refresh that one row in place. */
		updateUser(summary) {
			const index = this.users.findIndex((u) => u.uid === summary.uid)
			if (index === -1) {
				return
			}
			if (this.filter === 'disabled' && summary.enabled) {
				this.users = this.users.filter((u) => u.uid !== summary.uid)
				return
			}
			this.users = [...this.users.slice(0, index), summary, ...this.users.slice(index + 1)]
		},

		removeUser(uid) {
			this.users = this.users.filter((u) => u.uid !== uid)
		},
	},
}
</script>

<style scoped src="../styles/list.css"></style>

<style scoped>
.gm-list__row-avatar {
	flex-shrink: 0;
}

.gm-list__row--disabled .gm-list__row-name {
	color: var(--color-text-maxcontrast);
}

.gm-list__row--disabled .gm-list__row-avatar {
	opacity: .5;
}

.gm-list__row-tag {
	flex-shrink: 0;
	padding: 1px 8px;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-pill);
	color: var(--color-text-maxcontrast);
	font-size: 11px;
	font-weight: 600;
}

.gm-list__row-admin {
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
}

.gm-list__more {
	display: flex;
	justify-content: center;
	padding: 8px 0;
}

.gm-list__error {
	margin: 0 12px 0 4px;
}

.gm-skeleton-row--round::before {
	border-radius: 50%;
}
</style>
