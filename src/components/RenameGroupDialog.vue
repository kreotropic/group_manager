<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<NcDialog :open="open"
		:name="t('group_manager', 'Rename group')"
		:buttons="buttons"
		is-form
		size="small"
		@update:open="onUpdateOpen"
		@closing="reset">
		<div class="gm-rename-dialog">
			<NcTextField ref="nameField"
				v-model="displayName"
				:label="t('group_manager', 'Display name')"
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
import { renameGroup } from '../services/api.js'
import { extractErrorMessage } from '../utils/errors.js'

export default {
	name: 'RenameGroupDialog',

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
		group: {
			type: Object,
			default: null,
		},
	},

	emits: ['update:open', 'renamed'],

	data() {
		return {
			displayName: '',
			errorMessage: '',
		}
	},

	watch: {
		group: {
			immediate: true,
			handler(group) {
				this.displayName = group?.displayName ?? ''
			},
		},
		open(value) {
			if (value) {
				this.displayName = this.group?.displayName ?? ''
				this.errorMessage = ''
			}
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
			this.errorMessage = ''
		},

		/**
		 * Returning `false` keeps the dialog open (NcDialogButton awaits this).
		 */
		async submit() {
			const displayName = this.displayName.trim()
			if (displayName === '') {
				this.errorMessage = t('group_manager', 'Display name cannot be empty.')
				return false
			}

			try {
				const group = await renameGroup(this.group.id, displayName)
				this.$emit('renamed', group)
				return true
			} catch (err) {
				this.errorMessage = extractErrorMessage(err, t('group_manager', 'Could not rename the group.'))
				return false
			}
		},
	},

	computed: {
		buttons() {
			return [
				{ label: t('group_manager', 'Cancel'), type: 'reset', callback: () => true },
				{
					label: t('group_manager', 'Save'),
					type: 'submit',
					variant: 'primary',
					callback: this.submit,
				},
			]
		},
	},
}
</script>

<style scoped>
.gm-rename-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 4px 12px;
}
</style>
