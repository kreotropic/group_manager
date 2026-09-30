<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Ricardo Ferreira <rsfneg@gmail.com>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\GroupManager\Controller;

use OCA\GroupManager\AppInfo\Application;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\DataResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Per-admin UI preferences. Admin-only like the rest of the API (no
 * attribute means the framework requires an admin).
 */
class PreferencesController extends Controller {

    public const KEY_QUICK_ACCESS = 'quick_access';

    public function __construct(
        IRequest $request,
        private IConfig $config,
        private IUserSession $userSession,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    public function show(): DataResponse {
        return new DataResponse(['quickAccess' => $this->read()]);
    }

    public function setQuickAccess(bool $enabled): DataResponse {
        $uid = $this->userSession->getUser()?->getUID();
        if ($uid === null) {
            return new DataResponse(['error' => 'NOT_LOGGED_IN'], 401);
        }
        $this->config->setUserValue($uid, Application::APP_ID, self::KEY_QUICK_ACCESS, $enabled ? '1' : '0');
        return new DataResponse(['quickAccess' => $enabled]);
    }

    private function read(): bool {
        $uid = $this->userSession->getUser()?->getUID();
        return $uid !== null
            && $this->config->getUserValue($uid, Application::APP_ID, self::KEY_QUICK_ACCESS, '0') === '1';
    }
}
