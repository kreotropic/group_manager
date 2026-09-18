<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GroupManager\Service;

use OCP\App\IAppManager;
use OCP\Constants;
use OCP\IUserSession;
use OCP\Server;

/**
 * Bridges to the groupfolders app (a soft dependency: never type-hinted where
 * it would be eagerly resolved, only reached via Server::get() after
 * isEnabled() has already gated the call) to let a group's folder access be
 * assigned/removed and its per-group permissions edited.
 *
 * Permission model confirmed live against groupfolders' own admin UI
 * (2026-09-17, /settings/admin/groupfolders — request payloads inspected
 * directly): its three toggles are "Escrever" = UPDATE|CREATE, "Partilhar" =
 * SHARE, "Apagar" = DELETE; READ is always included and never exposed as a
 * toggle. That UI never clears READ, so this service doesn't either.
 */
class FolderAssignmentService {
    private const PERM_WRITE = Constants::PERMISSION_UPDATE | Constants::PERMISSION_CREATE;
    private const PERM_SHARE = Constants::PERMISSION_SHARE;
    private const PERM_DELETE = Constants::PERMISSION_DELETE;
    private const PERM_READ = Constants::PERMISSION_READ;

    public function __construct(
        private IAppManager $appManager,
        private IUserSession $userSession,
    ) {
    }

    /**
     * Whether the current admin can see group-folder assignment at all —
     * gates the tab's very existence (no tab, no placeholder, per spec).
     */
    public function isEnabled(): bool {
        return $this->appManager->isEnabledForUser('groupfolders', $this->userSession->getUser());
    }

    public function folderCount(string $gid): int {
        if (!$this->isEnabled()) {
            return 0;
        }
        return count($this->listAssigned($gid));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAssigned(string $gid): array {
        $this->requireEnabled();
        $out = [];
        foreach ($this->manager()->getAllFoldersWithSize() as $folder) {
            if ($this->groupHasAccess($folder, $gid)) {
                $out[] = $this->describeFolder($folder, $gid);
            }
        }
        usort($out, static fn (array $a, array $b) => strnatcasecmp($a['mountPoint'], $b['mountPoint']));
        return $out;
    }

    /**
     * Group folders NOT yet assigned to $gid, name-matching $search — the
     * pool for the assignment field's dropdown.
     *
     * @return list<array{id: int, mountPoint: string, quota: int, size: int, acl: bool}>
     */
    public function searchAssignable(string $gid, string $search, int $limit = 10): array {
        $this->requireEnabled();
        $needle = mb_strtolower(trim($search));
        $out = [];
        $folders = array_values($this->manager()->getAllFoldersWithSize());
        usort($folders, static fn ($a, $b) => strnatcasecmp($a->mountPoint, $b->mountPoint));
        foreach ($folders as $folder) {
            if ($this->groupHasAccess($folder, $gid)) {
                continue;
            }
            if ($needle !== '' && !str_contains(mb_strtolower($folder->mountPoint), $needle)) {
                continue;
            }
            $out[] = [
                'id' => $folder->id,
                'mountPoint' => $folder->mountPoint,
                'quota' => $folder->quota,
                'size' => $folder->rootCacheEntry->getSize(),
                'acl' => $folder->acl,
            ];
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function assignFolder(string $gid, int $folderId): array {
        $this->requireEnabled();
        $folder = $this->requireFolder($folderId);
        if ($this->groupHasAccess($folder, $gid)) {
            throw new GroupServiceException('Group already has access to this folder', 'FOLDER_ALREADY_ASSIGNED', 409);
        }
        $this->manager()->addApplicableGroup($folderId, $gid);
        return $this->describeFolder($this->requireFolder($folderId), $gid);
    }

    public function unassignFolder(string $gid, int $folderId): void {
        $this->requireEnabled();
        $this->requireFolder($folderId);
        $this->manager()->removeApplicableGroup($folderId, $gid);
    }

    /**
     * @return array<string, mixed>
     */
    public function setPermissions(string $gid, int $folderId, bool $write, bool $share, bool $delete): array {
        $this->requireEnabled();
        $folder = $this->requireFolder($folderId);
        if (!$this->groupHasAccess($folder, $gid)) {
            throw new GroupServiceException('Group does not have access to this folder', 'FOLDER_NOT_ASSIGNED', 404);
        }
        $permissions = self::PERM_READ
            | ($write ? self::PERM_WRITE : 0)
            | ($share ? self::PERM_SHARE : 0)
            | ($delete ? self::PERM_DELETE : 0);
        $this->manager()->setGroupPermissions($folderId, $gid, $permissions);
        return $this->describeFolder($this->requireFolder($folderId), $gid);
    }

    /**
     * Quota belongs to the folder, not this group — every other group with
     * access shares it — but the edit is still only reachable from a group
     * that can see the folder (i.e. has access to it), same gate as permissions.
     *
     * @return array<string, mixed>
     */
    public function setQuota(string $gid, int $folderId, int $quota): array {
        $this->requireEnabled();
        $folder = $this->requireFolder($folderId);
        if (!$this->groupHasAccess($folder, $gid)) {
            throw new GroupServiceException('Group does not have access to this folder', 'FOLDER_NOT_ASSIGNED', 404);
        }
        $this->manager()->setFolderQuota($folderId, $quota);
        return $this->describeFolder($this->requireFolder($folderId), $gid);
    }

    private function requireEnabled(): void {
        if (!$this->isEnabled()) {
            throw new GroupServiceException('Group folders app is not enabled', 'GROUPFOLDERS_DISABLED', 404);
        }
    }

    private function requireFolder(int $folderId): \OCA\GroupFolders\Folder\FolderWithMappingsAndCache {
        $folder = $this->manager()->getFolder($folderId);
        if ($folder === null) {
            throw new GroupServiceException('Group folder not found', 'GROUP_FOLDER_NOT_FOUND', 404);
        }
        return $folder;
    }

    /**
     * $folder->groups is keyed by entity id (group OR circle) with a `type`
     * discriminator — only 'group' entries are this app's concern.
     */
    private function groupHasAccess(\OCA\GroupFolders\Folder\FolderWithMappingsAndCache $folder, string $gid): bool {
        $entry = $folder->groups[$gid] ?? null;
        return $entry !== null && ($entry['type'] ?? 'group') === 'group';
    }

    /**
     * @return array<string, mixed>
     */
    private function describeFolder(\OCA\GroupFolders\Folder\FolderWithMappingsAndCache $folder, string $gid): array {
        $permissions = $folder->groups[$gid]['permissions'] ?? 0;
        return [
            'id' => $folder->id,
            'mountPoint' => $folder->mountPoint,
            'quota' => $folder->quota,
            'size' => $folder->rootCacheEntry->getSize(),
            'acl' => $folder->acl,
            'permissions' => [
                'write' => ($permissions & self::PERM_WRITE) === self::PERM_WRITE,
                'share' => ($permissions & self::PERM_SHARE) === self::PERM_SHARE,
                'delete' => ($permissions & self::PERM_DELETE) === self::PERM_DELETE,
            ],
        ];
    }

    private function manager(): \OCA\GroupFolders\Folder\FolderManager {
        return Server::get(\OCA\GroupFolders\Folder\FolderManager::class);
    }
}
