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
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
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
    private ILockingProvider&MockObject $lockingProvider;
    private GroupService $service;

    protected function setUp(): void {
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->subAdmin = $this->createMock(ISubAdmin::class);
        $this->ldapProviderFactory = $this->createMock(ILDAPProviderFactory::class);
        $this->folderAssignmentService = $this->createMock(FolderAssignmentService::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->l = $this->createMock(IL10N::class);
        $this->lockingProvider = $this->createMock(ILockingProvider::class);

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
            $this->lockingProvider,
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
    private function group(string $gid, array $backendNames, ?string $displayName = null, int|bool $memberCount = 0): IGroup&MockObject {
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

        $this->lockingProvider->expects($this->once())->method('acquireLock')
            ->with('group_manager/admin-group-removal', ILockingProvider::LOCK_EXCLUSIVE);
        $this->lockingProvider->expects($this->once())->method('releaseLock')
            ->with('group_manager/admin-group-removal', ILockingProvider::LOCK_EXCLUSIVE);

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

        // Lock still released even though the guard throws.
        $this->lockingProvider->expects($this->once())->method('releaseLock');

        $this->assertServiceException(
            fn () => $this->service->removeMember('admin', 'bob'),
            'LAST_ADMIN_PROTECTED',
            403,
        );
    }

    public function testRemoveMemberBlocksWhenAdminCountIsUnknown(): void {
        $admin = $this->group('admin', ['Database'], memberCount: false);
        $admin->method('inGroup')->willReturn(true);
        $admin->expects($this->never())->method('removeUser');
        $this->groupManager->method('get')->with('admin')->willReturn($admin);

        $bob = $this->user('bob');
        $this->userManager->method('get')->with('bob')->willReturn($bob);
        $this->userSession->method('getUser')->willReturn($this->user('alice'));

        $this->assertServiceException(
            fn () => $this->service->removeMember('admin', 'bob'),
            'ADMIN_COUNT_UNKNOWN',
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

    public function testRemoveMemberFromAdminGroupRefusedWhenLockIsBusy(): void {
        $admin = $this->group('admin', ['Database'], memberCount: 2);
        $admin->expects($this->never())->method('inGroup');
        $admin->expects($this->never())->method('removeUser');
        $this->groupManager->method('get')->with('admin')->willReturn($admin);

        $bob = $this->user('bob');
        $this->userManager->method('get')->with('bob')->willReturn($bob);

        $this->lockingProvider->method('acquireLock')->willThrowException(
            new LockedException('group_manager/admin-group-removal'),
        );
        $this->lockingProvider->expects($this->never())->method('releaseLock');

        $this->assertServiceException(
            fn () => $this->service->removeMember('admin', 'bob'),
            'ADMIN_GROUP_BUSY',
            409,
        );
    }

    public function testRemoveMemberFromAdminGroupIsNoopWhenAlreadyRemovedUnderLock(): void {
        // Membership is re-checked *after* the lock is acquired — a
        // concurrent removal could have already taken the user out of
        // "admin" between requireGroup() and the lock being granted.
        $admin = $this->group('admin', ['Database'], memberCount: 2);
        $admin->method('inGroup')->willReturn(false);
        $admin->expects($this->never())->method('removeUser');
        $this->groupManager->method('get')->with('admin')->willReturn($admin);

        $bob = $this->user('bob');
        $this->userManager->method('get')->with('bob')->willReturn($bob);

        $this->lockingProvider->expects($this->once())->method('releaseLock');

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

    // -----------------------------------------------------------------
    // GM-07: alreadyMember reflects real group membership, not a page.
    // -----------------------------------------------------------------

    public function testResolvePastedTokensFlagsRealMembershipRegardlessOfPage(): void {
        // The scenario the audit reproduced: 51 members, only 50 loaded by
        // the frontend (this method never even sees that page — it's the
        // frontend's own use of $group->inGroup() via the server, not
        // this.members, that GM-07 is about) — a member outside it must
        // still be recognized as already belonging to the group.
        $group = $this->group('finance', ['Database']);
        $alice = $this->user('alice');
        $alice->method('getDisplayName')->willReturn('Alice');
        $group->method('inGroup')->with($alice)->willReturn(true);
        $this->groupManager->method('get')->with('finance')->willReturn($group);
        $this->userManager->method('get')->with('alice')->willReturn($alice);

        $result = $this->service->resolvePastedTokens('finance', ['alice']);

        $this->assertSame(['token' => 'alice', 'matched' => true, 'uid' => 'alice', 'displayName' => 'Alice', 'alreadyMember' => true], $result[0]);
    }

    public function testResolvePastedTokensFlagsNonMemberAsNotAlreadyMember(): void {
        $group = $this->group('finance', ['Database']);
        $bob = $this->user('bob');
        $group->method('inGroup')->with($bob)->willReturn(false);
        $this->groupManager->method('get')->with('finance')->willReturn($group);
        $this->userManager->method('get')->with('bob')->willReturn($bob);

        $result = $this->service->resolvePastedTokens('finance', ['bob']);

        $this->assertFalse($result[0]['alreadyMember']);
    }

    public function testResolvePastedTokensUnmatchedTokenIsNotAlreadyMember(): void {
        $group = $this->group('finance', ['Database']);
        $group->expects($this->never())->method('inGroup');
        $this->groupManager->method('get')->with('finance')->willReturn($group);
        $this->userManager->method('get')->willReturn(null);

        $result = $this->service->resolvePastedTokens('finance', [sprintf('nobody_%s', uniqid())]);

        $this->assertFalse($result[0]['matched']);
        $this->assertFalse($result[0]['alreadyMember']);
    }

    // -----------------------------------------------------------------
    // GM-10: malformed input is a stable 400, not a 500.
    // -----------------------------------------------------------------

    /**
     * @return list<array{0: list<mixed>}>
     */
    public static function malformedTokenLists(): array {
        return [
            'nested array' => [[['wrong']]],
            'object/assoc array' => [[['a' => 1]]],
            'number' => [[123]],
            'bool' => [[true]],
            'null' => [[null]],
            'good entry after a bad one' => [['alice', 42]],
        ];
    }

    /**
     * @dataProvider malformedTokenLists
     */
    public function testResolvePastedTokensRejectsNonStringEntries(array $tokens): void {
        $group = $this->group('finance', ['Database']);
        $this->groupManager->method('get')->with('finance')->willReturn($group);
        $this->userManager->expects($this->never())->method('get');

        $this->assertServiceException(
            fn () => $this->service->resolvePastedTokens('finance', $tokens),
            'INVALID_TOKENS',
            400,
        );
    }

    public function testResolvePastedTokensRejectsOverlongEntry(): void {
        $group = $this->group('finance', ['Database']);
        $this->groupManager->method('get')->with('finance')->willReturn($group);

        $this->assertServiceException(
            fn () => $this->service->resolvePastedTokens('finance', [str_repeat('a', 321)]),
            'INVALID_TOKENS',
            400,
        );
    }

    public function testGetMembersRejectsNegativeOffset(): void {
        $group = $this->group('finance', ['Database']);
        $group->expects($this->never())->method('searchUsers');
        $this->groupManager->method('get')->with('finance')->willReturn($group);

        $this->assertServiceException(
            fn () => $this->service->getMembers('finance', '', 50, -1),
            'INVALID_PAGINATION',
            400,
        );
    }

    /**
     * @return list<array{0: int}>
     */
    public static function outOfRangeLimits(): array {
        return [
            'negative' => [-1],
            'zero' => [0],
            'excessive' => [10000],
        ];
    }

    /**
     * @dataProvider outOfRangeLimits
     */
    public function testGetMembersRejectsOutOfRangeLimit(int $limit): void {
        $group = $this->group('finance', ['Database']);
        $group->expects($this->never())->method('searchUsers');
        $this->groupManager->method('get')->with('finance')->willReturn($group);

        $this->assertServiceException(
            fn () => $this->service->getMembers('finance', '', $limit, 0),
            'INVALID_PAGINATION',
            400,
        );
    }

    public function testGetMembersDefaultsLimitInsteadOfUnbounded(): void {
        // An omitted limit used to mean "no limit" (GM-06) — it must now
        // mean a bounded default, the same 50 every frontend caller already
        // sends explicitly. +1: see testGetMembersHasMoreReflectsAnExtraFetchedRow.
        $group = $this->group('finance', ['Database']);
        $group->expects($this->once())->method('searchUsers')->with('', 51, 0)->willReturn([]);
        $this->groupManager->method('get')->with('finance')->willReturn($group);

        $this->service->getMembers('finance', '', null, 0);
    }

    public function testGetMembersPassesThroughValidLimit(): void {
        $group = $this->group('finance', ['Database']);
        $group->expects($this->once())->method('searchUsers')->with('', 26, 10)->willReturn([]);
        $this->groupManager->method('get')->with('finance')->willReturn($group);

        $this->service->getMembers('finance', '', 25, 10);
    }

    // -----------------------------------------------------------------
    // GM-05: hasMore is derived from an extra fetched row, not from total.
    // -----------------------------------------------------------------

    private function users(array $uids): array {
        return array_map(fn ($uid) => $this->user($uid), $uids);
    }

    public function testGetMembersHasMoreReflectsAnExtraFetchedRow(): void {
        // limit=2: asking for 3 rows and getting exactly 3 back means a 4th
        // might exist — hasMore is true, and only 2 are returned.
        $group = $this->group('finance', ['Database']);
        $group->method('searchUsers')->with('', 3, 0)->willReturn($this->users(['a', 'b', 'c']));
        $this->groupManager->method('get')->with('finance')->willReturn($group);

        $result = $this->service->getMembers('finance', '', 2, 0);

        $this->assertSame(['a', 'b'], array_map(fn ($m) => $m['uid'], $result['members']));
        $this->assertTrue($result['hasMore']);
    }

    public function testGetMembersHasMoreFalseOnTheLastPage(): void {
        $group = $this->group('finance', ['Database']);
        $group->method('searchUsers')->with('', 3, 0)->willReturn($this->users(['a', 'b']));
        $this->groupManager->method('get')->with('finance')->willReturn($group);

        $result = $this->service->getMembers('finance', '', 2, 0);

        $this->assertSame(['a', 'b'], array_map(fn ($m) => $m['uid'], $result['members']));
        $this->assertFalse($result['hasMore']);
    }

    /**
     * GM-05's own regression scenario: a backend of 120 members whose
     * count() is unknown (`false`) must still be paginable to the end —
     * hasMore must never depend on `total`. Walks 50 + 50 + 20 with no
     * duplication or truncation.
     */
    public function testGetMembersPaginatesAllMembersWithUnknownTotal(): void {
        $allUids = array_map(fn ($i) => sprintf('user%03d', $i), range(1, 120));
        $group = $this->group('finance', ['Database'], memberCount: false);
        $group->method('searchUsers')->willReturnCallback(
            fn ($search, $limit, $offset) => $this->users(array_slice($allUids, $offset, $limit)),
        );
        $this->groupManager->method('get')->with('finance')->willReturn($group);

        $seen = [];
        $offset = 0;
        $pages = 0;
        do {
            $result = $this->service->getMembers('finance', '', 50, $offset);
            $this->assertNull($result['total']);
            foreach ($result['members'] as $m) {
                $seen[] = $m['uid'];
            }
            $offset += count($result['members']);
            $pages++;
            $this->assertLessThanOrEqual(5, $pages, 'pagination did not terminate');
        } while ($result['hasMore']);

        $this->assertSame(3, $pages);
        $this->assertSame($allUids, $seen);
    }

    public function testCreateGroupRejectsGidWithSlash(): void {
        $this->assertServiceException(
            fn () => $this->service->createGroup('path/test'),
            'INVALID_GROUP_ID',
            400,
        );
    }

    // -----------------------------------------------------------------
    // GM-06: searchCandidates() does bounded work, not full enumeration.
    // -----------------------------------------------------------------

    public function testSearchCandidatesNeverEnumeratesDestinationGroupMembers(): void {
        $group = $this->group('finance', ['Database']);
        // The regression: the old code built its exclusion set from
        // IGroup::getUsers() -- every one of finance's own members,
        // unbounded by $limit or the search term.
        $group->expects($this->never())->method('getUsers');
        $alice = $this->user('alice');
        $alice->method('getDisplayName')->willReturn('Alice');
        $bob = $this->user('bob');
        $bob->method('getDisplayName')->willReturn('Bob');
        $group->method('inGroup')->willReturnMap([[$alice, true], [$bob, false]]);
        $this->groupManager->method('get')->with('finance')->willReturn($group);
        $this->groupManager->method('search')->willReturn([]);
        // A page shorter than $limit signals the end of real results, same
        // as an empty one -- the loop must not keep paging past it looking
        // for more.
        $this->userManager->method('search')->willReturnCallback(
            fn ($search, $limit, $offset) => $offset === 0 ? [$alice, $bob] : [],
        );

        $result = $this->service->searchCandidates('finance', 'a', 10);

        $this->assertSame(['bob'], array_map(fn ($u) => $u['uid'], $result['users']));
    }

    public function testSearchCandidatesStopsAfterPageBudgetOnHeavyOverlap(): void {
        // 100% overlap: every candidate examined turns out to already be a
        // member. The old code would have read through the whole backend
        // (or however many results IUserManager::search() had) looking for
        // one that wasn't; this one gives up after a fixed number of pages.
        $group = $this->group('finance', ['Database']);
        $group->method('inGroup')->willReturn(true);
        $this->groupManager->method('get')->with('finance')->willReturn($group);
        $this->groupManager->method('search')->willReturn([]);

        $calls = [];
        $this->userManager->expects($this->exactly(3))->method('search')
            ->willReturnCallback(function ($search, $limit, $offset) use (&$calls) {
                $calls[] = [$search, $limit, $offset];
                return array_map(fn ($i) => $this->user('u' . $i), range($offset, $offset + $limit - 1));
            });

        $result = $this->service->searchCandidates('finance', 'x', 10);

        $this->assertSame([], $result['users']);
        $this->assertSame([['x', 10, 0], ['x', 10, 10], ['x', 10, 20]], $calls);
    }

    public function testSearchCandidatesStopsEarlyOnceLimitIsReached(): void {
        // No overlap at all: the very first page already has enough new
        // candidates, so a second page must not be fetched.
        $group = $this->group('finance', ['Database']);
        $group->method('inGroup')->willReturn(false);
        $this->groupManager->method('get')->with('finance')->willReturn($group);
        $this->groupManager->method('search')->willReturn([]);

        $this->userManager->expects($this->once())->method('search')
            ->willReturnCallback(fn ($search, $limit, $offset) => array_map(
                function ($i) {
                    $user = $this->user('u' . $i);
                    $user->method('getDisplayName')->willReturn('U' . $i);
                    return $user;
                },
                range($offset, $offset + $limit - 1),
            ));

        $result = $this->service->searchCandidates('finance', 'x', 10);

        $this->assertCount(10, $result['users']);
    }

    public function testSearchCandidatesPagesWithACursorAndReportsHasMore(): void {
        $group = $this->group('finance', ['Database']);
        $group->method('inGroup')->willReturn(false);
        $this->groupManager->method('get')->with('finance')->willReturn($group);
        $this->groupManager->method('search')->willReturn([]);

        $calls = [];
        $this->userManager->method('search')->willReturnCallback(function ($search, $limit, $offset) use (&$calls) {
            $calls[] = $offset;
            return array_map(function ($i) {
                $user = $this->user('u' . $i);
                $user->method('getDisplayName')->willReturn('U' . $i);
                return $user;
            }, range($offset, $offset + $limit - 1));
        });

        $first = $this->service->searchCandidates('finance', 'x', 5, 0);
        $second = $this->service->searchCandidates('finance', 'x', 5, $first['nextOffset']);

        $this->assertSame(5, $first['nextOffset']);
        $this->assertTrue($first['hasMore']);
        $this->assertSame(['u5', 'u6', 'u7', 'u8', 'u9'], array_map(fn ($u) => $u['uid'], $second['users']));
        $this->assertSame(10, $second['nextOffset']);
    }

    public function testSearchCandidatesHasMoreIsFalseOnceTheSearchRunsOut(): void {
        $group = $this->group('finance', ['Database']);
        $group->method('inGroup')->willReturn(false);
        $this->groupManager->method('get')->with('finance')->willReturn($group);
        $this->groupManager->method('search')->willReturn([]);
        $this->userManager->method('search')->willReturn([]);

        $result = $this->service->searchCandidates('finance', 'x', 5, 40);

        $this->assertFalse($result['hasMore']);
        $this->assertSame(40, $result['nextOffset']);
    }

    public function testSearchCandidatesBrowsesByDisplayNameWhenNoTermIsGiven(): void {
        $group = $this->group('finance', ['Database']);
        $group->method('inGroup')->willReturn(false);
        $this->groupManager->method('get')->with('finance')->willReturn($group);
        $this->userManager->expects($this->never())->method('search');
        $this->userManager->expects($this->atLeastOnce())->method('searchDisplayName')
            ->willReturnCallback(fn ($search, $limit, $offset) => []);

        $this->service->searchCandidates('finance', '', 10);
    }

    public function testSearchCandidatesGroupsReportMemberCountNotOverlap(): void {
        $group = $this->group('finance', ['Database']);
        $this->groupManager->method('get')->with('finance')->willReturn($group);
        $this->userManager->method('search')->willReturn([]);

        $candidate = $this->group('sales', ['Database'], memberCount: 7);
        // The regression: the old code enumerated every candidate group's
        // own members (IGroup::getUsers()) to compute newMemberCount.
        $candidate->expects($this->never())->method('getUsers');
        $this->groupManager->method('search')->with('sal', 10)->willReturn([$candidate]);

        $result = $this->service->searchCandidates('finance', 'sal', 10);

        $this->assertSame([[
            'id' => 'sales',
            'displayName' => 'sales',
            'backend' => 'local',
            'memberCount' => 7,
        ]], $result['groups']);
    }

    public function testSearchCandidatesGroupsExcludeSelfAndEmptyGroups(): void {
        $group = $this->group('finance', ['Database']);
        $this->groupManager->method('get')->with('finance')->willReturn($group);
        $this->userManager->method('search')->willReturn([]);

        $self = $this->group('finance', ['Database'], memberCount: 5);
        $empty = $this->group('ghosts', ['Database'], memberCount: 0);
        // Unknown count (backend can't answer) is NOT treated as "probably
        // empty" -- it's offered, same as GM-05's "unknown total" elsewhere.
        $unknown = $this->group('mystery', ['Database'], memberCount: false);
        $real = $this->group('sales', ['Database'], memberCount: 3);
        $this->groupManager->method('search')->willReturn([$self, $empty, $unknown, $real]);

        $result = $this->service->searchCandidates('finance', 'x', 10);

        // Order preserved from the backend's own search() result, minus the
        // excluded entries -- not re-sorted (unlike the users side, which
        // corrects for IUserManager::search() not guaranteeing order).
        $this->assertSame(['mystery', 'sales'], array_column($result['groups'], 'id'));
        $this->assertNull(array_column($result['groups'], 'memberCount', 'id')['mystery']);
    }

    public function testSearchCandidatesGroupsSearchIsSkippedForEmptyTerm(): void {
        // Unchanged from before: browsing (empty search) never lists
        // candidate groups, only users -- picking "everyone" via a whole
        // group only makes sense once the admin has named one.
        $group = $this->group('finance', ['Database']);
        $this->groupManager->method('get')->with('finance')->willReturn($group);
        $this->userManager->method('searchDisplayName')->willReturn([]);
        $this->groupManager->expects($this->never())->method('search');

        $result = $this->service->searchCandidates('finance', '', 10);

        $this->assertSame([], $result['groups']);
    }

    /**
     * A GroupService whose ISubAdmin is configured by the test itself --
     * setUp()'s own mock already stubs getGroupsSubAdmins() to [] for
     * everything else.
     */
    private function serviceWithSubAdmin(ISubAdmin&MockObject $subAdmin): GroupService {
        return new GroupService(
            $this->groupManager,
            $this->userManager,
            $subAdmin,
            $this->ldapProviderFactory,
            $this->folderAssignmentService,
            $this->userSession,
            $this->l,
            $this->lockingProvider,
        );
    }

    public function testGetSubAdminsFlagsMembershipAndSortsByName(): void {
        $group = $this->group('finance', ['Database']);
        $this->groupManager->method('get')->with('finance')->willReturn($group);
        $zoe = $this->user('zoe');
        $zoe->method('getDisplayName')->willReturn('Zoe');
        $ana = $this->user('ana');
        $ana->method('getDisplayName')->willReturn('Ana');
        $group->method('inGroup')->willReturnCallback(fn (IUser $u) => $u->getUID() === 'zoe');

        $subAdmin = $this->createMock(ISubAdmin::class);
        $subAdmin->method('getGroupsSubAdmins')->with($group)->willReturn([$zoe, $ana]);

        $result = $this->serviceWithSubAdmin($subAdmin)->getSubAdmins('finance');

        $this->assertSame(['ana', 'zoe'], array_column($result['subAdmins'], 'uid'));
        $this->assertSame([false, true], array_column($result['subAdmins'], 'isMember'));
        $this->assertTrue($result['canGrant']);
    }

    public function testGetSubAdminsReportsAdminGroupAsNotGrantable(): void {
        $admin = $this->group('admin', ['Database']);
        $this->groupManager->method('get')->with('admin')->willReturn($admin);

        $this->assertFalse($this->service->getSubAdmins('admin')['canGrant']);
    }

    public function testAddSubAdminCreatesAssignmentForMember(): void {
        $group = $this->group('finance', ['Database']);
        $group->method('inGroup')->willReturn(true);
        $this->groupManager->method('get')->with('finance')->willReturn($group);
        $bob = $this->user('bob');
        $this->userManager->method('get')->with('bob')->willReturn($bob);

        $this->subAdmin->method('isSubAdminOfGroup')->willReturn(false);
        $this->subAdmin->expects($this->once())->method('createSubAdmin')->with($bob, $group);

        $result = $this->service->addSubAdmin('finance', 'bob');
        $this->assertTrue($result['isMember']);
    }

    public function testAddSubAdminWorksForLdapGroups(): void {
        // The assignment lives in Nextcloud's own table, not the directory.
        $group = $this->group('ldap-team', ['LDAP']);
        $group->method('inGroup')->willReturn(true);
        $this->groupManager->method('get')->with('ldap-team')->willReturn($group);
        $this->userManager->method('get')->willReturn($this->user('bob'));

        $this->subAdmin->expects($this->once())->method('createSubAdmin');

        $this->service->addSubAdmin('ldap-team', 'bob');
    }

    public function testAddSubAdminIsNoopWhenAlreadyAssigned(): void {
        $group = $this->group('finance', ['Database']);
        $group->method('inGroup')->willReturn(true);
        $this->groupManager->method('get')->willReturn($group);
        $this->userManager->method('get')->willReturn($this->user('bob'));

        $this->subAdmin->method('isSubAdminOfGroup')->willReturn(true);
        $this->subAdmin->expects($this->never())->method('createSubAdmin');

        $this->service->addSubAdmin('finance', 'bob');
    }

    public function testAddSubAdminRejectsNonMember(): void {
        $group = $this->group('finance', ['Database']);
        $group->method('inGroup')->willReturn(false);
        $this->groupManager->method('get')->willReturn($group);
        $this->userManager->method('get')->willReturn($this->user('bob'));

        $this->subAdmin->expects($this->never())->method('createSubAdmin');

        $this->assertServiceException(fn () => $this->service->addSubAdmin('finance', 'bob'), 'NOT_A_MEMBER', 400);
    }

    public function testAddSubAdminRejectsAdminGroup(): void {
        $admin = $this->group('admin', ['Database']);
        $admin->method('inGroup')->willReturn(true);
        $this->groupManager->method('get')->willReturn($admin);
        $this->userManager->method('get')->willReturn($this->user('bob'));

        $this->subAdmin->expects($this->never())->method('createSubAdmin');

        $this->assertServiceException(fn () => $this->service->addSubAdmin('admin', 'bob'), 'ADMIN_GROUP_PROTECTED', 403);
    }

    public function testAddSubAdminRejectsUnknownUser(): void {
        $this->groupManager->method('get')->willReturn($this->group('finance', ['Database']));
        $this->userManager->method('get')->willReturn(null);

        $this->assertServiceException(fn () => $this->service->addSubAdmin('finance', 'ghost'), 'USER_NOT_FOUND', 404);
    }

    public function testRemoveSubAdminDeletesAssignmentEvenOnAdminGroup(): void {
        $admin = $this->group('admin', ['Database']);
        $this->groupManager->method('get')->willReturn($admin);
        $bob = $this->user('bob');
        $this->userManager->method('get')->willReturn($bob);

        $this->subAdmin->method('isSubAdminOfGroup')->willReturn(true);
        $this->subAdmin->expects($this->once())->method('deleteSubAdmin')->with($bob, $admin);

        $this->service->removeSubAdmin('admin', 'bob');
    }

    public function testRemoveSubAdminIsNoopWhenNotAssigned(): void {
        $this->groupManager->method('get')->willReturn($this->group('finance', ['Database']));
        $this->userManager->method('get')->willReturn($this->user('bob'));

        $this->subAdmin->method('isSubAdminOfGroup')->willReturn(false);
        $this->subAdmin->expects($this->never())->method('deleteSubAdmin');

        $this->service->removeSubAdmin('finance', 'bob');
    }

    public function testRemoveMemberAlsoEndsGroupAdminAssignment(): void {
        $group = $this->group('finance', ['Database']);
        $group->method('inGroup')->willReturn(true);
        $group->expects($this->once())->method('removeUser');
        $this->groupManager->method('get')->willReturn($group);
        $bob = $this->user('bob');
        $this->userManager->method('get')->willReturn($bob);

        $this->subAdmin->method('isSubAdminOfGroup')->willReturn(true);
        $this->subAdmin->expects($this->once())->method('deleteSubAdmin')->with($bob, $group);

        $this->service->removeMember('finance', 'bob');
    }

    public function testRemoveMemberKeepsGroupAdminWhenAdminGroupRemovalIsRefused(): void {
        // A refused removal (here: removing oneself from "admin") must not
        // half-apply by still ending the group admin assignment.
        $admin = $this->group('admin', ['Database'], memberCount: 2);
        $admin->method('inGroup')->willReturn(true);
        $this->groupManager->method('get')->willReturn($admin);
        $alice = $this->user('alice');
        $this->userManager->method('get')->willReturn($alice);
        $this->userSession->method('getUser')->willReturn($alice);

        $this->subAdmin->expects($this->never())->method('deleteSubAdmin');

        $this->assertServiceException(fn () => $this->service->removeMember('admin', 'alice'), 'CANNOT_REMOVE_SELF_FROM_ADMIN', 403);
    }
}
