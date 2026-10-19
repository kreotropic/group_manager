<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div class="gm-detail-panel">
		<div v-if="loading" class="gm-detail-panel__loading">
			<NcLoadingIcon :size="32" />
		</div>

		<NcNoteCard v-else-if="loadError" type="error">
			{{ loadError }}
		</NcNoteCard>

		<template v-else-if="details">
			<div class="gm-detail-panel__fixed">
				<header class="gm-detail__header">
					<NcAvatar :user="details.id"
						:display-name="details.displayname"
						:size="44"
						:hide-status="true"
						:disable-menu="true"
						:disable-tooltip="true"
						:class="{ 'gm-user__avatar--disabled': !details.enabled }" />
					<div class="gm-detail__titles">
						<h2 class="gm-detail__name">
							<span class="gm-detail__name-text">{{ details.displayname || details.id }}</span>
							<span v-if="!details.enabled" class="gm-detail__badge">{{ t('group_manager', 'Disabled') }}</span>
							<span v-if="summary.isAdmin" class="gm-detail__badge">{{ t('group_manager', 'Administrator') }}</span>
							<span v-if="summary.backend === 'ldap'"
								class="gm-detail__badge"
								:title="t('group_manager', 'Some fields of this account are managed by LDAP and can\'t be changed here.')">
								{{ t('group_manager', 'LDAP') }}
							</span>
						</h2>
						<p v-if="subtitleText" class="gm-detail__subtitle" :title="subtitleText">{{ subtitleText }}</p>
					</div>

					<NcActions class="gm-detail__menu"
						:force-menu="true"
						:aria-label="t('group_manager', 'Account actions')">
						<NcActionButton v-if="details.enabled"
							close-after-click
							:disabled="isSelf || busy"
							:title="isSelf ? t('group_manager', 'You can\'t disable your own account') : ''"
							@click="toggleEnabled(false)">
							<template #icon>
								<AccountOffOutline :size="20" />
							</template>
							{{ t('group_manager', 'Disable account') }}
						</NcActionButton>
						<NcActionButton v-else
							close-after-click
							:disabled="busy"
							@click="toggleEnabled(true)">
							<template #icon>
								<AccountCheckOutline :size="20" />
							</template>
							{{ t('group_manager', 'Enable account') }}
						</NcActionButton>
						<NcActionButton v-if="details.email"
							close-after-click
							:disabled="busy"
							@click="resendWelcome">
							<template #icon>
								<EmailOutline :size="20" />
							</template>
							{{ t('group_manager', 'Resend welcome email') }}
						</NcActionButton>
						<NcActionSeparator />
						<NcActionButton close-after-click
							:disabled="isSelf || busy"
							@click="showDeleteDialog = true">
							<template #icon>
								<TrashCanOutline :size="20" />
							</template>
							{{ t('group_manager', 'Delete account') }}
						</NcActionButton>
					</NcActions>
				</header>

				<nav class="gm-detail__tabs">
					<div class="gm-detail__tabs-track">
						<button type="button"
							class="gm-detail__tab"
							:class="{ 'gm-detail__tab--active': activeTab === 'account' }"
							@click="activeTab = 'account'">
							<span class="gm-detail__tab-label">{{ t('group_manager', 'Account & profile') }}</span>
							<span v-if="activeTab !== 'account' && accountPending.hasPendingChanges" class="gm-detail__tab-dot" />
						</button>
						<button type="button"
							class="gm-detail__tab"
							:class="{ 'gm-detail__tab--active': activeTab === 'groups' }"
							@click="activeTab = 'groups'">
							<span class="gm-detail__tab-label">{{ t('group_manager', 'Groups') }}</span>
							<span v-if="activeTab !== 'groups' && groupsPending.hasPendingChanges" class="gm-detail__tab-dot" />
						</button>
					</div>
				</nav>
			</div>

			<div class="gm-detail-panel__body">
				<UserAccountForm v-show="activeTab === 'account'"
					ref="accountForm"
					:details="details"
					:summary="summary"
					:editable="editable"
					:options="options"
					class="gm-detail__tab-content"
					@changed="onChanged"
					@pending-changed="onAccountPending" />

				<UserGroupsManager v-show="activeTab === 'groups'"
					ref="groupsManager"
					:uid="details.id"
					:member-of="details.groups || []"
					:admin-of="details.subadmin || []"
					:groups="groups"
					class="gm-detail__tab-content"
					@changed="onGroupsChanged"
					@pending-changed="onGroupsPending" />
			</div>

			<div v-if="showFooter" class="gm-detail-panel__footer">
				<span class="gm-detail__footer-summary">{{ footerSummaryText }}</span>
				<NcButton :disabled="applying" @click="discardAll">
					{{ t('group_manager', 'Discard') }}
				</NcButton>
				<NcButton class="gm-detail__apply" variant="primary" :disabled="applying" @click="applyAll">
					<template v-if="applying" #icon>
						<NcLoadingIcon :size="18" />
					</template>
					{{ applying ? t('group_manager', 'Applying…') : t('group_manager', 'Apply') }}
				</NcButton>
			</div>

			<NcDialog :open="showDeleteDialog"
				dialog-classes="gm-dialog"
				:name="t('group_manager', 'Delete account?')"
				:buttons="deleteButtons"
				size="small"
				@update:open="onDeleteDialogUpdateOpen">
				<div class="gm-delete-dialog">
					<NcNoteCard type="warning">
						{{ t('group_manager', 'This will permanently delete the account "{name}" ({uid}), including all their files. This cannot be undone.', { name: details.displayname || details.id, uid: details.id }) }}
					</NcNoteCard>
					<!-- Disabling an administrator already asks first; deleting one
					     says so here, before the same confirmation. -->
					<NcNoteCard v-if="summary.isAdmin" type="error">
						{{ t('group_manager', 'This is an administrator account.') }}
					</NcNoteCard>
					<NcNoteCard v-if="deleteError" type="error">
						{{ deleteError }}
					</NcNoteCard>
				</div>
			</NcDialog>
		</template>
	</div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { showError, showSuccess } from '@nextcloud/dialogs'
import { confirmPassword } from '@nextcloud/password-confirmation'
import '@nextcloud/password-confirmation/style.css'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcActionSeparator from '@nextcloud/vue/components/NcActionSeparator'
import NcActions from '@nextcloud/vue/components/NcActions'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import AccountCheckOutline from 'vue-material-design-icons/AccountCheckOutline.vue'
import AccountOffOutline from 'vue-material-design-icons/AccountOffOutline.vue'
import EmailOutline from 'vue-material-design-icons/EmailOutline.vue'
import TrashCanOutline from 'vue-material-design-icons/TrashCanOutline.vue'
import UserAccountForm from './UserAccountForm.vue'
import UserGroupsManager from './UserGroupsManager.vue'
import {
	deleteUser,
	fetchUserDetails,
	fetchUserEditableFields,
	fetchUserSummary,
	resendWelcomeEmail,
	setUserEnabled,
} from '../services/api.js'
import { extractErrorMessage } from '../utils/errors.js'

const EMPTY_PENDING = { hasPendingChanges: false, count: 0 }

export default {
	name: 'UserDetail',

	components: {
		NcActionButton,
		NcActionSeparator,
		NcActions,
		NcAvatar,
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		AccountCheckOutline,
		AccountOffOutline,
		EmailOutline,
		TrashCanOutline,
		UserAccountForm,
		UserGroupsManager,
	},

	props: {
		uid: {
			type: String,
			required: true,
		},
		options: {
			type: Object,
			required: true,
		},
		/** Every group, from App.vue's list. */
		groups: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['updated', 'deleted', 'groups-changed', 'pending-changed'],

	data() {
		return {
			summary: null,
			details: null,
			editable: [],
			loading: true,
			loadError: '',
			activeTab: 'account',
			accountPending: { ...EMPTY_PENDING },
			groupsPending: { ...EMPTY_PENDING },
			applying: false,
			// Enable/disable or resend in flight.
			busy: false,
			showDeleteDialog: false,
			deleteError: '',
		}
	},

	computed: {
		isSelf() {
			return this.details?.id === this.options.currentUser
		},

		groupCount() {
			return (this.details?.groups ?? []).length
		},

		subtitleText() {
			if (!this.details) {
				return ''
			}
			const parts = []
			if (this.details.id !== this.details.displayname) {
				parts.push(this.details.id)
			}
			if (this.details.email) {
				parts.push(this.details.email)
			}
			parts.push(this.groupCount
				? n('group_manager', '%n group', '%n groups', this.groupCount)
				: t('group_manager', 'no groups'))
			return parts.join(' · ')
		},

		hasPendingChanges() {
			return this.accountPending.hasPendingChanges || this.groupsPending.hasPendingChanges
		},

		showFooter() {
			return this.hasPendingChanges || this.applying
		},

		footerSummaryText() {
			const parts = []
			parts.push(this.accountPending.count > 0
				? n('group_manager', '%n change in Account & profile', '%n changes in Account & profile', this.accountPending.count)
				: t('group_manager', 'none in Account & profile'))
			parts.push(this.groupsPending.count > 0
				? n('group_manager', '%n change in Groups', '%n changes in Groups', this.groupsPending.count)
				: t('group_manager', 'none in Groups'))
			return parts.join(' · ')
		},

		deleteButtons() {
			return [
				{ label: t('group_manager', 'Cancel'), type: 'reset', callback: () => true },
				{
					label: t('group_manager', 'Delete'),
					type: 'submit',
					variant: 'error',
					callback: this.confirmDelete,
				},
			]
		},
	},

	watch: {
		uid: {
			immediate: true,
			handler() {
				this.load()
			},
		},

		applying() {
			this.emitPendingState()
		},
	},

	methods: {
		t,

		async load() {
			this.loading = true
			this.loadError = ''
			try {
				await this.fetchAll()
			} catch (err) {
				this.loadError = extractErrorMessage(err, t('group_manager', 'Could not load this account.'))
			} finally {
				this.loading = false
			}
		},

		async fetchAll() {
			const [summary, details, editable] = await Promise.all([
				fetchUserSummary(this.uid),
				fetchUserDetails(this.uid),
				fetchUserEditableFields(this.uid).catch(() => []),
			])
			this.summary = summary
			this.details = details
			this.editable = editable
		},

		/**
		 * Silent (no `loading`): that would unmount both tabs, losing a
		 * failed field still staged or the result just shown.
		 */
		async refresh() {
			try {
				await this.fetchAll()
				this.$emit('updated', this.summary)
			} catch {
				// Keep what is shown; the change itself already reported.
			}
		},

		async onChanged() {
			await this.refresh()
		},

		async onGroupsChanged() {
			await this.refresh()
			this.$emit('groups-changed')
		},

		onAccountPending(payload) {
			this.accountPending = payload
			this.emitPendingState()
		},

		onGroupsPending(payload) {
			this.groupsPending = payload
			this.emitPendingState()
		},

		emitPendingState() {
			this.$emit('pending-changed', { hasPendingChanges: this.hasPendingChanges, applying: this.applying })
		},

		discardAll() {
			this.$refs.accountForm?.discardChanges()
			this.$refs.groupsManager?.discardChanges()
		},

		async applyAll() {
			try {
				await confirmPassword()
			} catch {
				return
			}
			this.applying = true
			try {
				// One after the other: both end with a refresh of the same
				// account, and core's edit rate limit is per user anyway.
				if (this.accountPending.hasPendingChanges) {
					await this.$refs.accountForm.applyChanges()
				}
				if (this.groupsPending.hasPendingChanges) {
					await this.$refs.groupsManager.applyChanges()
				}
			} finally {
				this.applying = false
			}
		},

		async toggleEnabled(enabled) {
			if (!enabled && this.summary.isAdmin
				&& !window.confirm(t('group_manager', '"{name}" is an administrator. Disable this account anyway?', { name: this.details.displayname || this.details.id }))) {
				return
			}
			this.busy = true
			try {
				await confirmPassword()
				await setUserEnabled(this.details.id, enabled)
				showSuccess(enabled ? t('group_manager', 'Account enabled.') : t('group_manager', 'Account disabled.'))
				await this.refresh()
			} catch (err) {
				if (err?.response) {
					showError(extractErrorMessage(err, t('group_manager', 'Could not change the account status.')))
				}
			} finally {
				this.busy = false
			}
		},

		async resendWelcome() {
			this.busy = true
			try {
				await confirmPassword()
				await resendWelcomeEmail(this.details.id)
				showSuccess(t('group_manager', 'Welcome email sent to {email}.', { email: this.details.email }))
			} catch (err) {
				if (err?.response) {
					showError(extractErrorMessage(err, t('group_manager', 'Could not send the welcome email.')))
				}
			} finally {
				this.busy = false
			}
		},

		onDeleteDialogUpdateOpen(value) {
			this.showDeleteDialog = value
			if (!value) {
				this.deleteError = ''
			}
		},

		/**
		 * Returning `false` keeps the dialog open (NcDialogButton awaits this).
		 */
		async confirmDelete() {
			try {
				await confirmPassword()
				await deleteUser(this.details.id)
				this.$emit('deleted', this.details.id)
				return true
			} catch (err) {
				this.deleteError = extractErrorMessage(err, t('group_manager', 'Could not delete the account.'))
				return false
			}
		},
	},
}
</script>

<style scoped src="../styles/detail.css"></style>

<style scoped>
.gm-user__avatar--disabled {
	opacity: .5;
}
</style>
