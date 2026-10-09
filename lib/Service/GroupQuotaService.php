<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GroupManager\Service;

use OCP\App\IAppManager;
use OCP\Files\FileInfo;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserSession;
use OCP\Server;

/**
 * Bridges to the groupquota app (a soft dependency, same approach as
 * FolderAssignmentService: nothing of groupquota is type-hinted, and its
 * classes are only reached after isEnabled() has gated the call).
 *
 * The quota itself is read and written straight through appconfig rather
 * than through groupquota's QuotaManager: the persisted contract
 * (app `groupquota`, key `quota_<gid>`, value in bytes, anything below zero
 * meaning "no limit") is what its filesystem wrapper and occ commands read,
 * and unlike a PHP class it is not something the app can rename between
 * releases without breaking its own stored data. Only the used-space figure
 * has no equivalent shortcut (it is a SUM over every member's file cache), so
 * that one alone goes through groupquota's UsedSpaceCalculator, guarded and
 * best-effort.
 *
 * groupquota applies, to a user in several groups that have a quota, the
 * quota of the FIRST such group in IGroupManager::getUserGroupIds() order
 * (alphabetical by group id on the database backend) — not the lowest, not
 * the highest, and the app's own README calls the case "undefined".
 * overlapFor() reproduces exactly that rule, so the warning the UI shows
 * names the group that really wins.
 */
class GroupQuotaService {
    private const APP = 'groupquota';
    private const KEY_PREFIX = 'quota_';
    /** oc_appconfig.configkey is a 64-character column. */
    private const MAX_KEY_LENGTH = 64;

    /** overlapFor(): members examined at most, and members listed back. */
    private const OVERLAP_SCAN_LIMIT = 500;
    private const OVERLAP_SAMPLE_SIZE = 20;

    public function __construct(
        private IAppManager $appManager,
        private IUserSession $userSession,
        private IL10N $l,
        private IConfig $config,
        private IGroupManager $groupManager,
    ) {
    }

    public function isEnabled(): bool {
        return $this->appManager->isEnabledForUser(self::APP, $this->userSession->getUser());
    }

    /**
     * The configured limit in bytes, or null when the group has none.
     * Cheap (one appconfig read), so GroupService::detail() can call it for
     * every group it describes.
     */
    public function quotaOf(string $gid): ?int {
        $raw = $this->config->getAppValue(self::APP, self::KEY_PREFIX . $gid, '');
        if ($raw === '' || (int)$raw < 0) {
            return null;
        }
        return (int)$raw;
    }

    /**
     * @return array{quota: ?int, used: ?int, overlap: array{count: int, scanned: int, truncated: bool, users: list<array{uid: string, displayName: string, winner: string}>}}
     */
    public function get(string $gid): array {
        $this->requireEnabled();
        $this->requireGroup($gid);
        return [
            'quota' => $this->quotaOf($gid),
            'used' => $this->usedBy($gid),
            'overlap' => $this->overlapFor($gid),
        ];
    }

    /**
     * @param int $quota bytes; FileInfo::SPACE_UNLIMITED (or any negative
     *                   value) removes the limit, 0 is rejected as groupquota
     *                   itself rejects it
     * @return array{quota: ?int, used: ?int, overlap: array{count: int, scanned: int, truncated: bool, users: list<array{uid: string, displayName: string, winner: string}>}}
     */
    public function set(string $gid, int $quota): array {
        $this->requireEnabled();
        $this->requireGroup($gid);
        if ($quota < 0) {
            $this->config->deleteAppValue(self::APP, self::KEY_PREFIX . $gid);
            return $this->get($gid);
        }
        if ($quota === 0) {
            throw new GroupServiceException($this->l->t('A quota must be greater than zero. Remove it to leave the group without a limit.'), 'INVALID_QUOTA', 400);
        }
        $key = self::KEY_PREFIX . $gid;
        if (strlen($key) > self::MAX_KEY_LENGTH) {
            throw new GroupServiceException($this->l->t('This group ID is too long to store a quota for.'), 'GROUP_ID_TOO_LONG', 400);
        }
        $this->config->setAppValue(self::APP, $key, (string)$quota);
        return $this->get($gid);
    }

    public function delete(string $gid): void {
        $this->requireEnabled();
        $this->requireGroup($gid);
        $this->config->deleteAppValue(self::APP, self::KEY_PREFIX . $gid);
    }

    /**
     * Members of $gid whose effective quota would come from another group.
     * "Would", because $gid is counted as a candidate even while it has no
     * quota of its own yet: the warning matters most right before one is set.
     *
     * @return array{count: int, scanned: int, truncated: bool, users: list<array{uid: string, displayName: string, winner: string}>}
     */
    public function overlapFor(string $gid): array {
        $empty = ['count' => 0, 'scanned' => 0, 'truncated' => false, 'users' => []];
        $candidates = array_flip($this->groupIdsWithQuota());
        $candidates[$gid] = true;
        if (count($candidates) < 2) {
            return $empty;
        }
        $group = $this->groupManager->get($gid);
        if ($group === null) {
            return $empty;
        }

        $members = $group->getUsers('', self::OVERLAP_SCAN_LIMIT + 1);
        $truncated = count($members) > self::OVERLAP_SCAN_LIMIT;
        $members = array_slice($members, 0, self::OVERLAP_SCAN_LIMIT);

        $count = 0;
        $sample = [];
        foreach ($members as $user) {
            foreach ($this->groupManager->getUserGroupIds($user) as $userGid) {
                if (!isset($candidates[$userGid])) {
                    continue;
                }
                if ($userGid !== $gid) {
                    $count++;
                    if (count($sample) < self::OVERLAP_SAMPLE_SIZE) {
                        $sample[] = [
                            'uid' => $user->getUID(),
                            'displayName' => $user->getDisplayName(),
                            'winner' => $userGid,
                        ];
                    }
                }
                break;
            }
        }

        return ['count' => $count, 'scanned' => count($members), 'truncated' => $truncated, 'users' => $sample];
    }

    /**
     * @return list<string> ids of every group that currently has a limit
     */
    private function groupIdsWithQuota(): array {
        $ids = [];
        foreach ($this->config->getAppKeys(self::APP) as $key) {
            if (!str_starts_with($key, self::KEY_PREFIX)) {
                continue;
            }
            $gid = substr($key, strlen(self::KEY_PREFIX));
            if ($gid !== '' && $this->quotaOf($gid) !== null) {
                $ids[] = $gid;
            }
        }
        return $ids;
    }

    /**
     * Best effort: a SUM over every member's file cache that only groupquota
     * knows how to compute, so any failure (class renamed, query error) just
     * means the usage bar is not shown.
     */
    private function usedBy(string $gid): ?int {
        $class = 'OCA\GroupQuota\Quota\UsedSpaceCalculator';
        $group = $this->groupManager->get($gid);
        if ($group === null || !class_exists($class)) {
            return null;
        }
        try {
            return (int)Server::get($class)->getUsedSpaceByGroup($group);
        } catch (\Throwable) {
            return null;
        }
    }

    private function requireEnabled(): void {
        if (!$this->isEnabled()) {
            throw new GroupServiceException($this->l->t('Group quota app is not enabled'), 'GROUPQUOTA_DISABLED', 404);
        }
    }

    private function requireGroup(string $gid): void {
        if ($this->groupManager->get($gid) === null) {
            throw new GroupServiceException($this->l->t('Group not found'), 'GROUP_NOT_FOUND', 404);
        }
    }
}
