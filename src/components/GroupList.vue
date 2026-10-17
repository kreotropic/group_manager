<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<nav class="gm-list" :aria-label="t('group_manager', 'Groups')">
		<div class="gm-list__top">
			<div class="gm-list__header">
				<div class="gm-list__modes" role="group" :aria-label="t('group_manager', 'Show')">
					<button type="button"
						class="gm-list__mode gm-list__mode--active"
						aria-pressed="true">
						{{ t('group_manager', 'Groups') }}
					</button>
					<button type="button"
						class="gm-list__mode"
						aria-pressed="false"
						@click="$emit('switch-mode', 'users')">
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

	emits: ['select', 'create', 'toggle-quick-access', 'switch-mode'],

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

<style scoped src="../styles/list.css"></style>
