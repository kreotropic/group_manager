<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->

# Changelog

All notable changes to Group Manager are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/).

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
