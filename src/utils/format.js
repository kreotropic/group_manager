/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getLanguage } from '@nextcloud/l10n'

const UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB']

/**
 * "12,4 GB" in pt-PT, "12.4 GB" in en, ... — decimal separator follows the
 * session's own language instead of being hardcoded to one locale.
 *
 * @param {number} bytes
 */
export function humanSize(bytes) {
	let value = Math.max(bytes, 0)
	let unitIndex = 0
	while (value >= 1024 && unitIndex < UNITS.length - 1) {
		value /= 1024
		unitIndex++
	}
	const formatted = new Intl.NumberFormat(getLanguage(), {
		maximumFractionDigits: unitIndex > 0 && value < 100 ? 1 : 0,
	}).format(value)
	return formatted + ' ' + UNITS[unitIndex]
}
