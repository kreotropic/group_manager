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
use OCP\IUserBackend;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\L10N\IFactory;
use OCP\UserInterface;
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

    private function backend(string $name): UserInterface&IUserBackend&MockObject {
        $backend = $this->createMockForIntersectionOfInterfaces([UserInterface::class, IUserBackend::class]);
        $backend->method('getBackendName')->willReturn($name);
        return $backend;
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
        $this->userManager->method('getBackends')->willReturn([]);

        $options = $this->service->getOptions();

        $this->assertSame(['1 GB', '5 GB', '20 GB'], $options['quotaPresets']);
        $this->assertSame('10 GB', $options['defaultQuota']);
        $this->assertFalse($options['sendEmail']);
        $this->assertTrue($options['requireEmail']);
        $this->assertSame(['en', 'pt_PT'], array_column($options['languages'], 'code'));
        $this->assertSame('admin', $options['currentUser']);
    }

    public function testOriginFilterPagesThroughThatBackendOnly(): void {
        $database = $this->backend('Database');
        $ldap = $this->backend('LDAP');
        $this->userManager->method('getBackends')->willReturn([$database, $ldap]);
        $this->userManager->expects($this->never())->method('search');
        $database->expects($this->never())->method('getUsers');
        $ldap->expects($this->once())
            ->method('getUsers')
            ->with('ana', 3, 4)
            ->willReturn(['ana', 'ana.b', 'ana.c']);
        $this->userManager->method('get')->willReturnCallback(fn (string $uid) => $this->user($uid, 'LDAP'));

        $result = $this->service->listUsers(' ana ', 2, 4, 'ldap');

        $this->assertTrue($result['hasMore']);
        $this->assertSame(['ana', 'ana.b'], array_column($result['users'], 'uid'));
        $this->assertSame(['ldap', 'ldap'], array_column($result['users'], 'backend'));
    }

    public function testOriginFilterSkipsAccountsThatVanished(): void {
        $database = $this->backend('Database');
        $this->userManager->method('getBackends')->willReturn([$database]);
        $database->method('getUsers')->willReturn(['ana', 'gone']);
        $this->userManager->method('get')->willReturnCallback(fn (string $uid) => $uid === 'gone' ? null : $this->user($uid));

        $result = $this->service->listUsers('', 10, 0, 'local');

        $this->assertSame(['ana'], array_column($result['users'], 'uid'));
    }

    public function testLdapFilterWithoutAnLdapBackendIsEmpty(): void {
        $this->userManager->method('getBackends')->willReturn([$this->backend('Database')]);

        $result = $this->service->listUsers('', 10, 0, 'ldap');

        $this->assertSame([], $result['users']);
        $this->assertFalse($result['hasMore']);
    }

    public function testOptionsTellWhetherAnLdapBackendExists(): void {
        $this->config->method('getAppValue')->willReturnArgument(2);
        $this->l10nFactory->method('getLanguages')->willReturn([]);

        $this->userManager->method('getBackends')->willReturnOnConsecutiveCalls(
            [$this->backend('Database')],
            [$this->backend('Database'), $this->backend('LDAP')],
        );

        $this->assertFalse($this->service->getOptions()['hasLdap']);
        $this->assertTrue($this->service->getOptions()['hasLdap']);
    }
}
