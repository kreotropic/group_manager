<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GroupManager\Controller;

use OCA\GroupManager\AppInfo\Application;
use OCA\GroupManager\Service\FolderAssignmentService;
use OCA\GroupManager\Service\GroupServiceException;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

/**
 * Admin-only REST API for assigning group folders (groupfolders app) to a
 * group and editing its per-folder Write/Share/Delete permissions. Every
 * method 404s with GROUPFOLDERS_DISABLED if that app isn't enabled for the
 * current admin — the frontend uses that to hide the whole tab.
 */
class GroupFolderController extends Controller {

    public function __construct(
        IRequest $request,
        private FolderAssignmentService $folderAssignmentService,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    public function index(string $gid): DataResponse {
        $gid = urldecode($gid);
        return $this->guarded(fn () => new DataResponse(['folders' => $this->folderAssignmentService->listAssigned($gid)]));
    }

    public function search(string $gid, string $search = '', int $limit = 10): DataResponse {
        $gid = urldecode($gid);
        return $this->guarded(fn () => new DataResponse(['folders' => $this->folderAssignmentService->searchAssignable($gid, $search, $limit)]));
    }

    public function assign(string $gid, int $folderId): DataResponse {
        $gid = urldecode($gid);
        return $this->guarded(fn () => new DataResponse($this->folderAssignmentService->assignFolder($gid, $folderId)));
    }

    public function unassign(string $gid, int $folderId): DataResponse {
        $gid = urldecode($gid);
        return $this->guarded(function () use ($gid, $folderId) {
            $this->folderAssignmentService->unassignFolder($gid, $folderId);
            return new DataResponse([]);
        });
    }

    public function setPermissions(string $gid, int $folderId, bool $write, bool $share, bool $delete): DataResponse {
        $gid = urldecode($gid);
        return $this->guarded(fn () => new DataResponse($this->folderAssignmentService->setPermissions($gid, $folderId, $write, $share, $delete)));
    }

    public function setQuota(string $gid, int $folderId, int $quota): DataResponse {
        $gid = urldecode($gid);
        return $this->guarded(fn () => new DataResponse($this->folderAssignmentService->setQuota($gid, $folderId, $quota)));
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
