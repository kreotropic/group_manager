<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->

# Group Manager — Roadmap

## Current state (v0.2.0)

The app is feature-complete for its core purpose — browsing groups and
bulk-editing local membership — and has grown a second major capability,
group-folder assignment, that was not part of the original plan. It is not
yet on the App Store and has no automated test suite; see **Next up** below.
Everything in this section is already implemented and working:

### Delivered

**Browsing**
- Local and LDAP/AD groups side by side, with search and an All / Local /
  LDAP filter, LDAP groups grouped under a "Synced" heading
- Case-duplicate display names (a common LDAP artifact) disambiguated by
  showing the real group ID
- Deep-linkable via a `#group=<gid>` URL hash

**Members tab**
- One field adds a person, expands a whole group into its addable members as
  a single queued action, or resolves a pasted multi-line list against real
  accounts before queueing
- Click-to-browse (alphabetically, server-sorted) and type-to-filter in the
  same dropdown; checkbox multi-select so several matches from one search can
  be queued without reopening it
- Nothing applies until **Apply** — a colored chip queue (green add / red
  remove) is the single source of truth for pending changes
- Batch apply with limited concurrency that never aborts over one failed row;
  failed rows stay queued with their error and a **Try again** that retries
  only those
- A confirmation guard when switching to a different group with changes still
  queued

**Folders tab** (present only when the Team Folders app is installed)
- Assign/unassign group folders to the selected group; per-group **Write /
  Share / Delete** switches (read is always implied, never a fourth switch)
- Folder quota shown and **editable** from here, with an explicit note that
  it is shared by every group with access to the folder
- An ACL badge on folders with advanced permissions turned on, since
  effective access can be more restrictive than the switches show
- Works identically for LDAP groups — folder assignment is not gated by how
  the group's members are managed
- Same chip-queue / batch-apply / never-abort model as Members, sharing one
  combined footer and Apply button with the Members tab so nothing gets
  applied by surprise from a tab you are not looking at

**LDAP groups**
- Members are read-only (the directory is the source of truth), with the
  group's DN shown; Folders stays fully editable
- Broken-backend accounts (a demo/test-data artifact, but a real class of
  problem) degrade gracefully: email/enabled-status lookups that throw fall
  back to safe defaults instead of 500ing the whole member list, and rows
  fall back to showing the account's `uid` in place of a missing email

**Accessibility & polish**
- Keyboard navigation (arrows, Enter, Esc) through the add-field dropdown
- A live region announces group-count changes
- Custom toggle switches and remove buttons avoid Nextcloud's global
  `<button>`/`<input>` sizing rules where a compact control needs to differ
  from the platform default (documented inline where it bites)

**Internationalization**
- Full UI translated into English, Portuguese (Portugal), German, Spanish
  and French, with a coverage-checking build script (`build/l10n.py`)

## Next up

Roughly in priority order:

### 1. Automated tests

`composer.json` already declares `phpunit/phpunit`, but there is no `tests/`
directory yet. `GroupService` and `FolderAssignmentService` are the two
places worth covering first — the local/LDAP backend gate
(`requireLocal()`), the permission-bitmask math in
`FolderAssignmentService::setPermissions()`, and the "browse when empty,
filter when typed" candidate search are all easy to get subtly wrong on a
refactor and hard to catch by eye in review.

### 2. CI

No `.github/workflows/` yet. At minimum: run `php -l` / a static analyser
over `lib/`, run `npm run build` to catch a broken frontend build, and run
`python3 build/l10n.py --check` so a new UI string added without its English
key (or a language quietly falling behind) fails the build instead of
shipping silently untranslated.

### 3. Accessibility contrast audit

The redesigned UI (chip queue, tabs, the folders table) was built against
Nextcloud's `--color-*` custom properties throughout, which should track
the platform's own contrast decisions in both light and dark themes — but
this has not been checked systematically against the 4.5:1 minimum for
secondary text across every screen and both themes, only spot-checked
during development.

### 4. App Store publication

Package and sign a release once the above are in a comfortable state. No
blockers identified so far — `info.xml` already declares the supported
Nextcloud range and PHP requirement.

## Post-launch — only if there's traction

- **Backend-side error message translation.** API error strings (e.g. "Group
  not found") are currently plain PHP strings, not run through `IL10N`, so
  they stay in English regardless of the admin's language — consistent with
  how the rest of the UI's error *handling* already works (the frontend
  supplies a translated fallback message and only shows the raw backend
  string when there is nothing better), but worth revisiting if it turns out
  admins rely on reading the exact backend message.
- **Bulk permission/quota edits across multiple folders at once**, the way
  Members already supports picking several people in one search — Folders
  currently queues one folder's permission or quota change at a time.
- **A "recently viewed groups" shortcut** for instances with very many
  groups, where the alphabetical left-hand list alone gets long to scroll.
