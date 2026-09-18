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
use OCP\LDAP\ILDAPProviderFactory;

/**
 * Wraps IGroupManager/IGroup with the app's own rules for what counts as a
 * "local" (renameable/deletable) group, and turns backend refusals into
 * GroupServiceException with a stable error code the frontend can match on.
 */
class GroupService {

    public function __construct(
        private IGroupManager $groupManager,
        private IUserManager $userManager,
        private ISubAdmin $subAdmin,
        private ILDAPProviderFactory $ldapProviderFactory,
        private FolderAssignmentService $folderAssignmentService,
        private IL10N $l,
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
     * @return array{members: list<array{uid: string, displayName: string, email: ?string, enabled: bool}>, total: int|null}
     */
    public function getMembers(string $gid, string $search = '', ?int $limit = null, int $offset = 0): array {
        $group = $this->requireGroup($gid);
        $users = $group->searchUsers($search, $limit, $offset);
        $total = $group->count($search);

        return [
            'members' => array_map(fn ($user) => $this->describeUser($user), array_values($users)),
            'total' => $total === false ? null : $total,
        ];
    }

    /**
     * Users AND groups matching $search, for the single add-field: users
     * already members of $gid are excluded (over-fetches from
     * IUserManager::search() to absorb them, since there's no way to exclude
     * at the query level); the group itself and groups with zero addable
     * members are excluded from the group side. Group results always carry
     * the count of members they'd actually add (their size minus the overlap
     * with $gid), never their raw size.
     *
     * @return array{users: list<array{uid: string, displayName: string}>, groups: list<array{id: string, displayName: string, backend: string, newMemberCount: int}>}
     */
    public function searchCandidates(string $gid, string $search, int $limit = 10): array {
        $group = $this->requireGroup($gid);
        $existingUids = array_flip(array_map(
            static fn ($user) => $user->getUID(),
            $group->getUsers(),
        ));

        $users = [];
        foreach ($this->userManager->search($search, $limit + count($existingUids), 0) as $user) {
            if (isset($existingUids[$user->getUID()])) {
                continue;
            }
            $users[] = ['uid' => $user->getUID(), 'displayName' => $user->getDisplayName()];
        }
        // IUserManager::search() doesn't guarantee display-name order (an
        // empty $search — used to browse candidates before typing — comes
        // back ordered by backend/uid instead), so sort explicitly.
        usort($users, static fn (array $a, array $b) => strnatcasecmp($a['displayName'], $b['displayName']));
        $users = array_slice($users, 0, $limit);

        $groups = [];
        if ($search !== '') {
            foreach ($this->groupManager->search($search) as $candidateGroup) {
                if ($candidateGroup->getGID() === $gid) {
                    continue;
                }
                $newMemberCount = 0;
                foreach ($candidateGroup->getUsers() as $user) {
                    if (!isset($existingUids[$user->getUID()])) {
                        $newMemberCount++;
                    }
                }
                if ($newMemberCount === 0) {
                    continue;
                }
                $groups[] = [
                    'id' => $candidateGroup->getGID(),
                    'displayName' => $candidateGroup->getDisplayName(),
                    'backend' => $this->describeBackend($candidateGroup)['backend'],
                    'newMemberCount' => $newMemberCount,
                ];
                if (count($groups) >= $limit) {
                    break;
                }
            }
        }

        return ['users' => $users, 'groups' => $groups];
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
     * @param list<string> $tokens
     * @return list<array{token: string, matched: bool, uid: ?string, displayName: ?string}>
     */
    public function resolvePastedTokens(string $gid, array $tokens): array {
        $this->requireGroup($gid);
        if (count($tokens) > 500) {
            throw new GroupServiceException($this->l->t('Too many entries pasted at once'), 'TOO_MANY_TOKENS', 400);
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
                ? ['token' => $token, 'matched' => false, 'uid' => null, 'displayName' => null]
                : ['token' => $token, 'matched' => true, 'uid' => $user->getUID(), 'displayName' => $user->getDisplayName()];
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

        if ($group->inGroup($user)) {
            $group->removeUser($user);
        }
    }

    public function createGroup(string $gid, string $displayName = ''): array {
        $gid = trim($gid);
        if ($gid === '') {
            throw new GroupServiceException($this->l->t('Group ID cannot be empty'), 'INVALID_GROUP_ID', 400);
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
