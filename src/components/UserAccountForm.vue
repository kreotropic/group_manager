<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div class="gm-ua">
		<div class="gm-ua__scroll">
			<div class="gm-ua__facts">
				<div class="gm-ua__fact">
					<span class="gm-ua__fact-label">{{ t('group_manager', 'User ID') }}</span>
					<span class="gm-ua__fact-value gm-ua__mono" :title="details.id">{{ details.id }}</span>
				</div>
				<div class="gm-ua__fact">
					<span class="gm-ua__fact-label">{{ t('group_manager', 'Origin') }}</span>
					<span class="gm-ua__fact-value">{{ originText }}</span>
				</div>
				<div class="gm-ua__fact">
					<span class="gm-ua__fact-label">{{ t('group_manager', 'Last login') }}</span>
					<span class="gm-ua__fact-value">{{ lastLoginText }}</span>
				</div>
				<div class="gm-ua__fact">
					<span class="gm-ua__fact-label">{{ t('group_manager', 'Storage') }}</span>
					<span class="gm-ua__fact-value">{{ storageText }}</span>
				</div>
			</div>

			<section class="gm-ua__section">
				<h3 class="gm-ua__heading">{{ t('group_manager', 'Account') }}</h3>
				<div class="gm-ua__grid">
					<div class="gm-ua__field" :class="fieldClass('displayname')">
						<label class="gm-ua__label" :for="ids.displayname">{{ t('group_manager', 'Display name') }}</label>
						<NcTextField :id="ids.displayname"
							:model-value="value('displayname')"
							:label-outside="true"
							:disabled="!canEdit('displayname') || applying"
							@update:model-value="stage('displayname', $event)" />
						<span v-if="!canEdit('displayname')" class="gm-ua__hint">{{ backendHint }}</span>
					</div>

					<div class="gm-ua__field" :class="fieldClass('email')">
						<label class="gm-ua__label" :for="ids.email">{{ t('group_manager', 'Email') }}</label>
						<NcTextField :id="ids.email"
							:model-value="value('email')"
							type="email"
							:label-outside="true"
							:disabled="!canEdit('email') || applying"
							@update:model-value="stage('email', $event)" />
						<span v-if="!canEdit('email')" class="gm-ua__hint">{{ backendHint }}</span>
					</div>

					<div class="gm-ua__field" :class="fieldClass('password')">
						<label class="gm-ua__label" :for="ids.password">{{ t('group_manager', 'New password') }}</label>
						<NcPasswordField v-if="details.backendCapabilities?.setPassword"
							:id="ids.password"
							:model-value="value('password')"
							:label-outside="true"
							:placeholder="t('group_manager', 'Leave empty to keep the current one')"
							autocomplete="new-password"
							:disabled="applying"
							@update:model-value="stage('password', $event)" />
						<span v-else class="gm-ua__readonly-value">{{ t('group_manager', 'Set in the account\'s backend') }}</span>
					</div>

					<div class="gm-ua__field" :class="fieldClass('quota')">
						<label class="gm-ua__label" :for="ids.quota">{{ t('group_manager', 'Personal quota') }}</label>
						<NcSelect :input-id="ids.quota"
							:model-value="quotaOption"
							:options="quotaOptions"
							:clearable="false"
							:taggable="true"
							:create-option="createQuotaOption"
							:disabled="applying"
							label="label"
							:label-outside="true"
							@update:model-value="onQuotaPicked" />
						<div v-if="usage" class="gm-ua__usage">
							<div v-if="usage.percent !== null"
								class="gm-ua__bar"
								role="progressbar"
								:aria-valuemin="0"
								:aria-valuemax="100"
								:aria-valuenow="Math.min(usage.percent, 100)"
								:aria-label="t('group_manager', 'Storage usage')">
								<div class="gm-ua__bar-fill"
									:class="{ 'gm-ua__bar-fill--full': usage.percent >= 90 }"
									:style="{ width: Math.min(usage.percent, 100) + '%' }" />
							</div>
							<span class="gm-ua__hint">{{ usage.text }}</span>
						</div>
						<span v-if="quotaError" class="gm-ua__hint gm-ua__hint--error">{{ quotaError }}</span>
					</div>

					<div class="gm-ua__field" :class="fieldClass('language')">
						<label class="gm-ua__label" :for="ids.language">{{ t('group_manager', 'Language') }}</label>
						<NcSelect :input-id="ids.language"
							:model-value="languageOption"
							:options="languageOptions"
							:clearable="false"
							:disabled="applying"
							label="name"
							:label-outside="true"
							@update:model-value="stage('language', $event?.code ?? '')" />
					</div>

					<div class="gm-ua__field" :class="fieldClass('manager')">
						<label class="gm-ua__label" :for="ids.manager">{{ t('group_manager', 'Manager') }}</label>
						<NcSelect :input-id="ids.manager"
							:model-value="managerOption"
							:options="managerOptions"
							:filterable="false"
							:loading="managerLoading"
							:disabled="applying"
							:placeholder="t('group_manager', 'Search for a person')"
							label="displayName"
							:label-outside="true"
							@search="searchManagers"
							@update:model-value="onManagerPicked" />
					</div>
				</div>
			</section>

			<section class="gm-ua__section">
				<div class="gm-ua__heading-row">
					<h3 class="gm-ua__heading">{{ t('group_manager', 'Profile') }}</h3>
					<NcCheckboxRadioSwitch v-if="'profile_enabled' in details"
						type="switch"
						:model-value="value('profile_enabled') === '1'"
						:disabled="!canEdit('profile_enabled') || applying"
						:class="fieldClass('profile_enabled')"
						@update:model-value="stage('profile_enabled', $event ? '1' : '0')">
						{{ t('group_manager', 'Profile enabled') }}
					</NcCheckboxRadioSwitch>
				</div>
				<div class="gm-ua__grid">
					<div v-for="field in profileFields"
						:key="field.key"
						class="gm-ua__field"
						:class="fieldClass(field.key)">
						<label class="gm-ua__label" :for="ids.prefix + field.key">{{ field.label }}</label>
						<NcTextField :id="ids.prefix + field.key"
							:model-value="value(field.key)"
							:type="field.type || 'text'"
							:label-outside="true"
							:disabled="!canEdit(field.key) || applying"
							@update:model-value="stage(field.key, $event)" />
						<span v-if="!canEdit(field.key)" class="gm-ua__hint">{{ backendHint }}</span>
					</div>
				</div>
				<div v-if="'biography' in details" class="gm-ua__field gm-ua__bio" :class="fieldClass('biography')">
					<label class="gm-ua__label" :for="ids.prefix + 'biography'">{{ t('group_manager', 'About') }}</label>
					<NcTextArea :id="ids.prefix + 'biography'"
						:model-value="value('biography')"
						:label-outside="true"
						:disabled="!canEdit('biography') || applying"
						resize="vertical"
						@update:model-value="stage('biography', $event)" />
				</div>
			</section>
		</div>

		<NcNoteCard v-if="lastResult" :type="lastResult.failures.length ? 'error' : 'success'" class="gm-ua__result">
			<p v-if="!lastResult.failures.length">
				{{ n('group_manager', '%n field saved.', '%n fields saved.', lastResult.saved) }}
			</p>
			<template v-else>
				<p>{{ t('group_manager', 'Some fields were not saved and are still staged:') }}</p>
				<ul class="gm-ua__failures">
					<li v-for="failure in lastResult.failures" :key="failure.key">
						<strong>{{ failure.label }}</strong>: {{ failure.message }}
					</li>
				</ul>
			</template>
		</NcNoteCard>
	</div>
</template>

<script>
import { translate as t, translatePlural as n, getLanguage } from '@nextcloud/l10n'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { editUserField, fetchUserSummary, fetchUsers } from '../services/api.js'
import { extractErrorMessage } from '../utils/errors.js'
import { humanSize } from '../utils/format.js'
import { isValidQuota, quotaChoices, quotaLabel } from '../utils/quota.js'

// What core reports as "no limit" (FileInfo::SPACE_UNLIMITED).
const UNLIMITED = -3

let instanceCount = 0

export default {
	name: 'UserAccountForm',

	components: {
		NcCheckboxRadioSwitch,
		NcNoteCard,
		NcPasswordField,
		NcSelect,
		NcTextArea,
		NcTextField,
	},

	props: {
		/** Core's details (fetchUserDetails). */
		details: {
			type: Object,
			required: true,
		},
		/** This app's summary, for quotaSetting. */
		summary: {
			type: Object,
			required: true,
		},
		/** Core's editable fields for this account. */
		editable: {
			type: Array,
			default: () => [],
		},
		options: {
			type: Object,
			required: true,
		},
	},

	emits: ['changed', 'pending-changed'],

	data() {
		instanceCount++
		return {
			// Only the fields changed from what the server has, by core's key.
			draft: {},
			applying: false,
			lastResult: null,
			quotaError: '',
			managerOptions: [],
			managerLoading: false,
			// The current manager's display name, looked up once.
			managerName: '',
			ids: {
				displayname: `gm-ua-displayname-${instanceCount}`,
				email: `gm-ua-email-${instanceCount}`,
				password: `gm-ua-password-${instanceCount}`,
				quota: `gm-ua-quota-${instanceCount}`,
				language: `gm-ua-language-${instanceCount}`,
				manager: `gm-ua-manager-${instanceCount}`,
				prefix: `gm-ua-${instanceCount}-`,
			},
		}
	},

	computed: {
		/** The server's values, keyed like core's editUser keys. */
		original() {
			const d = this.details
			const values = {
				displayname: d.displayname ?? '',
				email: d.email ?? '',
				password: '',
				quota: this.summary.quotaSetting ?? 'default',
				language: d.language ?? '',
				manager: d.manager ?? '',
			}
			for (const key of ['profile_enabled', 'biography', ...this.profileFields.map((f) => f.key)]) {
				if (key in d) {
					values[key] = d[key] ?? ''
				}
			}
			return values
		},

		changedKeys() {
			return Object.keys(this.draft)
		},

		backendHint() {
			return t('group_manager', 'Managed by the account\'s backend')
		},

		hasPendingChanges() {
			return this.changedKeys.length > 0
		},

		/**
		 * Only the properties this server's version reports (bluesky and
		 * pronouns are newer than others), in the order of Nextcloud's own
		 * profile settings.
		 */
		profileFields() {
			const all = [
				{ key: 'phone', label: t('group_manager', 'Phone number'), type: 'tel' },
				{ key: 'address', label: t('group_manager', 'Location') },
				{ key: 'website', label: t('group_manager', 'Website'), type: 'url' },
				{ key: 'organisation', label: t('group_manager', 'Organisation') },
				{ key: 'role', label: t('group_manager', 'Role') },
				{ key: 'headline', label: t('group_manager', 'Headline') },
				{ key: 'pronouns', label: t('group_manager', 'Pronouns') },
				{ key: 'fediverse', label: t('group_manager', 'Fediverse (e.g. Mastodon)') },
				{ key: 'bluesky', label: t('group_manager', 'Bluesky') },
				{ key: 'twitter', label: t('group_manager', 'X (formerly Twitter)') },
			]
			return all.filter((field) => field.key in this.details)
		},

		originText() {
			switch (this.summary.backend) {
			case 'local':
				return t('group_manager', 'Local account')
			case 'ldap':
				return t('group_manager', 'LDAP')
			default:
				return this.summary.backendName || this.details.backend
			}
		},

		lastLoginText() {
			const ts = this.details.lastLoginTimestamp
			if (!ts) {
				return t('group_manager', 'Never')
			}
			return new Intl.DateTimeFormat(getLanguage(), { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(ts * 1000))
		},

		usage() {
			const q = this.details.quota
			if (!q || typeof q.used !== 'number') {
				return null
			}
			const limited = typeof q.quota === 'number' && q.quota > 0
			return {
				percent: limited ? (q.used / q.quota) * 100 : null,
				text: limited
					? t('group_manager', '{used} of {total} used', { used: humanSize(q.used), total: humanSize(q.quota) })
					: t('group_manager', '{used} used', { used: humanSize(q.used) }),
			}
		},

		storageText() {
			const q = this.details.quota
			if (!q || typeof q.used !== 'number') {
				return '—'
			}
			return q.quota === UNLIMITED || !(q.quota > 0)
				? humanSize(q.used)
				: t('group_manager', '{used} of {total}', { used: humanSize(q.used), total: humanSize(q.quota) })
		},

		quotaOptions() {
			return quotaChoices(this.options, this.value('quota'))
		},

		quotaOption() {
			const id = this.value('quota')
			return { id, label: quotaLabel(id, this.options.defaultQuota) }
		},

		languageOptions() {
			return this.options.languages ?? []
		},

		languageOption() {
			const code = this.value('language')
			return this.languageOptions.find((l) => l.code === code) ?? (code ? { code, name: code } : null)
		},

		managerOption() {
			const uid = this.value('manager')
			if (!uid) {
				return null
			}
			const known = this.managerOptions.find((u) => u.uid === uid)
			return known ?? { uid, displayName: uid === this.original.manager && this.managerName ? this.managerName : uid }
		},
	},

	watch: {
		hasPendingChanges() {
			this.emitPending()
		},

		changedKeys() {
			this.emitPending()
		},

		/**
		 * Fresh details after a save: whatever now matches the server is no
		 * longer a change (a failed field stays staged).
		 */
		original(next) {
			const draft = { ...this.draft }
			for (const key of Object.keys(draft)) {
				if (key !== 'password' && draft[key] === next[key]) {
					delete draft[key]
				}
			}
			this.draft = draft
		},

		'details.manager': {
			immediate: true,
			handler(uid) {
				this.lookUpManagerName(uid)
			},
		},
	},

	methods: {
		t,
		n,

		value(key) {
			return key in this.draft ? this.draft[key] : (this.original[key] ?? '')
		},

		canEdit(key) {
			switch (key) {
			case 'displayname':
				return !!this.details.backendCapabilities?.setDisplayName
			case 'email':
			case 'profile_enabled':
			case 'biography':
				return this.editable.includes(key)
			default:
				return this.profileFields.some((f) => f.key === key) ? this.editable.includes(key) : true
			}
		},

		fieldClass(key) {
			return { 'gm-ua__field--changed': key in this.draft }
		},

		stage(key, value) {
			const draft = { ...this.draft }
			const unchanged = key === 'password' ? value === '' : value === this.original[key]
			if (unchanged) {
				delete draft[key]
			} else {
				draft[key] = value
			}
			this.draft = draft
			this.lastResult = null
		},

		createQuotaOption(text) {
			return { id: text.trim(), label: text.trim() }
		},

		onQuotaPicked(option) {
			if (!option) {
				return
			}
			if (!isValidQuota(option.id)) {
				this.quotaError = t('group_manager', 'Not a size: use a number with a unit, like 15 GB.')
				return
			}
			this.quotaError = ''
			this.stage('quota', option.id)
		},

		onManagerPicked(option) {
			this.stage('manager', option?.uid ?? '')
		},

		async searchManagers(search) {
			clearTimeout(this.managerTimer)
			this.managerTimer = setTimeout(async () => {
				this.managerLoading = true
				try {
					const page = await fetchUsers({ search, pageSize: 10 })
					this.managerOptions = page.users.filter((u) => u.uid !== this.details.id)
				} catch {
					this.managerOptions = []
				} finally {
					this.managerLoading = false
				}
			}, 250)
		},

		async lookUpManagerName(uid) {
			this.managerName = ''
			if (!uid) {
				return
			}
			try {
				this.managerName = (await fetchUserSummary(uid)).displayName
			} catch {
				// The id alone is shown.
			}
		},

		emitPending() {
			this.$emit('pending-changed', {
				hasPendingChanges: this.hasPendingChanges,
				count: this.changedKeys.length,
			})
		},

		discardChanges() {
			this.draft = {}
			this.quotaError = ''
			this.lastResult = null
		},

		labelFor(key) {
			const labels = {
				displayname: t('group_manager', 'Display name'),
				email: t('group_manager', 'Email'),
				password: t('group_manager', 'Password'),
				quota: t('group_manager', 'Personal quota'),
				language: t('group_manager', 'Language'),
				manager: t('group_manager', 'Manager'),
				profile_enabled: t('group_manager', 'Profile enabled'),
				biography: t('group_manager', 'About'),
			}
			return labels[key] ?? this.profileFields.find((f) => f.key === key)?.label ?? key
		},

		/**
		 * One request per changed field, one after the other (core takes a
		 * single field per call). A failure doesn't stop the rest; the
		 * failed fields stay staged with the reason. The caller has already
		 * confirmed the password.
		 */
		async applyChanges() {
			const keys = this.changedKeys
			if (keys.length === 0) {
				return
			}
			this.applying = true
			const failures = []
			let saved = 0
			try {
				for (const key of keys) {
					try {
						await editUserField(this.details.id, key, this.draft[key])
						saved++
						if (key === 'password') {
							const { password, ...rest } = this.draft
							this.draft = rest
						}
					} catch (err) {
						failures.push({
							key,
							label: this.labelFor(key),
							message: extractErrorMessage(err, err?.response?.status === 429
								? t('group_manager', 'Too many changes in a short time. Wait a few minutes and try again.')
								: t('group_manager', 'Not accepted by the server.')),
						})
					}
				}
			} finally {
				this.applying = false
			}
			this.lastResult = { saved, failures }
			if (saved > 0) {
				this.$emit('changed')
			}
		},
	},
}
</script>

<style scoped>
.gm-ua {
	display: flex;
	flex-direction: column;
	gap: 12px;
	min-height: 0;
}

.gm-ua__scroll {
	flex: 1;
	min-height: 0;
	overflow-y: auto;
	padding: 4px var(--gm-scrollbar-w) 16px 0;
	margin-right: calc(-1 * var(--gm-scrollbar-w));
}

.gm-ua__facts {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
	gap: 12px 24px;
	margin: 0 0 24px;
	padding: 14px 16px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-element, 8px);
	background: var(--color-background-hover);
}

.gm-ua__fact {
	display: flex;
	flex-direction: column;
	gap: 2px;
	min-width: 0;
}

.gm-ua__fact-label {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.gm-ua__fact-value {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.gm-ua__mono {
	font-family: monospace;
	font-size: 13px;
}

.gm-ua__section + .gm-ua__section {
	margin-top: 28px;
}

.gm-ua__heading-row {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 12px;
}

.gm-ua__heading {
	margin: 0 0 12px;
	font-size: 11px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: .09em;
	color: var(--color-text-maxcontrast);
}

.gm-ua__heading-row .gm-ua__heading {
	margin-bottom: 0;
}

.gm-ua__heading-row {
	margin-bottom: 12px;
}

.gm-ua__grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
	gap: 16px 20px;
	align-items: start;
}

.gm-ua__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	min-width: 0;
}

/* Inputs and selects bring their own outer margins; the field's gap and
   the grid's are the spacing here. */
.gm-ua__field :deep(.input-field),
.gm-ua__field :deep(.textarea) {
	margin: 0 !important;
}

.gm-ua__field :deep(.v-select.select) {
	width: 100%;
	min-width: 0;
	margin: 0;
}

.gm-ua__label {
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.gm-ua__readonly-value {
	min-height: 36px;
	display: flex;
	align-items: center;
	color: var(--color-text-maxcontrast);
	font-style: italic;
}

.gm-ua__usage {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-top: 4px;
}

.gm-ua__bar {
	height: 6px;
	border-radius: 3px;
	background: var(--color-background-darker);
	overflow: hidden;
}

.gm-ua__bar-fill {
	height: 100%;
	background: var(--color-primary-element);
}

.gm-ua__bar-fill--full {
	background: var(--color-element-error, var(--color-error));
}

.gm-ua__hint {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.gm-ua__hint--error {
	color: var(--color-text-error, var(--color-error));
}

.gm-ua__bio {
	margin-top: 16px;
}

/* A staged field: a primary-coloured bar on its left, the same cue for
   every kind of control. */
.gm-ua__field--changed {
	position: relative;
}

.gm-ua__field--changed::before {
	content: '';
	position: absolute;
	left: -10px;
	top: 6px;
	bottom: 6px;
	width: 3px;
	border-radius: 2px;
	background: var(--color-primary-element);
}

.gm-ua__failures {
	margin: 6px 0 0 18px;
	list-style: disc;
}

.gm-ua__result {
	flex-shrink: 0;
	margin: 0 !important;
}
</style>
