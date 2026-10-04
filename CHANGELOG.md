<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->

# Changelog

All notable changes to Group Manager are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/).

## [Unreleased]

## [0.4.1] - 2026-10-04

### Changed
- App Store screenshot URLs now point at the release's own tag instead of
  `master`. The App Store's screenshot mirror copies each URL once and never
  refreshes it, so a new URL per release is the only way updated screenshots
  reach the listing, and the images always match that version. No code
  changes.

  This release was made to fix the blank screenshots on the App Store
  listing, and it can't: they are blank because of a bug in the App Store's
  screenshot mirror, which serves every recently copied screenshot empty
  ([usercontent.apps.nextcloud.com#26](https://github.com/nextcloud/usercontent.apps.nextcloud.com/issues/26)).

## [0.4.0] - 2026-10-03

### Fixed
- **Opening any group's detail view (local or LDAP) returned HTTP 500 on
  Nextcloud 31** as soon as the Team Folders app (`groupfolders` 19.x) was
  installed, because `GroupService::detail()` counts a group's folders on
  every load. `FolderAssignmentService` called groupfolders' internal
  `FolderManager` the way its 20+ releases expect (a required storage-id
  argument dropped, folders read as `FolderWithMappingsAndCache` objects),
  which groupfolders 19.x doesn't provide (it takes that argument and returns
  plain arrays with different keys). Every result now goes through one
  normalization step, and the one groupfolders method this app used that
  doesn't exist before 20 (`mountPointExists()`) is replaced with a direct
  query against its own table, mirroring how `folder_protection` already
  reads groupfolders' schema instead of its unstable internal API.
- **Two admins removing each other from the `admin` group at the same
  moment could both succeed, leaving the instance with no administrators.**
  `removeMember()` now serializes removals from `admin` through an
  `OCP\Lock\ILockingProvider` exclusive lock, re-reading membership and the
  admin count only after acquiring it; a backend that can't answer the count
  at all is now treated as unsafe rather than let through. This closes the
  race for removals made through this app; it can't coordinate an admin
  removed some other way (`occ`, the core Users page, another app).
- **A group folder could be assigned, created, or have its permissions/quota
  changed for a group ID that doesn't exist** (a typo, or a group deleted
  between being picked and the request landing), leaving an association that
  silently grants full access the moment a group with that ID exists again
  (LDAP re-sync, or an admin recreating it). `FolderAssignmentService` now
  requires the group to exist before every such write. Unassigning is
  deliberately exempt, so an association left over from before this fix (or
  from this exact race, which a lookup-then-write can narrow but not close)
  can still be removed.
- **Pasting a list of users into "add members" showed a wrong count of new
  members** when the account belonged to the group but was outside the
  currently loaded/filtered page: the frontend decided membership from
  whatever page it had, not the group's real membership. `resolvePastedList`
  now reports each entry's real membership from the server. The "N members
  after applying" prediction also no longer uses the search-filtered member
  count as if it were the group's real size.
- Going back or forward in the browser past a group with unapplied member or
  folder changes used to discard them without asking; only switching groups
  from the list itself was guarded. Back/Forward now asks the same question,
  and reverts the jump if declined; while a batch is actually being applied,
  navigating away (either way) is refused outright, not just guarded by a
  prompt.
- A search whose response arrived out of order (members, add-field
  candidates, or assignable folders) could overwrite the current results
  with a stale answer to an earlier, already-superseded search. Every such
  search now discards an answer that's no longer for the latest query.
- A failed member/folder list load, or a failed refresh right after
  successfully applying changes, showed an empty or stale list with no
  indication anything had gone wrong. These now show an explicit error with
  a way to retry, and a successful write's own result is no longer hidden by
  a follow-up refresh failing.
- Group member pages could not be paged past the first one whenever the
  backend could enumerate members but couldn't answer a plain count
  (`count()` returning `false`, seen with some LDAP setups): the frontend
  required a known total to show "Load more". Paging now uses a `hasMore`
  flag the API derives from an extra fetched row, independent of the total.
- Creating a group whose ID contains `/` succeeded but left it permanently
  unreachable through this app's own API (every route needs `/`-free IDs);
  rejected at creation now. A group with `/` in its ID created some other
  way (occ, LDAP) is unaffected and still manageable everywhere else.
- Malformed input reached internal errors (HTTP 500) instead of a clean
  `400`: non-string entries pasted into the add-members list, and
  out-of-range `limit`/`offset` values for a group's member list. Both are
  now validated up front with a stable error code.
- **The add-members search for whole groups used to enumerate every member
  of the destination group, and every candidate group's own full
  membership, on every keystroke**: a large directory made this measurably
  slow. Membership is now checked one candidate at a time, up to
  a fixed page budget; a candidate group's entry shows its own size, not an
  exact "how many would be new" count (computed once, cheaply, only for the
  group actually picked). Opening a group's Folders tab, or counting its
  folders for the sidebar, no longer reads every group folder in the
  instance to find the ones assigned to it; both now query the assignment
  table directly for that group.
- The add field and the "Filter members" field are the same height again: a
  global Nextcloud input rule made the add field 2px taller, and its outline
  was clipped at the top.
- The Folders tab's Write / Share / Delete headers no longer run into each
  other in Portuguese, Spanish and French: the columns were narrower than the
  translated words.

### Known limitations
- The lock preventing the last-admin race, and the existence checks
  preventing orphaned folder associations, only coordinate operations made
  through this app. `occ`, the core Users settings page, or another app can
  still race with, or bypass, either.
- A folder-association row left over from before this release, or from a
  group deleted through some other route while this app was mid-request,
  isn't found or cleaned up automatically; only `unassignFolder()` can
  remove it, and only once someone notices it.
- A group ID containing `/` created before this release (or through `occ`,
  LDAP, another app) remains unreachable through this app's own group
  routes; only creating a *new* one that way is now rejected.
- Group folders NOT yet assigned to a group (the assignment field's search)
  are still found by reading every group folder in the instance; there's no
  per-group index for "absent from this group's assignment list" the way
  there is for "assigned to it".
- The groups list itself (left-hand panel) still loads every group and its
  member count up front; it isn't paged.
- On Nextcloud 34+, a request for a group's member list with a `pageSize`
  outside `[1, 500]` and no other malformed input gets this app's own clean
  `400` (worked around: the parameter isn't named `limit`, which the
  AppFramework's own request dispatcher special-cases as of that version).
  The add-field's candidate search and the folder-assignment search still
  use a parameter literally named `limit` and are not worked around the same
  way: an extreme, out-of-range value there can still surface as that
  framework's own raw 500 on 34/35.

### Added
- **Group admins.** A shield on each member's row makes them an admin of the
  group (a Nextcloud "subadmin"), queued and applied with the other changes;
  current admins show a badge, and the group's summary counts them. Works on
  LDAP groups too, whose membership stays read-only: the assignment is
  stored by Nextcloud, not in the directory, so LDAP groups now use the same
  member list as local ones, minus adding and removing. Removing a member
  also ends their group admin role (Nextcloud itself keeps it until the user
  or group is deleted). Group admins who aren't members, which the core
  Users page allows, are listed above the members and can be revoked. The
  `admin` group can't be given group admins, matching Nextcloud's own
  provisioning API: a group admin of `admin` could add themselves to it.
- **Nextcloud 35 support**, verified against a disposable instance the same
  way as 31–34.
- **Optional top bar shortcut** to the Group Manager page, switched on per
  admin from the groups list's menu (off by default).
- **The add field pages through every candidate** as the dropdown scrolls,
  instead of stopping at the first 10; without a search term, candidates
  are listed by display name.
- LDAP groups show a read-only badge and their DN, with a copy button.
- Groups whose names differ only by case are flagged in the list.
- Members show their profile picture when they have one.

### Changed
- Reworked layout: no outer frame, theme-safe colours (light, dark and high
  contrast), a fixed header over scrolling group and member lists, sticky
  section headers in the groups list, and one shared header height so the
  rule under the tabs runs straight across both columns.
- Tighter header: less padding above the app, the All / Local / LDAP filters
  on the groups list's title line, a shorter header for every group but an
  LDAP one (whose DN needs a line of its own), and the group's summary
  (members, admins, folders, disabled accounts) on the same line as its name.
- The "N after applying" count moved into that summary, so queuing the first
  change no longer shifts the add field sideways.
- Member rows fit on one line (name, then username or email), stacking only
  in narrow panels; UUID-style usernames are shown as a tooltip instead.
- Deleting a group moved to an actions menu beside the group's name; the
  apply/discard footer appears only while changes are pending.
- Keyboard focus rings show only for keyboard navigation, and Chrome on
  Windows no longer draws arrow buttons on the app's scrollbars.

## [0.3.0] - 2026-09-18

### Added
- **Create group folders directly from a group's Folders tab**: a "Create
  group folder" button provisions a brand-new folder and assigns it to the
  current group in one step; Nextcloud asks for a password confirmation
  first, matching the bar the Team Folders app's own admin screen sets for
  the same operation.
- **Automated test suite** (PHPUnit, `tests/Unit/`) and a CI workflow
  (`.github/workflows/ci.yml`: l10n coverage, PHP lint + tests, frontend
  build), closing the two top items from the Roadmap.
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
  rejected; found while testing the creation feature above.

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
