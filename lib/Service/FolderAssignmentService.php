<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GroupManager\Service;

use OCP\App\IAppManager;
use OCP\Constants;
use OCP\IL10N;
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
        private IL10N $l,
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
            throw new GroupServiceException($this->l->t('Group already has access to this folder'), 'FOLDER_ALREADY_ASSIGNED', 409);
        }
        $this->manager()->addApplicableGroup($folderId, $gid);
        return $this->describeFolder($this->requireFolder($folderId), $gid);
    }

    /**
     * Creates a brand-new group folder and immediately assigns it to $gid —
     * creating one from a specific group's screen implies it's for that
     * group, so a bare "create" with no assignment would just mean an extra
     * manual step back here right after.
     *
     * @return array<string, mixed>
     */
    public function createFolder(string $gid, string $mountPoint): array {
        $this->requireEnabled();
        // Checked here, before trimMountpoint(): an empty/all-slashes string
        // normalizes to '/' in that method, which it then returns early
        // WITHOUT throwing (that path exists to let '/' mount a Team folder
        // at the user's home) — trusting it alone would silently create a
        // nonsense group folder mounted at the root instead of rejecting it.
        if (trim($mountPoint) === '') {
            throw new GroupServiceException($this->l->t('Invalid folder name'), 'INVALID_MOUNT_POINT', 400);
        }
        try {
            $mountPoint = $this->manager()->trimMountpoint($mountPoint);
        } catch (\OCP\AppFramework\OCS\OCSBadRequestException) {
            throw new GroupServiceException($this->l->t('Invalid folder name'), 'INVALID_MOUNT_POINT', 400);
        }
        if ($mountPoint === '/') {
            throw new GroupServiceException($this->l->t('Invalid folder name'), 'INVALID_MOUNT_POINT', 400);
        }
        if ($this->manager()->mountPointExists($mountPoint)) {
            throw new GroupServiceException($this->l->t('A group folder with this name already exists'), 'FOLDER_ALREADY_EXISTS', 409);
        }
        $folderId = $this->manager()->createFolder($mountPoint);
        try {
            $this->manager()->addApplicableGroup($folderId, $gid);
        } catch (\Throwable) {
            // Compensate: don't leave a newly created folder that's assigned
            // to nobody sitting around as an orphan just because the second
            // step failed. Best-effort — if the cleanup itself fails too,
            // the original error is still what the admin needs to see.
            try {
                $this->manager()->removeFolder($folderId);
            } catch (\Throwable) {
            }
            throw new GroupServiceException($this->l->t('Group folder was created but could not be assigned to the group'), 'FOLDER_ASSIGN_FAILED', 500);
        }
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
            throw new GroupServiceException($this->l->t('Group does not have access to this folder'), 'FOLDER_NOT_ASSIGNED', 404);
        }
        $permissions = $this->encodePermissions($write, $share, $delete);
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
        if ($quota !== \OCP\Files\FileInfo::SPACE_UNLIMITED && $quota < 0) {
            throw new GroupServiceException($this->l->t('Invalid quota value'), 'INVALID_QUOTA', 400);
        }
        $folder = $this->requireFolder($folderId);
        if (!$this->groupHasAccess($folder, $gid)) {
            throw new GroupServiceException($this->l->t('Group does not have access to this folder'), 'FOLDER_NOT_ASSIGNED', 404);
        }
        $this->manager()->setFolderQuota($folderId, $quota);
        return $this->describeFolder($this->requireFolder($folderId), $gid);
    }

    private function requireEnabled(): void {
        if (!$this->isEnabled()) {
            throw new GroupServiceException($this->l->t('Group folders app is not enabled'), 'GROUPFOLDERS_DISABLED', 404);
        }
    }

    private function requireFolder(int $folderId): \OCA\GroupFolders\Folder\FolderWithMappingsAndCache {
        $folder = $this->manager()->getFolder($folderId);
        if ($folder === null) {
            throw new GroupServiceException($this->l->t('Group folder not found'), 'GROUP_FOLDER_NOT_FOUND', 404);
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
            'permissions' => $this->decodePermissions($permissions),
        ];
    }

    /**
     * The permission bitmask math, isolated from the groupfolders DTO so it
     * can be unit-tested without that app's classes being loadable.
     */
    private function encodePermissions(bool $write, bool $share, bool $delete): int {
        return self::PERM_READ
            | ($write ? self::PERM_WRITE : 0)
            | ($share ? self::PERM_SHARE : 0)
            | ($delete ? self::PERM_DELETE : 0);
    }

    /**
     * @return array{write: bool, share: bool, delete: bool}
     */
    private function decodePermissions(int $permissions): array {
        return [
            'write' => ($permissions & self::PERM_WRITE) === self::PERM_WRITE,
            'share' => ($permissions & self::PERM_SHARE) === self::PERM_SHARE,
            'delete' => ($permissions & self::PERM_DELETE) === self::PERM_DELETE,
        ];
    }

    private function manager(): \OCA\GroupFolders\Folder\FolderManager {
        return Server::get(\OCA\GroupFolders\Folder\FolderManager::class);
    }
}
