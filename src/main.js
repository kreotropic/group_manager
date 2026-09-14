/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { createApp } from 'vue'
import App from './App.vue'

document.addEventListener('DOMContentLoaded', () => {
	const admin = document.getElementById('group-manager')
	if (admin) {
		createApp(App).mount(admin)
	}
})
