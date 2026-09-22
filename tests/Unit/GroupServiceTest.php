<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GroupManager\Tests\Unit;

use OCA\GroupManager\Service\FolderAssignmentService;
use OCA\GroupManager\Service\GroupService;
use OCA\GroupManager\Service\GroupServiceException;
use OCP\Group\ISubAdmin;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\LDAP\ILDAPProviderFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class GroupServiceTest extends TestCase {

    private IGroupManager&MockObject $groupManager;
    private IUserManager&MockObject $userManager;
    private ISubAdmin&MockObject $subAdmin;
    private ILDAPProviderFactory&MockObject $ldapProviderFactory;
    private FolderAssignmentService&MockObject $folderAssignmentService;
    private IUserSession&MockObject $userSession;
    private IL10N&MockObject $l;
    private GroupService $service;

    protected function setUp(): void {
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->subAdmin = $this->createMock(ISubAdmin::class);
        $this->ldapProviderFactory = $this->createMock(ILDAPProviderFactory::class);
        $this->folderAssignmentService = $this->createMock(FolderAssignmentService::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->l = $this->createMock(IL10N::class);

        $this->ldapProviderFactory->method('isAvailable')->willReturn(false);
        $this->folderAssignmentService->method('isEnabled')->willReturn(false);
        $this->folderAssignmentService->method('folderCount')->willReturn(0);
        $this->subAdmin->method('getGroupsSubAdmins')->willReturn([]);
        // IUserSession::getUser() is nullable, so an unconfigured mock
        // already returns null (no signed-in user) — tests that care about
        // self-removal configure it explicitly, once, on their own.
        // Messages are asserted by errorCode, never by translated text, so
        // the mock just echoes the untranslated string back.
        $this->l->method('t')->willReturnArgument(0);

        $this->service = new GroupService(
            $this->groupManager,
            $this->userManager,
            $this->subAdmin,
            $this->ldapProviderFactory,
            $this->folderAssignmentService,
            $this->userSession,
            $this->l,
        );
    }

    private function user(string $uid): IUser&MockObject {
        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn($uid);
        return $user;
    }

    /**
     * @param list<string> $backendNames
     */
    private function group(string $gid, array $backendNames, ?string $displayName = null, int $memberCount = 0): IGroup&MockObject {
        $group = $this->createMock(IGroup::class);
        $group->method('getGID')->willReturn($gid);
        $group->method('getDisplayName')->willReturn($displayName ?? $gid);
        $group->method('getBackendNames')->willReturn($backendNames);
        $group->method('count')->willReturn($memberCount);
        $group->method('countDisabled')->willReturn(0);
        $group->method('canAddUser')->willReturn(true);
        $group->method('canRemoveUser')->willReturn(true);
        return $group;
    }

    private function assertServiceException(callable $action, string $errorCode, int $httpStatus): void {
        try {
            $action();
            $this->fail('Expected GroupServiceException');
        } catch (GroupServiceException $e) {
            $this->assertSame($errorCode, $e->errorCode);
            $this->assertSame($httpStatus, $e->httpStatus);
        }
    }

    public function testGetGroupThrowsWhenNotFound(): void {
        $this->groupManager->method('get')->with('missing')->willReturn(null);

        $this->assertServiceException(
            fn () => $this->service->getGroup('missing'),
            'GROUP_NOT_FOUND',
            404,
        );
    }

    public function testListGroupsClassifiesBackends(): void {
        $local = $this->group('finance', ['Database']);
        $ldap = $this->group('ldap-team', ['LDAP']);
        $other = $this->group('circle-x', ['Circle']);
        $this->groupManager->method('search')->with('')->willReturn([$local, $ldap, $other]);

        $result = $this->service->listGroups();

        $this->assertSame('local', $result[0]['backend']);
        $this->assertTrue($result[0]['isLocal']);
        $this->assertSame('ldap', $result[1]['backend']);
        $this->assertFalse($result[1]['isLocal']);
        $this->assertSame('other', $result[2]['backend']);
        $this->assertFalse($result[2]['isLocal']);
    }

    public function testAdminGroupCannotBeDeletedEvenWhenLocal(): void {
        $admin = $this->group('admin', ['Database']);
        $this->groupManager->method('search')->with('')->willReturn([$admin]);

        $result = $this->service->listGroups();

        $this->assertTrue($result[0]['isLocal']);
        $this->assertFalse($result[0]['canDelete']);
    }

    public function testRenameGroupRejectsLdapGroup(): void {
        $group = $this->group('ldap-team', ['LDAP']);
        $this->groupManager->method('get')->with('ldap-team')->willReturn($group);

        $this->assertServiceException(
            fn () => $this->service->renameGroup('ldap-team', 'New name'),
            'GROUP_NOT_LOCAL',
            403,
        );
    }

    public function testRenameGroupAcceptsLocalGroup(): void {
        $group = $this->group('finance', ['Database']);
        $group->expects($this->once())->method('setDisplayName')->with('Finance Team')->willReturn(true);
        $this->groupManager->method('get')->with('finance')->willReturn($group);

        $result = $this->service->renameGroup('finance', 'Finance Team');

        $this->assertSame('finance', $result['id']);
        $this->assertTrue($result['isLocal']);
    }

    public function testRenameGroupRejectsEmptyDisplayName(): void {
        $group = $this->group('finance', ['Database']);
        $this->groupManager->method('get')->with('finance')->willReturn($group);

        $this->assertServiceException(
            fn () => $this->service->renameGroup('finance', '   '),
            'INVALID_DISPLAY_NAME',
            400,
        );
    }

    public function testDeleteGroupRejectsAdminGroup(): void {
        $group = $this->group('admin', ['Database']);
        $this->groupManager->method('get')->with('admin')->willReturn($group);

        $this->assertServiceException(
            fn () => $this->service->deleteGroup('admin'),
            'ADMIN_GROUP_PROTECTED',
            403,
        );
    }

    public function testDeleteGroupRejectsLdapGroup(): void {
        $group = $this->group('ldap-team', ['LDAP']);
        $this->groupManager->method('get')->with('ldap-team')->willReturn($group);

        $this->assertServiceException(
            fn () => $this->service->deleteGroup('ldap-team'),
            'GROUP_NOT_LOCAL',
            403,
        );
    }

    public function testAddMemberRejectsLdapGroup(): void {
        $group = $this->group('ldap-team', ['LDAP']);
        $this->groupManager->method('get')->with('ldap-team')->willReturn($group);

        $this->assertServiceException(
            fn () => $this->service->addMember('ldap-team', 'alice'),
            'GROUP_NOT_LOCAL',
            403,
        );
    }

    public function testRemoveMemberRejectsLdapGroup(): void {
        $group = $this->group('ldap-team', ['LDAP']);
        $this->groupManager->method('get')->with('ldap-team')->willReturn($group);

        $this->assertServiceException(
            fn () => $this->service->removeMember('ldap-team', 'alice'),
            'GROUP_NOT_LOCAL',
            403,
        );
    }

    public function testCreateGroupRejectsEmptyGid(): void {
        $this->assertServiceException(
            fn () => $this->service->createGroup('   '),
            'INVALID_GROUP_ID',
            400,
        );
    }

    public function testCreateGroupRejectsDuplicateGid(): void {
        $this->groupManager->method('groupExists')->with('finance')->willReturn(true);

        $this->assertServiceException(
            fn () => $this->service->createGroup('finance'),
            'GROUP_ALREADY_EXISTS',
            409,
        );
    }

    public function testResolvePastedTokensRejectsTooMany(): void {
        $group = $this->group('finance', ['Database']);
        $this->groupManager->method('get')->with('finance')->willReturn($group);
        $tokens = array_fill(0, 501, 'someone');

        $this->assertServiceException(
            fn () => $this->service->resolvePastedTokens('finance', $tokens),
            'TOO_MANY_TOKENS',
            400,
        );
    }

    public function testRemoveMemberBlocksSelfRemovalFromAdminGroup(): void {
        $admin = $this->group('admin', ['Database'], memberCount: 3);
        $admin->method('inGroup')->willReturn(true);
        $admin->expects($this->never())->method('removeUser');
        $this->groupManager->method('get')->with('admin')->willReturn($admin);

        $alice = $this->user('alice');
        $this->userManager->method('get')->with('alice')->willReturn($alice);
        $this->userSession->method('getUser')->willReturn($this->user('alice'));

        $this->assertServiceException(
            fn () => $this->service->removeMember('admin', 'alice'),
            'CANNOT_REMOVE_SELF_FROM_ADMIN',
            403,
        );
    }

    public function testRemoveMemberBlocksRemovingLastAdmin(): void {
        $admin = $this->group('admin', ['Database'], memberCount: 1);
        $admin->method('inGroup')->willReturn(true);
        $admin->expects($this->never())->method('removeUser');
        $this->groupManager->method('get')->with('admin')->willReturn($admin);

        $bob = $this->user('bob');
        $this->userManager->method('get')->with('bob')->willReturn($bob);
        // Signed-in user is someone else, so this isn't a self-removal — but
        // bob is the group's only member, so it must still be refused.
        $this->userSession->method('getUser')->willReturn($this->user('alice'));

        $this->assertServiceException(
            fn () => $this->service->removeMember('admin', 'bob'),
            'LAST_ADMIN_PROTECTED',
            403,
        );
    }

    public function testRemoveMemberAllowsRemovingAnotherAdminWhenMoreThanOneRemain(): void {
        $admin = $this->group('admin', ['Database'], memberCount: 2);
        $admin->method('inGroup')->willReturn(true);
        $admin->expects($this->once())->method('removeUser');
        $this->groupManager->method('get')->with('admin')->willReturn($admin);

        $bob = $this->user('bob');
        $this->userManager->method('get')->with('bob')->willReturn($bob);
        $this->userSession->method('getUser')->willReturn($this->user('alice'));

        $this->service->removeMember('admin', 'bob');
    }

    public function testResolvePastedTokensAllowsUpToLimit(): void {
        $group = $this->group('finance', ['Database']);
        $this->groupManager->method('get')->with('finance')->willReturn($group);
        $this->userManager->method('get')->willReturn(null);
        $tokens = array_fill(0, 500, 'someone');

        $result = $this->service->resolvePastedTokens('finance', $tokens);

        $this->assertCount(500, $result);
    }
}
