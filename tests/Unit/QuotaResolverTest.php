<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GroupManager\Tests\Unit;

use OCA\GroupManager\Quota\GetQuotaListener;
use OCA\GroupManager\Quota\GroupUsageCalculator;
use OCA\GroupManager\Quota\QuotaResolver;
use OCA\GroupManager\Service\GroupQuotaService;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\User\GetQuotaEvent;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

const GB = 1024 * 1024 * 1024;

class QuotaResolverTest extends TestCase {

    private IConfig&MockObject $config;
    private IGroupManager&MockObject $groupManager;
    private GroupQuotaService&MockObject $quotas;
    private GroupUsageCalculator&MockObject $usage;
    private QuotaResolver $resolver;

    /** @var array<string, string> group_manager appconfig */
    private array $app = [];
    /** @var array<string, string> uid => files/quota user value */
    private array $personal = [];
    /** @var array<string, ?int> gid => quota */
    private array $limits = [];
    /** @var array<string, list<string>> uid => groups */
    private array $groups = [];
    /** @var array<string, array<string, int>> gid => uid => bytes */
    private array $members = [];

    protected function setUp(): void {
        $this->config = $this->createMock(IConfig::class);
        $this->config->method('getAppValue')->willReturnCallback(
            fn (string $app, string $key, string $default = '') => $this->app[$key] ?? $default
        );
        $this->config->method('getUserValue')->willReturnCallback(
            fn (string $uid, string $app, string $key, $default = '') => $this->personal[$uid] ?? $default
        );
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->groupManager->method('getUserGroupIds')->willReturnCallback(
            fn (IUser $u) => $this->groups[$u->getUID()] ?? []
        );
        $this->quotas = $this->createMock(GroupQuotaService::class);
        $this->quotas->method('quotaOf')->willReturnCallback(fn (string $gid) => $this->limits[$gid] ?? null);
        $this->usage = $this->createMock(GroupUsageCalculator::class);
        $this->usage->method('membersUsage')->willReturnCallback(fn (string $gid) => $this->members[$gid] ?? []);
        $this->usage->method('usedBy')->willReturnCallback(function (string $uid): int {
            // fresh figure: the same breakdown, summed across every group the account is in
            foreach ($this->members as $m) {
                if (isset($m[$uid])) {
                    return $m[$uid];
                }
            }
            return 0;
        });

        $this->resolver = new QuotaResolver($this->config, $this->groupManager, $this->quotas, $this->usage);
    }

    private function user(string $uid): IUser&MockObject {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        return $user;
    }

    public function testNoGroupQuotaLeavesNextcloudInCharge(): void {
        $this->groups = ['u1' => ['a']];
        $this->assertNull($this->resolver->effectiveFor($this->user('u1')));
    }

    public function testRoomIsOwnUsagePlusWhatTheGroupHasLeft(): void {
        $this->limits = ['a' => 10 * GB];
        $this->groups = ['u1' => ['a'], 'u2' => ['a']];
        $this->members = ['a' => ['u1' => 1 * GB, 'u2' => 4 * GB]];

        // u1 holds 1 GB, the others 4 GB: 10 - 4 = 6 GB of room, 5 GB still free in the pool.
        $this->assertSame(6 * GB, $this->resolver->effectiveFor($this->user('u1')));
        $this->assertSame(9 * GB, $this->resolver->effectiveFor($this->user('u2')));
    }

    public function testFullGroupLeavesNoRoomBeyondWhatTheAccountAlreadyHas(): void {
        $this->limits = ['a' => 5 * GB];
        $this->groups = ['u1' => ['a']];
        $this->members = ['a' => ['u1' => 1 * GB, 'u2' => 6 * GB]];

        $this->assertSame(1 * GB, $this->resolver->effectiveFor($this->user('u1')));
    }

    public function testStrictPolicyHonoursEveryGroupsLimit(): void {
        $this->limits = ['a' => 1 * GB, 'b' => 5 * GB];
        $this->groups = ['u2' => ['a', 'b']];
        $this->members = ['a' => ['u2' => 0], 'b' => ['u2' => 0, 'u3' => 4 * GB]];

        // a leaves 1 GB, b leaves only 1 GB (5 - 4): the tighter wins, and it is b, not the smaller quota.
        $this->assertSame(1 * GB, $this->resolver->effectiveFor($this->user('u2')));

        $this->members['b']['u3'] = 1 * GB;
        $this->assertSame(1 * GB, $this->resolver->effectiveFor($this->user('u2')), 'a is now the tighter one');
    }

    public function testPermissivePolicyTakesTheMostGenerousGroup(): void {
        $this->app['quota_policy'] = 'permissive';
        $this->limits = ['basic' => 1 * GB, 'premium' => 50 * GB];
        $this->groups = ['u1' => ['basic', 'premium']];
        $this->members = ['basic' => ['u1' => 0], 'premium' => ['u1' => 0]];

        $this->assertSame(50 * GB, $this->resolver->effectiveFor($this->user('u1')));
    }

    public function testUnknownPolicyFallsBackToStrict(): void {
        $this->app['quota_policy'] = 'whatever';
        $this->assertSame(QuotaResolver::POLICY_STRICT, $this->resolver->policy());
    }

    public function testExplicitPersonalQuotaStillAppliesAsTheSmaller(): void {
        $this->limits = ['a' => 10 * GB];
        $this->groups = ['u1' => ['a']];
        $this->members = ['a' => ['u1' => 0]];
        $this->personal = ['u1' => '2 GB'];

        $this->assertSame(2 * GB, $this->resolver->effectiveFor($this->user('u1')));
    }

    public function testInstanceDefaultPersonalQuotaDoesNotCapTheGroup(): void {
        $this->limits = ['a' => 10 * GB];
        $this->groups = ['u1' => ['a']];
        $this->members = ['a' => ['u1' => 0]];
        foreach (['default', 'none'] as $value) {
            $this->personal = ['u1' => $value];
            $this->assertSame(10 * GB, $this->resolver->effectiveFor($this->user('u1')), $value);
        }
    }

    public function testEnforcementIsOffUnlessSwitchedOn(): void {
        $this->assertFalse($this->resolver->isEnforced());
        $this->app['enforce_group_quota'] = '1';
        $this->assertTrue($this->resolver->isEnforced());
    }

    public function testListenerSetsTheQuotaInBytesOnlyWhenEnforced(): void {
        $this->limits = ['a' => 3 * GB];
        $this->groups = ['u1' => ['a']];
        $this->members = ['a' => ['u1' => 0]];
        $listener = new GetQuotaListener($this->resolver, $this->createMock(LoggerInterface::class));

        $event = new GetQuotaEvent($this->user('u1'));
        $listener->handle($event);
        $this->assertNull($event->getQuota(), 'switched off by default');

        $this->app['enforce_group_quota'] = '1';
        $listener->handle($event);
        $this->assertSame((3 * GB) . ' B', $event->getQuota());
    }

    public function testListenerFailsOpen(): void {
        $this->app['enforce_group_quota'] = '1';
        $broken = $this->createMock(IGroupManager::class);
        $broken->method('getUserGroupIds')->willThrowException(new \RuntimeException('db down'));
        $resolver = new QuotaResolver($this->config, $broken, $this->quotas, $this->usage);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning');

        $event = new GetQuotaEvent($this->user('u1'));
        (new GetQuotaListener($resolver, $logger))->handle($event);

        $this->assertNull($event->getQuota());
    }
}
