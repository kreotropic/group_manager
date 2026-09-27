<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GroupManager\Service;

use OCP\App\IAppManager;
use OCP\Constants;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\IUserSession;
use OCP\Server;

/**
 * Bridges to the groupfolders app (a soft dependency: never type-hinted where
 * it would be eagerly resolved, only reached via Server::get() after
 * isEnabled() has already gated the call) to let a group's folder access be
 * assigned/removed and its per-group permissions edited.
 *
 * groupfolders' own PHP API is not stable across its releases, and this
 * service used to depend on it more than it needed to: pre-20, `FolderManager`
 * returns folders as plain arrays and requires a storage id to list them;
 * 20+ wraps the same data in a `FolderWithMappingsAndCache` object and made
 * that argument optional (confirmed against 19.1.20 through 23.0.1). Every
 * result that crosses that boundary is funnelled through normalizeFolder()
 * into one array shape, so nothing below this point — including
 * requireFolder()'s return type — ever names a groupfolders class. The one
 * exception is manager() itself, an untyped Server::get() reached only after
 * requireEnabled(); mountPointExists() also avoids groupfolders' own method
 * (absent before 20) by querying its table directly, the same way
 * folder_protection's AdminController::fetchGroupFolderMountPoints() does.
 *
 * rootFolder() is resolved the same lazy way, not constructor-injected:
 * OCP\Files\IRootFolder extends OCP\Files\Folder and OC\Hooks\Emitter, and
 * that second, internal (non-OCP) interface isn't part of the
 * `nextcloud/ocp` stub package this app's unit tests build against —
 * constructing an instance of this class, real or mocked, with IRootFolder
 * as a constructor parameter fails wherever the tests run (this project's
 * CI included, which has no full Nextcloud install), not only in the one
 * code path that needs it.
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

    /** Cache for isLegacyGroupFolders() — a Reflection call, not worth repeating per request. */
    private ?bool $legacyGroupFolders = null;

    public function __construct(
        private IAppManager $appManager,
        private IUserSession $userSession,
        private IL10N $l,
        private IDBConnection $db,
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
        foreach ($this->fetchAllFolders() as $folder) {
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
        $folders = $this->fetchAllFolders();
        usort($folders, static fn (array $a, array $b) => strnatcasecmp($a['mountPoint'], $b['mountPoint']));
        foreach ($folders as $folder) {
            if ($this->groupHasAccess($folder, $gid)) {
                continue;
            }
            if ($needle !== '' && !str_contains(mb_strtolower($folder['mountPoint']), $needle)) {
                continue;
            }
            $out[] = [
                'id' => $folder['id'],
                'mountPoint' => $folder['mountPoint'],
                'quota' => $folder['quota'],
                'size' => $folder['size'],
                'acl' => $folder['acl'],
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
        if ($this->mountPointExists($mountPoint)) {
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

    private function requireFolder(int $folderId): array {
        $folder = $this->fetchFolder($folderId);
        if ($folder === null) {
            throw new GroupServiceException($this->l->t('Group folder not found'), 'GROUP_FOLDER_NOT_FOUND', 404);
        }
        return $folder;
    }

    /**
     * $folder['groups'] is keyed by entity id (group OR circle) with a `type`
     * discriminator — only 'group' entries are this app's concern.
     */
    private function groupHasAccess(array $folder, string $gid): bool {
        $entry = $folder['groups'][$gid] ?? null;
        return $entry !== null && ($entry['type'] ?? 'group') === 'group';
    }

    /**
     * @return array<string, mixed>
     */
    private function describeFolder(array $folder, string $gid): array {
        $permissions = $folder['groups'][$gid]['permissions'] ?? 0;
        return [
            'id' => $folder['id'],
            'mountPoint' => $folder['mountPoint'],
            'quota' => $folder['quota'],
            'size' => $folder['size'],
            'acl' => $folder['acl'],
            'permissions' => $this->decodePermissions($permissions),
        ];
    }

    /**
     * groupfolders before 20 requires a $rootStorageId argument to
     * getAllFoldersWithSize()/getFolder() (it feeds a sharding-aware query
     * join and is a no-op on a non-sharded setup, which is the overwhelming
     * common case); 20+ made it fully optional. Checked via Reflection on
     * the actual required-parameter count rather than
     * class_exists(FolderWithMappingsAndCache::class): a misdetection there
     * would silently call getAllFoldersWithSize($storageId) on 20+, whose
     * first positional parameter is $offset instead — wrong results, not a
     * loud failure. Reflection asks exactly the question each call site
     * depends on, so it can't produce that mismatch.
     */
    private function isLegacyGroupFolders(): bool {
        if ($this->legacyGroupFolders === null) {
            $this->legacyGroupFolders = (new \ReflectionMethod(
                \OCA\GroupFolders\Folder\FolderManager::class,
                'getAllFoldersWithSize',
            ))->getNumberOfRequiredParameters() > 0;
        }
        return $this->legacyGroupFolders;
    }

    /**
     * Only meaningful pre-20's $rootStorageId argument (see
     * isLegacyGroupFolders()) — matches what groupfolders' own pre-20
     * controllers/commands pass, rather than a hardcoded 0, which would
     * silently misbehave on a sharded filecache setup.
     */
    private function rootStorageId(): int {
        return $this->rootFolder()->getMountPoint()->getNumericStorageId() ?? 0;
    }

    private function rootFolder(): \OCP\Files\IRootFolder {
        return Server::get(\OCP\Files\IRootFolder::class);
    }

    /**
     * Turns whatever getFolder()/getAllFoldersWithSize() returned — a plain
     * array pre-20, a FolderWithMappingsAndCache object on 20+ — into one
     * fixed shape used everywhere else in this class, so nothing past this
     * point needs to know which one it got.
     *
     * @return array{id: int, mountPoint: string, quota: int, size: int, acl: bool, groups: array}
     */
    private function normalizeFolder(array|object $f): array {
        if (is_array($f)) {
            // Pre-20: keys are snake_case, and 'size' can come back as a
            // numeric string (getFolder() does `$row['size'] ?: 0` over a raw
            // DB row).
            return [
                'id' => (int) $f['id'],
                'mountPoint' => (string) $f['mount_point'],
                'quota' => (int) $f['quota'],
                'size' => (int) ($f['size'] ?? 0),
                'acl' => (bool) $f['acl'],
                'groups' => $f['groups'] ?? [],
            ];
        }
        // 20+: FolderWithMappingsAndCache. getSize() can return a float.
        return [
            'id' => $f->id,
            'mountPoint' => $f->mountPoint,
            'quota' => $f->quota,
            'size' => (int) ($f->rootCacheEntry?->getSize() ?? 0),
            'acl' => $f->acl,
            'groups' => $f->groups,
        ];
    }

    /**
     * @return list<array{id: int, mountPoint: string, quota: int, size: int, acl: bool, groups: array}>
     */
    private function fetchAllFolders(): array {
        $folders = $this->isLegacyGroupFolders()
            ? $this->manager()->getAllFoldersWithSize($this->rootStorageId())
            : $this->manager()->getAllFoldersWithSize();
        return array_values(array_map($this->normalizeFolder(...), $folders));
    }

    /**
     * @return array{id: int, mountPoint: string, quota: int, size: int, acl: bool, groups: array}|null
     */
    private function fetchFolder(int $folderId): ?array {
        $folder = $this->isLegacyGroupFolders()
            ? $this->manager()->getFolder($folderId, $this->rootStorageId())
            : $this->manager()->getFolder($folderId);
        return $folder === null ? null : $this->normalizeFolder($folder);
    }

    /**
     * groupfolders' own mountPointExists() doesn't exist before 20, so this
     * runs the same query its 20+ implementation runs, directly against its
     * table — the same approach folder_protection's
     * AdminController::fetchGroupFolderMountPoints() already uses instead of
     * going through groupfolders' PHP API. Version-independent by
     * construction: no branch needed.
     */
    private function mountPointExists(string $mountPoint): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'c'))
            ->from('group_folders')
            ->where($qb->expr()->eq('mount_point', $qb->createNamedParameter($mountPoint)));
        $result = $qb->executeQuery();
        $count = (int) $result->fetchOne();
        $result->closeCursor();
        return $count > 0;
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
