<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div class="gm-members">
		<div class="gm-members__scroll">
			<div v-if="showSearch" class="gm-members__header">
				<NcTextField class="gm-members__search"
					v-model="search"
					:label="t('group_manager', 'Search members')"
				:label-outside="true"
				:placeholder="t('group_manager', 'Search members')"
					:show-trailing-button="search.length > 0"
					@update:model-value="onSearchInput"
					@trailing-button-click="search = ''">
					<template #icon>
						<Magnify :size="18" />
					</template>
				</NcTextField>
			</div>

			<div class="gm-members__list">
				<NcNoteCard v-if="loadError" type="error" class="gm-members__error">
					{{ loadError }}
					<NcButton variant="secondary" @click="reload">
						{{ t('group_manager', 'Try again') }}
					</NcButton>
				</NcNoteCard>

				<div v-else-if="loading && members.length === 0" class="gm-members__loading">
					<NcLoadingIcon :size="24" />
				</div>

				<p v-else-if="members.length === 0" class="gm-members__empty">
					{{ t('group_manager', 'No members found.') }}
				</p>

				<ul v-else class="gm-members__grid">
					<li v-for="member in members" :key="member.uid" class="gm-members__item">
						<MemberAvatar :uid="member.uid" :name="member.displayName" :size="28" class="gm-members__item-avatar" />
						<span class="gm-members__item-text">
							<span class="gm-members__item-name" :title="memberNameTitle(member)">{{ member.displayName }}</span>
							<span v-if="detailLine(member)" class="gm-members__item-uid">{{ detailLine(member) }}</span>
						</span>
						<span v-if="!member.enabled" class="gm-members__item-disabled">{{ t('group_manager', 'disabled') }}</span>
					</li>
				</ul>

				<p v-if="loadMoreError" class="gm-members__load-more-error">
					{{ loadMoreError }}
				</p>

				<NcButton v-if="hasMore && !loadError"
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
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import MemberAvatar from './MemberAvatar.vue'
import { memberDetailLine, memberNameTitle } from '../utils/members.js'
import Magnify from 'vue-material-design-icons/Magnify.vue'
import { fetchGroupMembers } from '../services/api.js'
import { extractErrorMessage } from '../utils/errors.js'

const PAGE_SIZE = 50
const SEARCH_DEBOUNCE_MS = 300
const SEARCH_MIN_MEMBERS = 10

export default {
	name: 'GroupMembersList',

	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
		Magnify,
		MemberAvatar,
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
			// GM-05: whether the API answered with a full page, sent by the
			// server independently of `total` (which a backend can report as
			// unknown -- count() returning false -- even though it can still
			// page reliably). Relying on `total` here used to strand a group's
			// members past the first page whenever the backend couldn't
			// answer count().
			hasMore: false,
			// GM-03/GM-04: '' (not shown), or the message from a failed
			// reload -- replaces the list instead of silently leaving it
			// empty or stale (see reload()). loadMoreError is the same idea
			// for loadMore(), kept separate so a failed "next page" doesn't
			// hide the members already on screen.
			loadError: '',
			loadMoreError: '',
			// Bumped whenever a request in flight must stop being able to
			// win: on every filter change (immediately, before the debounce
			// even fires) and at the start of every reload(). A response is
			// only applied if this hasn't moved since the request started --
			// otherwise it's an answer to a search that's no longer current.
			membersSeq: 0,
			search: '',
			offset: 0,
			loading: true,
			loadingMore: false,
			searchTimer: null,
		}
	},

	computed: {
		// A filter box on a handful of rows is noise; it stays visible
		// while a term is typed so the list can always be un-filtered.
		showSearch() {
			return this.search !== '' || (this.total ?? this.members.length) >= SEARCH_MIN_MEMBERS
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
		this.membersSeq++
	},

	methods: {
		t,
		detailLine: memberDetailLine,
		memberNameTitle,

		onSearchInput() {
			// Invalidates anything already in flight right away, before the
			// debounce below even fires -- otherwise a request started for
			// the previous term could still win the race against this one.
			this.membersSeq++
			clearTimeout(this.searchTimer)
			this.searchTimer = setTimeout(() => this.reload(), SEARCH_DEBOUNCE_MS)
		},

		async reload() {
			const seq = ++this.membersSeq
			this.loading = true
			this.loadError = ''
			this.loadMoreError = ''
			this.offset = 0
			try {
				const data = await fetchGroupMembers(this.groupId, {
					search: this.search,
					pageSize: PAGE_SIZE,
					offset: 0,
				})
				if (seq !== this.membersSeq) {
					return // superseded by a newer search/reload -- not the latest answer
				}
				this.members = data.members
				this.total = data.total
				this.hasMore = data.hasMore
				this.offset = data.members.length
			} catch (err) {
				if (seq !== this.membersSeq) {
					return
				}
				// Surfaced, not swallowed: a failed load must not read as "no
				// members" (GM-04). members/total are left as they were.
				this.loadError = extractErrorMessage(err, t('group_manager', 'Could not load members.'))
			} finally {
				if (seq === this.membersSeq) {
					this.loading = false
				}
			}
		},

		async loadMore() {
			if (this.loadingMore) {
				return
			}
			// Not bumped: loadMore() continues the CURRENT search generation
			// rather than starting a new one, but still checked below so a
			// filter change mid-flight discards this page instead of
			// appending results for a search that's no longer current.
			const seq = this.membersSeq
			this.loadingMore = true
			this.loadMoreError = ''
			try {
				const data = await fetchGroupMembers(this.groupId, {
					search: this.search,
					pageSize: PAGE_SIZE,
					offset: this.offset,
				})
				if (seq !== this.membersSeq) {
					return
				}
				this.members = this.members.concat(data.members)
				this.total = data.total
				this.hasMore = data.hasMore
				this.offset += data.members.length
			} catch (err) {
				if (seq !== this.membersSeq) {
					return
				}
				this.loadMoreError = extractErrorMessage(err, t('group_manager', 'Could not load more members.'))
			} finally {
				if (seq === this.membersSeq) {
					this.loadingMore = false
				}
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
	display: flex;
	flex-direction: column;
}

/* Only the list scrolls; the search above it stays put. */
.gm-members__list {
	flex: 1;
	min-height: 0;
	overflow-y: auto;
	scrollbar-width: thin;
	scrollbar-gutter: stable;
	padding-bottom: 8px;
}

.gm-members__header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 16px;
	margin-bottom: 12px;
}

.gm-members__search {
	max-width: 320px;
	margin: 0 !important;
}

.gm-members__loading {
	display: flex;
	justify-content: center;
	padding: 20px 0;
}

.gm-members__empty {
	color: var(--color-text-maxcontrast);
}

.gm-members__error {
	display: flex;
	flex-direction: column;
	align-items: flex-start;
	gap: 8px;
}

.gm-members__load-more-error {
	margin: 8px 0 0;
	color: var(--color-error-text);
	font-size: 13px;
}

.gm-members__grid {
	display: flex;
	flex-direction: column;
	max-width: 720px;
	container-type: inline-size;
	list-style: none;
	margin: 0;
	padding: 0;
}

.gm-members__item {
	display: flex;
	align-items: center;
	gap: 10px;
	min-height: 44px;
	padding: 6px 2px;
	border-radius: var(--border-radius);
	min-width: 0;
}

.gm-members__item-avatar {
	flex-shrink: 0;
}

.gm-members__item-text {
	flex: 1;
	min-width: 0;
	display: flex;
	align-items: baseline;
	gap: 8px;
}

@container (max-width: 500px) {
	.gm-members__item-text {
		flex-direction: column;
		align-items: flex-start;
		gap: 0;
	}
}

.gm-members__item-uid {
	flex: 0 1 auto;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.gm-members__item-name {
	flex-shrink: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-size: 14px;
}

.gm-members__item-email {
	flex: 0 1 45%;
	min-width: 0;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	text-align: right;
	color: var(--color-text-maxcontrast);
	font-size: 12px;
}

.gm-members__item-disabled {
	flex: 0 0 auto;
	text-align: right;
	color: var(--color-text-maxcontrast);
	font-style: italic;
	font-size: 12px;
}

.gm-members__more {
	margin-top: 12px;
}
</style>
