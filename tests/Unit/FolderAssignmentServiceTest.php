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
use OCP\IL10N;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * encodePermissions()/decodePermissions() are exercised via reflection
 * because they are private — but, unlike the rest of this class, they take
 * and return only primitives, so they need no groupfolders class at all.
 * Everything else here (describeFolder(), searchAssignable(), ...) needs
 * `OCA\GroupFolders\Folder\FolderWithMappingsAndCache`, which is not
 * autoloadable outside a full Nextcloud bootstrap (confirmed: class_exists()
 * on it returns false under just lib/composer/autoload.php +
 * 3rdparty/autoload.php, the same autoloaders tests/bootstrap.php loads) —
 * covering those is left to a future integration test against a real
 * instance rather than faked here.
 */
class FolderAssignmentServiceTest extends TestCase {

    private IAppManager&MockObject $appManager;
    private IUserSession&MockObject $userSession;
    private IL10N&MockObject $l;
    private FolderAssignmentService $service;

    protected function setUp(): void {
        $this->appManager = $this->createMock(IAppManager::class);
        $this->userSession = $this->createMock(IUserSession::class);
        $this->l = $this->createMock(IL10N::class);
        $this->l->method('t')->willReturnArgument(0);
        $this->service = new FolderAssignmentService($this->appManager, $this->userSession, $this->l);
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
}
