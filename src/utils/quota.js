/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { translate as t } from '@nextcloud/l10n'

/**
 * A size core's quota parser accepts: a number with an optional unit
 * ("15 GB", "500MB", "2.5 tb").
 */
const SIZE_PATTERN = /^\d+(?:[.,]\d+)?\s*(?:B|K|KB|M|MB|G|GB|T|TB)?$/i

/**
 * A personal quota as typed or picked: 'default', 'none' or a size.
 */
export function isValidQuota(value) {
	return value === 'default' || value === 'none' || SIZE_PATTERN.test(String(value).trim())
}

export function quotaLabel(value, defaultQuota) {
	if (value === 'default') {
		return t('group_manager', 'Default ({quota})', {
			quota: defaultQuota === 'none' ? t('group_manager', 'Unlimited') : defaultQuota,
		})
	}
	if (value === 'none') {
		return t('group_manager', 'Unlimited')
	}
	return value
}

/**
 * The choices for a personal quota select: default, unlimited, the
 * instance's presets (Users settings), and `current` when it is none of
 * those (a custom size set earlier).
 *
 * @param {object} options from fetchUserOptions()
 * @param {string} [current]
 * @return {{id: string, label: string}[]}
 */
export function quotaChoices(options, current) {
	const ids = ['default', 'none', ...(options?.quotaPresets ?? [])]
	if (current && !ids.includes(current)) {
		ids.push(current)
	}
	return ids.map((id) => ({ id, label: quotaLabel(id, options?.defaultQuota ?? 'none') }))
}
