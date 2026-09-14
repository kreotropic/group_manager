/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

const path = require('path')
const webpackConfig = require('@nextcloud/webpack-vue-config')

// Single admin-settings entry point. @nextcloud/webpack-vue-config prefixes the
// app id, so this emits js/group_manager-main.js, which templates/admin.php
// loads via script('group_manager', 'group_manager-main').
webpackConfig.entry = {
	main: path.join(__dirname, 'src', 'main.js'),
}

module.exports = webpackConfig
