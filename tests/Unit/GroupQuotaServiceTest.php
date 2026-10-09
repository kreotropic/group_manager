<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GroupManager\Tests\Unit;

use OCA\GroupManager\Service\GroupQuotaService;
use OCA\GroupManager\Service\GroupServiceException;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * groupquota's own storage (appconfig, `quota_<gid>`) is simulated with an
 * array behind a mocked IConfig. UsedSpaceCalculator is not loadable here, so
 * `used` is always null: usedBy() is best-effort by design.
 */
class GroupQuotaServiceTest extends TestCase {

    private IAppManager&MockObject $appManager;
    private IConfig&MockObject $config;
    private IGroupManager&MockObject $groupManager;
    private GroupQuotaService $service;

    /** @var array<string, string> appconfig of the groupquota app */
    private array $store = [];
    /** @var array<string, list<string>> uid => group ids, in getUserGroupIds() order */
    private array $userGroups = [];

    protected function setUp(): void {
        $this->appManager = $this->createMock(IAppManager::class);
        $this->appManager->method('isEnabledForUser')->willReturn(true);
        $this->config = $this->createMock(IConfig::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnArgument(0);

        $this->config->method('getAppValue')->willReturnCallback(
            fn (string $app, string $key, string $default = '') => $this->store[$key] ?? $default
        );
        $this->config->method('setAppValue')->willReturnCallback(function (string $app, string $key, string $value): void {
            $this->store[$key] = $value;
        });
        $this->config->method('deleteAppValue')->willReturnCallback(function (string $app, string $key): void {
            unset($this->store[$key]);
        });
        $this->config->method('getAppKeys')->willReturnCallback(fn () => array_keys($this->store));

        $this->groupManager->method('getUserGroupIds')->willReturnCallback(
            fn (IUser $u) => $this->userGroups[$u->getUID()] ?? []
        );

        $this->service = new GroupQuotaService(
            $this->appManager,
            $this->createMock(IUserSession::class),
            $l,
            $this->config,
            $this->groupManager,
        );
    }

    private function user(string $uid): IUser&MockObject {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $user->method('getDisplayName')->willReturn(strtoupper($uid));
        return $user;
    }

    /**
     * @param list<string> $uids members; each one's group list comes from $this->userGroups
     */
    private function registerGroup(string $gid, array $uids): void {
        $group = $this->createMock(IGroup::class);
        $group->method('getGID')->willReturn($gid);
        $group->method('getUsers')->willReturnCallback(
            fn (string $search = '', int $limit = -1) => array_map(fn (string $u) => $this->user($u), $limit < 0 ? $uids : array_slice($uids, 0, $limit))
        );
        $this->groups[$gid] = $group;
        $this->groupManager->method('get')->willReturnCallback(fn (string $id) => $this->groups[$id] ?? null);
    }

    /** @var array<string, IGroup&MockObject> */
    private array $groups = [];

    public function testQuotaOfTreatsMissingAndNegativeValuesAsNoLimit(): void {
        $this->assertNull($this->service->quotaOf('a'));
        $this->store['quota_a'] = '-3';
        $this->assertNull($this->service->quotaOf('a'));
        $this->store['quota_a'] = '1073741824';
        $this->assertSame(1073741824, $this->service->quotaOf('a'));
    }

    public function testSetWritesBytesUnderGroupquotaKey(): void {
        $this->registerGroup('a', []);
        $this->service->set('a', 5368709120);
        $this->assertSame('5368709120', $this->store['quota_a']);
    }

    public function testSetNegativeRemovesTheLimit(): void {
        $this->registerGroup('a', []);
        $this->store['quota_a'] = '100';
        $result = $this->service->set('a', -3);
        $this->assertArrayNotHasKey('quota_a', $this->store);
        $this->assertNull($result['quota']);
    }

    public function testSetZeroIsRejected(): void {
        $this->registerGroup('a', []);
        try {
            $this->service->set('a', 0);
            $this->fail('expected INVALID_QUOTA');
        } catch (GroupServiceException $e) {
            $this->assertSame('INVALID_QUOTA', $e->errorCode);
            $this->assertSame(400, $e->httpStatus);
        }
        $this->assertArrayNotHasKey('quota_a', $this->store);
    }

    public function testSetRejectsAGroupIdWhoseKeyExceedsTheColumn(): void {
        $gid = str_repeat('g', 60);
        $this->registerGroup($gid, []);
        try {
            $this->service->set($gid, 1024);
            $this->fail('expected GROUP_ID_TOO_LONG');
        } catch (GroupServiceException $e) {
            $this->assertSame('GROUP_ID_TOO_LONG', $e->errorCode);
        }
        $this->assertSame([], $this->store);
    }

    public function testUnknownGroupIs404(): void {
        $this->groupManager->method('get')->willReturn(null);
        try {
            $this->service->set('ghost', 1024);
            $this->fail('expected GROUP_NOT_FOUND');
        } catch (GroupServiceException $e) {
            $this->assertSame('GROUP_NOT_FOUND', $e->errorCode);
            $this->assertSame(404, $e->httpStatus);
        }
    }

    public function testDisabledAppIs404(): void {
        $appManager = $this->createMock(IAppManager::class);
        $appManager->method('isEnabledForUser')->willReturn(false);
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnArgument(0);
        $service = new GroupQuotaService($appManager, $this->createMock(IUserSession::class), $l, $this->config, $this->groupManager);

        try {
            $service->get('a');
            $this->fail('expected GROUPQUOTA_DISABLED');
        } catch (GroupServiceException $e) {
            $this->assertSame('GROUPQUOTA_DISABLED', $e->errorCode);
            $this->assertSame(404, $e->httpStatus);
        }
    }

    public function testDeleteRemovesTheKey(): void {
        $this->registerGroup('a', []);
        $this->store['quota_a'] = '100';
        $this->service->delete('a');
        $this->assertSame([], $this->store);
    }

    public function testOverlapNamesTheGroupThatActuallyWins(): void {
        // groupquota picks the first group with a quota in getUserGroupIds() order.
        $this->store = ['quota_gq_a' => '1073741824', 'quota_gq_b' => '5368709120'];
        $this->userGroups = ['u1' => ['gq_a'], 'u2' => ['gq_a', 'gq_b'], 'u3' => ['gq_b']];
        $this->registerGroup('gq_a', ['u1', 'u2']);
        $this->registerGroup('gq_b', ['u2', 'u3']);

        $this->assertSame(0, $this->service->overlapFor('gq_a')['count'], 'gq_a wins for everyone it contains');

        $overlap = $this->service->overlapFor('gq_b');
        $this->assertSame(1, $overlap['count']);
        $this->assertSame([['uid' => 'u2', 'displayName' => 'U2', 'winner' => 'gq_a']], $overlap['users']);
    }

    public function testOverlapIgnoresGroupsWithoutAQuota(): void {
        $this->store = ['quota_gq_b' => '5368709120'];
        $this->userGroups = ['u2' => ['gq_a', 'gq_b']];
        $this->registerGroup('gq_b', ['u2']);

        $this->assertSame(0, $this->service->overlapFor('gq_b')['count']);
    }

    public function testOverlapAnticipatesAQuotaNotSetYet(): void {
        // gq_a has none yet; gq_0 does and sorts first, so it would override.
        $this->store = ['quota_gq_0' => '9663676416'];
        $this->userGroups = ['u2' => ['gq_0', 'gq_a']];
        $this->registerGroup('gq_a', ['u2']);

        $overlap = $this->service->overlapFor('gq_a');
        $this->assertSame(1, $overlap['count']);
        $this->assertSame('gq_0', $overlap['users'][0]['winner']);
    }

    public function testOverlapScanIsCappedAndFlagged(): void {
        $this->store = ['quota_a' => '1', 'quota_b' => '1'];
        $uids = [];
        for ($i = 0; $i < 600; $i++) {
            $uids[] = 'u' . $i;
            $this->userGroups['u' . $i] = ['a', 'b'];
        }
        $this->registerGroup('b', $uids);

        $overlap = $this->service->overlapFor('b');
        $this->assertTrue($overlap['truncated']);
        $this->assertSame(500, $overlap['scanned']);
        $this->assertSame(500, $overlap['count']);
        $this->assertCount(20, $overlap['users']);
    }
}
