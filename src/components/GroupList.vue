<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<nav class="gm-list" :aria-label="t('group_manager', 'Groups')">
		<div class="gm-list__header">
			<h2 class="gm-list__title">{{ t('group_manager', 'Groups') }}</h2>
		</div>

		<NcTextField class="gm-list__search"
			v-model="searchQuery"
			:label="t('group_manager', 'Search groups')">
			<template #icon>
				<Magnify :size="16" />
			</template>
		</NcTextField>

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
				<button v-for="group in localVisible"
					:key="group.id"
					type="button"
					class="gm-list__row"
					:class="{ 'gm-list__row--active': group.id === selectedId }"
					:aria-current="group.id === selectedId ? 'true' : undefined"
					@click="onItemClick($event, group.id)">
					<span class="gm-list__row-main">
						<span class="gm-list__row-name">{{ group.displayName }}</span>
						<span v-if="group.id !== group.displayName" class="gm-list__row-gid">{{ group.id }}</span>
					</span>
					<span class="gm-list__row-count" :class="{ 'gm-list__row-count--empty': !group.memberCount }">
						{{ group.memberCount ? group.memberCount : t('group_manager', 'empty') }}
					</span>
				</button>

				<div v-if="ldapVisible.length > 0" class="gm-list__section-header">
					{{ t('group_manager', 'SYNCED') }}
				</div>
				<button v-for="group in ldapVisible"
					:key="group.id"
					type="button"
					class="gm-list__row"
					:class="{ 'gm-list__row--active': group.id === selectedId }"
					:aria-current="group.id === selectedId ? 'true' : undefined"
					@click="onItemClick($event, group.id)">
					<span class="gm-list__row-main">
						<span class="gm-list__row-name">{{ group.displayName }}</span>
						<span v-if="group.id !== group.displayName" class="gm-list__row-gid">{{ group.id }}</span>
					</span>
					<span class="gm-list__row-count" :class="{ 'gm-list__row-count--empty': !group.memberCount }">
						{{ group.memberCount ? group.memberCount : t('group_manager', 'empty') }}
					</span>
				</button>
			</template>
		</div>

		<div class="gm-list__footer">
			<NcButton variant="primary" class="gm-list__create" @click="$emit('create')">
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
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import AccountSearchOutline from 'vue-material-design-icons/AccountSearchOutline.vue'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'GroupList',

	components: {
		NcButton,
		NcEmptyContent,
		NcTextField,
		AccountGroupOutline,
		AccountSearchOutline,
		Magnify,
		Plus,
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
	},

	emits: ['select', 'create'],

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
	},

	methods: {
		t,

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
	height: 100%;
	min-height: 0;
	border-right: 1px solid var(--color-border);
}

.gm-list__header {
	padding: 16px 20px 8px;
}

.gm-list__title {
	margin: 0;
	font-size: 17px;
	font-weight: 600;
}

.gm-list__search {
	padding: 0 20px;
	margin-bottom: 8px;
}

.gm-list__filters {
	display: flex;
	gap: 14px;
	padding: 4px 20px 12px;
}

.gm-list__filter {
	padding: 0 0 4px;
	border: none;
	border-bottom: 2px solid transparent;
	border-radius: 0;
	background: transparent;
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	font-weight: 600;
	cursor: pointer;
}

.gm-list__filter--active {
	color: var(--color-main-text);
	border-bottom-color: var(--color-primary-element);
}

.gm-list__scroll {
	flex: 1;
	min-height: 0;
	overflow-y: auto;
	border-top: 1px solid var(--color-border);
}

.gm-list__section-header {
	padding: 10px 20px 6px;
	font-size: 11px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: .09em;
	color: var(--color-text-maxcontrast);
}

.gm-list__row {
	display: flex;
	align-items: center;
	gap: 10px;
	width: 100%;
	padding: 8px 20px;
	border: none;
	border-radius: 0;
	background: transparent;
	color: var(--color-main-text);
	text-align: left;
	font-family: inherit;
	cursor: pointer;
	box-shadow: inset 2px 0 0 transparent;
}

.gm-list__row:hover {
	background: var(--color-background-hover);
}

.gm-list__row--active {
	background: var(--color-primary-element-light);
	box-shadow: inset 2px 0 0 var(--color-primary-element);
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
	font-size: 11px;
	color: var(--color-text-maxcontrast);
}

.gm-list__row-count {
	flex-shrink: 0;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
	font-variant-numeric: tabular-nums;
}

.gm-list__row-count--empty {
	font-style: italic;
	font-size: 12px;
}

.gm-list__footer {
	flex-shrink: 0;
	padding: 12px 20px;
	border-top: 1px solid var(--color-border);
}

.gm-list__create {
	width: 100%;
	justify-content: center;
	border-radius: var(--border-radius-pill);
}

.gm-skeleton-row {
	display: flex;
	align-items: center;
	height: 38px;
	padding: 0 20px;
}

.gm-skeleton-row__text {
	height: 12px;
	width: 60%;
	border-radius: 6px;
	background: var(--color-background-dark);
	animation: gm-skeleton-pulse 1.4s ease-in-out infinite;
}

@keyframes gm-skeleton-pulse {
	0%, 100% { opacity: 1; }
	50% { opacity: 0.4; }
}
</style>
