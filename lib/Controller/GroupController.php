<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GroupManager\Controller;

use OCA\GroupManager\AppInfo\Application;
use OCA\GroupManager\Service\GroupService;
use OCA\GroupManager\Service\GroupServiceException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Admin-only REST API for browsing groups (local + LDAP) and managing local
 * group membership. Every method here defaults to admin-required (the
 * framework blocks non-admins before the method body runs).
 */
class GroupController extends Controller {

    public function __construct(
        IRequest $request,
        private GroupService $groupService,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    public function index(): DataResponse {
        return new DataResponse(['groups' => $this->groupService->listGroups()]);
    }

    public function show(string $gid): DataResponse {
        $gid = urldecode($gid);
        return $this->guarded(fn () => new DataResponse($this->groupService->getGroup($gid)));
    }

    public function members(string $gid, string $search = '', ?int $limit = null, int $offset = 0): DataResponse {
        $gid = urldecode($gid);
        return $this->guarded(fn () => new DataResponse($this->groupService->getMembers($gid, $search, $limit, $offset)));
    }

    public function create(string $gid, string $displayName = ''): DataResponse {
        return $this->guarded(fn () => new DataResponse($this->groupService->createGroup($gid, $displayName)));
    }

    public function rename(string $gid, string $displayName): DataResponse {
        $gid = urldecode($gid);
        return $this->guarded(fn () => new DataResponse($this->groupService->renameGroup($gid, $displayName)));
    }

    public function destroy(string $gid): DataResponse {
        $gid = urldecode($gid);
        return $this->guarded(function () use ($gid) {
            $this->groupService->deleteGroup($gid);
            return new DataResponse([]);
        });
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
