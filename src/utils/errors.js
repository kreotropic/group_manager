/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * GroupServiceException responses already carry a human-readable message
 * (`error`) and a stable `code` — this just extracts the former with a
 * fallback for network errors / anything that isn't our own API shape.
 */
export function extractErrorMessage(err, fallback) {
	return err?.response?.data?.error || fallback
}

/**
 * The stable error code (e.g. GROUP_ALREADY_EXISTS), if any.
 */
export function extractErrorCode(err) {
	return err?.response?.data?.code || null
}
