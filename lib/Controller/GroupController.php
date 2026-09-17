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

    public function candidates(string $gid, string $search = '', int $limit = 10): DataResponse {
        $gid = urldecode($gid);
        return $this->guarded(fn () => new DataResponse($this->groupService->searchCandidates($gid, $search, $limit)));
    }

    public function expandGroup(string $gid, string $sourceGid): DataResponse {
        $gid = urldecode($gid);
        $sourceGid = urldecode($sourceGid);
        return $this->guarded(fn () => new DataResponse(['members' => $this->groupService->expandGroupForAdd($gid, $sourceGid)]));
    }

    /**
     * @param string[] $tokens
     */
    public function resolvePastedList(string $gid, array $tokens): DataResponse {
        $gid = urldecode($gid);
        return $this->guarded(fn () => new DataResponse(['results' => $this->groupService->resolvePastedTokens($gid, $tokens)]));
    }

    public function addMember(string $gid, string $uid): DataResponse {
        $gid = urldecode($gid);
        return $this->guarded(fn () => new DataResponse($this->groupService->addMember($gid, $uid)));
    }

    public function removeMember(string $gid, string $uid): DataResponse {
        $gid = urldecode($gid);
        $uid = urldecode($uid);
        return $this->guarded(function () use ($gid, $uid) {
            $this->groupService->removeMember($gid, $uid);
            return new DataResponse([]);
        });
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
