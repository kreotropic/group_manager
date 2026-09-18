<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<NcDialog :open="open"
		:name="t('group_manager', 'Create group folder')"
		:buttons="buttons"
		is-form
		size="small"
		@update:open="onUpdateOpen"
		@closing="reset">
		<div class="gm-create-folder-dialog">
			<NcTextField ref="mountPointField"
				v-model="mountPoint"
				:label="t('group_manager', 'Folder name')"
				:error="Boolean(errorMessage)"
				autofocus />
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
import { createGroupFolder } from '../services/api.js'
import { extractErrorMessage } from '../utils/errors.js'

export default {
	name: 'CreateGroupFolderDialog',

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
		groupId: {
			type: String,
			required: true,
		},
	},

	emits: ['update:open', 'created'],

	data() {
		return {
			mountPoint: '',
			errorMessage: '',
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
			this.mountPoint = ''
			this.errorMessage = ''
		},

		/**
		 * Returning `false` keeps the dialog open (NcDialogButton awaits this).
		 */
		async submit() {
			const mountPoint = this.mountPoint.trim()
			if (mountPoint === '') {
				this.errorMessage = t('group_manager', 'Invalid folder name')
				return false
			}

			try {
				// Creating a group folder provisions real backing storage, so —
				// unlike everything else in this app — it requires a recent
				// password confirmation, matching the bar the groupfolders admin
				// UI itself sets for the same operation.
				await confirmPassword()
				const folder = await createGroupFolder(this.groupId, mountPoint)
				this.$emit('created', folder)
				this.reset()
				return true
			} catch (err) {
				this.errorMessage = extractErrorMessage(err, t('group_manager', 'Could not create the group folder.'))
				return false
			}
		},
	},
}
</script>

<style scoped>
.gm-create-folder-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 4px 12px;
}
</style>
