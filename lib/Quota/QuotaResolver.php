<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GroupManager\Quota;

use OCA\GroupManager\Service\GroupQuotaService;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Util;

/**
 * The effective quota of one account once group quotas are taken into
 * account, for OCP\User\GetQuotaEvent.
 *
 * A group quota is a pool shared by every member, so the room an account has
 * left through group G is what it already uses plus what G has left:
 *
 *     room(G) = usedByUser + max(quota(G) - usedByGroup(G), 0)
 *
 * which, since usedByGroup = usedByOthers + usedByUser, simplifies to
 * max(quota(G) - usedByOthers, usedByUser). Written that way only
 * usedByOthers may be stale (it comes from the cached member breakdown); the
 * account's own usage is read fresh, so a burst of uploads by the account
 * itself can never be counted twice.
 *
 * With several groups the rooms are combined by the configured policy:
 * "strict" (default) takes the smallest, so every group's limit holds;
 * "permissive" the largest, for tiered plans.
 *
 * An explicit personal quota still applies, as the smaller of the two. A
 * personal quota that is only the instance default does not: the group pool
 * replaces it. Nothing here may call IUser::getQuota(), which is what
 * dispatches the event in the first place.
 */
class QuotaResolver {
    public const POLICY_STRICT = 'strict';
    public const POLICY_PERMISSIVE = 'permissive';

    public function __construct(
        private IConfig $config,
        private IGroupManager $groupManager,
        private GroupQuotaService $quotas,
        private GroupUsageCalculator $usage,
    ) {
    }

    public function isEnforced(): bool {
        return $this->config->getAppValue('group_manager', 'enforce_group_quota', '0') === '1';
    }

    public function policy(): string {
        $policy = $this->config->getAppValue('group_manager', 'quota_policy', self::POLICY_STRICT);
        return $policy === self::POLICY_PERMISSIVE ? self::POLICY_PERMISSIVE : self::POLICY_STRICT;
    }

    /**
     * @return int|null bytes, or null when no group quota applies (leave the
     *                  quota to Nextcloud)
     */
    public function effectiveFor(IUser $user): ?int {
        $uid = $user->getUID();
        $limits = [];
        foreach ($this->groupManager->getUserGroupIds($user) as $gid) {
            $quota = $this->quotas->quotaOf($gid);
            if ($quota !== null) {
                $limits[$gid] = $quota;
            }
        }
        if ($limits === []) {
            return null;
        }

        $own = $this->usage->usedBy($uid);
        $rooms = [];
        foreach ($limits as $gid => $quota) {
            $members = $this->usage->membersUsage($gid);
            $others = max(array_sum($members) - ($members[$uid] ?? 0), 0);
            $rooms[] = max($quota - $others, $own);
        }
        $room = $this->policy() === self::POLICY_PERMISSIVE ? max($rooms) : min($rooms);

        $personal = $this->explicitPersonalQuota($uid);
        return $personal === null ? $room : min($room, $personal);
    }

    /**
     * Bytes of a quota the admin set for this account on purpose, or null
     * when it follows the instance default (or is unreadable).
     */
    private function explicitPersonalQuota(string $uid): ?int {
        $raw = $this->config->getUserValue($uid, 'files', 'quota', 'default');
        if ($raw === 'default' || $raw === 'none' || $raw === '') {
            return null;
        }
        $bytes = Util::computerFileSize($raw);
        return $bytes === false ? null : (int)$bytes;
    }
}
