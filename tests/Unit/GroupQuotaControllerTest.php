<?php
declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GroupManager\Tests\Unit;

use OCA\GroupManager\Controller\GroupQuotaController;
use OCA\GroupManager\Service\GroupQuotaService;
use OCA\GroupManager\Service\GroupServiceException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class GroupQuotaControllerTest extends TestCase {

    private GroupQuotaService&MockObject $service;
    private GroupQuotaController $controller;

    protected function setUp(): void {
        $this->service = $this->createMock(GroupQuotaService::class);
        $this->controller = new GroupQuotaController($this->createMock(IRequest::class), $this->service);
    }

    public function testShowPassesGidThroughWithoutReDecoding(): void {
        $this->service->expects($this->once())->method('get')->with('research+dev')->willReturn([]);
        $this->controller->show('research+dev');
    }

    public function testSetPassesGidAndBytesThrough(): void {
        $this->service->expects($this->once())->method('set')->with('100% done', 1024)->willReturn([]);
        $this->controller->set('100% done', 1024);
    }

    public function testServiceErrorsBecomeStatusAndCode(): void {
        $this->service->method('get')->willThrowException(new GroupServiceException('off', 'GROUPQUOTA_DISABLED', 404));

        $response = $this->controller->show('a');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertSame(['error' => 'off', 'code' => 'GROUPQUOTA_DISABLED'], $response->getData());
    }

    /**
     * @dataProvider passwordConfirmedMethods
     */
    public function testMutationRequiresPasswordConfirmation(string $method): void {
        $attributes = (new \ReflectionMethod(GroupQuotaController::class, $method))
            ->getAttributes(PasswordConfirmationRequired::class);

        $this->assertNotEmpty($attributes, "$method must require a recent password confirmation");
    }

    public static function passwordConfirmedMethods(): array {
        return [['set'], ['destroy']];
    }
}
