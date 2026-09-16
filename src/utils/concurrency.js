/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Run `worker` over every item in `items`, at most `limit` at a time.
 * Never rejects because a single item failed — `worker` is expected to
 * catch its own errors and encode failure in its return value, since bulk
 * member changes must never abort the whole batch over one bad row.
 *
 * @template T, R
 * @param {T[]} items
 * @param {number} limit
 * @param {(item: T, index: number) => Promise<R>} worker
 * @return {Promise<R[]>}
 */
export async function runWithConcurrency(items, limit, worker) {
	const results = new Array(items.length)
	let nextIndex = 0

	async function runNext() {
		const index = nextIndex++
		if (index >= items.length) {
			return
		}
		results[index] = await worker(items[index], index)
		await runNext()
	}

	const workers = Array.from({ length: Math.min(limit, items.length) }, runNext)
	await Promise.all(workers)
	return results
}
