<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GroupManager\Tests\Unit;

use OCA\GroupManager\Controller\GroupFolderController;
use OCA\GroupManager\Service\FolderAssignmentService;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Same double-decode regression as GroupControllerTest, for the folder
 * assignment endpoints (all of which take $gid as a route placeholder that
 * Nextcloud's router has already decoded once).
 */
class GroupFolderControllerTest extends TestCase {

    private FolderAssignmentService&MockObject $folderAssignmentService;
    private GroupFolderController $controller;

    protected function setUp(): void {
        $request = $this->createMock(IRequest::class);
        $this->folderAssignmentService = $this->createMock(FolderAssignmentService::class);
        $this->controller = new GroupFolderController($request, $this->folderAssignmentService);
    }

    public function testIndexPassesGidThroughWithoutReDecoding(): void {
        $this->folderAssignmentService->expects($this->once())
            ->method('listAssigned')
            ->with('research+dev')
            ->willReturn([]);

        $this->controller->index('research+dev');
    }

    public function testAssignPassesGidThroughWithoutReDecoding(): void {
        $this->folderAssignmentService->expects($this->once())
            ->method('assignFolder')
            ->with('100% done', 5)
            ->willReturn([]);

        $this->controller->assign('100% done', 5);
    }

    /**
     * @dataProvider passwordConfirmedMethods
     */
    public function testMutationRequiresPasswordConfirmation(string $method): void {
        $attributes = (new \ReflectionMethod(GroupFolderController::class, $method))
            ->getAttributes(PasswordConfirmationRequired::class);

        $this->assertNotEmpty($attributes, "$method must require a recent password confirmation");
    }

    /**
     * @return list<array{0: string}>
     */
    public static function passwordConfirmedMethods(): array {
        return [
            ['create'],
            ['assign'],
            ['unassign'],
            ['setPermissions'],
            ['setQuota'],
        ];
    }
}
