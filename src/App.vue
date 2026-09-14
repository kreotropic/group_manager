<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div class="gm-app">
		<div class="gm-header">
			<h2>{{ t('group_manager', 'Group Manager') }}</h2>
			<p class="gm-header__sub">
				{{ t('group_manager', 'Browse local and LDAP groups and manage local group membership.') }}
			</p>
		</div>

		<NcNoteCard v-if="loadError" type="error">
			{{ loadError }}
		</NcNoteCard>

		<div class="gm-layout">
			<GroupList :groups="groups"
				:loading="loading"
				:selected-id="selectedId"
				@select="selectedId = $event"
				@create="showCreateDialog = true" />

			<div class="gm-detail">
				<NcEmptyContent :name="t('group_manager', 'Select a group')"
					:description="t('group_manager', 'Choose a group on the left to see its details and members.')">
					<template #icon>
						<AccountMultiple :size="48" />
					</template>
				</NcEmptyContent>
			</div>
		</div>

		<CreateGroupDialog :open="showCreateDialog"
			@update:open="showCreateDialog = $event"
			@created="onGroupCreated" />
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import AccountMultiple from 'vue-material-design-icons/AccountMultiple.vue'
import GroupList from './components/GroupList.vue'
import CreateGroupDialog from './components/CreateGroupDialog.vue'
import { fetchGroups } from './services/api.js'
import { extractErrorMessage } from './utils/errors.js'

export default {
	name: 'App',

	components: {
		NcNoteCard,
		NcEmptyContent,
		AccountMultiple,
		GroupList,
		CreateGroupDialog,
	},

	data() {
		return {
			groups: [],
			loading: true,
			loadError: '',
			selectedId: null,
			showCreateDialog: false,
		}
	},

	async mounted() {
		await this.loadGroups()
	},

	methods: {
		t,

		async loadGroups() {
			this.loading = true
			this.loadError = ''
			try {
				this.groups = await fetchGroups()
			} catch (err) {
				this.loadError = extractErrorMessage(err, t('group_manager', 'Could not load groups.'))
			} finally {
				this.loading = false
			}
		},

		onGroupCreated(group) {
			this.groups.push(group)
			this.groups.sort((a, b) => a.displayName.localeCompare(b.displayName))
			this.selectedId = group.id
			this.showCreateDialog = false
		},
	},
}
</script>

<style scoped>
.gm-app {
	max-width: 100%;
}

.gm-header {
	margin-bottom: 16px;
}

.gm-header__sub {
	color: var(--color-text-maxcontrast);
}

.gm-layout {
	display: flex;
	height: 70vh;
	min-height: 480px;
	max-height: 720px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	overflow: hidden;
	position: relative;
}

.gm-detail {
	flex: 1;
	display: flex;
	align-items: center;
	justify-content: center;
	overflow-y: auto;
}
</style>
