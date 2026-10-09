<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GroupManager\Quota;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IGroupManager;

/**
 * How much of the "files" tree each account uses, read from the file cache
 * of the account's home storage (oc_storages `home::<uid>`, or
 * `object::user:<uid>` on object-store setups), the same source Nextcloud's
 * own quota wrapper measures. Going through oc_storages rather than
 * oc_mounts means an account whose mount row has not been written yet is
 * still counted.
 *
 * The per-member breakdown of a group is cached for a short time, because it
 * is consulted from OCP\User\GetQuotaEvent, which fires from the admin user
 * list, the quota bar and every upload. QuotaResolver only relies on the
 * *other* members' share being slightly stale; the requesting account's own
 * usage is always read fresh (usedBy()).
 */
class GroupUsageCalculator {
    private const CACHE_PREFIX = 'group_usage_';
    public const DEFAULT_TTL = 60;
    /** Storage ids per query: two per account, kept well under the 1000 IN-list limit. */
    private const CHUNK = 400;

    /** @var array<string, array<string, int>> per-request memo */
    private array $memo = [];
    private ?ICache $cache = null;

    public function __construct(
        private IDBConnection $db,
        private IGroupManager $groupManager,
        private ICacheFactory $cacheFactory,
        private IConfig $config,
    ) {
    }

    /**
     * Bytes used by a single account, always fresh within the request.
     */
    public function usedBy(string $uid): int {
        return $this->fetchSizes([$uid])[$uid] ?? 0;
    }

    /**
     * uid => bytes for every member of the group (members with no files are
     * absent). Possibly up to the cache TTL old.
     *
     * @return array<string, int>
     */
    public function membersUsage(string $gid): array {
        if (isset($this->memo[$gid])) {
            return $this->memo[$gid];
        }
        $cache = $this->cache();
        $cached = $cache?->get(self::CACHE_PREFIX . $gid);
        if (is_string($cached)) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return $this->memo[$gid] = array_map('intval', $decoded);
            }
        }

        $group = $this->groupManager->get($gid);
        $uids = [];
        if ($group !== null) {
            foreach ($group->getUsers() as $user) {
                $uids[] = $user->getUID();
            }
        }
        $sizes = $this->fetchSizes($uids);

        $ttl = (int)$this->config->getAppValue('group_manager', 'quota_cache_ttl', (string)self::DEFAULT_TTL);
        if ($ttl > 0) {
            $cache?->set(self::CACHE_PREFIX . $gid, json_encode($sizes), $ttl);
        }
        return $this->memo[$gid] = $sizes;
    }

    /**
     * @param list<string> $uids
     * @return array<string, int>
     */
    private function fetchSizes(array $uids): array {
        if ($uids === []) {
            return [];
        }
        // storage id => uid, both home flavours
        $byStorageId = [];
        foreach ($uids as $uid) {
            $byStorageId[self::storageId('home::' . $uid)] = $uid;
            $byStorageId[self::storageId('object::user:' . $uid)] = $uid;
        }

        $sizes = [];
        foreach (array_chunk(array_keys($byStorageId), self::CHUNK) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('s.id', 'f.size')
                ->from('filecache', 'f')
                ->innerJoin('f', 'storages', 's', $qb->expr()->eq('f.storage', 's.numeric_id'))
                ->where($qb->expr()->in('s.id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_STR_ARRAY)))
                ->andWhere($qb->expr()->eq('f.path_hash', $qb->createNamedParameter(md5('files'))))
                ->andWhere($qb->expr()->gte('f.size', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
            $result = $qb->executeQuery();
            while (($row = $result->fetch()) !== false) {
                $uid = $byStorageId[$row['id']] ?? null;
                if ($uid !== null) {
                    $sizes[$uid] = ($sizes[$uid] ?? 0) + (int)$row['size'];
                }
            }
            $result->closeCursor();
        }
        return $sizes;
    }

    /**
     * oc_storages.id is a 64-character column: Nextcloud stores the md5 of
     * any longer id (OC\Files\Cache\Storage::adjustStorageId()).
     */
    private static function storageId(string $id): string {
        return strlen($id) > 64 ? md5($id) : $id;
    }

    private function cache(): ?ICache {
        if ($this->cache === null) {
            $this->cache = $this->cacheFactory->createDistributed('group_manager_quota');
        }
        return $this->cache;
    }
}
