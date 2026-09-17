<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div class="gm-members">
		<div class="gm-members__scroll">
			<div class="gm-members__header">
				<h3 class="gm-members__title">
					{{ t('group_manager', 'Members') }}
					<span v-if="total !== null" class="gm-members__total">({{ total }})</span>
				</h3>
				<NcTextField class="gm-members__search"
					v-model="search"
					:label="t('group_manager', 'Search members')"
					:show-trailing-button="search.length > 0"
					@update:model-value="onSearchInput"
					@trailing-button-click="search = ''">
					<template #icon>
						<Magnify :size="18" />
					</template>
				</NcTextField>
			</div>

			<div v-if="loading && members.length === 0" class="gm-members__loading">
				<NcLoadingIcon :size="24" />
			</div>

			<p v-else-if="members.length === 0" class="gm-members__empty">
				{{ t('group_manager', 'No members found.') }}
			</p>

			<ul v-else class="gm-members__grid">
				<li v-for="member in members" :key="member.uid" class="gm-members__item">
					<NcAvatar :user="member.uid"
						:display-name="member.displayName"
						:size="26"
						:disable-menu="true"
						:disable-tooltip="true"
						class="gm-members__item-avatar" />
					<span class="gm-members__item-name">{{ member.displayName }}</span>
					<span v-if="!member.enabled" class="gm-members__item-disabled">{{ t('group_manager', 'disabled') }}</span>
					<span v-else-if="member.email" class="gm-members__item-email">{{ member.email }}</span>
					<span v-else class="gm-members__item-email">{{ member.uid }}</span>
				</li>
			</ul>

			<NcButton v-if="hasMore"
				class="gm-members__more"
				:disabled="loadingMore"
				@click="loadMore">
				<template v-if="loadingMore" #icon>
					<NcLoadingIcon :size="18" />
				</template>
				{{ t('group_manager', 'Load more') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import { fetchGroupMembers } from '../services/api.js'

const PAGE_SIZE = 50
const SEARCH_DEBOUNCE_MS = 300

export default {
	name: 'GroupMembersList',

	components: {
		NcAvatar,
		NcButton,
		NcLoadingIcon,
		NcTextField,
		Magnify,
	},

	props: {
		groupId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			members: [],
			total: null,
			search: '',
			offset: 0,
			loading: true,
			loadingMore: false,
			searchTimer: null,
		}
	},

	computed: {
		hasMore() {
			return this.total !== null && this.members.length < this.total
		},
	},

	watch: {
		groupId() {
			this.search = ''
			this.reload()
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
			if (this.loadingMore) {
				return
			}
			this.loadingMore = true
			try {
				const data = await fetchGroupMembers(this.groupId, {
					search: this.search,
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
	},
}
</script>

<style scoped>
.gm-members {
	display: flex;
	flex-direction: column;
	height: 100%;
	min-height: 0;
}

.gm-members__scroll {
	flex: 1;
	min-height: 0;
	overflow-y: auto;
	padding-bottom: 8px;
}

.gm-members__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16px;
	margin-bottom: 12px;
}

.gm-members__title {
	margin: 0;
	font-size: 16px;
}

.gm-members__total {
	color: var(--color-text-maxcontrast);
	font-weight: normal;
}

.gm-members__search {
	max-width: 220px;
}

.gm-members__loading {
	display: flex;
	justify-content: center;
	padding: 20px 0;
}

.gm-members__empty {
	color: var(--color-text-maxcontrast);
}

.gm-members__grid {
	display: flex;
	flex-direction: column;
	max-width: 560px;
	list-style: none;
	margin: 0;
	padding: 0;
}

.gm-members__item {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 6px 2px;
	border-radius: var(--border-radius);
	min-width: 0;
}

.gm-members__item-avatar {
	flex-shrink: 0;
}

.gm-members__item-name {
	flex-shrink: 0;
	max-width: 45%;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-size: 14px;
}

.gm-members__item-email {
	flex: 1;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	text-align: right;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.gm-members__item-disabled {
	flex: 1;
	min-width: 0;
	text-align: right;
	color: var(--color-text-maxcontrast);
	font-style: italic;
	font-size: 12px;
}

.gm-members__more {
	margin-top: 12px;
}
</style>
