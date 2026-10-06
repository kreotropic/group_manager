<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GroupManager\Tests\Unit;

use OCA\GroupManager\Service\FolderAssignmentService;
use OCA\GroupManager\Service\GroupServiceException;
use OCP\App\IAppManager;
use OCP\Files\Cache\ICacheEntry;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * encodePermissions()/decodePermissions() are exercised via reflection
 * because they are private — but, unlike the rest of this class, they take
 * and return only primitives, so they need no groupfolders class at all.
 * The same is now true of normalizeFolder(), groupHasAccess() and
 * describeFolder(): the service was rewritten to convert groupfolders'
 * result into a plain array before touching it (see FolderAssignmentService's
 * class docblock), so plain arrays/objects are enough to exercise them here —
 * no groupfolders class needs to be loadable.
 *
 * Still left to a future integration test against a real instance:
 * isLegacyGroupFolders(), rootStorageId(), fetchAllFolders(), fetchFolder(),
 * mountPointExists() and fetchAssignedFolderIds() (GM-06) themselves, and
 * every public method that calls through manager()/rootFolder()
 * (Server::get()) — those need groupfolders' classes/tables (or, for
 * rootFolder(), a real Nextcloud service container) to actually exist.
 * build/nc-instance.sh's per-Nextcloud-version matrix (including
 * groupfolders 19.x, where this fix's normalization path matters) covers
 * that ground instead, plus build/api-check.py's own GM-06 checks (folders:
 * folderCount/listAssigned use the direct query, not a full sweep).
 * Notably, OCP\Files\IRootFolder itself can't even be constructor-injected
 * here for that reason: it extends OC\Hooks\Emitter, an internal (non-OCP)
 * interface this project's bare CI environment has no autoloader for —
 * rootFolder() is resolved lazily via Server::get(), the same pattern
 * manager() already used, precisely so that merely instantiating this
 * service (as every test below does) never needs it.
 */
class FolderAssignmentServiceTest extends TestCase {

    private IAppManager&MockObject $appManager;
    private IUserSession&MockObject $userSession;
    private IL10N&MockObject $l;
    private IDBConnection&MockObject $db;
    private IGroupManager&MockObject $groupManager;
    private FolderAssignmentService $service;

    protected function setUp(): void {
        $this->appManager = $this->createMock(IAppManager::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->l = $this->createMock(IL10N::class);
        $this->l->method('t')->willReturnArgument(0);
        $this->db = $this->createMock(IDBConnection::class);
        $this->groupManager = $this->createMock(IGroupManager::class);
        $this->service = new FolderAssignmentService(
            $this->appManager,
            $this->userSession,
            $this->l,
            $this->db,
            $this->groupManager,
        );
    }

    /**
     * assignFolder/createFolder/setPermissions/setQuota/listAssigned/
     * searchAssignable all call requireGroup() before touching anything
     * that needs groupfolders' own backend (manager()/Server::get()) — see
     * FolderAssignmentService::requireGroup()'s docblock — so the
     * GROUP_NOT_FOUND path for each is reachable here, unlike the rest of
     * those methods.
     */
    private function assertRejectsMissingGroup(callable $action): void {
        $this->appManager->method('isEnabledForUser')->willReturn(true);
        $this->groupManager->method('get')->with('ghost')->willReturn(null);

        try {
            $action();
            $this->fail('Expected GroupServiceException');
        } catch (GroupServiceException $e) {
            $this->assertSame('GROUP_NOT_FOUND', $e->errorCode);
            $this->assertSame(404, $e->httpStatus);
        }
    }

    private function encode(bool $write, bool $share, bool $delete): int {
        $method = new \ReflectionMethod($this->service, 'encodePermissions');
        return $method->invokeArgs($this->service, [$write, $share, $delete]);
    }

    /**
     * @return array{write: bool, share: bool, delete: bool}
     */
    private function decode(int $permissions): array {
        $method = new \ReflectionMethod($this->service, 'decodePermissions');
        return $method->invokeArgs($this->service, [$permissions]);
    }

    private function normalize(array|object $folder): array {
        $method = new \ReflectionMethod($this->service, 'normalizeFolder');
        return $method->invokeArgs($this->service, [$folder]);
    }

    private function groupHasAccess(array $folder, string $gid): bool {
        $method = new \ReflectionMethod($this->service, 'groupHasAccess');
        return $method->invokeArgs($this->service, [$folder, $gid]);
    }

    private function describeFolder(array $folder, string $gid): array {
        $method = new \ReflectionMethod($this->service, 'describeFolder');
        return $method->invokeArgs($this->service, [$folder, $gid]);
    }

    /**
     * A groupfolders pre-20 FolderManager::getFolder()/getAllFoldersWithSize()
     * row: a plain array, snake_case keys, 'size' possibly a numeric string
     * straight off the DB row.
     */
    private function legacyFolderRow(): array {
        return [
            'id' => '7',
            'mount_point' => 'Finance',
            'quota' => -3,
            'size' => '12345',
            'acl' => 1,
            'groups' => ['finance' => ['displayName' => 'finance', 'permissions' => 31, 'type' => 'group']],
        ];
    }

    /**
     * The 20+ shape: a FolderWithMappingsAndCache-like object (an anonymous
     * class stands in for it — the real class isn't autoloadable here, and
     * normalizeFolder() never names it, only checks is_array()).
     */
    private function modernFolder(): object {
        $cacheEntry = $this->createMock(ICacheEntry::class);
        $cacheEntry->method('getSize')->willReturn(12345);
        return new class ($cacheEntry) {
            public int $id = 7;
            public string $mountPoint = 'Finance';
            public int $quota = -3;
            public bool $acl = true;
            public array $groups = ['finance' => ['displayName' => 'finance', 'permissions' => 31, 'type' => 'group']];
            public function __construct(public ICacheEntry $rootCacheEntry) {
            }
        };
    }

    public function testEncodePermissionsAlwaysIncludesRead(): void {
        $this->assertSame(1, $this->encode(false, false, false));
    }

    public function testEncodePermissionsCombinesFlags(): void {
        // READ(1) | WRITE(UPDATE 2 + CREATE 4 = 6) | DELETE(8) = 15, no SHARE(16).
        $this->assertSame(15, $this->encode(true, false, true));
    }

    public function testEncodePermissionsAllFlags(): void {
        // READ(1) | WRITE(6) | SHARE(16) | DELETE(8) = 31.
        $this->assertSame(31, $this->encode(true, true, true));
    }

    /**
     * @return list<array{0: bool, 1: bool, 2: bool}>
     */
    public static function permissionCombinations(): array {
        return [
            [false, false, false],
            [true, false, false],
            [false, true, false],
            [false, false, true],
            [true, true, false],
            [true, false, true],
            [false, true, true],
            [true, true, true],
        ];
    }

    /**
     * @dataProvider permissionCombinations
     */
    public function testDecodePermissionsRoundTripsEncode(bool $write, bool $share, bool $delete): void {
        $permissions = $this->encode($write, $share, $delete);

        $this->assertSame(
            ['write' => $write, 'share' => $share, 'delete' => $delete],
            $this->decode($permissions),
        );
    }

    public function testDecodePermissionsIgnoresUnrelatedBits(): void {
        // A stray high bit outside READ/WRITE/SHARE/DELETE must not be
        // mistaken for any of the three toggles.
        $this->assertSame(
            ['write' => false, 'share' => false, 'delete' => false],
            $this->decode(1 | 64),
        );
    }

    public function testSetQuotaRejectsNegativeNonSentinelValue(): void {
        $this->appManager->method('isEnabledForUser')->willReturn(true);

        try {
            $this->service->setQuota('finance', 5, -1);
            $this->fail('Expected GroupServiceException');
        } catch (GroupServiceException $e) {
            $this->assertSame('INVALID_QUOTA', $e->errorCode);
            $this->assertSame(400, $e->httpStatus);
        }
    }

    public function testSetQuotaRejectsDisabledAppBeforeQuotaCheck(): void {
        // requireEnabled() must win here — GROUPFOLDERS_DISABLED, not
        // INVALID_QUOTA, even though the quota given is also invalid.
        $this->appManager->method('isEnabledForUser')->willReturn(false);

        try {
            $this->service->setQuota('finance', 5, -1);
            $this->fail('Expected GroupServiceException');
        } catch (GroupServiceException $e) {
            $this->assertSame('GROUPFOLDERS_DISABLED', $e->errorCode);
        }
    }

    public function testCreateFolderRejectsDisabledApp(): void {
        // Same reasoning as testSetQuotaRejectsDisabledAppBeforeQuotaCheck:
        // requireEnabled() runs before anything that would need a
        // groupfolders class, so this is the one createFolder() branch
        // testable here — trimMountpoint()/mountPointExists()/createFolder()
        // itself all go through manager() (Server::get()), left to a future
        // integration test per this file's own class-level note.
        $this->appManager->method('isEnabledForUser')->willReturn(false);

        try {
            $this->service->createFolder('finance', 'Shared');
            $this->fail('Expected GroupServiceException');
        } catch (GroupServiceException $e) {
            $this->assertSame('GROUPFOLDERS_DISABLED', $e->errorCode);
            $this->assertSame(404, $e->httpStatus);
        }
    }

    // -----------------------------------------------------------------
    // normalizeFolder(): groupfolders pre-20 (array) vs 20+ (object) shapes
    // -----------------------------------------------------------------

    private function expectedNormalizedFolder(): array {
        return [
            'id' => 7,
            'mountPoint' => 'Finance',
            'quota' => -3,
            'size' => 12345,
            'acl' => true,
            'groups' => ['finance' => ['displayName' => 'finance', 'permissions' => 31, 'type' => 'group']],
        ];
    }

    public function testNormalizeFolderFromLegacyArray(): void {
        $this->assertSame($this->expectedNormalizedFolder(), $this->normalize($this->legacyFolderRow()));
    }

    public function testNormalizeFolderFromModernObject(): void {
        $this->assertSame($this->expectedNormalizedFolder(), $this->normalize($this->modernFolder()));
    }

    public function testNormalizeFolderLegacyAndModernAgreeOnEquivalentInput(): void {
        // The regression this fix is for: whichever groupfolders release
        // answers, the rest of the service must see the exact same shape.
        $this->assertSame(
            $this->normalize($this->legacyFolderRow()),
            $this->normalize($this->modernFolder()),
        );
    }

    public function testNormalizeFolderDefaultsMissingLegacyGroups(): void {
        $row = $this->legacyFolderRow();
        unset($row['groups']);
        $this->assertSame([], $this->normalize($row)['groups']);
    }

    // -----------------------------------------------------------------
    // groupHasAccess()
    // -----------------------------------------------------------------

    public function testGroupHasAccessTrueForGroupEntry(): void {
        $folder = $this->normalize($this->legacyFolderRow());
        $this->assertTrue($this->groupHasAccess($folder, 'finance'));
    }

    public function testGroupHasAccessFalseForCircleEntry(): void {
        $folder = $this->normalize($this->legacyFolderRow());
        $folder['groups']['finance']['type'] = 'circle';
        $this->assertFalse($this->groupHasAccess($folder, 'finance'));
    }

    public function testGroupHasAccessFalseWhenGroupMissing(): void {
        $folder = $this->normalize($this->legacyFolderRow());
        $this->assertFalse($this->groupHasAccess($folder, 'no_such_group'));
    }

    public function testGroupHasAccessDefaultsMissingTypeToGroup(): void {
        $folder = $this->normalize($this->legacyFolderRow());
        unset($folder['groups']['finance']['type']);
        $this->assertTrue($this->groupHasAccess($folder, 'finance'));
    }

    // -----------------------------------------------------------------
    // describeFolder()
    // -----------------------------------------------------------------

    public function testDescribeFolderShapeAndPermissions(): void {
        $folder = $this->normalize($this->legacyFolderRow());
        $this->assertSame(
            [
                'id' => 7,
                'mountPoint' => 'Finance',
                'quota' => -3,
                'size' => 12345,
                'acl' => true,
                'permissions' => ['write' => true, 'share' => true, 'delete' => true], // 31
            ],
            $this->describeFolder($folder, 'finance'),
        );
    }

    public function testDescribeFolderDefaultsMissingGroupPermissionsToZero(): void {
        $folder = $this->normalize($this->legacyFolderRow());
        $described = $this->describeFolder($folder, 'no_such_group');
        $this->assertSame(['write' => false, 'share' => false, 'delete' => false], $described['permissions']);
    }

    // rootStorageId() itself (and isLegacyGroupFolders()/fetchAllFolders()/
    // fetchFolder()/mountPointExists()) needs a real Nextcloud service
    // container — see this file's class docblock — and is covered by
    // build/nc-instance.sh's matrix instead, not here.

    // -----------------------------------------------------------------
    // requireGroup() — GM-02: no read or write against a nonexistent group.
    // -----------------------------------------------------------------

    public function testListAssignedRejectsMissingGroup(): void {
        $this->assertRejectsMissingGroup(fn () => $this->service->listAssigned('ghost'));
    }

    public function testSearchAssignableRejectsMissingGroup(): void {
        $this->assertRejectsMissingGroup(fn () => $this->service->searchAssignable('ghost', ''));
    }

    public function testAssignFolderRejectsMissingGroup(): void {
        $this->assertRejectsMissingGroup(fn () => $this->service->assignFolder('ghost', 5));
    }

    public function testListMountPointsRejectsDisabledApp(): void {
        $this->appManager->method('isEnabledForUser')->willReturn(false);
        $this->db->expects($this->never())->method('getQueryBuilder');

        try {
            $this->service->listMountPoints();
            $this->fail('Expected GroupServiceException');
        } catch (GroupServiceException $e) {
            $this->assertSame('GROUPFOLDERS_DISABLED', $e->errorCode);
        }
    }

    public function testCreateFolderRejectsMissingGroupBeforeTouchingGroupfolders(): void {
        $this->assertRejectsMissingGroup(fn () => $this->service->createFolder('ghost', 'Shared'));
    }

    public function testSetPermissionsRejectsMissingGroup(): void {
        $this->assertRejectsMissingGroup(fn () => $this->service->setPermissions('ghost', 5, true, false, false));
    }

    public function testSetQuotaRejectsMissingGroup(): void {
        // A valid, non-negative quota, so this reaches requireGroup() rather
        // than being rejected earlier by the quota-value check itself.
        $this->assertRejectsMissingGroup(fn () => $this->service->setQuota('ghost', 5, 1000));
    }

}
