<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GroupManager\Tests\Unit;

use OCA\GroupManager\Service\GroupServiceException;
use OCA\GroupManager\Service\UserService;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class UserServiceTest extends TestCase {

    private IUserManager&MockObject $userManager;
    private IGroupManager&MockObject $groupManager;
    private IUserSession&MockObject $userSession;
    private IConfig&MockObject $config;
    private IFactory&MockObject $l10nFactory;
    private UserService $service;

    protected function setUp(): void {
        $this->userManager = $this->createMock(IUserManager::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->config = $this->createMock(IConfig::class);
        $this->l10nFactory = $this->createMock(IFactory::class);
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnArgument(0);

        $this->service = new UserService(
            $this->userManager,
            $this->groupManager,
            $this->userSession,
            $this->config,
            $this->l10nFactory,
            $l,
        );
    }

    private function user(string $uid, string $backend = 'Database', bool $enabled = true): IUser&MockObject {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $user->method('getDisplayName')->willReturn(ucfirst($uid));
        $user->method('getEMailAddress')->willReturn($uid . '@example.com');
        $user->method('isEnabled')->willReturn($enabled);
        $user->method('getBackendClassName')->willReturn($backend);
        $user->method('getLastLogin')->willReturn(0);
        return $user;
    }

    public function testListAsksForOneExtraRowToTellWhetherMoreExist(): void {
        $this->userManager->expects($this->once())
            ->method('search')
            ->with('ana', 3, 6)
            ->willReturn(['a' => $this->user('a'), 'b' => $this->user('b'), 'c' => $this->user('c')]);

        $result = $this->service->listUsers(' ana ', 2, 6, 'all');

        $this->assertTrue($result['hasMore']);
        $this->assertSame(['a', 'b'], array_column($result['users'], 'uid'));
    }

    public function testLastPageHasNoMore(): void {
        $this->userManager->method('search')->willReturn(['a' => $this->user('a')]);

        $result = $this->service->listUsers('', 2, 0, 'all');

        $this->assertFalse($result['hasMore']);
        $this->assertCount(1, $result['users']);
    }

    public function testDisabledFilterUsesTheDisabledUsersQuery(): void {
        $this->userManager->expects($this->never())->method('search');
        $this->userManager->expects($this->once())
            ->method('getDisabledUsers')
            ->with(51, 0, 'x')
            ->willReturn([$this->user('off', 'Database', false)]);

        $result = $this->service->listUsers('x', null, 0, 'disabled');

        $this->assertFalse($result['users'][0]['enabled']);
    }

    public function testBackendNamesMapToTheAppsOwnOrigins(): void {
        $this->userManager->method('search')->willReturn([
            $this->user('local', 'Database'),
            $this->user('dir', 'LDAP'),
            $this->user('sso', 'user_saml'),
        ]);

        $result = $this->service->listUsers('', 10, 0, 'all');

        $this->assertSame(['local', 'ldap', 'other'], array_column($result['users'], 'backend'));
        $this->assertSame('user_saml', $result['users'][2]['backendName']);
    }

    public function testAFlakyBackendLookupDoesNotFailThePage(): void {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('flaky');
        $user->method('getDisplayName')->willReturn('Flaky');
        $user->method('getEMailAddress')->willThrowException(new \RuntimeException('LDAP down'));
        $user->method('isEnabled')->willThrowException(new \RuntimeException('LDAP down'));
        $user->method('getBackendClassName')->willReturn('LDAP');
        $this->userManager->method('search')->willReturn([$user]);

        $result = $this->service->listUsers('', 10, 0, 'all');

        $this->assertNull($result['users'][0]['email']);
        $this->assertTrue($result['users'][0]['enabled']);
    }

    /**
     * @dataProvider invalidPages
     */
    public function testInvalidPaginationIsRejected(?int $pageSize, int $offset): void {
        $this->expectException(GroupServiceException::class);
        $this->service->listUsers('', $pageSize, $offset, 'all');
    }

    public static function invalidPages(): array {
        return [
            'zero page size' => [0, 0],
            'page too large' => [201, 0],
            'negative offset' => [10, -1],
        ];
    }

    public function testUnknownFilterIsRejected(): void {
        try {
            $this->service->listUsers('', 10, 0, 'admins');
            $this->fail('Expected an exception');
        } catch (GroupServiceException $e) {
            $this->assertSame('INVALID_FILTER', $e->errorCode);
            $this->assertSame(400, $e->httpStatus);
        }
    }

    public function testGetUserReportsTheRawQuotaSetting(): void {
        $this->userManager->method('get')->with('ana')->willReturn($this->user('ana'));
        $this->config->method('getUserValue')->with('ana', 'files', 'quota', 'default')->willReturn('5 GB');

        $this->assertSame('5 GB', $this->service->getUser('ana')['quotaSetting']);
    }

    public function testGetUnknownUserIs404(): void {
        $this->userManager->method('get')->willReturn(null);
        try {
            $this->service->getUser('ghost');
            $this->fail('Expected an exception');
        } catch (GroupServiceException $e) {
            $this->assertSame('USER_NOT_FOUND', $e->errorCode);
            $this->assertSame(404, $e->httpStatus);
        }
    }

    public function testOptionsDropDefaultAndNoneFromThePresets(): void {
        $this->config->method('getAppValue')->willReturnMap([
            ['files', 'quota_preset', '1 GB, 5 GB, 10 GB', '1 GB, default, 5 GB,, none, 20 GB'],
            ['files', 'default_quota', 'none', '10 GB'],
            ['core', 'newUser.sendEmail', 'yes', 'no'],
            ['core', 'newUser.requireEmail', 'no', 'yes'],
            ['core', 'newUser.generateUserID', 'no', 'no'],
        ]);
        $this->l10nFactory->method('getLanguages')->willReturn([
            'commonLanguages' => [['code' => 'en', 'name' => 'English']],
            'otherLanguages' => [['code' => 'pt_PT', 'name' => 'Português']],
        ]);
        $admin = $this->user('admin');
        $this->userSession->method('getUser')->willReturn($admin);

        $options = $this->service->getOptions();

        $this->assertSame(['1 GB', '5 GB', '20 GB'], $options['quotaPresets']);
        $this->assertSame('10 GB', $options['defaultQuota']);
        $this->assertFalse($options['sendEmail']);
        $this->assertTrue($options['requireEmail']);
        $this->assertSame(['en', 'pt_PT'], array_column($options['languages'], 'code'));
        $this->assertSame('admin', $options['currentUser']);
    }
}
