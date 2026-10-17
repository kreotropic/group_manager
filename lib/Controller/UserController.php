<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GroupManager\Controller;

use OCA\GroupManager\AppInfo\Application;
use OCA\GroupManager\Service\GroupServiceException;
use OCA\GroupManager\Service\UserService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Admin-only (the framework's default) read API for the Users view. Writes
 * go to core's provisioning API directly, see UserService.
 */
class UserController extends Controller {

    public function __construct(
        IRequest $request,
        private UserService $userService,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * $pageSize, not $limit: see GroupController::members().
     */
    public function index(string $search = '', ?int $pageSize = null, int $offset = 0, string $filter = 'all'): DataResponse {
        return $this->guarded(fn () => new DataResponse($this->userService->listUsers($search, $pageSize, $offset, $filter)));
    }

    public function show(string $uid): DataResponse {
        return $this->guarded(fn () => new DataResponse($this->userService->getUser($uid)));
    }

    public function options(): DataResponse {
        return new DataResponse($this->userService->getOptions());
    }

    private function guarded(callable $action): DataResponse {
        try {
            return $action();
        } catch (GroupServiceException $e) {
            return new DataResponse(
                ['error' => $e->getMessage(), 'code' => $e->errorCode],
                $e->httpStatus,
            );
        }
    }
}
