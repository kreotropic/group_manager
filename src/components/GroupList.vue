<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<NcAppNavigation :aria-label="t('group_manager', 'Groups')">
		<template #search>
			<NcAppNavigationSearch v-model="searchQuery"
				:label="t('group_manager', 'Search groups')" />
			<div class="gm-origin-filter" role="group" :aria-label="t('group_manager', 'Filter by origin')">
				<NcButton v-for="opt in originOptions"
					:key="opt.value"
					:variant="origin === opt.value ? 'primary' : 'tertiary'"
					:aria-pressed="origin === opt.value"
					size="small"
					@click="origin = opt.value">
					{{ opt.label }}
				</NcButton>
			</div>
		</template>

		<template #list>
			<template v-if="loading">
				<div v-for="n in 6" :key="n" class="gm-skeleton-row">
					<span class="gm-skeleton-row__icon" />
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

			<NcAppNavigationItem v-for="group in filteredGroups"
				v-else
				:key="group.id"
				:name="group.displayName"
				:title="group.id !== group.displayName ? group.id : undefined"
				:active="group.id === selectedId"
				@click="$emit('select', group.id)">
				<template #icon>
					<Lan v-if="group.backend === 'ldap'" :size="20" />
					<AccountMultiple v-else :size="20" />
				</template>
				<template v-if="group.memberCount !== null" #counter>
					<NcCounterBubble :count="group.memberCount" />
				</template>
			</NcAppNavigationItem>
		</template>

		<template #footer>
			<NcAppNavigationNew :text="t('group_manager', 'New group')"
				button-id="gm-new-group"
				@click="$emit('create')">
				<template #icon>
					<Plus :size="20" />
				</template>
			</NcAppNavigationNew>
		</template>
	</NcAppNavigation>
</template>

<script>
import NcAppNavigation from '@nextcloud/vue/components/NcAppNavigation'
import NcAppNavigationSearch from '@nextcloud/vue/components/NcAppNavigationSearch'
import NcAppNavigationItem from '@nextcloud/vue/components/NcAppNavigationItem'
import NcAppNavigationNew from '@nextcloud/vue/components/NcAppNavigationNew'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCounterBubble from '@nextcloud/vue/components/NcCounterBubble'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import AccountGroupOutline from 'vue-material-design-icons/AccountGroupOutline.vue'
import AccountSearchOutline from 'vue-material-design-icons/AccountSearchOutline.vue'
import Lan from 'vue-material-design-icons/Lan.vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'GroupList',

	components: {
		NcAppNavigation,
		NcAppNavigationSearch,
		NcAppNavigationItem,
		NcAppNavigationNew,
		NcButton,
		NcCounterBubble,
		NcEmptyContent,
		AccountMultiple,
		AccountGroupOutline,
		AccountSearchOutline,
		Lan,
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
	},

	methods: {
		t,

		clearFilters() {
			this.searchQuery = ''
			this.origin = 'all'
		},
	},
}
</script>

<style scoped>
/*
 * The NcAppNavigation collapse toggle is meant to be positioned by the
 * global #content-vue app layout, which this settings-section page doesn't
 * have — without it the button renders inline next to the search field
 * instead of fixed in a corner. Collapsing this sidebar has no real use
 * here anyway (it's not a full-page app), so it's simplest to hide it.
 */
:deep(.app-navigation-toggle) {
	display: none;
}

.gm-origin-filter {
	display: flex;
	gap: 4px;
	padding: 4px 8px 8px;
}

.gm-origin-filter :deep(button) {
	flex: 1;
}

.gm-skeleton-row {
	display: flex;
	align-items: center;
	gap: 10px;
	height: 44px;
	padding: 0 12px;
}

.gm-skeleton-row__icon {
	width: 20px;
	height: 20px;
	border-radius: 50%;
	flex-shrink: 0;
}

.gm-skeleton-row__text {
	height: 12px;
	width: 60%;
	border-radius: 6px;
}

.gm-skeleton-row__icon,
.gm-skeleton-row__text {
	background: var(--color-background-darker);
	animation: gm-skeleton-pulse 1.4s ease-in-out infinite;
}

@keyframes gm-skeleton-pulse {
	0%, 100% { opacity: 1; }
	50% { opacity: 0.4; }
}
</style>
