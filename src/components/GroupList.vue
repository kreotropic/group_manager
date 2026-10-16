<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<nav class="gm-list" :aria-label="t('group_manager', 'Groups')">
		<div class="gm-list__top">
			<div class="gm-list__header">
				<h2 class="gm-list__title">{{ t('group_manager', 'Groups') }}</h2>
				<NcActions class="gm-list__options" :aria-label="t('group_manager', 'Options')">
					<NcActionCheckbox :model-value="quickAccess"
						@update:model-value="$emit('toggle-quick-access')">
						{{ t('group_manager', 'Show shortcut in the top bar') }}
					</NcActionCheckbox>
				</NcActions>
			</div>

			<!-- The row stays without LDAP groups (no pills to show), so the
			     search field keeps level with the detail panel's fields. -->
			<div class="gm-list__switch">
				<div v-if="hasLdapGroups" class="gm-list__filters" role="group" :aria-label="t('group_manager', 'Filter by origin')">
					<button v-for="opt in originOptions"
						:key="opt.value"
						type="button"
						class="gm-list__filter"
						:class="{ 'gm-list__filter--active': origin === opt.value }"
						:aria-pressed="origin === opt.value"
						@click="origin = opt.value">
						{{ opt.label }}
					</button>
				</div>
			</div>

			<NcTextField class="gm-list__search"
				v-model="searchQuery"
				:label="t('group_manager', 'Search groups')"
				:label-outside="true"
				:placeholder="t('group_manager', 'Search groups')">
				<template #icon>
					<Magnify :size="16" />
				</template>
			</NcTextField>

		</div>

		<div class="gm-list__scroll">
			<template v-if="loading">
				<div v-for="n in 6" :key="n" class="gm-skeleton-row">
					<span class="gm-skeleton-row__text" />
				</div>
			</template>

			<NcEmptyContent v-else-if="groups.length === 0"
				:name="t('group_manager', 'No groups yet')"
				:description="t('group_manager', 'Create your first local group to get started.')">
				<template #icon>
					<AccountGroupOutline :size="48" />
				</template>
				<template #action>
					<NcButton variant="primary" @click="$emit('create')">
						{{ t('group_manager', 'Create group') }}
					</NcButton>
				</template>
			</NcEmptyContent>

			<NcEmptyContent v-else-if="filteredGroups.length === 0"
				:name="t('group_manager', 'No groups found')"
				:description="t('group_manager', 'Try a different search term or clear the origin filter.')">
				<template #icon>
					<AccountSearchOutline :size="48" />
				</template>
				<template #action>
					<NcButton @click="clearFilters">
						{{ t('group_manager', 'Clear filters') }}
					</NcButton>
				</template>
			</NcEmptyContent>

			<template v-else>
				<template v-for="section in sections" :key="section.key">
					<!-- Always shown, even for a single section: it sits level with
					     the members heading on the right. -->
					<div class="gm-list__section-header">
						{{ section.label }}
					</div>
					<button v-for="group in section.groups"
						:key="group.id"
						type="button"
						class="gm-list__row"
						:class="{ 'gm-list__row--active': group.id === selectedId }"
						:aria-current="group.id === selectedId ? 'true' : undefined"
						:title="caseClashes.has(group.id) ? t('group_manager', 'Another group has the same name apart from upper/lower case. ID: {id}', { id: group.id }) : group.id"
						@click="onItemClick($event, group.id)">
						<GroupIcon :backend="group.backend" />
						<span class="gm-list__row-main">
							<span class="gm-list__row-name">{{ group.displayName }}</span>
							<span v-if="group.id !== group.displayName" class="gm-list__row-gid">{{ group.id }}</span>
						</span>
						<AlertOutline v-if="caseClashes.has(group.id)" :size="16" class="gm-list__row-warn" />
						<NcCounterBubble class="gm-list__row-count"
							:class="{ 'gm-list__row-count--empty': !group.memberCount }"
							:count="group.memberCount || 0"
							raw />
					</button>
				</template>
			</template>
		</div>

		<div class="gm-list__footer">
			<NcButton variant="primary" wide class="gm-list__create" @click="$emit('create')">
				<template #icon>
					<Plus :size="17" />
				</template>
				{{ t('group_manager', 'Create group') }}
			</NcButton>
		</div>
	</nav>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcActionCheckbox from '@nextcloud/vue/components/NcActionCheckbox'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcCounterBubble from '@nextcloud/vue/components/NcCounterBubble'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import AccountSearchOutline from 'vue-material-design-icons/AccountSearchOutline.vue'
import AlertOutline from 'vue-material-design-icons/AlertOutline.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import { translate as t } from '@nextcloud/l10n'
import GroupIcon from './GroupIcon.vue'

export default {
	name: 'GroupList',

	components: {
		NcButton,
		NcActionCheckbox,
		NcActions,
		NcCounterBubble,
		NcEmptyContent,
		NcTextField,
		AccountGroupOutline,
		AccountSearchOutline,
		AlertOutline,
		Magnify,
		Plus,
		GroupIcon,
	},

	props: {
		groups: {
			type: Array,
			default: () => [],
		},
		loading: {
			type: Boolean,
			default: false,
		},
		selectedId: {
			type: String,
			default: null,
		},
		quickAccess: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['select', 'create', 'toggle-quick-access'],

	data() {
		return {
			searchQuery: '',
			origin: 'all',
			originOptions: [
				{ value: 'all', label: t('group_manager', 'All') },
				{ value: 'local', label: t('group_manager', 'Local') },
				{ value: 'ldap', label: t('group_manager', 'LDAP') },
			],
		}
	},

	computed: {
		hasLdapGroups() {
			return this.groups.some((group) => group.backend === 'ldap')
		},

		/**
		 * Ids of groups whose name differs from another group's only by case
		 * (Engineering / engineering). Nextcloud allows both, but nobody can
		 * tell them apart in a list, so they are flagged as a data problem.
		 */
		caseClashes() {
			const byName = new Map()
			for (const group of this.groups) {
				const key = group.displayName.toLowerCase()
				byName.set(key, [...(byName.get(key) ?? []), group])
			}
			const clashes = new Set()
			for (const same of byName.values()) {
				if (new Set(same.map((g) => g.displayName)).size > 1) {
					same.forEach((g) => clashes.add(g.id))
				}
			}
			return clashes
		},

		filteredGroups() {
			const query = this.searchQuery.trim().toLowerCase()
			return this.groups.filter((group) => {
				if (this.origin !== 'all' && group.backend !== this.origin) {
					return false
				}
				if (query === '') {
					return true
				}
				return group.displayName.toLowerCase().includes(query)
					|| group.id.toLowerCase().includes(query)
			})
		},

		localVisible() {
			return this.filteredGroups.filter((group) => group.backend !== 'ldap')
		},

		ldapVisible() {
			return this.filteredGroups.filter((group) => group.backend === 'ldap')
		},

		/**
		 * One list, split into Local / LDAP sections only when both kinds are
		 * visible -- the same two words as the filter tabs above, so the two
		 * ways of grouping read as one system.
		 */
		sections() {
			const all = [
				{ key: 'local', label: t('group_manager', 'Local'), groups: this.localVisible },
				{ key: 'ldap', label: t('group_manager', 'LDAP'), groups: this.ldapVisible },
			]
			return all.filter((section) => section.groups.length > 0)
		},
	},

	watch: {
		selectedId() {
			this.scrollToActive()
		},

		loading(now) {
			if (!now) {
				this.scrollToActive()
			}
		},
	},

	mounted() {
		this.scrollToActive()
	},

	methods: {
		t,

		/**
		 * A deep-linked or just-created group can sit below the fold; without
		 * this the list gave no sign of which group the panel was showing.
		 */
		scrollToActive() {
			this.$nextTick(() => {
				this.$el.querySelector?.('.gm-list__row--active')?.scrollIntoView({ block: 'nearest' })
			})
		},

		clearFilters() {
			this.searchQuery = ''
			this.origin = 'all'
		},

		onItemClick(event, gid) {
			this.$emit('select', gid)
		},
	},
}
</script>

<style scoped>
.gm-list {
	width: 296px;
	flex: none;
	display: flex;
	flex-direction: column;
	gap: var(--gf-row-gap);
	box-sizing: border-box;
	height: 100%;
	min-height: 0;
	padding-top: var(--gf-pad-top);
	border-radius: var(--border-radius-container-large, 14px);
	background: var(--color-background-dark);
}

.gm-list__top {
	flex: none;
	display: flex;
	flex-direction: column;
	gap: var(--gf-row-gap);
}

.gm-list__header {
	display: flex;
	align-items: center;
	gap: 8px;
	height: var(--gf-row-header);
	padding: 0 8px 0 20px;
	--default-clickable-area: 32px;
}

.gm-list__title {
	flex-shrink: 0;
	margin: 0;
	font-size: 20px;
	font-weight: 600;
}

/* NcTextField draws its box 2px inside its wrapper on each side (room for
   the focus ring): 4px more, taken back with the margin, makes the visible
   box --gf-row-input tall in a row of that same height. */
.gm-list__search {
	--default-clickable-area: calc(var(--gf-row-input) + 4px);
	/* 10px + the wrapper's own 2px: the box starts at 12px, like the pills
	   and the section label. */
	padding: 0 10px;
	margin: -2px 0 !important;
}

/* Segmented control: a darker pill track, the active option lifted out of
   it on the main background. */
.gm-list__options {
	margin-left: auto;
}

/* A row of its own: on the title's line it didn't fit beside the options
   menu in the list's width. */
.gm-list__switch {
	display: flex;
	align-items: center;
	height: var(--gf-row-switch);
	padding: 0 12px;
}

.gm-list__filters {
	flex: 1;
	display: flex;
	gap: 2px;
	min-width: 0;
	padding: 3px;
	border-radius: var(--border-radius-pill);
	background: var(--color-background-darker);
}

.gm-list__filters .gm-list__filter {
	margin: 0;
	min-height: 0;
	flex: 1;
	padding: 3px 10px;
	white-space: nowrap;
	border: none;
	border-radius: var(--border-radius-pill);
	background: transparent;
	color: var(--color-main-text);
	font-family: inherit;
	font-size: 13px;
	font-weight: 500;
	cursor: pointer;
}

.gm-list__filters .gm-list__filter:hover {
	background: color-mix(in srgb, var(--color-main-background) 50%, transparent);
}

.gm-list__filters .gm-list__filter--active,
.gm-list__filters .gm-list__filter--active:hover {
	background: var(--color-main-background);
	color: var(--gm-primary-text);
	font-weight: 600;
	box-shadow: 0 1px 2px rgba(0, 0, 0, .12);
}

.gm-list__scroll {
	flex: 1;
	min-height: 0;
	overflow-x: hidden;
	overflow-y: auto;
	/* Thin bar without arrows; the gutter is reserved so the rows keep the
	   same right margin whether or not the list scrolls. */
	scrollbar-gutter: stable;
	padding: 0 0 8px 8px;
}

.gm-list__section-header {
	/* Sticks to the top once scrolled past and to the bottom while still
	   below the fold, so a section further down is always labelled. */
	position: sticky;
	top: 0;
	bottom: 0;
	z-index: 1;
	display: flex;
	align-items: center;
	box-sizing: border-box;
	height: var(--gf-row-section);
	background: var(--color-background-dark);
	padding: 0 12px;
	font-size: 11px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: .09em;
	color: var(--color-text-maxcontrast);
}

.gm-list__row {
	font-weight: 400;
	display: flex;
	align-items: center;
	gap: 10px;
	width: 100%;
	/* NcAppNavigationItem's height; a row with a gid line under the name
	   grows past it. */
	min-height: var(--default-clickable-area);
	box-sizing: border-box;
	padding: 3px 8px;
	border: none;
	border-radius: var(--border-radius-element, 8px);
	background: transparent;
	color: var(--color-main-text);
	text-align: left;
	font-family: inherit;
	cursor: pointer;
}

/* Core's button rule (margin 3px 3px 3px 0) outranks a single class. */
.gm-list .gm-list__row {
	margin: 0 0 2px;
}

/* The ring is for keyboard navigation only; a click must leave just the
   active background. */
.gm-list__row:focus:not(:focus-visible),
.gm-list__filters .gm-list__filter:focus:not(:focus-visible) {
	outline: none;
	box-shadow: none;
}

.gm-list__filters .gm-list__filter--active:focus:not(:focus-visible) {
	box-shadow: 0 1px 2px rgba(0, 0, 0, .12);
}

/* !important to beat core's button:focus-visible ring (main text colour
   plus a 4px shadow), which is !important too. */
.gm-list .gm-list__row:focus-visible,
.gm-list__filters .gm-list__filter:focus-visible {
	outline: 2px solid var(--color-primary-element) !important;
	outline-offset: -2px;
	box-shadow: none !important;
}

.gm-list__row:hover {
	background: var(--color-background-hover);
}

.gm-list__row--active,
.gm-list__row--active:hover {
	background: var(--color-main-background);
	color: var(--gm-primary-text);
	box-shadow: 0 1px 2px rgba(0, 0, 0, .06);
}

.gm-list__row-main {
	flex: 1;
	min-width: 0;
	display: flex;
	flex-direction: column;
}

.gm-list__row-name {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-size: 14px;
}

.gm-list__row--active .gm-list__row-name {
	font-weight: 600;
}

.gm-list__row-gid {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-family: monospace;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.gm-list__row-warn {
	flex-shrink: 0;
	color: var(--color-warning-text);
}

.gm-list__row-count {
	flex-shrink: 0;
	font-variant-numeric: tabular-nums;
}

.gm-list__row-count--empty {
	opacity: .7;
}

.gm-list__footer {
	flex-shrink: 0;
	padding: 12px;
}

.gm-skeleton-row {
	display: flex;
	align-items: center;
	gap: 10px;
	height: 40px;
	padding: 0 8px;
}

.gm-skeleton-row::before {
	content: '';
	width: 28px;
	height: 28px;
	border-radius: var(--border-radius-large);
	background: var(--color-background-darker);
	animation: gm-skeleton-pulse 1.4s ease-in-out infinite;
}

.gm-skeleton-row__text {
	height: 12px;
	width: 60%;
	border-radius: 6px;
	background: var(--color-background-darker);
	animation: gm-skeleton-pulse 1.4s ease-in-out infinite;
}

@keyframes gm-skeleton-pulse {
	0%, 100% { opacity: 1; }
	50% { opacity: 0.4; }
}
</style>
