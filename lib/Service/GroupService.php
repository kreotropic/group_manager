<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GroupManager\Service;

use OCP\Group\ISubAdmin;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\LDAP\ILDAPProviderFactory;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Wraps IGroupManager/IGroup with the app's own rules for what counts as a
 * "local" (renameable/deletable) group, and turns backend refusals into
 * GroupServiceException with a stable error code the frontend can match on.
 */
class GroupService {

    /**
     * Shared-lock key guarding removals from the "admin" group — see
     * guardAdminGroupRemoval().
     */
    private const ADMIN_GROUP_LOCK = 'group_manager/admin-group-removal';

    /**
     * getMembers() pagination bounds — GM-10/GM-06: an omitted limit used to
     * mean "no limit", letting a single request enumerate an entire
     * backend; it now means DEFAULT_MEMBERS_LIMIT, same as the page size
     * every caller already requests explicitly.
     */
    private const MIN_MEMBERS_LIMIT = 1;
    private const MAX_MEMBERS_LIMIT = 200;
    private const DEFAULT_MEMBERS_LIMIT = 50;

    /** resolvePastedTokens() — GM-10: a single pasted line has no reason to be this long. */
    private const MAX_TOKEN_LENGTH = 320;

    /**
     * searchCandidates() — GM-06: how many pages of IUserManager::search()
     * (each $limit long) it examines at most while excluding $gid's own
     * members one candidate at a time, before giving up rather than reading
     * through the whole backend on a search with heavy overlap.
     */
    private const CANDIDATE_SEARCH_PAGE_BUDGET = 3;

    public function __construct(
        private IGroupManager $groupManager,
        private IUserManager $userManager,
        private ISubAdmin $subAdmin,
        private ILDAPProviderFactory $ldapProviderFactory,
        private FolderAssignmentService $folderAssignmentService,
        private IUserSession $userSession,
        private IL10N $l,
        private ILockingProvider $lockingProvider,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listGroups(): array {
        $groups = $this->groupManager->search('');
        return array_map(fn (IGroup $group) => $this->summarize($group), $groups);
    }

    public function getGroup(string $gid): array {
        return $this->detail($this->requireGroup($gid));
    }

    /**
     * `hasMore` (GM-05) is derived from actually fetching one extra row, not
     * from `total`: a backend that can enumerate members just fine can still
     * answer `count()` with `false` (unknown), and the frontend used to
     * treat that as "no more pages exist" — silently stranding every member
     * past the first one loaded. `total`, when known, is still returned for
     * display.
     *
     * @return array{members: list<array{uid: string, displayName: string, email: ?string, enabled: bool}>, total: int|null, hasMore: bool}
     */
    public function getMembers(string $gid, string $search = '', ?int $limit = null, int $offset = 0): array {
        $group = $this->requireGroup($gid);
        $limit = $this->validateMembersLimit($limit);
        if ($offset < 0) {
            throw new GroupServiceException($this->l->t('Offset must not be negative'), 'INVALID_PAGINATION', 400);
        }
        $users = array_values($group->searchUsers($search, $limit + 1, $offset));
        $hasMore = count($users) > $limit;
        if ($hasMore) {
            $users = array_slice($users, 0, $limit);
        }
        $total = $group->count($search);

        return [
            'members' => array_map(fn ($user) => $this->describeUser($user), $users),
            'total' => $total === false ? null : $total,
            'hasMore' => $hasMore,
        ];
    }

    /**
     * Users AND groups matching $search, for the single add-field.
     *
     * GM-06: this used to enumerate every one of $gid's members
     * (IGroup::getUsers(), unbounded) just to build an exclusion set for
     * paging through IUserManager::search(), and every candidate group's
     * own full membership (IGroup::getUsers() again) just to report how
     * many of them were new — both synchronous, per keystroke. Excluding
     * existing members is now IGroup::inGroup() checked one candidate at a
     * time, over CANDIDATE_SEARCH_PAGE_BUDGET pages of the user search at
     * most — bounded work regardless of $gid's size or the overlap with it.
     * A candidate group's own overlap is no longer computed at all here:
     * `memberCount` is its raw size (count(), can be unknown), not "how many
     * it would add" — that number is cheap for exactly one group
     * (expandGroupForAdd(), already bounded to that group's own members) and
     * is computed there, once, only for the group the admin actually picks.
     * Groups not worth offering (zero members) are excluded, but overlap is
     * no longer a filter: an admin typing a search sees every matching
     * group, same as for users.
     *
     * Paged: $offset is the cursor a previous call returned as `nextOffset`
     * (a position in the raw user search, not a count of results, since
     * existing members are skipped along the way). `hasMore` is false only
     * once the search itself ran out, so the dropdown can keep loading as it
     * is scrolled instead of stopping at the first $limit people.
     *
     * @return array{users: list<array{uid: string, displayName: string}>, groups: list<array{id: string, displayName: string, backend: string, memberCount: ?int}>, nextOffset: int, hasMore: bool}
     */
    public function searchCandidates(string $gid, string $search, int $limit = 10, int $offset = 0): array {
        $group = $this->requireGroup($gid);

        $users = [];
        $cursor = max(0, $offset);
        $exhausted = false;
        for ($page = 0; $page < self::CANDIDATE_SEARCH_PAGE_BUDGET && count($users) < $limit; $page++) {
            // Browsing (no term) goes by display name, so paging through
            // hundreds of people is alphabetical; IUserManager::search()
            // would hand them back in backend/uid order.
            $candidates = $search === ''
                ? $this->userManager->searchDisplayName('', $limit, $cursor)
                : $this->userManager->search($search, $limit, $cursor);
            if (count($candidates) === 0) {
                $exhausted = true;
                break;
            }
            foreach ($candidates as $user) {
                $cursor++;
                if ($group->inGroup($user)) {
                    continue;
                }
                $users[] = ['uid' => $user->getUID(), 'displayName' => $user->getDisplayName()];
                if (count($users) >= $limit) {
                    break;
                }
            }
        }
        usort($users, static fn (array $a, array $b) => strnatcasecmp($a['displayName'], $b['displayName']));

        $groups = [];
        // Groups are offered on the first page only; later pages just
        // continue the user list.
        if ($search !== '' && $offset === 0) {
            foreach ($this->groupManager->search($search, $limit) as $candidateGroup) {
                if ($candidateGroup->getGID() === $gid) {
                    continue;
                }
                $memberCount = $this->nullableCount($candidateGroup->count());
                if ($memberCount === 0) {
                    continue;
                }
                $groups[] = [
                    'id' => $candidateGroup->getGID(),
                    'displayName' => $candidateGroup->getDisplayName(),
                    'backend' => $this->describeBackend($candidateGroup)['backend'],
                    'memberCount' => $memberCount,
                ];
            }
        }

        return ['users' => $users, 'groups' => $groups, 'nextOffset' => $cursor, 'hasMore' => !$exhausted];
    }

    /**
     * The members of $sourceGid that aren't already members of $gid — used
     * when the admin picks "whole group" as a candidate in the add field, to
     * expand it into individual add operations without the client having to
     * fetch (and filter) potentially hundreds of members itself.
     *
     * @return list<array{uid: string, displayName: string}>
     */
    public function expandGroupForAdd(string $gid, string $sourceGid): array {
        $group = $this->requireGroup($gid);
        $sourceGroup = $this->requireGroup($sourceGid);

        $existingUids = array_flip(array_map(
            static fn ($user) => $user->getUID(),
            $group->getUsers(),
        ));

        $newMembers = [];
        foreach ($sourceGroup->getUsers() as $user) {
            if (!isset($existingUids[$user->getUID()])) {
                $newMembers[] = ['uid' => $user->getUID(), 'displayName' => $user->getDisplayName()];
            }
        }
        return $newMembers;
    }

    /**
     * Resolves a pasted list of tokens (one per line, uid or email) against
     * real accounts. Each input token comes back exactly once, either
     * matched (with the resolved user) or not — the caller decides what to
     * do with unmatched tokens (surface them, let the admin fix and retry).
     *
     * `alreadyMember` (GM-07) is $gid's real, current membership
     * (IGroup::inGroup(), not scoped by any page/filter) — the frontend used
     * to decide this from whatever page of members it happened to have
     * loaded, so a member outside that page or filter read as a brand-new
     * addition.
     *
     * @param list<string> $tokens
     * @return list<array{token: string, matched: bool, uid: ?string, displayName: ?string, alreadyMember: bool}>
     */
    public function resolvePastedTokens(string $gid, array $tokens): array {
        $group = $this->requireGroup($gid);
        if (count($tokens) > 500) {
            throw new GroupServiceException($this->l->t('Too many entries pasted at once'), 'TOO_MANY_TOKENS', 400);
        }
        // The controller's `@param string[] $tokens` is a hint, not an
        // enforced type: PHP's own request-body binding hands this method
        // whatever JSON array the client sent, array/object/number entries
        // included — trim() on anything but a string is a TypeError, not a
        // clean 400. Checked as its own pass, before any entry is
        // processed, so one bad entry can't leave a partial result set.
        foreach ($tokens as $token) {
            if (!is_string($token)) {
                throw new GroupServiceException($this->l->t('Pasted entries must be text'), 'INVALID_TOKENS', 400);
            }
            if (strlen($token) > self::MAX_TOKEN_LENGTH) {
                throw new GroupServiceException($this->l->t('A pasted entry is too long'), 'INVALID_TOKENS', 400);
            }
        }

        $results = [];
        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }

            $user = $this->userManager->get($token);
            if ($user === null && str_contains($token, '@')) {
                foreach ($this->userManager->search($token, 5, 0) as $candidate) {
                    if (strcasecmp((string)$candidate->getEMailAddress(), $token) === 0) {
                        $user = $candidate;
                        break;
                    }
                }
            }

            $results[] = $user === null
                ? ['token' => $token, 'matched' => false, 'uid' => null, 'displayName' => null, 'alreadyMember' => false]
                : [
                    'token' => $token,
                    'matched' => true,
                    'uid' => $user->getUID(),
                    'displayName' => $user->getDisplayName(),
                    'alreadyMember' => $group->inGroup($user),
                ];
        }
        return $results;
    }

    /**
     * @return array{uid: string, displayName: string, email: ?string, enabled: bool}
     */
    public function addMember(string $gid, string $uid): array {
        $group = $this->requireGroup($gid);
        $this->requireLocal($group, 'modified');

        $user = $this->userManager->get($uid);
        if ($user === null) {
            throw new GroupServiceException($this->l->t('User not found'), 'USER_NOT_FOUND', 404);
        }
        if (!$group->canAddUser()) {
            throw new GroupServiceException($this->l->t('Adding members is not supported by the backend'), 'BACKEND_UNSUPPORTED', 400);
        }

        if (!$group->inGroup($user)) {
            $group->addUser($user);
        }
        return $this->describeUser($user);
    }

    public function removeMember(string $gid, string $uid): void {
        $group = $this->requireGroup($gid);
        $this->requireLocal($group, 'modified');

        $user = $this->userManager->get($uid);
        if ($user === null) {
            throw new GroupServiceException($this->l->t('User not found'), 'USER_NOT_FOUND', 404);
        }
        if (!$group->canRemoveUser()) {
            throw new GroupServiceException($this->l->t('Removing members is not supported by the backend'), 'BACKEND_UNSUPPORTED', 400);
        }

        if ($gid === 'admin') {
            $this->removeFromAdminGroupLocked($group, $user);
        } elseif ($group->inGroup($user)) {
            $group->removeUser($user);
        }

        // Nextcloud keeps a group admin (subadmin) assignment when the person
        // leaves the group -- it is only cleaned up when the user or the group
        // is deleted. Leaving through this app also ends it, so a removed
        // member doesn't silently keep managing the group they just left.
        if ($this->subAdmin->isSubAdminOfGroup($user, $group)) {
            $this->subAdmin->deleteSubAdmin($user, $group);
        }
    }

    /**
     * The group's admins (Nextcloud "subadmins"), members or not: the core
     * Users page can make anyone a group admin, membership isn't required.
     *
     * @return array{subAdmins: list<array{uid: string, displayName: string, email: ?string, enabled: bool, isMember: bool}>, canGrant: bool}
     */
    public function getSubAdmins(string $gid): array {
        $group = $this->requireGroup($gid);
        $subAdmins = array_map(
            fn (\OCP\IUser $user) => $this->describeUser($user) + ['isMember' => $group->inGroup($user)],
            $this->subAdmin->getGroupsSubAdmins($group),
        );
        usort($subAdmins, fn (array $a, array $b) => strnatcasecmp($a['displayName'], $b['displayName']));

        return [
            'subAdmins' => $subAdmins,
            'canGrant' => $this->canGrantSubAdmin($group),
        ];
    }

    /**
     * Makes a member of $gid one of its group admins. Works for LDAP groups
     * too: the assignment is stored by Nextcloud, not in the directory.
     *
     * @return array{uid: string, displayName: string, email: ?string, enabled: bool, isMember: bool}
     */
    public function addSubAdmin(string $gid, string $uid): array {
        $group = $this->requireGroup($gid);
        if (!$this->canGrantSubAdmin($group)) {
            throw new GroupServiceException($this->l->t('The admin group cannot have group admins'), 'ADMIN_GROUP_PROTECTED', 403);
        }
        $user = $this->requireUser($uid);
        if (!$group->inGroup($user)) {
            throw new GroupServiceException($this->l->t('Only members of the group can be made group admins'), 'NOT_A_MEMBER', 400);
        }

        if (!$this->subAdmin->isSubAdminOfGroup($user, $group)) {
            $this->subAdmin->createSubAdmin($user, $group);
        }
        return $this->describeUser($user) + ['isMember' => true];
    }

    /**
     * Ends a group admin assignment, member or not. Allowed on every group,
     * "admin" included: an assignment made there some other way is exactly
     * the kind worth being able to undo.
     */
    public function removeSubAdmin(string $gid, string $uid): void {
        $group = $this->requireGroup($gid);
        $user = $this->requireUser($uid);

        if ($this->subAdmin->isSubAdminOfGroup($user, $group)) {
            $this->subAdmin->deleteSubAdmin($user, $group);
        }
    }

    /**
     * A group admin of "admin" could add anyone, themselves included, to
     * the admin group -- full instance admin rights through the back door.
     * Nextcloud's own provisioning API refuses it for the same reason.
     */
    private function canGrantSubAdmin(IGroup $group): bool {
        return $group->getGID() !== 'admin';
    }

    private function requireUser(string $uid): \OCP\IUser {
        $user = $this->userManager->get($uid);
        if ($user === null) {
            throw new GroupServiceException($this->l->t('User not found'), 'USER_NOT_FOUND', 404);
        }
        return $user;
    }

    /**
     * Removals from "admin" are serialized through OCP\Lock\ILockingProvider
     * (an exclusive lock backed by the DB or Redis — a process/node-shared
     * mechanism available on every supported version, unlike a PHP variable
     * or a local file). Two admins removing different members of "admin" at
     * the same moment used to both pass the count check before either
     * IGroup::removeUser() call landed, since the public API exposes no
     * transaction of its own to close that window; acquiring this lock
     * first and re-reading membership/count only *after* it's held is what
     * closes it, because nothing read before the lock can be trusted not to
     * be stale by the time this runs.
     *
     * This only coordinates removals that go through this method. A removal
     * issued by `occ group:removeuser`, the core Users admin page, or any
     * other app's own IGroup::removeUser() call doesn't take this lock and
     * isn't covered by this guarantee — a lock private to this app can't
     * coordinate emitters it doesn't know about.
     */
    private function removeFromAdminGroupLocked(IGroup $group, \OCP\IUser $user): void {
        try {
            $this->lockingProvider->acquireLock(self::ADMIN_GROUP_LOCK, ILockingProvider::LOCK_EXCLUSIVE);
        } catch (LockedException) {
            throw new GroupServiceException($this->l->t('Another admin group change is in progress, please try again'), 'ADMIN_GROUP_BUSY', 409);
        }
        try {
            if (!$group->inGroup($user)) {
                return;
            }
            $this->guardAdminGroupRemoval($group, $user);
            $group->removeUser($user);
        } finally {
            $this->lockingProvider->releaseLock(self::ADMIN_GROUP_LOCK, ILockingProvider::LOCK_EXCLUSIVE);
        }
    }

    /**
     * Must only be called with ADMIN_GROUP_LOCK already held (see
     * removeFromAdminGroupLocked()) — both checks below read state that's
     * only trustworthy inside that protected section.
     *
     * An admin can't remove themselves from "admin" (the caller is always a
     * current admin — this whole controller requires it — so refusing
     * self-removal alone guarantees at least one admin always survives any
     * single call here); the count check is the defense-in-depth backstop
     * for everyone else. A count() the backend can't answer is treated the
     * same as "not safe to remove" rather than silently let through.
     */
    private function guardAdminGroupRemoval(IGroup $group, \OCP\IUser $user): void {
        $currentUser = $this->userSession->getUser();
        if ($currentUser !== null && $currentUser->getUID() === $user->getUID()) {
            throw new GroupServiceException($this->l->t('You cannot remove yourself from the admin group'), 'CANNOT_REMOVE_SELF_FROM_ADMIN', 403);
        }

        $count = $group->count();
        if ($count === false) {
            throw new GroupServiceException($this->l->t('Could not verify the number of administrators, please try again'), 'ADMIN_COUNT_UNKNOWN', 403);
        }
        if ($count <= 1) {
            throw new GroupServiceException($this->l->t('Cannot remove the last member of the admin group'), 'LAST_ADMIN_PROTECTED', 403);
        }
    }

    public function createGroup(string $gid, string $displayName = ''): array {
        $gid = trim($gid);
        if ($gid === '') {
            throw new GroupServiceException($this->l->t('Group ID cannot be empty'), 'INVALID_GROUP_ID', 400);
        }
        // GM-09 (contention, not the full fix): this app's own routes bind
        // {gid} as '[^/]+', so a GID containing '/' — the backend itself
        // accepts one — could never be opened, renamed or deleted through
        // this API again once created. Refusing it at creation is cheap and
        // covers the one path that can add a GID with a slash going
        // forward; a group with one already existing (LDAP, occ, another
        // app) is unaffected and still manageable everywhere but here.
        if (str_contains($gid, '/')) {
            throw new GroupServiceException($this->l->t('Group ID cannot contain "/"'), 'INVALID_GROUP_ID', 400);
        }
        if ($this->groupManager->groupExists($gid)) {
            throw new GroupServiceException($this->l->t('A group with this ID already exists'), 'GROUP_ALREADY_EXISTS', 409);
        }

        $group = $this->groupManager->createGroup($gid);
        if ($group === null) {
            throw new GroupServiceException($this->l->t('Group creation is not supported by the backend'), 'BACKEND_UNSUPPORTED', 400);
        }

        $displayName = trim($displayName);
        if ($displayName !== '') {
            $group->setDisplayName($displayName);
        }

        return $this->detail($group);
    }

    public function renameGroup(string $gid, string $displayName): array {
        $group = $this->requireGroup($gid);
        $this->requireLocal($group, 'renamed');

        $displayName = trim($displayName);
        if ($displayName === '') {
            throw new GroupServiceException($this->l->t('Display name cannot be empty'), 'INVALID_DISPLAY_NAME', 400);
        }

        if (!$group->setDisplayName($displayName)) {
            throw new GroupServiceException($this->l->t('Rename was rejected by the backend'), 'BACKEND_UNSUPPORTED', 400);
        }

        return $this->detail($group);
    }

    public function deleteGroup(string $gid): void {
        $group = $this->requireGroup($gid);

        if ($gid === 'admin') {
            throw new GroupServiceException($this->l->t('The admin group cannot be deleted'), 'ADMIN_GROUP_PROTECTED', 403);
        }

        // Group_LDAP also implements IDeleteGroupBackend (it "deletes" the local
        // LDAP mapping, not the directory entry) — that's a different operation
        // from what this app's UI means by delete, so it's refused here
        // regardless of what the backend itself would technically allow.
        $this->requireLocal($group, 'deleted');

        if (!$group->delete()) {
            throw new GroupServiceException($this->l->t('Delete was rejected by the backend'), 'BACKEND_UNSUPPORTED', 400);
        }
    }

    /**
     * @return positive-int the effective limit — DEFAULT_MEMBERS_LIMIT when
     * $limit is omitted, otherwise $limit itself once confirmed in range.
     */
    private function validateMembersLimit(?int $limit): int {
        if ($limit === null) {
            return self::DEFAULT_MEMBERS_LIMIT;
        }
        if ($limit < self::MIN_MEMBERS_LIMIT || $limit > self::MAX_MEMBERS_LIMIT) {
            throw new GroupServiceException($this->l->t('Limit is out of range'), 'INVALID_PAGINATION', 400);
        }
        return $limit;
    }

    private function requireGroup(string $gid): IGroup {
        $group = $this->groupManager->get($gid);
        if ($group === null) {
            throw new GroupServiceException($this->l->t('Group not found'), 'GROUP_NOT_FOUND', 404);
        }
        return $group;
    }

    /**
     * @param 'renamed'|'deleted'|'modified' $action
     */
    private function requireLocal(IGroup $group, string $action): void {
        if ($this->describeBackend($group)['isLocal']) {
            return;
        }
        $message = match ($action) {
            'renamed' => $this->l->t('Group is managed by an external backend and cannot be renamed here'),
            'deleted' => $this->l->t('Group is managed by an external backend and cannot be deleted here'),
            default => $this->l->t('Group is managed by an external backend and cannot be modified here'),
        };
        throw new GroupServiceException($message, 'GROUP_NOT_LOCAL', 403);
    }

    private function summarize(IGroup $group): array {
        $backend = $this->describeBackend($group);
        return [
            'id' => $group->getGID(),
            'displayName' => $group->getDisplayName(),
            'backend' => $backend['backend'],
            'backendNames' => $backend['backendNames'],
            'isLocal' => $backend['isLocal'],
            'memberCount' => $this->nullableCount($group->count()),
            'canRename' => $backend['isLocal'],
            'canDelete' => $backend['isLocal'] && $group->getGID() !== 'admin',
        ];
    }

    private function detail(IGroup $group): array {
        return $this->summarize($group) + [
            'disabledCount' => $this->nullableCount($group->countDisabled()),
            'subAdminCount' => count($this->subAdmin->getGroupsSubAdmins($group)),
            'canGrantSubAdmin' => $this->canGrantSubAdmin($group),
            'canAddUser' => $group->canAddUser(),
            'canRemoveUser' => $group->canRemoveUser(),
            'dn' => $this->resolveLdapDn($group),
            'foldersEnabled' => $this->folderAssignmentService->isEnabled(),
            'folderCount' => $this->folderAssignmentService->folderCount($group->getGID()),
        ];
    }

    /**
     * Best-effort LDAP DN lookup via the public ILDAPProvider API — only
     * meaningful (and only attempted) for a group whose backend is LDAP, and
     * never fatal: user_ldap being disabled, or the lookup itself failing,
     * both just mean no DN is shown.
     */
    private function resolveLdapDn(IGroup $group): ?string {
        if (!in_array('LDAP', $group->getBackendNames(), true) || !$this->ldapProviderFactory->isAvailable()) {
            return null;
        }
        try {
            return $this->ldapProviderFactory->getLDAPProvider()->getGroupDN($group->getGID());
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @return array{backend: string, backendNames: list<string>, isLocal: bool}
     */
    private function describeBackend(IGroup $group): array {
        $names = $group->getBackendNames();
        if (in_array('LDAP', $names, true)) {
            $backend = 'ldap';
        } elseif ($names === ['Database']) {
            $backend = 'local';
        } else {
            $backend = 'other';
        }

        return [
            'backend' => $backend,
            'backendNames' => $names,
            'isLocal' => $backend === 'local',
        ];
    }

    private function nullableCount(int|bool $count): ?int {
        return $count === false ? null : $count;
    }

    /**
     * @return array{uid: string, displayName: string, email: ?string, enabled: bool}
     */
    private function describeUser(\OCP\IUser $user): array {
        // $user is frequently an OC\User\LazyUser (returned by IGroup::searchUsers()
        // et al.) which resolves the real backend user on first property access —
        // if that backend is flaky right at this moment (an LDAP server hiccup,
        // the account having just been deleted), getEMailAddress()/isEnabled()
        // throw instead of returning a safe default. One member's backend being
        // momentarily unreachable must not 500 the whole member list.
        try {
            $email = $user->getEMailAddress();
        } catch (\Exception) {
            $email = null;
        }
        try {
            $enabled = $user->isEnabled();
        } catch (\Exception) {
            $enabled = true;
        }

        return [
            'uid' => $user->getUID(),
            'displayName' => $user->getDisplayName(),
            'email' => $email,
            'enabled' => $enabled,
        ];
    }
}
