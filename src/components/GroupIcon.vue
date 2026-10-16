<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<span class="group-icon"
		:class="backend === 'ldap' ? 'group-icon--ldap' : 'group-icon--local'"
		:style="{ width: size + 'px', height: size + 'px' }"
		aria-hidden="true">
		<AccountNetwork v-if="backend === 'ldap'" :size="iconSize" />
		<AccountGroup v-else :size="iconSize" />
	</span>
</template>

<script>
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import AccountNetwork from 'vue-material-design-icons/AccountNetwork.vue'

/**
 * Square badge with an icon for a group's origin: LDAP in the theme's
 * primary colour, local in its success colour. Used in the group list and
 * the detail header, so the two read as one system.
 */
export default {
	name: 'GroupIcon',

	components: {
		AccountGroup,
		AccountNetwork,
	},

	props: {
		backend: {
			type: String,
			default: 'local',
		},
		size: {
			type: Number,
			default: 28,
		},
	},

	computed: {
		iconSize() {
			return Math.round(this.size * 0.6)
		},
	},
}
</script>

<style scoped>
.group-icon {
	display: flex;
	align-items: center;
	justify-content: center;
	flex: none;
	border-radius: var(--border-radius-large);
}

/* The icon wrapper is an inline span; as a flex item it centres exactly,
   with no line-height gap under the SVG. */
.group-icon :deep(.material-design-icon) {
	display: flex;
}

.group-icon--local {
	background: color-mix(in srgb, var(--color-success) 18%, transparent);
	color: var(--color-success-text, var(--color-success));
}

.group-icon--ldap {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}
</style>
