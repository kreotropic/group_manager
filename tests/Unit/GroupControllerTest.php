<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GroupManager\Tests\Unit;

use OCA\GroupManager\Controller\GroupController;
use OCA\GroupManager\Service\GroupService;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Nextcloud's router already url-decodes route placeholders before they
 * reach the controller — a manual urldecode() on top of that double-decodes
 * anything containing a literal "+" (turned into a space) or a "%xx"
 * sequence in the group/user id. These tests assert the raw, already-decoded
 * value the framework hands in is passed straight through unchanged.
 */
class GroupControllerTest extends TestCase {

    private GroupService&MockObject $groupService;
    private GroupController $controller;

    protected function setUp(): void {
        $request = $this->createMock(IRequest::class);
        $this->groupService = $this->createMock(GroupService::class);
        $this->controller = new GroupController($request, $this->groupService);
    }

    public function testShowPassesGidThroughWithoutReDecoding(): void {
        $this->groupService->expects($this->once())
            ->method('getGroup')
            ->with('research+dev')
            ->willReturn([]);

        $this->controller->show('research+dev');
    }

    public function testMembersPassesGidThroughWithoutReDecoding(): void {
        $this->groupService->expects($this->once())
            ->method('getMembers')
            ->with('100% done', '', null, 0)
            ->willReturn(['members' => [], 'total' => 0]);

        $this->controller->members('100% done');
    }

    public function testExpandGroupPassesBothIdsThroughWithoutReDecoding(): void {
        $this->groupService->expects($this->once())
            ->method('expandGroupForAdd')
            ->with('a+b', 'c+d')
            ->willReturn([]);

        $this->controller->expandGroup('a+b', 'c+d');
    }

    public function testAddMemberPassesGidThroughWithoutReDecoding(): void {
        $this->groupService->expects($this->once())
            ->method('addMember')
            ->with('research+dev', 'alice')
            ->willReturn([]);

        $this->controller->addMember('research+dev', 'alice');
    }

    public function testRemoveMemberPassesGidAndUidThroughWithoutReDecoding(): void {
        $this->groupService->expects($this->once())
            ->method('removeMember')
            ->with('research+dev', 'a+b');

        $this->controller->removeMember('research+dev', 'a+b');
    }

    /**
     * @dataProvider passwordConfirmedMethods
     */
    public function testMutationRequiresPasswordConfirmation(string $method): void {
        $attributes = (new \ReflectionMethod(GroupController::class, $method))
            ->getAttributes(PasswordConfirmationRequired::class);

        $this->assertNotEmpty($attributes, "$method must require a recent password confirmation");
    }

    /**
     * @return list<array{0: string}>
     */
    public static function passwordConfirmedMethods(): array {
        return [
            ['create'],
            ['destroy'],
            ['addMember'],
            ['removeMember'],
        ];
    }
}
