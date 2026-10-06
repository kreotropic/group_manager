/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * A group folder's mount point is a `/`-separated path. Nextcloud shows every
 * level above the last as a plain folder and only the last one as the group
 * folder itself, so the "tree" is just those levels: a node is a *real* group
 * folder when some mount point ends exactly there, and a plain level
 * otherwise.
 */

/**
 * Same shape the server normalizes to: backslashes as slashes, no leading or
 * trailing slash, no empty levels.
 *
 * @param {string} raw
 * @return {string}
 */
export function normalizePath(raw) {
	return String(raw ?? '')
		.replace(/\\/g, '/')
		.split('/')
		.map((part) => part.trim())
		.filter((part) => part !== '')
		.join('/')
}

/**
 * The parent level of what is being typed: everything before the last `/`.
 * "A/B/" and "A/B/new" both have parent "A/B"; "new" has none.
 *
 * @param {string} raw
 * @return {string}
 */
export function typedParent(raw) {
	const text = String(raw ?? '').replace(/\\/g, '/')
	const cut = text.lastIndexOf('/')
	return cut === -1 ? '' : normalizePath(text.slice(0, cut))
}

/**
 * "A/B/C" -> ["A", "A/B"]
 *
 * @param {string} path normalized
 * @return {string[]}
 */
export function ancestorsOf(path) {
	const parts = path === '' ? [] : path.split('/')
	return parts.slice(0, -1).map((_, i) => parts.slice(0, i + 1).join('/'))
}

const collator = new Intl.Collator(undefined, { sensitivity: 'base', numeric: true })

/**
 * @param {{id: number, mountPoint: string}[]} folders
 * @return {{roots: object[], byPath: Map<string, object>}} nodes are
 *   { path, name, folderId (null for a plain level), children }
 */
export function buildTree(folders) {
	const byPath = new Map()
	const roots = []

	const ensure = (path) => {
		let node = byPath.get(path)
		if (!node) {
			const cut = path.lastIndexOf('/')
			node = { path, name: path.slice(cut + 1), folderId: null, children: [] }
			byPath.set(path, node)
			if (cut === -1) {
				roots.push(node)
			} else {
				ensure(path.slice(0, cut)).children.push(node)
			}
		}
		return node
	}

	for (const folder of folders) {
		const path = normalizePath(folder.mountPoint)
		if (path !== '') {
			ensure(path).folderId = folder.id
		}
	}

	const sort = (nodes) => {
		nodes.sort((a, b) => collator.compare(a.name, b.name))
		nodes.forEach((node) => sort(node.children))
	}
	sort(roots)

	return { roots, byPath }
}

/**
 * The rows to draw: depth-first, descending only into expanded nodes.
 *
 * @param {object[]} roots
 * @param {Set<string>} expanded paths
 * @return {{node: object, depth: number}[]}
 */
export function visibleRows(roots, expanded) {
	const rows = []
	const walk = (nodes, depth) => {
		for (const node of nodes) {
			rows.push({ node, depth })
			if (node.children.length > 0 && expanded.has(node.path)) {
				walk(node.children, depth + 1)
			}
		}
	}
	walk(roots, 0)
	return rows
}
