<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div class="gm-app">
		<h2>{{ t('group_manager', 'Group Manager') }}</h2>
		<p class="gm-placeholder">
			{{ t('group_manager', 'Scaffold is up. The group list and member management UI land in later phases.') }}
		</p>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'

export default {
	name: 'App',
	methods: {
		t,
	},
}
</script>

<style scoped>
.gm-app {
	max-width: 100%;
}

.gm-placeholder {
	color: var(--color-text-maxcontrast);
}
</style>
