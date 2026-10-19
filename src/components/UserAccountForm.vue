<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div class="gm-ua">
		<div class="gm-ua__scroll">
			<section class="gm-ua__section">
				<h3 class="gm-ua__heading">{{ t('group_manager', 'Account') }}</h3>
				<!-- One note for what the account's backend owns, instead of one
				     under each of those fields. -->
				<p v-if="managedNote" class="gm-ua__managed">
					<LockOutline :size="16" />
					{{ managedNote }}
				</p>
				<div class="gm-ua__grid">
					<div class="gm-ua__field" :class="fieldClass('displayname')">
						<div class="gm-ua__label-row">
							<component :is="canEdit('displayname') ? 'label' : 'span'"
								class="gm-ua__label"
								:for="canEdit('displayname') ? ids.displayname : undefined">
								{{ t('group_manager', 'Display name') }}
							</component>
							<span v-if="isSaved('displayname')" class="gm-ua__saved"><Check :size="14" />{{ t('group_manager', 'Saved') }}</span>
						</div>
						<NcTextField v-if="canEdit('displayname')"
							:id="ids.displayname"
							:model-value="value('displayname')"
							:label-outside="true"
							:disabled="applying"
							@update:model-value="stage('displayname', $event)" />
						<span v-else class="gm-ua__value" :class="{ 'gm-ua__value--empty': !value('displayname') }">{{ readonlyText('displayname') }}</span>
					</div>

					<!-- Pronouns sit with the name they go with. -->
					<div v-for="field in accountProfileFields"
						:key="field.key"
						class="gm-ua__field"
						:class="fieldClass(field.key)">
						<div class="gm-ua__label-row">
							<component :is="canEdit(field.key) ? 'label' : 'span'"
								class="gm-ua__label"
								:for="canEdit(field.key) ? ids.prefix + field.key : undefined">
								{{ field.label }}
							</component>
							<span v-if="isSaved(field.key)" class="gm-ua__saved"><Check :size="14" />{{ t('group_manager', 'Saved') }}</span>
						</div>
						<NcTextField v-if="canEdit(field.key)"
							:id="ids.prefix + field.key"
							:model-value="value(field.key)"
							:type="field.type || 'text'"
							:label-outside="true"
							:disabled="applying"
							@update:model-value="stage(field.key, $event)" />
						<span v-else class="gm-ua__value" :class="{ 'gm-ua__value--empty': !value(field.key) }">{{ readonlyText(field.key) }}</span>
					</div>

					<div class="gm-ua__field" :class="fieldClass('email')">
						<div class="gm-ua__label-row">
							<component :is="canEdit('email') ? 'label' : 'span'"
								class="gm-ua__label"
								:for="canEdit('email') ? ids.email : undefined">
								{{ t('group_manager', 'Email') }}
							</component>
							<span v-if="isSaved('email')" class="gm-ua__saved"><Check :size="14" />{{ t('group_manager', 'Saved') }}</span>
						</div>
						<NcTextField v-if="canEdit('email')"
							:id="ids.email"
							:model-value="value('email')"
							type="email"
							:label-outside="true"
							:disabled="applying"
							@update:model-value="stage('email', $event)" />
						<span v-else class="gm-ua__value" :class="{ 'gm-ua__value--empty': !value('email') }">{{ readonlyText('email') }}</span>
					</div>

					<!-- Only where the backend lets it be set (not LDAP), and only
					     once asked for: an empty password box on every account
					     read as something to fill in. -->
					<div v-if="details.backendCapabilities?.setPassword" class="gm-ua__field" :class="fieldClass('password')">
						<div class="gm-ua__label-row">
							<!-- A label for the box once open; while closed the button
							     carries its own name ("Change password"). -->
							<component :is="passwordOpen ? 'label' : 'span'"
								class="gm-ua__label"
								:for="passwordOpen ? ids.password : undefined">
								{{ t('group_manager', 'Password') }}
							</component>
							<span v-if="isSaved('password')" class="gm-ua__saved"><Check :size="14" />{{ t('group_manager', 'Saved') }}</span>
						</div>
						<div v-if="passwordOpen" class="gm-ua__password">
							<NcPasswordField ref="passwordField"
								:id="ids.password"
								:model-value="value('password')"
								:label-outside="true"
								:placeholder="t('group_manager', 'New password')"
								autocomplete="new-password"
								:disabled="applying"
								@update:model-value="stage('password', $event)" />
							<NcButton variant="tertiary" :disabled="applying" @click="closePassword">
								{{ t('group_manager', 'Cancel') }}
							</NcButton>
						</div>
						<div v-else>
							<NcButton :disabled="applying" @click="openPassword">
								{{ t('group_manager', 'Change password') }}
							</NcButton>
						</div>
					</div>

					<div class="gm-ua__field" :class="fieldClass('quota')">
						<div class="gm-ua__label-row">
							<label class="gm-ua__label" :for="ids.quota">{{ t('group_manager', 'Personal quota') }}</label>
							<span v-if="isSaved('quota')" class="gm-ua__saved"><Check :size="14" />{{ t('group_manager', 'Saved') }}</span>
						</div>
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
						<!-- Usage against a limit only: with no limit there is
						     nothing to measure it by, and the amount used is in the
						     header already. -->
						<div v-if="usage" class="gm-ua__usage">
							<NcProgressBar :value="Math.min(usage.percent, 100)"
								:error="usage.percent >= 90"
								size="small"
								:aria-label="t('group_manager', 'Storage usage')" />
							<span class="gm-ua__hint">{{ usage.text }}</span>
						</div>
						<span v-if="quotaError" class="gm-ua__hint gm-ua__hint--error">{{ quotaError }}</span>
					</div>

					<div class="gm-ua__field" :class="fieldClass('language')">
						<div class="gm-ua__label-row">
							<label class="gm-ua__label" :for="ids.language">{{ t('group_manager', 'Language') }}</label>
							<span v-if="isSaved('language')" class="gm-ua__saved"><Check :size="14" />{{ t('group_manager', 'Saved') }}</span>
						</div>
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
						<div class="gm-ua__label-row">
							<label class="gm-ua__label" :for="ids.manager">{{ t('group_manager', 'Manager') }}</label>
							<span v-if="isSaved('manager')" class="gm-ua__saved"><Check :size="14" />{{ t('group_manager', 'Saved') }}</span>
						</div>
						<NcSelect :input-id="ids.manager"
							:model-value="managerOption"
							:options="managerOptions"
							:filterable="false"
							:loading="managerLoading"
							:disabled="applying"
							:placeholder="t('group_manager', 'None')"
							label="displayName"
							:label-outside="true"
							@search="searchManagers"
							@update:model-value="onManagerPicked">
							<template #option="option">
								<span class="gm-ua__person">
									<NcAvatar :user="option.uid"
										:display-name="option.displayName"
										:size="24"
										:hide-status="true"
										:disable-menu="true"
										:disable-tooltip="true" />
									<span class="gm-ua__person-name">{{ option.displayName }}</span>
								</span>
							</template>
							<template #selected-option="option">
								<span class="gm-ua__person">
									<NcAvatar :user="option.uid"
										:display-name="option.displayName"
										:size="24"
										:hide-status="true"
										:disable-menu="true"
										:disable-tooltip="true" />
									<span class="gm-ua__person-name">{{ option.displayName }}</span>
								</span>
							</template>
						</NcSelect>
					</div>
				</div>
			</section>

			<section class="gm-ua__section">
				<div class="gm-ua__heading-row">
					<h3 class="gm-ua__heading">{{ t('group_manager', 'Profile') }}</h3>
					<span v-if="isSaved('profile_enabled')" class="gm-ua__saved"><Check :size="14" />{{ t('group_manager', 'Saved') }}</span>
				</div>
				<div v-if="'profile_enabled' in details" class="gm-ua__visibility">
					<NcCheckboxRadioSwitch type="switch"
						:model-value="profileEnabled"
						:disabled="!canEdit('profile_enabled') || applying"
						:class="fieldClass('profile_enabled')"
						@update:model-value="stage('profile_enabled', $event ? '1' : '0')">
						{{ t('group_manager', 'Public profile visible') }}
					</NcCheckboxRadioSwitch>
					<span class="gm-ua__hint">{{ t('group_manager', 'Shows these fields on the person\'s profile page. When off, they are kept and can still be edited.') }}</span>
				</div>

				<!-- Faded while the profile is hidden, still editable. -->
				<div class="gm-ua__profile" :class="{ 'gm-ua__profile--off': 'profile_enabled' in details && !profileEnabled }">
					<div v-for="group in profileGroups" :key="group.key" class="gm-ua__subsection">
						<h4 class="gm-ua__subheading">{{ group.label }}</h4>
						<div class="gm-ua__grid">
							<div v-for="field in group.fields"
								:key="field.key"
								class="gm-ua__field"
								:class="fieldClass(field.key)">
								<div class="gm-ua__label-row">
									<component :is="canEdit(field.key) ? 'label' : 'span'"
										class="gm-ua__label"
										:for="canEdit(field.key) ? ids.prefix + field.key : undefined">
										{{ field.label }}
									</component>
									<span v-if="isSaved(field.key)" class="gm-ua__saved"><Check :size="14" />{{ t('group_manager', 'Saved') }}</span>
								</div>
								<NcTextField v-if="canEdit(field.key)"
									:id="ids.prefix + field.key"
									:model-value="value(field.key)"
									:type="field.type || 'text'"
									:label-outside="true"
									:disabled="applying"
									@update:model-value="stage(field.key, $event)" />
								<span v-else class="gm-ua__value" :class="{ 'gm-ua__value--empty': !value(field.key) }">{{ readonlyText(field.key) }}</span>
							</div>
						</div>
					</div>

					<div v-if="'biography' in details" class="gm-ua__field gm-ua__bio" :class="fieldClass('biography')">
						<div class="gm-ua__label-row">
							<component :is="canEdit('biography') ? 'label' : 'span'"
								class="gm-ua__label"
								:for="canEdit('biography') ? ids.prefix + 'biography' : undefined">
								{{ t('group_manager', 'About') }}
							</component>
							<span v-if="isSaved('biography')" class="gm-ua__saved"><Check :size="14" />{{ t('group_manager', 'Saved') }}</span>
						</div>
						<NcTextArea v-if="canEdit('biography')"
							:id="ids.prefix + 'biography'"
							:model-value="value('biography')"
							:label-outside="true"
							:disabled="applying"
							resize="vertical"
							@update:model-value="stage('biography', $event)" />
						<span v-else class="gm-ua__value gm-ua__value--multiline" :class="{ 'gm-ua__value--empty': !value('biography') }">{{ readonlyText('biography') }}</span>
					</div>
				</div>
			</section>
		</div>

		<!-- Successes show by each saved field; only failures need a list. -->
		<NcNoteCard v-if="lastResult?.failures.length" type="error" class="gm-ua__result">
			<p>{{ t('group_manager', 'Some fields were not saved and are still staged:') }}</p>
			<ul class="gm-ua__failures">
				<li v-for="failure in lastResult.failures" :key="failure.key">
					<strong>{{ failure.label }}</strong>: {{ failure.message }}
				</li>
			</ul>
		</NcNoteCard>
	</div>
</template>

<script>
import { translate as t, translatePlural as n } from '@nextcloud/l10n'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcPasswordField from '@nextcloud/vue/components/NcPasswordField'
import NcProgressBar from '@nextcloud/vue/components/NcProgressBar'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import Check from 'vue-material-design-icons/Check.vue'
import LockOutline from 'vue-material-design-icons/LockOutline.vue'
import { editUserField, fetchUserSummary, fetchUsers } from '../services/api.js'
import { extractErrorMessage } from '../utils/errors.js'
import { humanSize } from '../utils/format.js'
import { isValidQuota, quotaChoices, quotaLabel } from '../utils/quota.js'

// How long "Saved" stays by a field after Apply.
const SAVED_MS = 6000

let instanceCount = 0

export default {
	name: 'UserAccountForm',

	components: {
		NcAvatar,
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
		NcPasswordField,
		NcProgressBar,
		NcSelect,
		NcTextArea,
		NcTextField,
		Check,
		LockOutline,
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
			// The password box shows only once asked for.
			passwordOpen: false,
			// Fields saved by the last Apply, marked "Saved" for a moment.
			savedKeys: [],
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

		/**
		 * One note for the account fields the backend owns: the usual LDAP
		 * case (name, email and password all owned) spelled out, any other
		 * mix said generally, the fields themselves showing as plain text.
		 */
		managedNote() {
			const owned = [
				!this.canEdit('displayname'),
				!this.canEdit('email'),
				!this.details.backendCapabilities?.setPassword,
			]
			if (!owned.some(Boolean)) {
				return ''
			}
			const ldap = this.summary.backend === 'ldap'
			if (owned.every(Boolean)) {
				return ldap
					? t('group_manager', 'Name, email and password are managed by LDAP.')
					: t('group_manager', 'Name, email and password are managed by the account\'s backend.')
			}
			return ldap
				? t('group_manager', 'Some account fields are managed by LDAP.')
				: t('group_manager', 'Some account fields are managed by the account\'s backend.')
		},

		profileEnabled() {
			return this.value('profile_enabled') === '1'
		},

		/** Profile properties shown in the Account section (pronouns). */
		accountProfileFields() {
			return this.profileFields.filter((field) => field.section === 'account')
		},

		/** The rest of the profile, in small titled groups, empty ones left out. */
		profileGroups() {
			return [
				{ key: 'contact', label: t('group_manager', 'Contact') },
				{ key: 'work', label: t('group_manager', 'Work') },
				{ key: 'social', label: t('group_manager', 'Social') },
			]
				.map((group) => ({ ...group, fields: this.profileFields.filter((field) => field.section === group.key) }))
				.filter((group) => group.fields.length > 0)
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
				{ key: 'pronouns', label: t('group_manager', 'Pronouns'), section: 'account' },
				{ key: 'phone', label: t('group_manager', 'Phone number'), type: 'tel', section: 'contact' },
				{ key: 'address', label: t('group_manager', 'Location'), section: 'contact' },
				{ key: 'website', label: t('group_manager', 'Website'), type: 'url', section: 'contact' },
				{ key: 'organisation', label: t('group_manager', 'Organisation'), section: 'work' },
				{ key: 'role', label: t('group_manager', 'Role'), section: 'work' },
				{ key: 'headline', label: t('group_manager', 'Headline'), section: 'work' },
				{ key: 'fediverse', label: t('group_manager', 'Fediverse (e.g. Mastodon)'), section: 'social' },
				{ key: 'bluesky', label: t('group_manager', 'Bluesky'), section: 'social' },
				{ key: 'twitter', label: t('group_manager', 'X (formerly Twitter)'), section: 'social' },
			]
			return all.filter((field) => field.key in this.details)
		},

		/** Usage against a limit; null without one (the header has the amount). */
		usage() {
			const q = this.details.quota
			if (!q || typeof q.used !== 'number' || !(typeof q.quota === 'number' && q.quota > 0)) {
				return null
			}
			return {
				percent: (q.used / q.quota) * 100,
				text: t('group_manager', '{used} of {total} used', { used: humanSize(q.used), total: humanSize(q.quota) }),
			}
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

	beforeUnmount() {
		clearTimeout(this.savedTimer)
	},

	methods: {
		t,
		n,

		value(key) {
			return key in this.draft ? this.draft[key] : (this.original[key] ?? '')
		},

		/** A field the account's backend owns, shown as text. */
		readonlyText(key) {
			return this.value(key) || t('group_manager', 'Not set')
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

		isSaved(key) {
			return this.savedKeys.includes(key)
		},

		openPassword() {
			this.passwordOpen = true
			this.$nextTick(() => this.$refs.passwordField?.focus?.())
		},

		closePassword() {
			this.passwordOpen = false
			this.stage('password', '')
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
			this.savedKeys = this.savedKeys.filter((k) => k !== key)
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
			this.passwordOpen = false
		},

		labelFor(key) {
			const labels = {
				displayname: t('group_manager', 'Display name'),
				email: t('group_manager', 'Email'),
				password: t('group_manager', 'Password'),
				quota: t('group_manager', 'Personal quota'),
				language: t('group_manager', 'Language'),
				manager: t('group_manager', 'Manager'),
				profile_enabled: t('group_manager', 'Public profile visible'),
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
			const savedKeys = []
			try {
				for (const key of keys) {
					try {
						await editUserField(this.details.id, key, this.draft[key])
						savedKeys.push(key)
						if (key === 'password') {
							const { password, ...rest } = this.draft
							this.draft = rest
							this.passwordOpen = false
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
			this.lastResult = { saved: savedKeys.length, failures }
			this.showSaved(savedKeys)
			if (savedKeys.length > 0) {
				this.$emit('changed')
			}
		},

		showSaved(keys) {
			clearTimeout(this.savedTimer)
			this.savedKeys = keys
			if (keys.length > 0) {
				this.savedTimer = setTimeout(() => { this.savedKeys = [] }, SAVED_MS)
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
	font-size: 14px;
	color: var(--color-text-maxcontrast);
}

.gm-ua__label-row {
	display: flex;
	align-items: baseline;
	gap: 8px;
	min-width: 0;
}

/* "Saved" by a field for a moment after Apply. */
.gm-ua__saved {
	display: inline-flex;
	align-items: center;
	gap: 2px;
	font-size: 13px;
	color: var(--color-success-text);
}

/* The one note for the fields the account's backend owns. */
.gm-ua__managed {
	display: flex;
	align-items: center;
	gap: 6px;
	margin: -4px 0 14px;
	font-size: 13px;
	color: var(--color-text-maxcontrast);
}

.gm-ua__password {
	display: flex;
	align-items: center;
	gap: 8px;
}

.gm-ua__password :deep(.input-field) {
	flex: 1;
	min-width: 0;
}

/* The manager: avatar and name, in the list and once picked. */
.gm-ua__person {
	display: flex;
	align-items: center;
	gap: 8px;
	min-width: 0;
}

.gm-ua__person-name {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.gm-ua__visibility {
	display: flex;
	flex-direction: column;
	gap: 2px;
	margin-bottom: 16px;
}

/* A hidden profile: its fields faded, still editable. */
.gm-ua__profile--off .gm-ua__subsection,
.gm-ua__profile--off .gm-ua__bio {
	opacity: .5;
}

.gm-ua__subsection + .gm-ua__subsection,
.gm-ua__subsection + .gm-ua__bio {
	margin-top: 20px;
}

.gm-ua__subheading {
	margin: 0 0 10px;
	font-size: 14px;
	font-weight: 600;
	color: var(--color-main-text);
}

/* A field the account's backend owns: its value as plain text, as tall as
   an input so the grid's rows stay level, with the note under it. */
.gm-ua__value {
	min-height: 36px;
	display: flex;
	align-items: center;
	overflow-wrap: anywhere;
	color: var(--color-main-text);
	font-size: var(--default-font-size);
}

.gm-ua__value--multiline {
	align-items: flex-start;
	padding-top: 8px;
	white-space: pre-wrap;
}

.gm-ua__value--empty {
	color: var(--color-text-maxcontrast);
}

.gm-ua__usage {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-top: 4px;
}

.gm-ua__hint {
	font-size: 13px;
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
