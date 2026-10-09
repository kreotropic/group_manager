<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GroupManager\Controller;

use OCA\GroupManager\AppInfo\Application;
use OCA\GroupManager\Service\GroupQuotaService;
use OCA\GroupManager\Service\GroupServiceException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Admin-only REST API over the groupquota app's per-group limits. Every
 * method 404s with GROUPQUOTA_DISABLED if that app isn't enabled for the
 * current admin; the frontend uses that to hide the tab.
 */
class GroupQuotaController extends Controller {

    public function __construct(
        IRequest $request,
        private GroupQuotaService $groupQuotaService,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    public function show(string $gid): DataResponse {
        return $this->guarded(fn () => new DataResponse($this->groupQuotaService->get($gid)));
    }

    /**
     * @param int $quota bytes; -3 (or any negative value) removes the limit
     */
    #[PasswordConfirmationRequired]
    public function set(string $gid, int $quota): DataResponse {
        return $this->guarded(fn () => new DataResponse($this->groupQuotaService->set($gid, $quota)));
    }

    #[PasswordConfirmationRequired]
    public function destroy(string $gid): DataResponse {
        return $this->guarded(function () use ($gid) {
            $this->groupQuotaService->delete($gid);
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
