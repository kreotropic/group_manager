/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

const UNITS = ['B', 'KB', 'MB', 'GB', 'TB', 'PB']

/**
 * "12,4 GB" — comma decimal to match pt-PT formatting used throughout the design.
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
	const formatted = unitIndex > 0 && value < 100 ? value.toFixed(1) : Math.round(value).toString()
	return formatted.replace('.', ',') + ' ' + UNITS[unitIndex]
}
