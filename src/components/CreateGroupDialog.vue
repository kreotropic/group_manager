<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<NcDialog :open="open"
		:name="t('group_manager', 'Create group')"
		:buttons="buttons"
		is-form
		size="small"
		@update:open="onUpdateOpen"
		@closing="reset">
		<div class="gm-create-dialog">
			<NcTextField ref="gidField"
				v-model="gid"
				:label="t('group_manager', 'Group ID')"
				:helper-text="t('group_manager', 'Cannot be changed later.')"
				:error="Boolean(fieldError)"
				autofocus />
			<NcTextField v-model="displayName"
				:label="t('group_manager', 'Display name (optional)')"
				:placeholder="gid" />
			<NcNoteCard v-if="errorMessage" type="error">
				{{ errorMessage }}
			</NcNoteCard>
		</div>
	</NcDialog>
</template>

<script>
import NcDialog from '@nextcloud/vue/components/NcDialog'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { translate as t } from '@nextcloud/l10n'
import { confirmPassword } from '@nextcloud/password-confirmation'
import '@nextcloud/password-confirmation/style.css'
import { createGroup } from '../services/api.js'
import { extractErrorMessage } from '../utils/errors.js'

export default {
	name: 'CreateGroupDialog',

	components: {
		NcDialog,
		NcTextField,
		NcNoteCard,
	},

	props: {
		open: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:open', 'created'],

	data() {
		return {
			gid: '',
			displayName: '',
			errorMessage: '',
			fieldError: false,
		}
	},

	computed: {
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

	methods: {
		t,

		onUpdateOpen(value) {
			this.$emit('update:open', value)
			if (!value) {
				this.reset()
			}
		},

		reset() {
			this.gid = ''
			this.displayName = ''
			this.errorMessage = ''
			this.fieldError = false
		},

		/**
		 * Returning `false` keeps the dialog open (NcDialogButton awaits this).
		 */
		async submit() {
			const gid = this.gid.trim()
			if (gid === '') {
				this.fieldError = true
				this.errorMessage = t('group_manager', 'Group ID cannot be empty.')
				return false
			}

			try {
				await confirmPassword()
				const group = await createGroup(gid, this.displayName.trim())
				this.$emit('created', group)
				this.reset()
				return true
			} catch (err) {
				this.fieldError = true
				this.errorMessage = extractErrorMessage(err, t('group_manager', 'Could not create the group.'))
				return false
			}
		},
	},
}
</script>

<style scoped>
.gm-create-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 4px 12px;
}
</style>
