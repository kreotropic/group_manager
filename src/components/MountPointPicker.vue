<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<div class="gm-mp">
		<NcTextField ref="field"
			:model-value="modelValue"
			:label="t('group_manager', 'Folder path')"
			:placeholder="t('group_manager', 'Departments/Projects/New folder')"
			:error="exists"
			:disabled="disabled"
			@update:model-value="$emit('update:modelValue', $event)" />

		<p class="gm-mp__hint">
			{{ t('group_manager', 'Type a full path, or pick a level below to create the folder inside it.') }}
		</p>

		<div v-if="preview.length > 0" class="gm-mp__preview" aria-live="polite">
			<span class="gm-mp__preview-label">{{ t('group_manager', 'Appears in Files as') }}</span>
			<span class="gm-mp__crumbs">
				<template v-for="(part, index) in preview" :key="index">
					<ChevronRight v-if="index > 0" :size="14" class="gm-mp__crumb-sep" />
					<span class="gm-mp__crumb" :class="{ 'gm-mp__crumb--last': index === preview.length - 1 }">{{ part }}</span>
				</template>
			</span>
			<span v-if="preview.length > 1" class="gm-mp__preview-note">
				{{ t('group_manager', 'Only the last level is the group folder; the levels above it are plain folders without permissions of their own.') }}
			</span>
		</div>

		<NcNoteCard v-if="exists" type="error">
			{{ t('group_manager', 'A group folder with this path already exists.') }}
		</NcNoteCard>
		<NcNoteCard v-else-if="insideFolder" type="warning">
			{{ t('group_manager', '"{name}" is itself a group folder; the new folder would be mounted inside it.', { name: insideFolder.path }) }}
		</NcNoteCard>

		<div class="gm-mp__tree-title">
			{{ t('group_manager', 'Existing folders') }}
		</div>
		<div class="gm-mp__tree">
			<div v-if="loading" class="gm-mp__status">
				<NcLoadingIcon :size="20" />
			</div>
			<p v-else-if="loadError" class="gm-mp__status gm-mp__status--error">
				{{ loadError }}
			</p>
			<p v-else-if="rows.length === 0" class="gm-mp__status">
				{{ t('group_manager', 'No group folders yet.') }}
			</p>
			<ul v-else class="gm-mp__list" role="tree" :aria-label="t('group_manager', 'Existing folders')">
				<li v-for="{ node, depth } in rows"
					:key="node.path"
					class="gm-mp__row"
					:class="{ 'gm-mp__row--current': node.path === parent }"
					role="treeitem"
					:aria-level="depth + 1"
					:aria-expanded="node.children.length > 0 ? String(expanded.has(node.path)) : undefined"
					:aria-selected="String(node.path === parent)"
					:style="{ paddingInlineStart: (depth * 18 + 4) + 'px' }">
					<button v-if="node.children.length > 0"
						type="button"
						class="gm-mp__toggle"
						:aria-label="expanded.has(node.path) ? t('group_manager', 'Collapse') : t('group_manager', 'Expand')"
						@click="toggle(node.path)">
						<ChevronDown v-if="expanded.has(node.path)" :size="18" />
						<ChevronRight v-else :size="18" />
					</button>
					<span v-else class="gm-mp__toggle-spacer" />
					<button type="button"
						class="gm-mp__pick"
						:disabled="disabled"
						@click="pick(node.path)">
						<FolderAccount v-if="node.folderId !== null"
							:size="18"
							class="gm-mp__icon gm-mp__icon--folder"
							:title="t('group_manager', 'Group folder')" />
						<FolderOutline v-else :size="18" class="gm-mp__icon" />
						<span class="gm-mp__name">{{ node.name }}</span>
					</button>
				</li>
			</ul>
		</div>
	</div>
</template>

<script>
import { translate as t } from '@nextcloud/l10n'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import ChevronDown from 'vue-material-design-icons/ChevronDown.vue'
import ChevronRight from 'vue-material-design-icons/ChevronRight.vue'
import FolderAccount from 'vue-material-design-icons/FolderAccount.vue'
import FolderOutline from 'vue-material-design-icons/FolderOutline.vue'
import { fetchMountPoints } from '../services/api.js'
import { extractErrorMessage } from '../utils/errors.js'
import { ancestorsOf, buildTree, normalizePath, typedParent, visibleRows } from '../utils/mountPointTree.js'

/**
 * Text field for a group folder's mount point plus a browsable tree of the
 * levels that already exist. Picking a level doesn't create or select
 * anything on its own: it writes "Level/Sub/" into the field, ready for the
 * new folder's own name to be typed after it.
 */
export default {
	name: 'MountPointPicker',

	components: {
		ChevronDown,
		ChevronRight,
		FolderAccount,
		FolderOutline,
		NcLoadingIcon,
		NcNoteCard,
		NcTextField,
	},

	props: {
		modelValue: {
			type: String,
			default: '',
		},
		disabled: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['update:modelValue'],

	data() {
		return {
			loading: true,
			loadError: '',
			tree: { roots: [], byPath: new Map() },
			expanded: new Set(),
		}
	},

	computed: {
		normalized() {
			return normalizePath(this.modelValue)
		},

		parent() {
			return typedParent(this.modelValue)
		},

		preview() {
			return this.normalized === '' ? [] : this.normalized.split('/')
		},

		rows() {
			return visibleRows(this.tree.roots, this.expanded)
		},

		/**
		 * Same path as an existing folder, ignoring case (the server rejects
		 * the exact duplicate; one that only differs by case is allowed
		 * there but indistinguishable to a person, so it is flagged too).
		 */
		exists() {
			const wanted = this.normalized.toLowerCase()
			if (wanted === '') {
				return false
			}
			for (const [path, node] of this.tree.byPath) {
				if (node.folderId !== null && path.toLowerCase() === wanted) {
					return true
				}
			}
			return false
		},

		/** The nearest ancestor of the typed path that is itself a group folder. */
		insideFolder() {
			const ancestors = ancestorsOf(this.normalized)
			for (let i = ancestors.length - 1; i >= 0; i--) {
				const node = this.tree.byPath.get(ancestors[i])
				if (node && node.folderId !== null) {
					return node
				}
			}
			return null
		},
	},

	watch: {
		// Keep the tree open on the level being typed into.
		parent: 'revealParent',
	},

	async mounted() {
		try {
			this.tree = buildTree(await fetchMountPoints())
			this.revealParent()
		} catch (err) {
			this.loadError = extractErrorMessage(err, t('group_manager', 'Could not load the existing folders.'))
		} finally {
			this.loading = false
		}
		this.$nextTick(() => this.focus())
	},

	methods: {
		t,

		focus() {
			this.$refs.field?.focus?.()
		},

		toggle(path) {
			const next = new Set(this.expanded)
			if (next.has(path)) {
				next.delete(path)
			} else {
				next.add(path)
			}
			this.expanded = next
		},

		revealParent() {
			if (this.parent === '') {
				return
			}
			const next = new Set(this.expanded)
			for (const path of [...ancestorsOf(this.parent), this.parent]) {
				if (this.tree.byPath.has(path)) {
					next.add(path)
				}
			}
			this.expanded = next
		},

		pick(path) {
			this.$emit('update:modelValue', path + '/')
			this.$nextTick(() => this.focus())
		},
	},
}
</script>

<style scoped>
.gm-mp {
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.gm-mp__hint {
	margin: 0;
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.gm-mp__preview {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 8px 12px;
	border-radius: var(--border-radius-large);
	background: var(--color-background-hover);
}

.gm-mp__preview-label,
.gm-mp__preview-note {
	font-size: 12px;
	color: var(--color-text-maxcontrast);
}

.gm-mp__crumbs {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 2px;
	min-width: 0;
}

.gm-mp__crumb {
	overflow-wrap: anywhere;
}

.gm-mp__crumb--last {
	font-weight: bold;
}

.gm-mp__crumb-sep {
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
}

.gm-mp__tree-title {
	margin-top: 4px;
	font-size: 12px;
	font-weight: bold;
	text-transform: uppercase;
	letter-spacing: .07em;
	color: var(--color-text-maxcontrast);
}

.gm-mp__tree {
	max-height: 240px;
	overflow-y: auto;
	scrollbar-gutter: stable;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background: var(--color-main-background);
}

.gm-mp__status {
	display: flex;
	justify-content: center;
	margin: 0;
	padding: 16px;
	color: var(--color-text-maxcontrast);
}

.gm-mp__status--error {
	color: var(--color-error-text);
}

.gm-mp__list {
	margin: 0;
	padding: 4px 0;
	list-style: none;
}

.gm-mp__row {
	display: flex;
	align-items: center;
}

.gm-mp__row--current {
	background: var(--color-primary-element-light);
	color: var(--color-primary-element-light-text);
}

.gm-mp__row:not(.gm-mp__row--current):hover {
	background: var(--color-background-hover);
}

.gm-mp__toggle,
.gm-mp__toggle-spacer {
	flex: 0 0 28px;
	width: 28px;
	height: 32px;
}

.gm-mp__list .gm-mp__toggle,
.gm-mp__list .gm-mp__pick {
	margin: 0;
	min-height: 0;
	border: none;
	background: transparent;
	color: inherit;
	cursor: pointer;
}

.gm-mp__list .gm-mp__toggle {
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 0;
	border-radius: var(--border-radius);
}

.gm-mp__list .gm-mp__pick {
	flex: 1;
	min-width: 0;
	display: flex;
	align-items: center;
	gap: 6px;
	height: 32px;
	padding: 0 8px 0 0;
	text-align: start;
	font-family: inherit;
	font-size: 14px;
	font-weight: normal;
}

.gm-mp__icon {
	flex-shrink: 0;
	color: var(--color-text-maxcontrast);
}

.gm-mp__icon--folder {
	color: var(--color-primary-element);
}

.gm-mp__name {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
}

.gm-mp__list .gm-mp__row--current .gm-mp__pick {
	font-weight: bold;
}

.gm-mp__row--current .gm-mp__name {
	font-weight: bold;
}
</style>
