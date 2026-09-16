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
use OCP\LDAP\ILDAPProviderFactory;

/**
 * Wraps IGroupManager/IGroup with the app's own rules for what counts as a
 * "local" (renameable/deletable) group, and turns backend refusals into
 * GroupServiceException with a stable error code the frontend can match on.
 */
class GroupService {

    public function __construct(
        private IGroupManager $groupManager,
        private ISubAdmin $subAdmin,
        private ILDAPProviderFactory $ldapProviderFactory,
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
     * @return array{members: list<array{uid: string, displayName: string}>, total: int|null}
     */
    public function getMembers(string $gid, string $search = '', ?int $limit = null, int $offset = 0): array {
        $group = $this->requireGroup($gid);
        $users = $group->searchUsers($search, $limit, $offset);
        $total = $group->count($search);

        return [
            'members' => array_map(static fn ($user) => [
                'uid' => $user->getUID(),
                'displayName' => $user->getDisplayName(),
            ], array_values($users)),
            'total' => $total === false ? null : $total,
        ];
    }

    public function createGroup(string $gid, string $displayName = ''): array {
        $gid = trim($gid);
        if ($gid === '') {
            throw new GroupServiceException('Group ID cannot be empty', 'INVALID_GROUP_ID', 400);
        }
        if ($this->groupManager->groupExists($gid)) {
            throw new GroupServiceException('A group with this ID already exists', 'GROUP_ALREADY_EXISTS', 409);
        }

        $group = $this->groupManager->createGroup($gid);
        if ($group === null) {
            throw new GroupServiceException('Group creation is not supported by the backend', 'BACKEND_UNSUPPORTED', 400);
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
            throw new GroupServiceException('Display name cannot be empty', 'INVALID_DISPLAY_NAME', 400);
        }

        if (!$group->setDisplayName($displayName)) {
            throw new GroupServiceException('Rename was rejected by the backend', 'BACKEND_UNSUPPORTED', 400);
        }

        return $this->detail($group);
    }

    public function deleteGroup(string $gid): void {
        $group = $this->requireGroup($gid);

        if ($gid === 'admin') {
            throw new GroupServiceException('The admin group cannot be deleted', 'ADMIN_GROUP_PROTECTED', 403);
        }

        // Group_LDAP also implements IDeleteGroupBackend (it "deletes" the local
        // LDAP mapping, not the directory entry) — that's a different operation
        // from what this app's UI means by delete, so it's refused here
        // regardless of what the backend itself would technically allow.
        $this->requireLocal($group, 'deleted');

        if (!$group->delete()) {
            throw new GroupServiceException('Delete was rejected by the backend', 'BACKEND_UNSUPPORTED', 400);
        }
    }

    private function requireGroup(string $gid): IGroup {
        $group = $this->groupManager->get($gid);
        if ($group === null) {
            throw new GroupServiceException('Group not found', 'GROUP_NOT_FOUND', 404);
        }
        return $group;
    }

    private function requireLocal(IGroup $group, string $action): void {
        if (!$this->describeBackend($group)['isLocal']) {
            throw new GroupServiceException(
                "Group is managed by an external backend and cannot be {$action} here",
                'GROUP_NOT_LOCAL',
                403,
            );
        }
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
}
