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
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
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
        return $this->guarded(fn () => new DataResponse($this->groupService->getGroup($gid)));
    }

    /**
     * Not $limit: Nextcloud's own AppFramework Dispatcher special-cases any
     * controller parameter named exactly that, silently clamping it to
     * [1, 500] — a value outside that range never reaches this method or
     * GroupService's own INVALID_PAGINATION validation (GM-10) at all,
     * failing instead as an uncaught ParameterOutOfRangeException (a raw
     * 500, not JSON) before the framework even routes here. Confirmed
     * present on NC 34/35 and absent on 31–33 (the dispatcher's own
     * ensureParameterValueSatisfiesRange() only gained this "limit" case
     * with a 3rd, $default, argument somewhere in that gap) while building
     * this app's own matrix. $pageSize is exempt from the special-casing,
     * so this app's own bounds (and error shape) are what a client actually
     * sees on every version this app supports.
     */
    public function members(string $gid, string $search = '', ?int $pageSize = null, int $offset = 0): DataResponse {
        return $this->guarded(fn () => new DataResponse($this->groupService->getMembers($gid, $search, $pageSize, $offset)));
    }

    public function candidates(string $gid, string $search = '', int $limit = 10): DataResponse {
        return $this->guarded(fn () => new DataResponse($this->groupService->searchCandidates($gid, $search, $limit)));
    }

    public function expandGroup(string $gid, string $sourceGid): DataResponse {
        return $this->guarded(fn () => new DataResponse(['members' => $this->groupService->expandGroupForAdd($gid, $sourceGid)]));
    }

    /**
     * @param string[] $tokens
     */
    public function resolvePastedList(string $gid, array $tokens): DataResponse {
        return $this->guarded(fn () => new DataResponse(['results' => $this->groupService->resolvePastedTokens($gid, $tokens)]));
    }

    #[PasswordConfirmationRequired]
    public function addMember(string $gid, string $uid): DataResponse {
        return $this->guarded(fn () => new DataResponse($this->groupService->addMember($gid, $uid)));
    }

    #[PasswordConfirmationRequired]
    public function removeMember(string $gid, string $uid): DataResponse {
        return $this->guarded(function () use ($gid, $uid) {
            $this->groupService->removeMember($gid, $uid);
            return new DataResponse([]);
        });
    }

    #[PasswordConfirmationRequired]
    public function create(string $gid, string $displayName = ''): DataResponse {
        return $this->guarded(fn () => new DataResponse($this->groupService->createGroup($gid, $displayName)));
    }

    public function rename(string $gid, string $displayName): DataResponse {
        return $this->guarded(fn () => new DataResponse($this->groupService->renameGroup($gid, $displayName)));
    }

    #[PasswordConfirmationRequired]
    public function destroy(string $gid): DataResponse {
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
