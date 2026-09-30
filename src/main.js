/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createApp } from 'vue'
import App from './App.vue'
// @nextcloud/dialogs 7 ships its toast styles separately; without this the
// showSuccess()/showError() toasts render as bare unstyled text.
import '@nextcloud/dialogs/style.css'

document.addEventListener('DOMContentLoaded', () => {
	const admin = document.getElementById('group-manager')
	if (admin) {
		createApp(App).mount(admin)
	}
})
