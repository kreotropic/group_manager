<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->
<template>
	<span class="gm-avatar" :style="{ width: size + 'px', height: size + 'px', fontSize: Math.round(size * 0.4) + 'px' }" aria-hidden="true">
		<img v-if="photoUrl" :src="photoUrl" alt="" class="gm-avatar__img">
		<template v-else>{{ initials }}</template>
	</span>
</template>

<script>
import { generateUrl } from '@nextcloud/router'

// uid@size -> Promise<boolean>: one probe per user however many rows or
// re-renders ask, and a repeat visit to a group costs nothing.
const customAvatarCache = new Map()

function hasCustomAvatar(uid, size) {
	const key = uid + '@' + size
	if (!customAvatarCache.has(key)) {
		const url = generateUrl('/avatar/{uid}/{size}', { uid, size })
		customAvatarCache.set(key, fetch(url, { credentials: 'same-origin' })
			.then((response) => response.ok && response.headers.get('X-NC-IsCustomAvatar') === '1')
			.catch(() => false))
	}
	return customAvatarCache.get(key)
}

/**
 * The user's own photo when they uploaded one, otherwise initials on one
 * neutral, theme-aware circle. NcAvatar's generated placeholder is a
 * different pastel colour per user with white text, which read as random
 * and failed contrast; Nextcloud flags real photos with X-NC-IsCustomAvatar,
 * so only those are shown as images.
 */
export default {
	name: 'MemberAvatar',

	props: {
		uid: {
			type: String,
			required: true,
		},
		name: {
			type: String,
			required: true,
		},
		size: {
			type: Number,
			default: 28,
		},
	},

	data() {
		return {
			photoUrl: '',
		}
	},

	computed: {
		initials() {
			const words = this.name.trim().split(/\s+/).filter(Boolean)
			if (words.length === 0) {
				return '?'
			}
			const first = Array.from(words[0])[0]
			const last = words.length > 1 ? Array.from(words[words.length - 1])[0] : ''
			return (first + last).toUpperCase()
		},

		requestSize() {
			// 2x for high-DPI screens, in the steps the avatar endpoint caches.
			return this.size * 2 <= 64 ? 64 : 128
		},
	},

	watch: {
		uid: 'probe',
	},

	mounted() {
		this.probe()
	},

	methods: {
		async probe() {
			this.photoUrl = ''
			const uid = this.uid
			if (await hasCustomAvatar(uid, this.requestSize) && uid === this.uid) {
				this.photoUrl = generateUrl('/avatar/{uid}/{size}', { uid, size: this.requestSize })
			}
		},
	},
}
</script>

<style scoped>
.gm-avatar {
	flex-shrink: 0;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	overflow: hidden;
	border-radius: 50%;
	background: var(--color-background-dark);
	color: var(--color-main-text);
	font-weight: 600;
	line-height: 1;
}

.gm-avatar__img {
	width: 100%;
	height: 100%;
	object-fit: cover;
}
</style>
