<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->

# Changelog

All notable changes to Group Manager are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/).

## [0.3.0] - 2026-09-18

### Added
- **Create group folders directly from a group's Folders tab** — a "Create
  group folder" button provisions a brand-new folder and assigns it to the
  current group in one step; Nextcloud asks for a password confirmation
  first, matching the bar the Team Folders app's own admin screen sets for
  the same operation.
- **Automated test suite** (PHPUnit, `tests/Unit/`) and a CI workflow
  (`.github/workflows/ci.yml`: l10n coverage, PHP lint + tests, frontend
  build) — closing the two top items from the Roadmap.
- Backend error messages (e.g. "Group not found") are now translated via
  `IL10N` into all five supported languages, instead of always showing in
  English regardless of the admin's own language.

### Fixed
- `setQuota()` and the pasted-member-list resolver now reject invalid input
  (a negative quota other than "unlimited", more than 500 pasted entries in
  one go) instead of passing it straight through to the backend.
- `humanSize()` now formats numbers using the session's own language instead
  of always using a comma decimal separator.
- Creating a group folder with an empty or slashes-only name used to
  silently create a folder mounted at the storage root instead of being
  rejected — found while testing the creation feature above.

## [0.2.0] - 2026-09-18

### Added
- **Group-folder assignment tab** (needs the Team Folders app): assign or
  remove a group's access to group folders, edit its **Write / Share /
  Delete** permissions and the folder's quota, and see an **ACL** badge when
  a folder has advanced permissions enabled. Works for LDAP groups the same
  as local ones.
- **Redesigned Members tab**: the old checkbox-per-row model is replaced by a
  single add field (person, whole group, or a pasted list) and a colored
  chip queue that is the one place to see everything about to change.
  Checkbox multi-select in the add-field dropdown lets several matches from
  one search be queued together; the dropdown now also browses all addable
  people alphabetically before you type anything.
- **German, Spanish, French and Portuguese (Portugal)** translations of the
  whole interface, and a `build/l10n.py` script (coverage check + `.js`
  regeneration) to keep them in sync with the source strings.
- A shared footer and Apply button across the Members and Folders tabs, with
  a pending-changes dot on whichever tab is not currently active, so
  switching tabs never hides a queued change from view.

### Changed
- The detail panel now uses the browser's available height instead of a
  fixed 70vh/720px cap.
- Quota is shown right-aligned with tabular figures; the group-folder table
  switched from an HTML `<table>` to a flex-row layout to stop the columns
  from overflowing the panel on narrower windows.

### Fixed
- A member whose backend account could not be fully resolved (an LDAP
  hiccup, or an account deleted moments earlier) used to 500 the *entire*
  member list instead of just that one row.
- Several custom-sized controls (permission switches, the × remove buttons,
  the search field) were silently overridden by Nextcloud's own global
  `<button>`/`<input>` sizing and color rules; each is now scoped explicitly
  so the intended compact size and color survive.
- A group-folder chip color contrast issue, a paste-review count that
  silently dropped members already in the group, and a dropdown that would
  not close on an outside click after the "browse before typing" change.

## [0.1.0] - 2026-09-14

### Added
- Initial release: browse local and LDAP/AD groups from one Settings screen,
  inspect members (paginated, searchable), and manage local group membership
  (add/remove, one user or a batch) without leaving the group.
- Create, rename and delete local groups; LDAP groups are shown read-only
  with their backend and, once resolvable, their directory DN.
- Local-group membership changes apply as a batch with limited concurrency
  that does not abort over a single failed row.
- Deep-linkable group selection via a `#group=<gid>` URL hash, a
  discard-changes guard on tab close and on switching groups, and keyboard
  support throughout.
