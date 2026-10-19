<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<NcDialog :open="open"
		dialog-classes="gm-dialog"
		:name="t('group_manager', 'Create user')"
		:buttons="buttons"
		is-form
		size="normal"
		@update:open="onUpdateOpen"
		@closing="reset">
		<div class="gm-create-user">
			<div class="gm-create-user__grid">
				<div class="gm-create-user__select">
					<label class="gm-create-user__label" :for="ids.uid">
						{{ options.generateUserId ? t('group_manager', 'User ID (optional)') : t('group_manager', 'User ID') }}
					</label>
					<NcTextField :id="ids.uid"
						v-model="uid"
						:label-outside="true"
						:helper-text="options.generateUserId
							? t('group_manager', 'Leave empty to have one generated. Cannot be changed later.')
							: t('group_manager', 'Used to log in. Cannot be changed later.')"
						:error="errorField === 'uid'"
						autocomplete="off"
						autofocus />
				</div>
				<div class="gm-create-user__select">
					<label class="gm-create-user__label" :for="ids.displayName">{{ t('group_manager', 'Display name') }}</label>
					<NcTextField :id="ids.displayName"
						v-model="displayName"
						:label-outside="true"
						:placeholder="uid" />
				</div>
				<div class="gm-create-user__select">
					<label class="gm-create-user__label" :for="ids.email">
						{{ options.requireEmail ? t('group_manager', 'Email') : t('group_manager', 'Email (optional)') }}
					</label>
					<NcTextField :id="ids.email"
						v-model="email"
						type="email"
						:label-outside="true"
						:error="errorField === 'email'"
						autocomplete="off" />
				</div>
				<div class="gm-create-user__select">
					<label class="gm-create-user__label" :for="ids.password">{{ t('group_manager', 'Password') }}</label>
					<NcPasswordField :id="ids.password"
						v-model="password"
						:label-outside="true"
						:helper-text="passwordHint"
						:error="errorField === 'password'"
						autocomplete="new-password" />
				</div>

				<div class="gm-create-user__select">
					<label class="gm-create-user__label" :for="ids.groups">{{ t('group_manager', 'Groups') }}</label>
					<NcSelect v-model="selectedGroups"
						:input-id="ids.groups"
						:options="groupOptions"
						:multiple="true"
						:close-on-select="false"
						:placeholder="t('group_manager', 'None')"
						label="displayName"
						:label-outside="true" />
				</div>

				<div class="gm-create-user__select">
					<label class="gm-create-user__label" :for="ids.quota">{{ t('group_manager', 'Personal quota') }}</label>
					<NcSelect v-model="quota"
						:input-id="ids.quota"
						:options="quotaOptions"
						:clearable="false"
						:taggable="true"
						:create-option="(text) => ({ id: text.trim(), label: text.trim() })"
						label="label"
						:label-outside="true" />
				</div>

				<div class="gm-create-user__select">
					<label class="gm-create-user__label" :for="ids.language">{{ t('group_manager', 'Language') }}</label>
					<NcSelect v-model="language"
						:input-id="ids.language"
						:options="options.languages || []"
						:placeholder="t('group_manager', 'Server default')"
						label="name"
						:label-outside="true" />
				</div>
			</div>

			<NcNoteCard v-if="errorMessage" type="error">
				{{ errorMessage }}
			</NcNoteCard>
		</div>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { translate as t } from '@nextcloud/l10n'
import { confirmPassword } from '@nextcloud/password-confirmation'
import '@nextcloud/password-confirmation/style.css'
import { createUser } from '../services/api.js'
import { extractErrorMessage } from '../utils/errors.js'
import { isValidQuota, quotaChoices } from '../utils/quota.js'

export default {
	name: 'CreateUserDialog',

	components: {
		NcDialog,
		NcNoteCard,
		NcPasswordField,
		NcSelect,
		NcTextField,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
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
		/** Ticked when the dialog opens (the group being looked at, if any). */
		initialGroupIds: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['update:open', 'created'],

	data() {
		return {
			uid: '',
			displayName: '',
			email: '',
			password: '',
			selectedGroups: [],
			quota: null,
			language: null,
			errorMessage: '',
			errorField: '',
			ids: {
				uid: 'gm-create-user-uid',
				displayName: 'gm-create-user-displayname',
				email: 'gm-create-user-email',
				password: 'gm-create-user-password',
				groups: 'gm-create-user-groups',
				quota: 'gm-create-user-quota',
				language: 'gm-create-user-language',
			},
		}
	},

	computed: {
		// LDAP groups take no members from here.
		groupOptions() {
			return this.groups.filter((g) => g.backend !== 'ldap')
		},

		quotaOptions() {
			return quotaChoices(this.options)
		},

		passwordHint() {
			if (this.options.sendEmail) {
				return t('group_manager', 'Leave empty to email the user a link to set their own.')
			}
			return t('group_manager', 'New-user emails are turned off, so a password is needed.')
		},

		buttons() {
			return [
				{ label: t('group_manager', 'Cancel'), type: 'reset', callback: () => true },
				{
					label: t('group_manager', 'Create'),
					type: 'submit',
					variant: 'primary',
					callback: this.submit,
				},
			]
		},
	},

	watch: {
		open(value) {
			if (value) {
				this.reset()
				this.selectedGroups = this.groupOptions.filter((g) => this.initialGroupIds.includes(g.id))
			}
		},
	},

	methods: {
		t,

		onUpdateOpen(value) {
			this.$emit('update:open', value)
		},

		reset() {
			this.uid = ''
			this.displayName = ''
			this.email = ''
			this.password = ''
			this.selectedGroups = []
			this.quota = this.quotaOptions[0] ?? null
			this.language = null
			this.errorMessage = ''
			this.errorField = ''
		},

		fail(field, message) {
			this.errorField = field
			this.errorMessage = message
			return false
		},

		/**
		 * Returning `false` keeps the dialog open (NcDialogButton awaits this).
		 * Core does the real validation (id format, password policy, email);
		 * these checks only catch what would obviously fail.
		 */
		async submit() {
			const uid = this.uid.trim()
			const email = this.email.trim()
			if (uid === '' && !this.options.generateUserId) {
				return this.fail('uid', t('group_manager', 'User ID cannot be empty.'))
			}
			if (uid.includes('/')) {
				return this.fail('uid', t('group_manager', 'User ID cannot contain "/".'))
			}
			if (email === '' && this.options.requireEmail) {
				return this.fail('email', t('group_manager', 'An email address is required on this server.'))
			}
			if (this.password === '' && email === '') {
				return this.fail('password', t('group_manager', 'Give a password, or an email address to send a link to set one.'))
			}
			if (this.password === '' && !this.options.sendEmail) {
				return this.fail('password', t('group_manager', 'A password is needed: new-user emails are turned off.'))
			}
			if (this.quota && !isValidQuota(this.quota.id)) {
				return this.fail('', t('group_manager', 'Not a size: use a number with a unit, like 15 GB.'))
			}

			try {
				await confirmPassword()
			} catch {
				return false
			}
			try {
				const id = await createUser({
					userid: uid,
					password: this.password,
					displayName: this.displayName.trim(),
					email,
					groups: this.selectedGroups.map((g) => g.id),
					quota: this.quota && this.quota.id !== 'default' ? this.quota.id : '',
					language: this.language?.code ?? '',
				})
				this.$emit('created', id)
				return true
			} catch (err) {
				return this.fail('', extractErrorMessage(err, t('group_manager', 'Could not create the user.')))
			}
		},
	},
}
</script>

<style scoped>
.gm-create-user {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 4px 4px 12px;
}

.gm-create-user__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
	gap: 16px 20px;
	align-items: start;
}

.gm-create-user__select {
	display: flex;
	flex-direction: column;
	gap: 4px;
	min-width: 0;
}

.gm-create-user__select :deep(.input-field) {
	margin: 0 !important;
}

.gm-create-user__select :deep(.v-select.select) {
	width: 100%;
	min-width: 0;
	margin: 0;
}

.gm-create-user__label {
	font-size: 14px;
	color: var(--color-text-maxcontrast);
}
</style>
