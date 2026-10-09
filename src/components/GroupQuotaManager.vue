<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div class="gm-gq">
		<div class="gm-gq__scroll">
			<NcNoteCard v-if="loadError" type="error">
				{{ loadError }}
				<NcButton variant="secondary" @click="reload">
					{{ t('group_manager', 'Try again') }}
				</NcButton>
			</NcNoteCard>

			<div v-else-if="loading" class="gm-gq__loading">
				<NcLoadingIcon :size="24" />
			</div>

			<template v-else>
				<div class="gm-gq__summary">
					<div class="gm-gq__limit">
						<span class="gm-gq__label">{{ t('group_manager', 'Group limit') }}</span>
						<span class="gm-gq__value" :class="{ 'gm-gq__value--changed': hasPendingChanges }">
							{{ limitText }}
						</span>
						<span v-if="hasPendingChanges" class="gm-gq__pending">
							{{ t('group_manager', 'was {value}', { value: currentLimitText }) }}
						</span>
					</div>

					<div v-if="usedBytes !== null" class="gm-gq__usage">
						<div v-if="effectiveQuota !== null"
							class="gm-gq__bar"
							role="progressbar"
							:aria-valuemin="0"
							:aria-valuemax="100"
							:aria-valuenow="Math.min(usagePercent, 100)"
							:aria-label="t('group_manager', 'Group storage usage')">
							<div class="gm-gq__bar-fill"
								:class="{ 'gm-gq__bar-fill--full': usagePercent >= 100 }"
								:style="{ width: Math.min(usagePercent, 100) + '%' }" />
						</div>
						<span class="gm-gq__usage-text">{{ usageText }}</span>
					</div>
				</div>

				<div class="gm-gq__presets" role="group" :aria-label="t('group_manager', 'Quota presets')">
					<button v-for="preset in presets"
						:key="preset.value"
						type="button"
						class="gm-gq__preset"
						:class="{ 'gm-gq__preset--active': selectedValue === preset.value }"
						:disabled="applying"
						@click="pick(preset.value)">
						{{ preset.label }}
					</button>
					<span class="gm-gq__custom">
						<input v-model.number="customGb"
							type="number"
							min="0.1"
							step="0.1"
							class="gm-gq__custom-input"
							:disabled="applying"
							:aria-label="t('group_manager', 'Custom quota in GB')"
							@keydown.enter.prevent="applyCustom">
						<span class="gm-gq__custom-unit">GB</span>
						<button type="button"
							class="gm-gq__preset"
							:disabled="applying || !customValid"
							@click="applyCustom">
							{{ t('group_manager', 'Set') }}
						</button>
					</span>
				</div>

				<NcNoteCard v-if="overlap.count > 0" type="warning" class="gm-gq__overlap">
					<p>
						{{ overlapText }}
					</p>
					<ul class="gm-gq__overlap-list">
						<li v-for="user in overlap.users" :key="user.uid">
							{{ t('group_manager', '{name} follows the quota of {group}', { name: user.displayName, group: user.winner }) }}
						</li>
					</ul>
					<p v-if="overlap.count > overlap.users.length">
						{{ t('group_manager', 'and {count} more', { count: overlap.count - overlap.users.length }) }}
					</p>
					<p v-if="overlap.truncated">
						{{ t('group_manager', 'Only the first {count} members were checked.', { count: overlap.scanned }) }}
					</p>
				</NcNoteCard>

				<p class="gm-gq__note">
					{{ t('group_manager', 'The limit is shared: the files of every member count against it together.') }}
					{{ t('group_manager', 'A member of several groups that have a quota follows only the first of them by group ID.') }}
				</p>
			</template>
		</div>

		<NcNoteCard v-if="lastResult" :type="lastResult.error ? 'error' : 'success'" class="gm-gq__result">
			<p>{{ lastResult.error || t('group_manager', 'Quota updated.') }}</p>
			<NcButton v-if="lastResult.error" @click="applyChanges">
				{{ t('group_manager', 'Try again') }}
			</NcButton>
		</NcNoteCard>
	</div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import { confirmPassword } from '@nextcloud/password-confirmation'
import '@nextcloud/password-confirmation/style.css'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { fetchGroupQuota, setGroupQuota } from '../services/api.js'
import { extractErrorMessage } from '../utils/errors.js'
import { humanSize } from '../utils/format.js'

const GB = 1024 * 1024 * 1024
// What the backend treats as "no limit" (FileInfo::SPACE_UNLIMITED).
const UNLIMITED_QUOTA = -3

const EMPTY_OVERLAP = { count: 0, scanned: 0, truncated: false, users: [] }

export default {
	name: 'GroupQuotaManager',

	components: {
		NcButton,
		NcLoadingIcon,
		NcNoteCard,
	},

	props: {
		groupId: {
			type: String,
			required: true,
		},
	},

	emits: ['changed', 'pending-changed'],

	data() {
		return {
			loading: true,
			loadError: '',
			// null = no limit
			quota: null,
			usedBytes: null,
			overlap: { ...EMPTY_OVERLAP },
			// undefined = nothing staged; otherwise bytes, or UNLIMITED_QUOTA
			draft: undefined,
			customGb: null,
			applying: false,
			lastResult: null,
		}
	},

	computed: {
		hasPendingChanges() {
			return this.draft !== undefined
		},

		/** The limit as it would be once applied, null = none. */
		effectiveQuota() {
			if (this.draft === undefined) {
				return this.quota
			}
			return this.draft === UNLIMITED_QUOTA ? null : this.draft
		},

		selectedValue() {
			return this.effectiveQuota === null ? UNLIMITED_QUOTA : this.effectiveQuota
		},

		limitText() {
			return this.effectiveQuota === null ? t('group_manager', 'Unlimited') : humanSize(this.effectiveQuota)
		},

		currentLimitText() {
			return this.quota === null ? t('group_manager', 'Unlimited') : humanSize(this.quota)
		},

		usagePercent() {
			if (this.effectiveQuota === null || this.usedBytes === null) {
				return 0
			}
			return (this.usedBytes / this.effectiveQuota) * 100
		},

		usageText() {
			if (this.effectiveQuota === null) {
				return t('group_manager', '{used} used', { used: humanSize(this.usedBytes) })
			}
			return t('group_manager', '{used} of {total} used', {
				used: humanSize(this.usedBytes),
				total: humanSize(this.effectiveQuota),
			})
		},

		presets() {
			return [
				{ value: UNLIMITED_QUOTA, label: t('group_manager', 'Unlimited') },
				{ value: 1 * GB, label: '1 GB' },
				{ value: 5 * GB, label: '5 GB' },
				{ value: 10 * GB, label: '10 GB' },
				{ value: 50 * GB, label: '50 GB' },
				{ value: 100 * GB, label: '100 GB' },
			]
		},

		customValid() {
			return typeof this.customGb === 'number' && Number.isFinite(this.customGb) && this.customGb >= 0.1
		},

		overlapText() {
			if (this.quota === null && this.draft === undefined) {
				return n('group_manager', '%n member is also in a group whose quota takes precedence over one set here.', '%n members are also in a group whose quota takes precedence over one set here.', this.overlap.count)
			}
			return n('group_manager', '%n member is also in a group whose quota takes precedence over this one.', '%n members are also in a group whose quota takes precedence over this one.', this.overlap.count)
		},
	},

	watch: {
		groupId() {
			this.resetState()
			this.reload()
		},

		hasPendingChanges(value) {
			this.$emit('pending-changed', { hasPendingChanges: value, count: value ? 1 : 0 })
		},
	},

	mounted() {
		this.reload()
	},

	methods: {
		t,

		resetState() {
			this.draft = undefined
			this.customGb = null
			this.lastResult = null
		},

		async reload() {
			this.loading = true
			this.loadError = ''
			try {
				const data = await fetchGroupQuota(this.groupId)
				this.quota = data.quota ?? null
				this.usedBytes = data.used ?? null
				this.overlap = data.overlap ?? { ...EMPTY_OVERLAP }
			} catch (err) {
				this.loadError = extractErrorMessage(err, t('group_manager', 'Could not load the quota.'))
			} finally {
				this.loading = false
			}
		},

		/**
		 * Staging a value equal to what is stored just clears the draft: the
		 * footer must not offer an Apply that changes nothing.
		 */
		pick(value) {
			this.lastResult = null
			const current = this.quota === null ? UNLIMITED_QUOTA : this.quota
			this.draft = value === current ? undefined : value
		},

		applyCustom() {
			if (!this.customValid) {
				return
			}
			this.pick(Math.round(this.customGb * GB))
			this.customGb = null
		},

		discardChanges() {
			this.resetState()
		},

		async applyChanges() {
			if (this.draft === undefined) {
				return
			}
			this.applying = true
			this.lastResult = null
			try {
				await confirmPassword()
			} catch {
				this.applying = false
				return
			}
			try {
				const data = await setGroupQuota(this.groupId, this.draft)
				this.quota = data.quota ?? null
				this.usedBytes = data.used ?? null
				this.overlap = data.overlap ?? { ...EMPTY_OVERLAP }
				this.draft = undefined
				this.lastResult = { error: '' }
				this.$emit('changed')
			} catch (err) {
				this.lastResult = { error: extractErrorMessage(err, t('group_manager', 'Could not update the quota.')) }
			} finally {
				this.applying = false
			}
		},
	},
}
</script>

<style scoped>
.gm-gq {
	display: flex;
	flex-direction: column;
	height: 100%;
	min-height: 0;
}

.gm-gq__scroll {
	flex: 1;
	min-height: 0;
	overflow-y: auto;
	padding-bottom: 8px;
}

.gm-gq__loading {
	display: flex;
	justify-content: center;
	padding: 24px 0;
}

.gm-gq__summary {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 16px;
	margin-bottom: 16px;
	border: 1px solid var(--color-border);
	border-radius: 8px;
}

.gm-gq__limit {
	display: flex;
	align-items: baseline;
	gap: 12px;
	flex-wrap: wrap;
}

.gm-gq__label {
	color: var(--color-text-maxcontrast);
}

.gm-gq__value {
	font-size: 22px;
	font-weight: 600;
}

.gm-gq__value--changed {
	color: var(--color-primary-element);
}

.gm-gq__pending {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.gm-gq__usage {
	display: flex;
	flex-direction: column;
	gap: 6px;
}

.gm-gq__bar {
	height: 8px;
	border-radius: 4px;
	background: var(--color-background-dark);
	overflow: hidden;
}

.gm-gq__bar-fill {
	height: 100%;
	background: var(--color-primary-element);
}

.gm-gq__bar-fill--full {
	background: var(--color-element-error, var(--color-error));
}

.gm-gq__usage-text {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
}

.gm-gq__presets {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 8px;
	margin-bottom: 16px;
}

.gm-gq__preset {
	min-height: 34px;
	padding: 0 14px;
	border: 1px solid var(--color-border-maxcontrast);
	border-radius: var(--border-radius-element, 8px);
	background: var(--color-main-background);
	color: var(--color-main-text);
	cursor: pointer;
}

.gm-gq__preset:hover:not(:disabled) {
	background: var(--color-background-hover);
}

.gm-gq__preset--active {
	border-color: var(--color-primary-element);
	background: var(--color-primary-element-light, var(--color-background-hover));
	font-weight: 600;
}

.gm-gq__preset:disabled {
	opacity: 0.5;
	cursor: default;
}

.gm-gq__custom {
	display: inline-flex;
	align-items: center;
	gap: 6px;
	margin-left: 8px;
}

.gm-gq__custom-input {
	width: 90px;
	min-height: 34px;
	margin: 0;
}

.gm-gq__custom-unit {
	color: var(--color-text-maxcontrast);
}

.gm-gq__overlap-list {
	margin: 8px 0 0 18px;
	list-style: disc;
}

.gm-gq__note {
	color: var(--color-text-maxcontrast);
	font-size: 13px;
	margin-top: 8px;
}

.gm-gq__result {
	flex-shrink: 0;
}
</style>
