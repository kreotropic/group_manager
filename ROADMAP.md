<!--
  - SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
  - SPDX-License-Identifier: AGPL-3.0-or-later
  -->

# Group Manager: Roadmap

## Current state (v0.5.0)

The app is feature-complete for its core purpose (browsing groups and
bulk-editing local membership) and has grown two capabilities that were not
part of the original plan: group-folder creation and assignment, group
admin (subadmin) management, and group quotas through the Group Quota app. It has an automated test suite, CI and a
per-version test matrix (Nextcloud 31–35); 0.4.0 is its first App Store
release, published 2026-10-03. Everything in this section is already implemented and working:

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
- Nothing applies until **Apply**: a colored chip queue (green add / red
  remove / blue group admin) is the single source of truth for pending
  changes
- Batch apply with limited concurrency that never aborts over one failed row;
  failed rows stay queued with their error and a **Try again** that retries
  only those
- A confirmation guard when switching to a different group with changes still
  queued, Back/Forward included
- **Group admins** (Nextcloud subadmins): a shield per member row grants or
  revokes the role through the same queue; admins who aren't members are
  listed and revocable; removing a member ends their admin role; never on
  the `admin` group

**Folders tab** (present only when the Team Folders app is installed)
- **Create a brand-new group folder** directly from this tab (password
  confirmation required, since it provisions real storage), assigned to the
  current group immediately, or assign/unassign an existing one
- The create dialog takes a full path (`a/b/c`) with a browsable tree of the
  levels that already exist, a live preview of how the folder appears in
  Files, and warnings when the path exists or nests inside another folder
- Per-group **Write / Share / Delete** switches (read is always implied,
  never a fourth switch)
- Folder quota shown and **editable** from here, with an explicit note that
  it is shared by every group with access to the folder
- An ACL badge on folders with advanced permissions turned on, since
  effective access can be more restrictive than the switches show
- Works identically for LDAP groups: folder assignment is not gated by how
  the group's members are managed
- Same chip-queue / batch-apply / never-abort model as Members, sharing one
  combined footer and Apply button with the Members tab so nothing gets
  applied by surprise from a tab you are not looking at

**Quota tab** (present only when the Group Quota app is enabled)
- View, set and remove a group's shared storage limit, with presets, a
  custom size, "Unlimited" and a usage bar; writes need password
  confirmation
- Warns about members who also belong to another group with a quota, and
  names the group whose limit actually applies to them (Group Quota uses the
  first such group in the order Nextcloud lists the user's groups, which
  depends on the database; its own README calls the case undefined)
- Group Quota does the enforcing; this tab reads and writes the same
  `quota_<gid>` app setting its storage wrapper and occ commands use

**LDAP groups**
- Membership is read-only (the directory is the source of truth), with the
  group's DN shown; group admins and Folders stay fully editable
- Broken-backend accounts (a demo/test-data artifact, but a real class of
  problem) degrade gracefully: email/enabled-status lookups that throw fall
  back to safe defaults instead of 500ing the whole member list, and rows
  fall back to showing the account's `uid` in place of a missing email

**Accessibility & polish**
- Theme-safe colours (light, dark, high contrast), a fixed header over
  scrolling lists, and an optional per-admin top bar shortcut
- Keyboard navigation (arrows, Enter, Esc) through the add-field dropdown
- A live region announces group-count changes
- Custom toggle switches and remove buttons avoid Nextcloud's global
  `<button>`/`<input>` sizing rules where a compact control needs to differ
  from the platform default (documented inline where it bites)

**Internationalization**
- Full UI translated into English, Portuguese (Portugal), German, Spanish
  and French, with a coverage-checking build script (`build/l10n.py`)
- Backend error messages (e.g. "Group not found") are translated via
  `IL10N` too, not just the frontend strings

**Quality & tooling**
- PHPUnit test suite (`tests/Unit/`) covering `GroupService` and
  `FolderAssignmentService`: the local/LDAP backend gate
  (`requireLocal()`), the permission-bitmask math in
  `FolderAssignmentService::setPermissions()`, and input validation
- CI (`.github/workflows/ci.yml`): l10n coverage check, `php -l` + PHPUnit,
  and a frontend build that also verifies the committed `js/` bundle is up
  to date with `src/`
- A per-version matrix (`build/nc-instance.sh`, Nextcloud 31–35 with Team
  Folders and a live LDAP directory) running the unit suite, an HTTP API
  check (`build/api-check.py`) and a real two-process admin-removal race
  (`build/admin-race-check.sh`); results in `build/README.md`

## Next up

Roughly in priority order:

### 1. Drop a group's quota when the group is deleted

Deleting a group leaves its `quota_<gid>` setting behind (neither this app
nor Group Quota listens for group deletion), so a new group created later
with the same ID silently inherits the old limit. A `GroupDeletedEvent`
listener that removes the key would close it for deletions made anywhere,
not only from this app.

### 2. Native group quota enforcement

A spike (branch `spike/native-group-quota`) enforces group quotas through
core's public `GetQuotaEvent` instead of Group Quota's storage wrapper:
a shared pool per group, a defined rule for users in several groups
(strictest or most permissive, instead of "first in whatever order the database returns"), and no
dependency on another app. It passed its acceptance checks but still needs
its own table and migration, a settings switch, an import from Group
Quota's settings, deletion cleanup and checks on Nextcloud 31–34 and with
LDAP before it can ship.

### 3. Accessibility contrast audit

The redesigned UI (chip queue, tabs, the folders table) was built against
Nextcloud's `--color-*` custom properties throughout, which should track
the platform's own contrast decisions in both light and dark themes, but
this has not been checked systematically against the 4.5:1 minimum for
secondary text across every screen and both themes, only spot-checked
during development.

### 4. Edit and create users from the member list

Planned, not started. Clicking a member opens their account for editing
(phase A); creating a user from the add field comes after (phase B).

**Approach.** The frontend calls core's OCS provisioning API directly, so
no new PHP: `GET cloud/users/{uid}` (with the backend's capabilities) and
`GET cloud/users/{uid}/editable` to read, `PUT cloud/users/{uid}` (one
key/value per field) to edit, `PUT .../enable` / `.../disable`,
`POST cloud/users` to create and `POST .../welcome` to resend the welcome
email. Validation, password policy, rate limiting and the audit log are
then core's own, and subadmin rules come for free should the app ever be
opened to group admins. Checked against Nextcloud 35: every one of these
endpoints requires password confirmation (the app already handles it), and
editing is rate-limited to 50 changes per 10 minutes. `IUser::canChange*()`
tells which fields an LDAP account can't change; those are shown disabled,
with the reason.

**Phase A: edit.**
- Opened from the member's name or avatar (a real button, Enter works), not
  the whole row, so it doesn't compete with the row's action zone.
- A side panel inside the group card, over the member list, so the group
  stays in view and its staged changes are kept.
- Editable: display name, email, personal quota, enabled; password for
  local accounts only (LDAP gets "resend welcome email").
- Read-only: user ID, origin, last login, storage used, the other groups
  (clickable, to jump to them) and the groups they administer.
- Its own Save, applied immediately: a user edit is not a group change, so
  it stays out of the group's Apply bar.
- Guards: you can't disable your own account; disabling a member of the
  admin group asks first; the personal quota field says when Group Quota
  overrides it, as the Quota tab does.

**Phase B: create.** When a search in the add field finds no one, offer
"Create user 'xyz'", opening a dialog prefilled with that text: user ID,
display name, email, a password or an email invitation (needs mail
configured), quota, and the current group already ticked. Creation is
immediate (Discard can't undo it) and for local accounts only.

**Open decisions:**
1. A side panel inside the card (preferred) or a modal dialog (simpler)?
2. Phase A fields: the list above, or also language, manager and the
   profile fields (phone, organisation, role…)?
3. Allow deleting users here, or leave that to Nextcloud's Users page
   (disabling is enough here)?
4. Administrators only, or open user editing to group admins later?

## Post-launch: only if there's traction

- **Bulk permission/quota edits across multiple folders at once**, the way
  Members already supports picking several people in one search; Folders
  currently queues one folder's permission or quota change at a time.
- **A "recently viewed groups" shortcut** for instances with very many
  groups, where the alphabetical left-hand list alone gets long to scroll.
